<?php
/**
 * media.php — streams uploaded media with full HTTP Range support.
 *
 * Why this matters for video:
 *  - Chromium never downloads a video twice: files are content-addressed
 *    (sha1 hash in the name), so we can send Cache-Control: immutable.
 *  - Range requests (206) let the browser start playback instantly and
 *    seek/buffer efficiently instead of pulling the whole file up front.
 *  - Conditional requests (If-None-Match / If-Range) are honoured, so a
 *    player that already holds the file costs the server ~nothing.
 *
 * If you front this with nginx/Apache you can go further and let the web
 * server do the sending (X-Accel-Redirect / X-Sendfile) — see README.
 */
declare(strict_types=1);
require __DIR__ . '/lib.php';

$f = $_GET['f'] ?? '';
// Content-addressed names only: <sha1>.<ext> — no traversal possible.
if (!preg_match('/^[a-f0-9]{40}\.[a-z0-9]{2,5}$/', $f)) {
    http_response_code(400);
    exit('Bad file id');
}

$path = storage_path('media/' . $f);
if (!is_file($path)) {
    http_response_code(404);
    exit('Not found');
}

$size = filesize($path);
$etag = '"' . substr($f, 0, 40) . '"';
$mime = [
    'mp4' => 'video/mp4',  'webm' => 'video/webm',
    'jpg' => 'image/jpeg', 'png'  => 'image/png',
    'webp'=> 'image/webp', 'gif'  => 'image/gif',
][pathinfo($f, PATHINFO_EXTENSION)] ?? 'application/octet-stream';

header('Content-Type: ' . $mime);
header('Accept-Ranges: bytes');
header('ETag: ' . $etag);
// Content never changes for a given hash — cache it forever, everywhere.
header('Cache-Control: public, max-age=31536000, immutable');
header('X-Content-Type-Options: nosniff');

// Full-body revalidation
if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
    http_response_code(304);
    exit;
}

$start = 0;
$end   = $size - 1;
$partial = false;

$range = $_SERVER['HTTP_RANGE'] ?? '';
$ifRange = $_SERVER['HTTP_IF_RANGE'] ?? '';
if ($range !== '' && ($ifRange === '' || $ifRange === $etag)) {
    // Only single ranges (bytes=start-end); multipart ranges are pointless here.
    if (preg_match('/^bytes=(\d*)-(\d*)$/', $range, $m) && ($m[1] !== '' || $m[2] !== '')) {
        if ($m[1] === '') {                 // suffix range: last N bytes
            $start = max(0, $size - (int) $m[2]);
        } else {
            $start = (int) $m[1];
            if ($m[2] !== '') {
                $end = min((int) $m[2], $size - 1);
            }
        }
        if ($start > $end || $start >= $size) {
            header('Content-Range: bytes */' . $size);
            http_response_code(416);
            exit;
        }
        $partial = true;
    }
}

$length = $end - $start + 1;
if ($partial) {
    http_response_code(206);
    header("Content-Range: bytes $start-$end/$size");
}
header('Content-Length: ' . $length);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') {
    exit;
}

// Stream in chunks without buffering the file in memory.
while (ob_get_level() > 0) {
    ob_end_clean();
}
ignore_user_abort(false);
set_time_limit(0);

$fp = fopen($path, 'rb');
fseek($fp, $start);
$remaining = $length;
while ($remaining > 0 && !feof($fp) && !connection_aborted()) {
    $chunk = fread($fp, min(1 << 19, $remaining)); // 512 KB chunks
    if ($chunk === false) {
        break;
    }
    echo $chunk;
    flush();
    $remaining -= strlen($chunk);
}
fclose($fp);
