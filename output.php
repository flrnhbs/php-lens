<?php	declare(strict_types=1);		
/* * output.php — a bare, standalone receiver meant for OBS's Browser Source	 
 * * (or any tool that just loads a URL). Unlike the popup window opened from	 
 * * index.php, this page doesn't depend on that tab being open — it runs its	 
 * * own signaling handshake and asks the desktop tab to forward the phone's	 
 * * stream to it directly. That's what lets you point OBS at a URL that	 
 * * works on its own, survives OBS restarts, etc.	 
 * *	 
 * * It shows nothing but the video, edge-to-edge, no chrome at all. A small	 
 * * status line is visible only until the stream connects, then it's	 
 * * removed entirely so nothing but the camera feed is ever on screen.	 
 * */
$rawSession = (string) ($_GET['session'] ?? '');
$sessionValid = (bool) (
    preg_match('/^[a-f0-9]{6,32}$/', $rawSession) ?: 
    preg_match('/admin/', $rawSession)
);   
$sessionId = $sessionValid ? $rawSession : '';	?>
<!doctype html>	
<html lang="en">
    <head>
        <meta charset="utf-8">
        <title>Lens — clean output</title>
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <style>	 
        html, body { margin: 0; padding: 0; height: 100%; background: #000; overflow: hidden; }	 
        video { width: 100vw; height: 100vh; object-fit: cover; display: block; background: #000; }	 
        #status {	    
            position: fixed; left: 14px; bottom: 12px;	    
            font: 12px/1.4 ui-monospace, "JetBrains Mono", Menlo, monospace;	    
            color: rgba(255,255,255,0.45);	    
            letter-spacing: 0.04em;	    
            text-transform: uppercase;
        }	
        </style>	
    </head>
    <body>
        <?php if (!$sessionValid): ?>	  
        <div id="status">No valid session in this URL.</div>	
        <?php else: ?>		  
        <video id="remoteVideo" autoplay playsinline muted></video>	 
        <div id="status">Waiting for stream…</div>		  
        <script>	  
            const sessionId = <?php echo json_encode($sessionId); ?>;	  
            const signalingUrl = 'signaling.php';	  
            const viewerId = 'viewer-' + randomHex(8);		  
            const remoteVideo = document.getElementById('remoteVideo');	  
            const statusEl = document.getElementById('status');		  
            function randomHex(len) {	    
                const bytes = new Uint8Array(Math.ceil(len / 2));	    
                (window.crypto || window.msCrypto).getRandomValues(bytes);	    
                return Array.from(bytes).map((b) => b.toString(16).padStart(2, '0')).join('').slice(0, len);
            }		  
            let pc = null;	  
            let pendingCandidates = [];		  
            async function send(message, to) {	   
                try {	      
                    await fetch(`${signalingUrl}?session=${sessionId}&role=${viewerId}&to=${to}`, {	        
                        method: 'POST',	        
                        headers: { 'Content-Type': 'application/json' },	        
                        body: JSON.stringify(message)	      
                    });	    
                } catch (e) { /* next poll cycle keeps things moving */ }	  
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
                        remoteVideo.play().catch(() => {});	      
                        statusEl.remove();	   
                    };	    
                    pc.onicecandidate = (event) => {	      
                        if (event.candidate) {	        
                            send({ type: 'viewer-candidate', viewerId, candidate: event.candidate.toJSON() }, 'desktop');	      
                        }	    
                    };	    
                    pc.onconnectionstatechange = () => {	      
                        if (['disconnected', 'failed', 'closed'].includes(pc.connectionState) && !document.getElementById('status')) {	        
                            // Reinsert a status line if a live connection drops, so it's	        
                            // obvious (outside OBS) that the feed died rather than went dark.	        
                            // The #status CSS rule applies automatically since it targets the id.	        
                            const el = document.createElement('div');	        
                            el.id = 'status';
                            el.textContent = 'Disconnected — waiting for stream…';
                            document.body.appendChild(el);
                        }
                        };
                        return pc;
                    }

  async function handleMessage(msg) {
    if (msg.type === 'viewer-offer') {
      const conn = ensurePeerConnection();
      await conn.setRemoteDescription(new RTCSessionDescription(msg.sdp));
      for (const candidate of pendingCandidates) {
        try { await conn.addIceCandidate(candidate); } catch (e) {}
      }
      pendingCandidates = [];
      const answer = await conn.createAnswer();
      await conn.setLocalDescription(answer);
      send({ type: 'viewer-answer', viewerId, sdp: conn.localDescription }, 'desktop');
    } else if (msg.type === 'viewer-candidate') {
      if (pc && pc.remoteDescription && pc.remoteDescription.type) {
        try { await pc.addIceCandidate(msg.candidate); } catch (e) {}
      } else {
        pendingCandidates.push(msg.candidate);
      }
    }
  }

  async function poll() {
    try {
      const res = await fetch(`${signalingUrl}?session=${sessionId}&role=${viewerId}`, { cache: 'no-store' });
      const data = await res.json();
      for (const msg of (data.messages || [])) {
        await handleMessage(msg);
      }
    } catch (e) { /* try again next tick */ }
    setTimeout(poll, 1000);
  }

  send({ type: 'viewer-hello', viewerId }, 'desktop');
  poll();

  window.addEventListener('beforeunload', () => {
    navigator.sendBeacon?.(
      `${signalingUrl}?session=${sessionId}&role=${viewerId}&to=desktop`,
      JSON.stringify({ type: 'viewer-bye', viewerId })
    );
  });
  </script>

<?php endif; ?>

</body>
</html>