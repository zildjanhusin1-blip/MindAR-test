<?php
require_once 'db_connect.php'; // keep same DB access pattern

$productId = isset($_GET['id']) ? $_GET['id'] : null;
$productImg = '';
$productSize = '';

if ($productId) {
    $tables = ['productsmedian', 'productssophisticated', 'productsluxurious'];
    foreach ($tables as $table) {
        $stmt = $pdo->prepare("SELECT Image, Size FROM $table WHERE ProductID = ?");
        $stmt->execute([$productId]);
        $row = $stmt->fetch();
        if ($row) {
            if (!empty($row['Image'])) {
                $base64 = base64_encode($row['Image']);
                $productImg = 'data:image/jpeg;base64,' . $base64;
            }
            $productSize = $row['Size'];
            break;
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Measurement (MindAR + Three.js)</title>
  <style>
    body{margin:0;overflow:hidden;font-family:system-ui}
    #hud{position:fixed;left:12px;top:12px;z-index:999;color:#111;background:rgba(255,255,255,0.9);padding:10px;border-radius:8px}
    #hud input{width:80px}
    #status{position:fixed;left:12px;bottom:12px;background:rgba(0,0,0,0.6);color:#fff;padding:8px;border-radius:6px}
  </style>
</head>
<body>
  <div id="hud">
    <div><strong>AR Measurement (image target)</strong></div>
    <div style="margin-top:6px">Target width (cm): <input id="targetWidth" value="21"/></div>
    <div style="margin-top:6px">
      <button id="btnPlace">Place point</button>
      <button id="btnUndo">Undo</button>
      <button id="btnClear">Clear</button>
      <button id="btnConfirm">Confirm</button>
    </div>
    <div style="margin-top:6px;font-size:12px;color:#444">Note: put your MindAR target file at <code>assets/targets/measurement.mind</code></div>
  </div>
  <div id="status">Waiting for target...</div>

  <script type="module">
import * as THREE from 'https://cdn.jsdelivr.net/npm/three@0.160.1/build/three.module.js';
import { MindARThree } from 'https://cdn.jsdelivr.net/npm/mind-ar@1.1.5/dist/mindar-image-three.prod.js';

const productImg = <?php echo json_encode($productImg); ?>;
const productSize = <?php echo json_encode($productSize); ?>;

const mindar = new MindARThree({
  container: document.body,
  imageTargetSrc: './assets/targets/measurement.mind'
});

const { renderer, scene, camera } = mindar;
const anchor = mindar.addAnchor(0);

// local anchor group will move with the detected target
const anchorGroup = anchor.group;

// helper plane (in anchor local space) used for raycasting
const planeGeo = new THREE.PlaneGeometry(1, 1);
const planeMat = new THREE.MeshBasicMaterial({ visible: false });
const anchorPlane = new THREE.Mesh(planeGeo, planeMat);
anchorPlane.rotation.x = -Math.PI / 2; // align if needed; keep at z=0
anchorGroup.add(anchorPlane);

// storage
const points = [];
const lines = [];
const labels = [];

const pointGeo = new THREE.SphereGeometry(0.01, 12, 12);
const pointMat = new THREE.MeshBasicMaterial({ color: 0xed8d1b });

const raycaster = new THREE.Raycaster();
const pointer = new THREE.Vector2();

function screenToRaycaster(x, y){
  pointer.x = (x / window.innerWidth) * 2 - 1;
  pointer.y = -(y / window.innerHeight) * 2 + 1;
  raycaster.setFromCamera(pointer, camera);
  return raycaster.intersectObject(anchorPlane, false);
}

function placePointFromScreen(x, y){
  const hits = screenToRaycaster(x, y);
  if (!hits.length) return false;
  const worldPoint = hits[0].point; // world coords
  // convert world point to anchor local
  const local = anchorGroup.worldToLocal(worldPoint.clone());

  const p = new THREE.Mesh(pointGeo, pointMat.clone());
  p.position.copy(local);
  anchorGroup.add(p);
  points.push(p);

  if (points.length >= 2){
    const a = points[points.length-2].position.clone();
    const b = points[points.length-1].position.clone();
    const geometry = new THREE.BufferGeometry().setFromPoints([a,b]);
    const material = new THREE.LineBasicMaterial({ color:0x000000 });
    const line = new THREE.Line(geometry, material);
    anchorGroup.add(line);
    lines.push(line);

    const d = a.distanceTo(b); // units relative to anchor (anchor width = 1 unit)
    const label = makeLabel((d * getScaleFactor()).toFixed(2) + ' m');
    const mid = new THREE.Vector3().addVectors(a,b).multiplyScalar(0.5);
    label.position.copy(mid).add(new THREE.Vector3(0,0.02,0));
    anchorGroup.add(label);
    labels.push(label);
  }
  updateTilePreview();
  return true;
}

function makeLabel(text){
  const canvas = document.createElement('canvas');
  const ctx = canvas.getContext('2d');
  const fontSize = 64;
  ctx.font = `${fontSize}px sans-serif`;
  const w = ctx.measureText(text).width + 40;
  canvas.width = w; canvas.height = fontSize + 20;
  ctx.fillStyle = 'white'; ctx.fillRect(0,0,canvas.width,canvas.height);
  ctx.fillStyle = 'black'; ctx.textAlign='center'; ctx.textBaseline='middle';
  ctx.fillText(text, canvas.width/2, canvas.height/2);
  const tex = new THREE.CanvasTexture(canvas);
  const mat = new THREE.SpriteMaterial({ map: tex, depthTest:false });
  const spr = new THREE.Sprite(mat);
  spr.scale.set(0.12 * (canvas.width / canvas.height), 0.12, 1);
  spr.onBeforeRender = function(renderer, scene, camera){ spr.quaternion.copy(camera.quaternion); };
  return spr;
}

function getScaleFactor(){
  // assume anchorPlane width = 1 unit equals the image target width
  const cm = parseFloat(document.getElementById('targetWidth').value) || 1;
  return (cm/100); // meters per 1 unit
}

function undo(){
  if (labels.length){ const l=labels.pop(); anchorGroup.remove(l); }
  if (lines.length){ const ln=lines.pop(); anchorGroup.remove(ln); }
  if (points.length){ const p=points.pop(); anchorGroup.remove(p); }
  updateTilePreview();
}
function clearAll(){ labels.splice(0).forEach(l=>anchorGroup.remove(l)); lines.splice(0).forEach(l=>anchorGroup.remove(l)); points.splice(0).forEach(p=>anchorGroup.remove(p)); updateTilePreview(); }

function calculateSizes(){
  if (points.length < 2) return {length:0,width:0};
  const edges = [];
  for (let i=0;i<points.length;i++){
    const a=points[i].position; const b=points[(i+1)%points.length].position;
    edges.push(a.distanceTo(b));
  }
  const unique=[]; const tol=0.01;
  edges.forEach(e=>{ if(!unique.some(u=>Math.abs(u-e)<tol)) unique.push(e); });
  unique.sort((a,b)=>b-a);
  return { length: (unique[0]||0)*getScaleFactor(), width:(unique[1]||0)*getScaleFactor() };
}

// Tile preview: simple textured plane bounded by convex hull of points (if >=3)
let tileMesh=null;
function updateTilePreview(){
  if (tileMesh){ anchorGroup.remove(tileMesh); tileMesh.geometry.dispose(); tileMesh.material.map.dispose(); tileMesh.material.dispose(); tileMesh=null; }
  if (points.length < 3) return;
  // build simple geometry using points order
  const verts = [];
  points.forEach(p=>verts.push(p.position.x,p.position.y,p.position.z));
  const geometry = new THREE.BufferGeometry();
  geometry.setAttribute('position', new THREE.Float32BufferAttribute(verts,3));
  // naive triangulation (fan)
  const indices=[]; for(let i=1;i<points.length-1;i++) indices.push(0,i,i+1);
  geometry.setIndex(indices); geometry.computeVertexNormals();
  const tex = new THREE.TextureLoader().load(productImg || 'noImage.png');
  tex.wrapS = tex.wrapT = THREE.RepeatWrapping; tex.repeat.set(1,1);
  const mat = new THREE.MeshBasicMaterial({ map:tex, side:THREE.DoubleSide });
  tileMesh = new THREE.Mesh(geometry, mat);
  anchorGroup.add(tileMesh);
}

document.getElementById('btnPlace').addEventListener('click', ()=>{
  // use center of screen placement as fallback
  if (!placePointFromScreen(window.innerWidth/2, window.innerHeight/2)) alert('Target not visible or placement failed. Aim camera at the printed target and wait until detected.');
});
document.getElementById('btnUndo').addEventListener('click', undo);
document.getElementById('btnClear').addEventListener('click', clearAll);
document.getElementById('btnConfirm').addEventListener('click', ()=>{
  const {length,width} = calculateSizes();
  const lengthFixed = parseFloat(length.toFixed(2));
  const widthFixed = parseFloat(width.toFixed(2));
  // postMessage to opener (same fallback as original)
  try{ if (window.opener && !window.opener.closed){ window.opener.postMessage({type:'ar-measurement', length:lengthFixed, width:widthFixed, productId: <?php echo json_encode($productId); ?>}, '*'); try{ window.close(); }catch(e){} return; }}catch(e){}
  alert('Measurements: '+lengthFixed+' m x '+widthFixed+' m');
});

// place by tapping screen when anchor is found
window.addEventListener('pointerdown', (e)=>{
  // only place when anchor visible
  if (anchor.visible) placePointFromScreen(e.clientX, e.clientY);
});

anchor.onTargetFound = () => { document.getElementById('status').textContent = 'Target found — place points'; };
anchor.onTargetLost = () => { document.getElementById('status').textContent = 'Target lost — aim camera at target'; };

await mindar.start();
renderer.setAnimationLoop(()=>{ renderer.render(scene,camera); });

// expose for debugging
window._mindar = mindar;
  </script>
</body>
</html>
