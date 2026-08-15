# Lens — use your phone as a webcam for your desktop browser

A small PHP site with three parts:

- **`index.php`** — open on your desktop. It generates a session and shows a QR code.
- **`mobile.php`** — opens automatically when you scan the QR code with your phone. It captures your phone's camera.
- **`signaling.php`** — a small backend endpoint the two pages use to introduce themselves to each other (it exchanges WebRTC connection info; the actual video never touches the server).

Once paired, the phone streams its camera directly to the desktop tab over WebRTC (peer-to-peer), and the video fills the whole browser tab.

## Requirements

- **PHP 7.4+** with no special extensions.
- **HTTPS.** Browsers only allow camera access (`getUserMedia`) and WebRTC on a secure origin. The one exception is `localhost`. If you deploy this to a real domain, it must be served over `https://` (a free certificate from Let's Encrypt is enough).
- The `sessions/` folder must be writable by PHP (it stores tiny, short-lived JSON files used to pass connection info between the two pages — nothing else).

## Deploying

1. Upload all files, keeping the folder structure:
   ```
   index.php
   mobile.php
   signaling.php
   assets/style.css
   sessions/            (writable, and blocked from direct web access)
   ```
2. Make sure `sessions/` is writable: `chmod 770 sessions`.
3. Visit `index.php` on your desktop over HTTPS (or `http://localhost/...` for local testing).
4. Scan the QR code with your phone's camera app — it'll open `mobile.php` with the session already filled in.
5. On the phone, tap the shutter button and allow camera access. The feed appears full-screen on the desktop tab within a couple of seconds.

## How the pairing works

1. `index.php` generates a random session id and encodes a link to `mobile.php?session=...` as a QR code.
2. The phone opens that link, and both pages start polling `signaling.php` about once a second.
3. The phone captures its camera, creates a WebRTC offer, and posts it to `signaling.php`.
4. The desktop picks up the offer, creates an answer, and posts it back. Both sides also exchange ICE candidates the same way.
5. Once WebRTC finishes connecting, video flows **directly between the phone and the desktop browser** — `signaling.php` is only used for that brief handshake, not for the video itself.

## Limitations to know about

- **Same network / open networks work best.** This uses public STUN servers (Google's) for NAT traversal, which is enough for most home Wi-Fi and phone-hotspot setups. Some strict corporate or campus networks block the peer-to-peer connection entirely; a production version of this would add a TURN server (e.g. via Twilio, Cloudflare, or your own coturn) as a relay fallback.
- **One phone per session.** Reload the desktop page to start a new session/QR code.
- **File-based signaling.** This uses simple JSON files instead of a database or WebSocket server, which keeps hosting requirements minimal (plain PHP, no extra services) but isn't meant for heavy concurrent use. Fine for personal or small-team use.
- Video-only by default (no microphone audio) to keep permissions simple — see the note below to add audio.

## Continuous integration

`.github/workflows/php-lint.yml` runs on every push/PR to `main`: it checks out the repo and runs `php -l` (PHP's built-in syntax linter) over every `.php` file on PHP 8.1, 8.2, and 8.3, so a syntax error can't merge silently. It's intentionally minimal since this project has no build step or dependencies — extend it with `phpcs`/`phpstan` steps if you want stricter checks.

## Extending it

- **Add microphone audio:** in `mobile.php`, change `audio: false` to `audio: true` in both `getUserMedia` calls.
- **Add a TURN server** for reliability on restrictive networks: add its URL/credentials to the `iceServers` array in both `index.php` and `mobile.php`.
- **Multiple simultaneous pairs:** already supported — each browser tab that loads `index.php` gets its own session id, so several people can pair their own phones independently.
