<?php
/**
 * screenshot.php?id=<screen id> — serves a screen's latest player-submitted
 * screenshot to the admin panel. Session-authenticated (admin only); unlike
 * media.php this content is mutable, so no immutable caching.
 *
 * Canvas can't read cross-origin iframe pixels, so the player never captures
 * anything while a `url` playlist item is showing (see captureScreenshot() in
 * player.php) — any screenshot on disk in that case is just stale, from
 * whatever last played before it. Rather than show that misleading stale
 * image, this returns a small JSON marker instead when the *current* item
 * (per the last heartbeat's player_info) is type url, and the admin page
 * renders a text fallback.
 */
declare(strict_types=1);
require __DIR__ . '/lib.php';
require_admin();

$id = (int) ($_GET['id'] ?? 0);
if (!$id) {
    http_response_code(404);
    exit('Unknown screen');
}

$q = db()->prepare('SELECT screenshot_at, player_info FROM screens WHERE id=?');
$q->execute([$id]);
$screen = $q->fetch();
if (!$screen) {
    http_response_code(404);
    exit('Unknown screen');
}

$info = json_decode($screen['player_info'] ?? '', true);
$itemType = is_array($info) ? ($info['type'] ?? null) : null;

if ($itemType === 'url') {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode(['url_only' => true, 'title' => is_array($info) ? ($info['item'] ?? null) : null]);
    exit;
}

$path = storage_path('screenshots/' . $id . '.jpg');
if (!is_file($path)) {
    http_response_code(404);
    exit('No screenshot yet');
}

header('Content-Type: image/jpeg');
header('Cache-Control: no-store');
header('X-Captured-At: ' . (int) $screen['screenshot_at']);
header('Content-Length: ' . (string) filesize($path));
readfile($path);
