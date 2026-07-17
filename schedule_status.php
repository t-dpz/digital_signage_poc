<?php
/**
 * schedule_status.php?screen_id=<id> — JSON status per schedule rule (group_id),
 * so the admin screen-detail page can refresh the "Active" column live without a
 * full page reload. Session-authenticated (admin only). See schedule_group_statuses().
 */
declare(strict_types=1);
require __DIR__ . '/lib.php';
require_admin();

header('Content-Type: application/json');
header('Cache-Control: no-store');

$id = (int) ($_GET['screen_id'] ?? 0);
$q = db()->prepare('SELECT * FROM screens WHERE id=?');
$q->execute([$id]);
$screen = $q->fetch();
if (!$screen) {
    http_response_code(404);
    exit(json_encode(['error' => 'unknown screen']));
}

echo json_encode(schedule_group_statuses($screen));
