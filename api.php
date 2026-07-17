<?php
/**
 * api.php — endpoints consumed by players (token-authenticated).
 *
 *   GET  api.php?action=manifest&token=…    → schedule + playlists (ETag/304)
 *   POST api.php?action=heartbeat&token=…   → liveness + player state
 *   GET  api.php?action=command&token=…     → fetch + clear pending command (companion agent)
 *   POST api.php?action=screenshot&token=…  → raw JPEG body, overwrites the screen's live thumbnail
 */
declare(strict_types=1);
require __DIR__ . '/lib.php';

header('X-Content-Type-Options: nosniff');

$screen = screen_by_token((string) ($_GET['token'] ?? ''));
if (!$screen) {
    http_response_code(403);
    header('Content-Type: application/json');
    exit(json_encode(['error' => 'unknown screen token']));
}

$action = $_GET['action'] ?? 'manifest';

if ($action === 'heartbeat' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $info = substr(file_get_contents('php://input') ?: '', 0, 2000);
    $stmt = db()->prepare('UPDATE screens SET last_seen = ?, player_info = ? WHERE id = ?');
    $stmt->execute([now(), $info, $screen['id']]);
    header('Content-Type: application/json');
    exit(json_encode(['ok' => true]));
}

if ($action === 'screenshot' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = file_get_contents('php://input') ?: '';
    if ($data === '' || strlen($data) > 2 * 1024 * 1024) {
        http_response_code(400);
        header('Content-Type: application/json');
        exit(json_encode(['error' => 'empty or oversized body']));
    }
    file_put_contents(storage_path('screenshots/' . $screen['id'] . '.jpg'), $data);
    db()->prepare('UPDATE screens SET screenshot_at = ? WHERE id = ?')->execute([now(), $screen['id']]);
    header('Content-Type: application/json');
    exit(json_encode(['ok' => true]));
}

if ($action === 'command') {
    $cmd = screen_command_fetch_and_clear((int) $screen['id']);
    header('Content-Type: application/json');
    exit(json_encode(['command' => $cmd['command'] ?? null]));
}

if ($action === 'manifest') {
    $manifest = build_manifest($screen);
    $etag = '"' . $manifest['hash'] . '"';

    header('ETag: ' . $etag);
    header('Cache-Control: no-cache');           // always revalidate, 304 when unchanged
    if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
        http_response_code(304);
        exit;
    }
    header('Content-Type: application/json');
    exit(json_encode($manifest, JSON_UNESCAPED_SLASHES));
}

http_response_code(400);
header('Content-Type: application/json');
echo json_encode(['error' => 'unknown action']);
