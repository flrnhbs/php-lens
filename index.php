<?php
declare(strict_types=1);

// A fresh, unguessable session id per page load (48 bits of entropy).
// Reload this page any time to start a new pairing session.
$param_preset = $_GET['admin'] ?? false;
if ($param_preset) {
  $sessionId = 'admin';
  } else {
$sessionId = bin2hex(random_bytes(6));
}

$res_support = $_GET['fullres'] ?? false;

// Plain $_SERVER checks miss HTTPS when a reverse proxy (Render, most
// PaaS hosts, many load balancers) terminates TLS and forwards plain
// HTTP to the app — that's why X-Forwarded-Proto is checked too.
$scheme = 'https';
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    $scheme = 'https';
} elseif (($_SERVER['SERVER_PORT'] ?? '') === '443') {
    $scheme = 'https';
} elseif (strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') {
    $scheme = 'https';
}
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
$mobileUrl = $scheme . '://' . $host . $basePath . '/mobile.php?session=' . $sessionId . '&fullres=' . $res_support;
$outputUrl = $scheme . '://' . $host . $basePath . '/output.php?session=' . $sessionId . '&fullres=' . $res_support;
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Lens — pair a phone as this camera</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/style.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
</head>
<body>

<div class="stage" id="pairingStage">
  <div class="panel">
    <div class="eyebrow"><span class="eyebrow-dot">●</span> Lens</div>
    <h1>Turn a phone into this camera</h1>
    <p class="subtext">Scan this with your phone's camera to connect it. Keep this tab open while you're streaming.</p>

    <div class="frame" id="qrFrame">
      <span class="corner tl"></span><span class="corner tr"></span>
      <span class="corner bl"></span><span class="corner br"></span>
      <div class="qr-wrap" id="qrCode"></div>
    </div>

    <div class="session-code">SESSION <b><?php echo strtoupper($sessionId); ?></b></div>

    <div class="status-row" id="statusRow">
      <span class="dot" id="statusDot"></span>
      <span id="statusText">Waiting for your phone</span>
    </div>

    <div class="link-container"><tr>
        <td><div class="container">
          <div class="link-row">
            <input id="mobileUrl" type="text" readonly value="<?php echo htmlspecialchars($mobileUrl, ENT_QUOTES); ?>">
            <button class="btn" id="copyBtn" type="button">Copy</button>
          </div>
    <p class="footnote">Scan the QR above, or copy the left link to open on your phone. No app needed — video streams straight to this tab, never through the server.</p>
        </div></td>
        
        <div class="container">
          <hr>
        </div>
        
        <td><div class="container">
          <div class="link-row">
            <input id="outputUrl" type="text" readonly value="<?php echo htmlspecialchars($outputUrl, ENT_QUOTES); ?>">
            <button class="btn" id="copyOutputBtn" type="button">Copy</button>
          </div>
    <p class="footnote">A second link with no UI at all — just the video, full-bleed. Paste it into OBS as a Browser Source, or anywhere else that needs a clean feed.</p>
        </div></td>
      </tr></div>

  </div>
</div>
<div class="video-layer" id="videoLayer">
  <video id="remoteVideo" autoplay playsinline muted></video>
  <div class="hud">
    <div class="hud-pill"><span class="dot dot--live" style="margin-right:2px"></span> <span id="liveStatusText">Streaming</span></div>
    <div class="hud-controls">
      <button class="hud-btn" id="popoutBtn" type="button" title="Open clean output window">⧉</button>
      <button class="hud-btn" id="copyOutputHudBtn" type="button" title="Copy clean output URL">🔗</button>
      <button class="hud-btn" id="fullscreenBtn" type="button" title="Fullscreen">⤢</button>
      <button class="hud-btn" id="disconnectBtn" type="button" title="Disconnect">✕</button>
    </div>
  </div>
</div>

