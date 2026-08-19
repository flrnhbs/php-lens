<?php
declare(strict_types=1);

/**
 * signaling.php
 *
 * A minimal WebRTC signaling relay. It doesn't understand WebRTC at all —
 * it just holds small per-client "mailboxes" and lets one client POST a
 * message addressed to another, then GET (and clear) whatever has arrived
 * for itself. Pages poll this endpoint every second or so to exchange the
 * SDP offer/answer and ICE candidates needed to open a direct WebRTC
 * connection.
 *
 * Three kinds of client talk to each other here:
 *   - "mobile"        the phone (always exactly one, the camera source)
 *   - "desktop"        the main pairing tab (receives the phone's stream,
 *                       and re-forwards it to any viewers below)
 *   - "viewer-xxxxxx"  a standalone clean-output page, e.g. an OBS Browser
 *                       Source (zero or more, each with its own random id)
 *
 * Messages between the phone and the desktop keep working exactly as
 * before with no "to" needed (each is the other's implicit target).
 * Anything involving a viewer must pass an explicit ?to=... — that's what
 * lets one desktop mailbox fan a message out to many different viewers.
 *
 * No database required — messages are stored as small JSON files in
 * sessions/. That folder is blocked from direct web access (see
 * sessions/.htaccess) and old mailboxes are garbage-collected below.
 */

header('Content-Type: application/json');
header('Cache-Control: no-store');

$sessionsDir = __DIR__ . '/sessions';
if (!is_dir($sessionsDir)) {
    mkdir($sessionsDir, 0770, true);
}

// Garbage-collect mailboxes untouched for over an hour.
foreach (glob($sessionsDir . '/*.json') ?: [] as $file) {
    if (is_file($file) && (time() - (int) filemtime($file)) > 3600) {
        @unlink($file);
    }
}

$session = preg_replace('/[^a-f0-9]/', '', (string) ($_REQUEST['session'] ?? ''));
$session = substr($session, 0, 32);

// A "client" is whoever is talking to this endpoint: the phone, the
// desktop tab, or a viewer page (each viewer picks its own random id
// client-side, e.g. "viewer-83f2a1c9").
$clientPattern = '/^(desktop|mobile|viewer-[a-f0-9]{6,16})$/';

$role = (string) ($_REQUEST['role'] ?? '');

if ($session === '' || !preg_match($clientPattern, $role)) {
    http_response_code(400);
    echo json_encode(['error' => 'A valid session id and role are required.']);
    exit;
}

function lens_mailbox_path(string $dir, string $session, string $role): string
{
    return $dir . '/' . $session . '-' . $role . '.json';
}

/** Read and empty a mailbox in one locked operation. */
function lens_take(string $path): array
{
    if (!is_file($path)) {
        return [];
    }
    $fp = fopen($path, 'c+');
    if ($fp === false) {
        return [];
    }
    $messages = [];
    if (flock($fp, LOCK_EX)) {
        $contents = stream_get_contents($fp);
        $decoded = $contents !== false && $contents !== '' ? json_decode($contents, true) : [];
        $messages = is_array($decoded) ? $decoded : [];
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, '[]');
        fflush($fp);
        flock($fp, LOCK_UN);
    }
    fclose($fp);
    return $messages;
}

/** Append one message to a mailbox. */
function lens_put(string $path, array $message): void
{
    $fp = fopen($path, 'c+');
    if ($fp === false) {
        return;
    }
    if (flock($fp, LOCK_EX)) {
        $contents = stream_get_contents($fp);
        $decoded = $contents !== false && $contents !== '' ? json_decode($contents, true) : [];
        $messages = is_array($decoded) ? $decoded : [];
        $messages[] = $message;
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($messages));
        fflush($fp);
        flock($fp, LOCK_UN);
    }
    fclose($fp);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    $payload = $raw !== false ? json_decode($raw, true) : null;
    if (!is_array($payload) || !isset($payload['type'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Message body must be JSON with a "type" field.']);
        exit;
    }

    $to = $_REQUEST['to'] ?? null;
    if ($to !== null) {
        $to = (string) $to;
        if (!preg_match($clientPattern, $to)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid "to" target.']);
            exit;
        }
    } elseif ($role === 'desktop') {
        $to = 'mobile';
    } elseif ($role === 'mobile') {
        $to = 'desktop';
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'A viewer must include an explicit "to" target.']);
        exit;
    }

    lens_put(lens_mailbox_path($sessionsDir, $session, $to), $payload);
    echo json_encode(['ok' => true]);
    exit;
}

if ($method === 'GET') {
    $messages = lens_take(lens_mailbox_path($sessionsDir, $session, $role));
    echo json_encode(['messages' => $messages]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed.']);
