<?php
declare(strict_types=1);

$rawSession = (string) ($_GET['session'] ?? '');
$sessionValid = (bool) preg_match('/^[a-f0-9]{6,32}$/', $rawSession);
$sessionId = $sessionValid ? $rawSession : '';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Lens — stream this camera</title>
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, maximum-scale=1">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/style.css">
</head>
<body>

<?php if (!$sessionValid): ?>

  <div class="stage">
    <div class="panel">
      <div class="eyebrow"><span class="eyebrow-dot">●</span> Lens</div>
      <h1>This link looks incomplete</h1>
      <p class="error-text">There's no valid session in this URL. Scan the QR code shown on the desktop screen again.</p>
    </div>
  </div>

<?php else: ?>

  <div class="stage" id="startStage">
    <div class="panel">
      <div class="eyebrow"><span class="eyebrow-dot">●</span> Lens</div>
      <h1>Connect this phone</h1>
      <p class="subtext">This phone will stream its camera straight to the desktop tab that showed the QR code.</p>

      <div class="session-code">SESSION <b><?php echo strtoupper(htmlspecialchars($sessionId, ENT_QUOTES)); ?></b></div>

      <div class="frame">
        <span class="corner tl"></span><span class="corner tr"></span>
        <span class="corner bl"></span><span class="corner br"></span>
        <button class="shutter" id="startBtn" type="button" aria-label="Start camera">
          <span class="shutter-fill"></span>
        </button>
      </div>

      <p class="footnote" id="startLabel">Tap to start the camera</p>
      <p class="error-text" id="startError" style="display:none"></p>
    </div>
  </div>

  <div class="video-layer" id="videoLayer">
    <video id="localVideo" autoplay playsinline muted></video>
    <div class="hud">
      <div class="hud-pill"><span class="dot dot--live" style="margin-right:2px"></span> <span id="liveStatusText">Connecting…</span></div>
      <div class="hud-controls">
        <button class="hud-btn" id="flipBtn" type="button" title="Flip camera">⟲</button>
        <button class="hud-btn" id="stopBtn" type="button" title="Stop">✕</button>
      </div>
    </div>
  </div>

  <script>
  const sessionId = <?php echo json_encode($sessionId); ?>;
  const signalingUrl = 'signaling.php';

  const startStage = document.getElementById('startStage');
  const startBtn = document.getElementById('startBtn');
  const startLabel = document.getElementById('startLabel');
  const startError = document.getElementById('startError');
  const videoLayer = document.getElementById('videoLayer');
  const localVideo = document.getElementById('localVideo');
  const liveStatusText = document.getElementById('liveStatusText');
  const flipBtn = document.getElementById('flipBtn');
  const stopBtn = document.getElementById('stopBtn');

  let pc = null;
  let localStream = null;
  let videoSender = null;
  let currentFacing = 'environment';
  let pendingCandidates = [];

  async function send(message) {
    try {
      await fetch(`${signalingUrl}?session=${sessionId}&role=mobile`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(message)
      });
    } catch (e) { /* next poll cycle will retry the flow */ }
  }

  // Let the desktop know a phone has opened the link, even before the
  // camera starts, so it can update its status.
  send({ type: 'hello' });

  function ensurePeerConnection() {
    if (pc) return pc;
    pc = new RTCPeerConnection({
      iceServers: [
        { urls: 'stun:stun.l.google.com:19302' },
        { urls: 'stun:stun1.l.google.com:19302' }
      ]
    });
    pc.onicecandidate = (event) => {
      if (event.candidate) send({ type: 'candidate', candidate: event.candidate.toJSON() });
    };
    pc.onconnectionstatechange = () => {
      if (pc.connectionState === 'connected') {
        liveStatusText.textContent = 'Streaming to desktop';
      } else if (['disconnected', 'failed', 'closed'].includes(pc.connectionState)) {
        stopStreaming('Tap to start the camera');
      }
    };
    return pc;
  }

  function friendlyCameraError(err) {
    if (err && (err.name === 'NotAllowedError' || err.name === 'PermissionDeniedError')) {
      return 'Camera access was blocked. Allow camera access for this site in your browser settings, then reload.';
    }
    if (err && err.name === 'NotFoundError') {
      return 'No camera was found on this device.';
    }
    return 'Could not start the camera. Reload and try again.';
  }

  async function startCamera() {
    startError.style.display = 'none';
    try {
      localStream = await navigator.mediaDevices.getUserMedia({
        video: { facingMode: currentFacing, width: { ideal: 1920 }, height: { ideal: 1080 } },
        audio: false
      });
    } catch (err) {
      startError.textContent = friendlyCameraError(err);
      startError.style.display = 'block';
      return;
    }

    localVideo.srcObject = localStream;
    startStage.style.display = 'none';
    videoLayer.classList.add('is-active');
    liveStatusText.textContent = 'Connecting…';

    const conn = ensurePeerConnection();
    localStream.getTracks().forEach((track) => {
      const sender = conn.addTrack(track, localStream);
      if (track.kind === 'video') videoSender = sender;
    });

    const offer = await conn.createOffer();
    await conn.setLocalDescription(offer);
    send({ type: 'offer', sdp: conn.localDescription });
  }

  function stopStreaming(label) {
    if (pc) { pc.close(); pc = null; }
    if (localStream) { localStream.getTracks().forEach((t) => t.stop()); localStream = null; }
    videoSender = null;
    pendingCandidates = [];
    videoLayer.classList.remove('is-active');
    startStage.style.display = 'flex';
    startLabel.textContent = label || 'Tap to start the camera';
  }

  async function flipCamera() {
    if (!localStream) return;
    currentFacing = currentFacing === 'environment' ? 'user' : 'environment';
    try {
      const newStream = await navigator.mediaDevices.getUserMedia({
        video: { facingMode: currentFacing, width: { ideal: 1920 }, height: { ideal: 1080 } },
        audio: false
      });
      const newTrack = newStream.getVideoTracks()[0];
      const oldTrack = localStream.getVideoTracks()[0];
      if (videoSender) await videoSender.replaceTrack(newTrack);
      if (oldTrack) { oldTrack.stop(); localStream.removeTrack(oldTrack); }
      localStream.addTrack(newTrack);
      localVideo.srcObject = localStream;
    } catch (e) {
      currentFacing = currentFacing === 'environment' ? 'user' : 'environment'; // revert on failure
    }
  }

  async function handleMessage(msg) {
    if (msg.type === 'answer') {
      if (!pc) return;
      await pc.setRemoteDescription(new RTCSessionDescription(msg.sdp));
      for (const candidate of pendingCandidates) {
        try { await pc.addIceCandidate(candidate); } catch (e) {}
      }
      pendingCandidates = [];
    } else if (msg.type === 'candidate') {
      if (pc && pc.remoteDescription && pc.remoteDescription.type) {
        try { await pc.addIceCandidate(msg.candidate); } catch (e) {}
      } else {
        pendingCandidates.push(msg.candidate);
      }
    } else if (msg.type === 'bye') {
      stopStreaming('Desktop disconnected — tap to start again');
    }
  }

  async function poll() {
    try {
      const res = await fetch(`${signalingUrl}?session=${sessionId}&role=mobile`, { cache: 'no-store' });
      const data = await res.json();
      for (const msg of (data.messages || [])) {
        await handleMessage(msg);
      }
    } catch (e) { /* try again next tick */ }
    setTimeout(poll, 1000);
  }
  poll();

  startBtn.addEventListener('click', startCamera);
  flipBtn.addEventListener('click', flipCamera);
  stopBtn.addEventListener('click', () => { send({ type: 'bye' }); stopStreaming(); });

  window.addEventListener('beforeunload', () => {
    navigator.sendBeacon?.(`${signalingUrl}?session=${sessionId}&role=mobile`, JSON.stringify({ type: 'bye' }));
  });
  </script>

<?php endif; ?>

</body>
</html>