<script>
const sessionId = <?php echo json_encode($sessionId); ?>;
const signalingUrl = 'signaling.php';

const res_support = <?php echo json_encode($res_support); ?>;

const qrFrame = document.getElementById('qrFrame');
const pairingStage = document.getElementById('pairingStage');
const videoLayer = document.getElementById('videoLayer');
const remoteVideo = document.getElementById('remoteVideo');
const statusDot = document.getElementById('statusDot');
const statusText = document.getElementById('statusText');
const copyBtn = document.getElementById('copyBtn');
const mobileUrlInput = document.getElementById('mobileUrl');
const outputUrlInput = document.getElementById('outputUrl');
const copyOutputBtn = document.getElementById('copyOutputBtn');
const copyOutputHudBtn = document.getElementById('copyOutputHudBtn');
const fullscreenBtn = document.getElementById('fullscreenBtn');
const disconnectBtn = document.getElementById('disconnectBtn');
const popoutBtn = document.getElementById('popoutBtn');

new QRCode(document.getElementById('qrCode'), {
  text: mobileUrlInput.value,
  width: 176,
  height: 176,
  colorDark: '#0b0e11',
  colorLight: '#ffffff',
  correctLevel: QRCode.CorrectLevel.M
});

async function copyText(text, btn) {
  const original = btn.textContent;
  try {
    await navigator.clipboard.writeText(text);
    btn.textContent = 'Copied';
  } catch (e) {
    btn.textContent = 'Copied';
    const helper = document.createElement('textarea');
    helper.value = text;
    helper.style.position = 'fixed';
    helper.style.opacity = '0';
    document.body.appendChild(helper);
    helper.select();
    try { document.execCommand('copy'); } catch (e2) {}
    document.body.removeChild(helper);
  }
  setTimeout(() => { btn.textContent = original; }, 1500);
}

copyBtn.addEventListener('click', () => copyText(mobileUrlInput.value, copyBtn));
copyOutputBtn.addEventListener('click', () => copyText(outputUrlInput.value, copyOutputBtn));
copyOutputHudBtn.addEventListener('click', () => copyText(outputUrlInput.value, copyOutputHudBtn));

let pc = null;
let pendingCandidates = [];
let pollTimer = null;
let pollDelay = 1000;
let cleanWindow = null;
let cleanVideoEl = null;

// Standalone viewer pages (output.php, e.g. an OBS Browser Source) each
// register themselves here; this desktop tab forwards the phone's stream
// to every one of them over its own dedicated peer connection.
const viewerConnections = new Map(); // viewerId -> { pc, videoSender, pendingCandidates }
const pendingViewerIds = new Set();  // viewers that showed up before a stream existed

async function sendToViewer(viewerId, message) {
  try {
    await fetch(`${signalingUrl}?session=${sessionId}&role=desktop&to=${viewerId}`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(message)
    });
  } catch (e) { /* next poll cycle will retry the flow */ }
}

function connectViewer(viewerId) {
  if (viewerConnections.has(viewerId)) return;
  if (!remoteVideo.srcObject) {
    pendingViewerIds.add(viewerId);
    return;
  }

  const vpc = new RTCPeerConnection({
    iceServers: [
      { urls: 'stun:stun.l.google.com:19302' },
      { urls: 'stun:stun1.l.google.com:19302' }
    ]
  });
  let videoSender = null;
remoteVideo.srcObject.getTracks().forEach((track) => {
  const sender = vpc.addTrack(track, remoteVideo.srcObject);
  
  if (track.kind === 'video') {
    videoSender = sender;
   if (res_support) {
      // --- 4K parameters forceren ---
    const parameters = sender.getParameters();
    
    // Zorg dat de encodings array bestaat
    if (!parameters.encodings) {
      parameters.encodings = [{}];
    }
    
    // Forceer de instellingen
    parameters.encodings[0].maxBitrate = 15000000; // 15 Mbps voor 4K
    parameters.encodings[0].scaleResolutionDownBy = 1.0; // Browser mag resolutie niet verkleinen
    
    // Pas de instellingen toe
    sender.setParameters(parameters).catch(err => {
      console.error("Kon 4K parameters niet instellen:", err);
    });
}  }
});
  vpc.onicecandidate = (event) => {
    if (event.candidate) sendToViewer(viewerId, { type: 'viewer-candidate', candidate: event.candidate.toJSON() });
  };
  vpc.onconnectionstatechange = () => {
    if (['failed', 'closed'].includes(vpc.connectionState)) viewerConnections.delete(viewerId);
  };

  const entry = { pc: vpc, videoSender, pendingCandidates: [] };
  viewerConnections.set(viewerId, entry);

  vpc.createOffer()
    .then((offer) => vpc.setLocalDescription(offer))
    .then(() => sendToViewer(viewerId, { type: 'viewer-offer', sdp: vpc.localDescription }));
}

