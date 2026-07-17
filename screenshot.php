<?php
/**
 * screenshot.php?id=<screen id> — serves a screen's latest player-submitted
 * screenshot to the admin panel. Session-authenticated (admin only); unlike
 * media.php this content is mutable, so no immutable caching.
 */
declare(strict_types=1);
require __DIR__ . '/lib.php';
require_admin();

$id = (int) ($_GET['id'] ?? 0);
$path = storage_path('screenshots/' . $id . '.jpg');
if (!$id || !is_file($path)) {
    http_response_code(404);
    exit('No screenshot yet');
}

$q = db()->prepare('SELECT screenshot_at FROM screens WHERE id=?');
$q->execute([$id]);
$at = $q->fetchColumn();

header('Content-Type: image/jpeg');
header('Cache-Control: no-store');
header('X-Captured-At: ' . (int) $at);
header('Content-Length: ' . (string) filesize($path));
readfile($path);
