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
    <div style="margin-top:6px;font-size:12px;color:#444">Markerless mode: no image target required (Instant World Tracker)</div>
  </div>
  <div id="status">Waiting for target...</div>

  <script type="module">
import * as THREE from 'https://cdn.jsdelivr.net/npm/three@0.160.1/build/three.module.js';
import * as ZapparThree from 'https://cdn.jsdelivr.net/npm/@zappar/zappar-threejs@1.0.19/dist/zappar-threejs.module.js';

const productImg = <?php echo json_encode($productImg); ?>;
const productSize = <?php echo json_encode($productSize); ?>;

// Renderer + scene + Zappar camera
const renderer = new THREE.WebGLRenderer({ antialias: true, alpha: true });
renderer.setPixelRatio(window.devicePixelRatio);
renderer.setSize(window.innerWidth, window.innerHeight);
document.body.appendChild(renderer.domElement);

const camera = new ZapparThree.Camera();
const scene = new THREE.Scene();

// Add a simple light so placed meshes look ok
const light = new THREE.HemisphereLight(0xffffff, 0xbbbbff, 1);
scene.add(light);

// Use Zappar Instant World Tracker (markerless)
let tracker = null;
try {
  tracker = new ZapparThree.InstantWorldTracker();
} catch (e) {
  console.warn('Failed to create InstantWorldTracker:', e);
}

// Anchor group that follows the instant world tracker (markerless)
let anchorGroup = new THREE.Group();
if (tracker) {
  anchorGroup = new ZapparThree.InstantWorldAnchorGroup(tracker, camera);
}
scene.add(anchorGroup);

// Start camera if available
try {
  if (camera && typeof camera.start === 'function') camera.start();
} catch(e) { console.warn('camera.start failed', e); }

// helper plane (in anchor local space) used for raycasting
const planeGeo = new THREE.PlaneGeometry(1, 1);
const planeMat = new THREE.MeshBasicMaterial({ visible: false });
const anchorPlane = new THREE.Mesh(planeGeo, planeMat);
anchorPlane.rotation.x = -Math.PI / 2;
anchorGroup.add(anchorPlane);

// storage for points/lines/labels
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
  raycaster.setFromCamera(pointer, camera.camera ? camera.camera : camera);
  return raycaster.intersectObject(anchorPlane, false);
}

function placePointFromScreen(x, y){
  const hits = screenToRaycaster(x, y);
  if (!hits.length) return false;
  const worldPoint = hits[0].point;
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

    const d = a.distanceTo(b);
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
  const cm = parseFloat(document.getElementById('targetWidth').value) || 1;
  return (cm/100);
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

let tileMesh=null;
function updateTilePreview(){
  if (tileMesh){ anchorGroup.remove(tileMesh); tileMesh.geometry.dispose(); tileMesh.material.map.dispose(); tileMesh.material.dispose(); tileMesh=null; }
  if (points.length < 3) return;
  const verts = [];
  points.forEach(p=>verts.push(p.position.x,p.position.y,p.position.z));
  const geometry = new THREE.BufferGeometry();
  geometry.setAttribute('position', new THREE.Float32BufferAttribute(verts,3));
  const indices=[]; for(let i=1;i<points.length-1;i++) indices.push(0,i,i+1);
  geometry.setIndex(indices); geometry.computeVertexNormals();
  const tex = new THREE.TextureLoader().load(productImg || 'noImage.png');
  tex.wrapS = tex.wrapT = THREE.RepeatWrapping; tex.repeat.set(1,1);
  const mat = new THREE.MeshBasicMaterial({ map:tex, side:THREE.DoubleSide });
  tileMesh = new THREE.Mesh(geometry, mat);
  anchorGroup.add(tileMesh);
}

document.getElementById('btnPlace').addEventListener('click', ()=>{
  if (!placePointFromScreen(window.innerWidth/2, window.innerHeight/2)) alert('Target not visible or placement failed. Aim camera at the printed target and wait until detected.');
});
document.getElementById('btnUndo').addEventListener('click', undo);
document.getElementById('btnClear').addEventListener('click', clearAll);
function sendMeasurements(){
  if (points.length < 3) {
    alert('Please place at least 3 points to measure a surface.');
    return;
  }

  const { length, width } = calculateSizes();
  const lengthFixed = parseFloat(length.toFixed(2));
  const widthFixed = parseFloat(width.toFixed(2));

  const params = new URLSearchParams(location.search);
  const session = params.get('session');
  const product = params.get('product') || <?php echo json_encode($productId); ?> || null;

  // POSTMESSAGE path
  try {
    if (window.opener && !window.opener.closed) {
      window.opener.postMessage({
        type: 'ar-measurement',
        length: lengthFixed,
        width: widthFixed,
        productId: product ? Number(product) : null,
        session: session || null
      }, '*');
      try { window.close(); } catch(e) {}
      return;
    }
  } catch (err) {
    console.warn('postMessage failed:', err);
  }

  // Redirect fallback if no session
  if (!session) {
    const redirectUrl = `ProductDetails.php?id=${encodeURIComponent(product)}&length=${encodeURIComponent(lengthFixed)}&width=${encodeURIComponent(widthFixed)}`;
    window.location.href = redirectUrl;
    return;
  }

  // Server POST fallback
  const endpoint = location.origin + '/ar-submit.php';
  fetch(endpoint, {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({ session: session, length: lengthFixed, width: widthFixed, productId: product ? Number(product) : null })
  }).then(r => r.json().catch(() => { throw new Error('Invalid JSON response'); }))
    .then(res => {
      if (res && res.status === 'ok') {
        try { alert('Measurements sent. Return to your PC.'); } catch(e){}
        try { window.close(); } catch(e) { document.body.innerHTML = '<div style="padding:20px;font-family:system-ui">Measurements sent. You may close this tab and return to your computer.</div>'; }
      } else {
        alert('Failed to send measurements: ' + (res && res.msg ? res.msg : 'unknown'));
      }
    }).catch(err => {
      console.error('POST failed', err);
      alert('Failed to send measurements. Check network and try again.');
    });
}

document.getElementById('btnConfirm').addEventListener('click', sendMeasurements);

window.addEventListener('pointerdown', (e)=>{ if (anchorGroup.visible) placePointFromScreen(e.clientX, e.clientY); });

// status updates based on anchor visibility
const statusEl = document.getElementById('status');
function updateStatus(){
  if (!tracker) { statusEl.textContent = 'Instant tracker not available in this browser/device'; return; }
  statusEl.textContent = anchorGroup.visible ? 'Tracking — place points' : 'Not tracking — move device to initialize tracking';
}

// Animation / render loop
function animate(){
  try { if (camera && typeof camera.updateFrame === 'function') camera.updateFrame(renderer); else if (camera && typeof camera.update === 'function') camera.update(); } catch(e) { console.warn('camera update error', e); }
  updateStatus();
  renderer.render(scene, camera.camera ? camera.camera : camera);
  requestAnimationFrame(animate);
}

animate();

// expose for debugging
window._zappar = { camera, tracker, anchorGroup };
  </script>
</body>
</html>