function refreshViewerTracks(stream) {
  for (const viewerId of pendingViewerIds) connectViewer(viewerId);
  pendingViewerIds.clear();

  // Existing viewers keep their connection open; just swap in the fresh
  // track so a phone reconnect doesn't require OBS to reload anything.
  const newTrack = stream.getVideoTracks()[0];
  if (!newTrack) return;
  for (const entry of viewerConnections.values()) {
    if (entry.videoSender) entry.videoSender.replaceTrack(newTrack).catch(() => {});
  }
}

function syncCleanOutput(stream) {
  if (cleanWindow && !cleanWindow.closed && cleanVideoEl) {
    cleanVideoEl.srcObject = stream;
    cleanVideoEl.play().catch(() => {});
  }
}

function openCleanOutput() {
  if (cleanWindow && !cleanWindow.closed) {
    cleanWindow.focus();
    return;
  }
  if (res_support) {
    const win = window.open('about:blank', 'lensCleanOutput', 'width=3840,height=2160')
  } else {
  const win = window.open('about:blank', 'lensCleanOutput', 'width=1920,height=1080');
  } 
  if (!win) {
    alert('Pop-up was blocked. Allow pop-ups for this site to open the clean output window.');
    return;
  }
  cleanWindow = win;
  win.document.open();
  win.document.write(
    '<!doctype html><html><head><title>Lens — Clean Output</title><style>' +
    'html,body{margin:0;padding:0;height:100%;background:#000;overflow:hidden;}' +
    'video{width:100vw;height:100vh;object-fit:cover;display:block;background:#000;}' +
    '</style></head><body><video id="cleanVideo" autoplay playsinline muted></video></body></html>'
  );
  win.document.close();
  cleanVideoEl = win.document.getElementById('cleanVideo');
  if (remoteVideo.srcObject) syncCleanOutput(remoteVideo.srcObject);
}

function setStatus(text, mode) {
  statusText.textContent = text;
  statusDot.className = 'dot' + (mode === 'live' ? ' dot--live' : mode === 'off' ? ' dot--off' : '');
}

async function send(message) {
  try {
    await fetch(`${signalingUrl}?session=${sessionId}&role=desktop`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(message)
    });
  } catch (e) { /* next poll cycle will retry the flow */ }
}

function ensurePeerConnection() {
  if (pc) return pc;
  pc = new RTCPeerConnection({
    iceServers: [
      { urls: 'stun:stun.l.google.com:19302' },
      { urls: 'stun:stun1.l.google.com:19302' }
    ]
  });
  pc.ontrack = (event) => {
    remoteVideo.srcObject = event.streams[0];
    pairingStage.style.display = 'none';
    videoLayer.classList.add('is-active');
    qrFrame.classList.add('frame--live');
    setStatus('Streaming', 'live');
    document.getElementById('liveStatusText').textContent = 'Streaming';
    syncCleanOutput(event.streams[0]);
    refreshViewerTracks(event.streams[0]);
  };
  pc.onicecandidate = (event) => {
    if (event.candidate) send({ type: 'candidate', candidate: event.candidate.toJSON() });
  };
  pc.onconnectionstatechange = () => {
    if (['disconnected', 'failed', 'closed'].includes(pc.connectionState)) {
      resetToWaiting('Phone disconnected — scan again to reconnect');
    }
  };
  return pc;
}

