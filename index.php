<?php
declare(strict_types=1);

// A fresh, unguessable session id per page load (48 bits of entropy).
// Reload this page any time to start a new pairing session.
$sessionId = bin2hex(random_bytes(6));

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['SERVER_PORT'] ?? '') === '443')
    ? 'https'
    : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
$mobileUrl = $scheme . '://' . $host . $basePath . '/mobile.php?session=' . $sessionId;
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

    <div class="link-row">
      <input id="mobileUrl" type="text" readonly value="<?php echo htmlspecialchars($mobileUrl, ENT_QUOTES); ?>">
      <button class="btn" id="copyBtn" type="button">Copy</button>
    </div>

    <p class="footnote">No app needed. The phone streams straight to this tab over a direct connection — video never passes through the server.</p>
  </div>
</div>

<div class="video-layer" id="videoLayer">
  <video id="remoteVideo" autoplay playsinline></video>
  <div class="hud">
    <div class="hud-pill"><span class="dot dot--live" style="margin-right:2px"></span> <span id="liveStatusText">Streaming</span></div>
    <div class="hud-controls">
      <button class="hud-btn" id="fullscreenBtn" type="button" title="Fullscreen">⤢</button>
      <button class="hud-btn" id="disconnectBtn" type="button" title="Disconnect">✕</button>
    </div>
  </div>
</div>

<script>
const sessionId = <?php echo json_encode($sessionId); ?>;
const signalingUrl = 'signaling.php';

const qrFrame = document.getElementById('qrFrame');
const pairingStage = document.getElementById('pairingStage');
const videoLayer = document.getElementById('videoLayer');
const remoteVideo = document.getElementById('remoteVideo');
const statusDot = document.getElementById('statusDot');
const statusText = document.getElementById('statusText');
const copyBtn = document.getElementById('copyBtn');
const mobileUrlInput = document.getElementById('mobileUrl');
const fullscreenBtn = document.getElementById('fullscreenBtn');
const disconnectBtn = document.getElementById('disconnectBtn');

new QRCode(document.getElementById('qrCode'), {
  text: mobileUrlInput.value,
  width: 176,
  height: 176,
  colorDark: '#0b0e11',
  colorLight: '#ffffff',
  correctLevel: QRCode.CorrectLevel.M
});

copyBtn.addEventListener('click', async () => {
  try {
    await navigator.clipboard.writeText(mobileUrlInput.value);
    copyBtn.textContent = 'Copied';
    setTimeout(() => (copyBtn.textContent = 'Copy'), 1500);
  } catch (e) {
    mobileUrlInput.select();
  }
});

let pc = null;
let pendingCandidates = [];
let pollTimer = null;
let pollDelay = 1000;

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

window.addEventListener('beforeunload', () => {
  navigator.sendBeacon?.(`${signalingUrl}?session=${sessionId}&role=desktop`, JSON.stringify({ type: 'bye' }));
});
</script>
</body>
</html>