function resetToWaiting(message) {
  if (pc) { pc.close(); pc = null; }
  pendingCandidates = [];
  remoteVideo.srcObject = null;
  videoLayer.classList.remove('is-active');
  qrFrame.classList.remove('frame--live');
  pairingStage.style.display = 'flex';
  setStatus(message || 'Waiting for your phone');
}

async function handleMessage(msg) {
  if (msg.type === 'hello') {
    setStatus('Phone connected — starting camera…');
  } else if (msg.type === 'offer') {
    const conn = ensurePeerConnection();
    await conn.setRemoteDescription(new RTCSessionDescription(msg.sdp));
    for (const candidate of pendingCandidates) {
      try { await conn.addIceCandidate(candidate); } catch (e) {}
    }
    pendingCandidates = [];
    const answer = await conn.createAnswer();
    await conn.setLocalDescription(answer);
    send({ type: 'answer', sdp: conn.localDescription });
  } else if (msg.type === 'candidate') {
    if (pc && pc.remoteDescription && pc.remoteDescription.type) {
      try { await pc.addIceCandidate(msg.candidate); } catch (e) {}
    } else {
      pendingCandidates.push(msg.candidate);
    }
  } else if (msg.type === 'bye') {
    resetToWaiting('Phone disconnected — scan again to reconnect');
  } else if (msg.type === 'viewer-hello') {
    connectViewer(msg.viewerId);
  } else if (msg.type === 'viewer-answer') {
    const entry = viewerConnections.get(msg.viewerId);
    if (!entry) return;
    await entry.pc.setRemoteDescription(new RTCSessionDescription(msg.sdp));
    for (const candidate of entry.pendingCandidates) {
      try { await entry.pc.addIceCandidate(candidate); } catch (e) {}
    }
    entry.pendingCandidates = [];
  } else if (msg.type === 'viewer-candidate') {
    const entry = viewerConnections.get(msg.viewerId);
    if (!entry) return;
    if (entry.pc.remoteDescription && entry.pc.remoteDescription.type) {
      try { await entry.pc.addIceCandidate(msg.candidate); } catch (e) {}
    } else {
      entry.pendingCandidates.push(msg.candidate);
    }
  } else if (msg.type === 'viewer-bye') {
    const entry = viewerConnections.get(msg.viewerId);
    if (entry) { entry.pc.close(); viewerConnections.delete(msg.viewerId); }
    pendingViewerIds.delete(msg.viewerId);
  }
}

async function poll() {
  try {
    const res = await fetch(`${signalingUrl}?session=${sessionId}&role=desktop`, { cache: 'no-store' });
    const data = await res.json();
    for (const msg of (data.messages || [])) {
      await handleMessage(msg);
    }
  } catch (e) { /* try again next tick */ }
  pollTimer = setTimeout(poll, pollDelay);
}
poll();

fullscreenBtn.addEventListener('click', () => {
  if (!document.fullscreenElement) {
    videoLayer.requestFullscreen?.();
  } else {
    document.exitFullscreen?.();
  }
});

disconnectBtn.addEventListener('click', () => {
  send({ type: 'bye' });
  resetToWaiting('Waiting for your phone');
});

popoutBtn.addEventListener('click', openCleanOutput);

window.addEventListener('beforeunload', () => {
  navigator.sendBeacon?.(`${signalingUrl}?session=${sessionId}&role=desktop`, JSON.stringify({ type: 'bye' }));
});
</script>
</body>
</html>