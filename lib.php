<?php
/**
 * Signage — core library (no dependencies, PHP 8.1+, SQLite)
 */
declare(strict_types=1);

const SIGNAGE_VERSION = '1.0.0';
const ROTATION_DEFAULT_SECONDS = 120.0; // schedules tied on day/time/priority rotate at this cadence unless overridden
// Multiple `schedules` rows sharing a group_id are one admin-facing "rule" with several
// OR'd time windows (e.g. 08:00–09:00 OR 14:00–15:00) — same playlist/days/priority/dates,
// just more than one start/end pair. Purely an admin UI/editing grouping: build_manifest()
// and the resolvers still see (and only ever need) flat, independent per-window rows.
const MAX_SCHEDULE_WINDOWS = 4;
// Per-chunk size for content_upload_*. Deliberately under PHP's stock upload_max_filesize/
// post_max_size (2M/8M) so chunked upload works even without the 2G .htaccess override present.
const UPLOAD_CHUNK_BYTES = 1024 * 1024;

// Network protocols a screen's panel can be power-cycled over (Tier 2 in the power-schedule
// design — see CLAUDE.md). '' = not managed here (browser kiosk always on, or Tier 1/3 elsewhere).
// No driver actually sends commands yet; this just names the config a future cron script reads.
const POWER_DRIVERS = [
    ''     => 'Not managed',
    'mdc'  => 'Samsung MDC (LAN, TCP)',
    'sicp' => 'Philips SICP (LAN, TCP)',
    'lg'   => 'LG webOS Signage (LAN)',
    'wol'  => 'Wake-on-LAN only',
];

$GLOBALS['cfg'] = require __DIR__ . '/config.php';

function cfg(string $key): mixed
{
    return $GLOBALS['cfg'][$key] ?? null;
}

// Every schedule/heartbeat/created_at display below assumes wall-clock time at the
// screens' physical location. PHP defaults to UTC with no php.ini date.timezone set,
// which silently shifts schedule windows (and every admin-facing timestamp) by the
// local UTC offset — set explicitly rather than depending on server/php.ini config.
date_default_timezone_set((string) (cfg('timezone') ?: 'UTC'));

function storage_path(string $sub = ''): string
{
    $base = rtrim(cfg('storage_path'), '/');
    return $sub === '' ? $base : $base . '/' . ltrim($sub, '/');
}

/* ---------------------------------------------------------------- database */

function db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }
    if (!is_dir(storage_path('media'))) {
        mkdir(storage_path('media'), 0775, true);
    }
    if (!is_dir(storage_path('screenshots'))) {
        mkdir(storage_path('screenshots'), 0775, true);
    }
    $fresh = !file_exists(storage_path('signage.sqlite'));
    $pdo = new PDO('sqlite:' . storage_path('signage.sqlite'), null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA foreign_keys = ON');
    if ($fresh) {
        install_schema($pdo);
    } else {
        migrate_schema($pdo);
    }
    return $pdo;
}

function install_schema(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
CREATE TABLE screens (
    id                   INTEGER PRIMARY KEY AUTOINCREMENT,
    name                 TEXT NOT NULL,
    token                TEXT NOT NULL UNIQUE,
    notes                TEXT NOT NULL DEFAULT '',
    fallback_playlist_id INTEGER REFERENCES playlists(id) ON DELETE SET NULL,
    last_seen            INTEGER,
    player_info          TEXT NOT NULL DEFAULT '',
    pending_command      TEXT,     -- 'restart_kiosk' | 'reboot' | NULL
    pending_command_at   INTEGER,  -- unix ts when issued, NULL when none
    screenshot_at        INTEGER,  -- unix ts of last player-submitted screenshot, NULL if none yet
    power_driver         TEXT NOT NULL DEFAULT '',  -- key into POWER_DRIVERS; '' = unmanaged
    power_host           TEXT NOT NULL DEFAULT '',  -- IP/hostname for the LAN control protocol
    power_port           INTEGER,                   -- TCP port, driver-specific default if NULL
    power_mac            TEXT NOT NULL DEFAULT '',  -- MAC for Wake-on-LAN
    updated_at           INTEGER NOT NULL,
    created_at           INTEGER NOT NULL
);
CREATE TABLE folders (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT NOT NULL,
    created_at INTEGER NOT NULL
);
CREATE TABLE content (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    type       TEXT NOT NULL CHECK (type IN ('video','image','url')),
    title      TEXT NOT NULL,
    filename   TEXT NOT NULL DEFAULT '',   -- hash.ext inside storage/media
    mime       TEXT NOT NULL DEFAULT '',
    size       INTEGER NOT NULL DEFAULT 0,
    duration   REAL,                        -- probed video duration (seconds), NULL if unknown
    url        TEXT NOT NULL DEFAULT '',    -- for type=url
    folder_id  INTEGER REFERENCES folders(id) ON DELETE SET NULL,
    created_at INTEGER NOT NULL
);
CREATE TABLE playlists (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT NOT NULL,
    updated_at INTEGER NOT NULL,
    created_at INTEGER NOT NULL
);
CREATE TABLE playlist_items (
    id                INTEGER PRIMARY KEY AUTOINCREMENT,
    playlist_id       INTEGER NOT NULL REFERENCES playlists(id) ON DELETE CASCADE,
    content_id        INTEGER NOT NULL REFERENCES content(id)   ON DELETE CASCADE,
    position          INTEGER NOT NULL DEFAULT 0,
    duration_override REAL,      -- seconds; NULL = natural (video) or default (image/url)
    muted             INTEGER NOT NULL DEFAULT 1
);
CREATE TABLE schedules (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    screen_id   INTEGER NOT NULL REFERENCES screens(id)   ON DELETE CASCADE,
    playlist_id INTEGER NOT NULL REFERENCES playlists(id) ON DELETE CASCADE,
    dow_mask    INTEGER NOT NULL DEFAULT 127,  -- bit 0 = Monday … bit 6 = Sunday
    start_time  TEXT NOT NULL DEFAULT '00:00', -- HH:MM
    end_time    TEXT NOT NULL DEFAULT '24:00', -- HH:MM, may be < start_time (overnight)
    date_start  TEXT,                          -- YYYY-MM-DD, optional
    date_end    TEXT,                          -- YYYY-MM-DD, optional (inclusive)
    priority    INTEGER NOT NULL DEFAULT 0,
    rotation_seconds REAL,                     -- seconds this schedule gets when tied with
                                                -- another active schedule at equal priority;
                                                -- NULL = ROTATION_DEFAULT_SECONDS
    group_id    TEXT NOT NULL DEFAULT ''       -- rows sharing this id are one admin-facing
                                                -- multi-window rule — see MAX_SCHEDULE_WINDOWS
);
CREATE TABLE power_schedules (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    screen_id   INTEGER NOT NULL REFERENCES screens(id) ON DELETE CASCADE,
    dow_mask    INTEGER NOT NULL DEFAULT 127,  -- bit 0 = Monday … bit 6 = Sunday
    start_time  TEXT NOT NULL DEFAULT '08:00', -- HH:MM, panel should be ON from here…
    end_time    TEXT NOT NULL DEFAULT '18:00', -- …until here (may be < start_time = overnight)
    created_at  INTEGER NOT NULL
);
CREATE INDEX idx_items_playlist ON playlist_items(playlist_id, position);
CREATE INDEX idx_sched_screen   ON schedules(screen_id);
CREATE INDEX idx_sched_group    ON schedules(group_id);
CREATE INDEX idx_content_folder ON content(folder_id);
CREATE INDEX idx_power_sched_screen ON power_schedules(screen_id);
SQL);
}

/**
 * Idempotent guard so existing (pre-folders) databases pick up the new
 * table/column without a migration system — see CLAUDE.md "no migrations".
 */
function migrate_schema(PDO $pdo): void
{
    $hasFolders = $pdo->query(
        "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='folders'"
    )->fetchColumn();
    if (!$hasFolders) {
        $pdo->exec(<<<'SQL'
CREATE TABLE folders (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT NOT NULL,
    created_at INTEGER NOT NULL
);
SQL);
    }
    $hasFolderId = false;
    foreach ($pdo->query('PRAGMA table_info(content)') as $col) {
        if ($col['name'] === 'folder_id') { $hasFolderId = true; break; }
    }
    if (!$hasFolderId) {
        $pdo->exec('ALTER TABLE content ADD COLUMN folder_id INTEGER REFERENCES folders(id) ON DELETE SET NULL');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_content_folder ON content(folder_id)');
    }
    // Invariant: every content row belongs to a folder — sweep stragglers into "Unsorted"
    // (covers rows created before this invariant existed, or left null by any other path).
    if ((int) $pdo->query('SELECT COUNT(*) FROM content WHERE folder_id IS NULL')->fetchColumn() > 0) {
        $uid = ensure_unsorted_folder($pdo);
        $pdo->prepare('UPDATE content SET folder_id=? WHERE folder_id IS NULL')->execute([$uid]);
    }
    $hasRotation = false;
    foreach ($pdo->query('PRAGMA table_info(schedules)') as $col) {
        if ($col['name'] === 'rotation_seconds') { $hasRotation = true; break; }
    }
    if (!$hasRotation) {
        $pdo->exec('ALTER TABLE schedules ADD COLUMN rotation_seconds REAL');
    }
    $hasGroupId = false;
    foreach ($pdo->query('PRAGMA table_info(schedules)') as $col) {
        if ($col['name'] === 'group_id') { $hasGroupId = true; break; }
    }
    if (!$hasGroupId) {
        $pdo->exec("ALTER TABLE schedules ADD COLUMN group_id TEXT NOT NULL DEFAULT ''");
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_sched_group ON schedules(group_id)');
        // Every pre-existing row becomes its own singleton group — no behavior/display change.
        $pdo->exec("UPDATE schedules SET group_id = 'sg' || id WHERE group_id = ''");
    }
    $hasPendingCommand = false;
    foreach ($pdo->query('PRAGMA table_info(screens)') as $col) {
        if ($col['name'] === 'pending_command') { $hasPendingCommand = true; break; }
    }
    if (!$hasPendingCommand) {
        $pdo->exec('ALTER TABLE screens ADD COLUMN pending_command TEXT');
        $pdo->exec('ALTER TABLE screens ADD COLUMN pending_command_at INTEGER');
    }
    $hasPowerDriver = false;
    foreach ($pdo->query('PRAGMA table_info(screens)') as $col) {
        if ($col['name'] === 'power_driver') { $hasPowerDriver = true; break; }
    }
    if (!$hasPowerDriver) {
        $pdo->exec("ALTER TABLE screens ADD COLUMN power_driver TEXT NOT NULL DEFAULT ''");
        $pdo->exec("ALTER TABLE screens ADD COLUMN power_host TEXT NOT NULL DEFAULT ''");
        $pdo->exec('ALTER TABLE screens ADD COLUMN power_port INTEGER');
        $pdo->exec("ALTER TABLE screens ADD COLUMN power_mac TEXT NOT NULL DEFAULT ''");
    }
    $hasScreenshotAt = false;
    foreach ($pdo->query('PRAGMA table_info(screens)') as $col) {
        if ($col['name'] === 'screenshot_at') { $hasScreenshotAt = true; break; }
    }
    if (!$hasScreenshotAt) {
        $pdo->exec('ALTER TABLE screens ADD COLUMN screenshot_at INTEGER');
    }
    $hasPowerSchedules = $pdo->query(
        "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='power_schedules'"
    )->fetchColumn();
    if (!$hasPowerSchedules) {
        $pdo->exec(<<<'SQL'
CREATE TABLE power_schedules (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    screen_id   INTEGER NOT NULL REFERENCES screens(id) ON DELETE CASCADE,
    dow_mask    INTEGER NOT NULL DEFAULT 127,
    start_time  TEXT NOT NULL DEFAULT '08:00',
    end_time    TEXT NOT NULL DEFAULT '18:00',
    created_at  INTEGER NOT NULL
);
SQL);
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_power_sched_screen ON power_schedules(screen_id)');
    }
}

/** Get-or-create the catch-all folder every uncategorized content item lands in. */
function ensure_unsorted_folder(PDO $pdo): int
{
    $id = $pdo->query("SELECT id FROM folders WHERE name = 'Unsorted'")->fetchColumn();
    if ($id) {
        return (int) $id;
    }
    $pdo->prepare('INSERT INTO folders (name, created_at) VALUES (?, ?)')->execute(['Unsorted', now()]);
    return (int) $pdo->lastInsertId();
}

function now(): int
{
    return time();
}

/* -------------------------------------------------------------------- auth */

function admin_session_start(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_name('signage_admin');
        session_start();
    }
}

function is_admin(): bool
{
    admin_session_start();
    return !empty($_SESSION['is_admin']);
}

function require_admin(): void
{
    if (!is_admin()) {
        header('Location: index.php?page=login');
        exit;
    }
}

function csrf_token(): string
{
    admin_session_start();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function csrf_check(): void
{
    admin_session_start();
    if (($_POST['csrf'] ?? '') !== ($_SESSION['csrf'] ?? null)) {
        http_response_code(419);
        exit('CSRF token mismatch — go back and retry.');
    }
}

/* ----------------------------------------------------------------- helpers */

function e(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function redirect(string $to): never
{
    header('Location: ' . $to);
    exit;
}

function flash(?string $msg = null): ?string
{
    admin_session_start();
    if ($msg !== null) {
        $_SESSION['flash'] = $msg;
        return null;
    }
    $m = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $m;
}

function human_size(int $bytes): string
{
    foreach (['B', 'KB', 'MB', 'GB'] as $u) {
        if ($bytes < 1024) {
            return round($bytes, 1) . ' ' . $u;
        }
        $bytes /= 1024;
    }
    return round($bytes, 1) . ' TB';
}

/* ------------------------------------------------------- chunked uploads -- */

function upload_tmp_dir(string $id = ''): string
{
    return storage_path('uploads' . ($id !== '' ? '/' . $id : ''));
}

/** Remove a chunk-upload temp dir and everything in it (not recursive — flat by design). */
function upload_tmp_rm(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) as $f) {
        if ($f === '.' || $f === '..') { continue; }
        @unlink($dir . '/' . $f);
    }
    @rmdir($dir);
}

/** Discard chunk-upload temp dirs abandoned (closed tab, crash) more than a day ago. */
function upload_sweep_stale(): void
{
    $base = upload_tmp_dir();
    if (!is_dir($base)) {
        return;
    }
    foreach (scandir($base) as $id) {
        if ($id === '.' || $id === '..') { continue; }
        $meta = json_decode((string) @file_get_contents($base . '/' . $id . '/meta.json'), true);
        if (!$meta || ($meta['created_at'] ?? 0) < now() - 86400) {
            upload_tmp_rm($base . '/' . $id);
        }
    }
}

/** Try to read a video's duration via ffprobe, if present on the host. */
function probe_duration(string $file): ?float
{
    $ffprobe = trim((string) @shell_exec('command -v ffprobe 2>/dev/null'));
    if ($ffprobe === '') {
        return null;
    }
    $out = @shell_exec(escapeshellarg($ffprobe)
        . ' -v error -show_entries format=duration -of csv=p=0 '
        . escapeshellarg($file) . ' 2>/dev/null');
    $d = (float) trim((string) $out);
    return $d > 0 ? round($d, 2) : null;
}

/* --------------------------------------------------------------- schedules */

/**
 * The manifest a player consumes. It contains the screen's full weekly
 * schedule plus every referenced playlist, so the player can keep resolving
 * "what should play right now" locally — including while offline.
 */
function build_manifest(array $screen): array
{
    $pdo = db();

    $schedules = $pdo->prepare(
        'SELECT playlist_id, dow_mask, start_time, end_time, date_start, date_end, priority, rotation_seconds
           FROM schedules WHERE screen_id = ? ORDER BY priority DESC, id ASC'
    );
    $schedules->execute([$screen['id']]);
    $schedules = $schedules->fetchAll();

    $playlistIds = array_values(array_unique(array_filter(array_merge(
        array_column($schedules, 'playlist_id'),
        [$screen['fallback_playlist_id']]
    ))));

    $playlists = [];
    if ($playlistIds) {
        $in = implode(',', array_fill(0, count($playlistIds), '?'));
        $stmt = $pdo->prepare(
            "SELECT pi.playlist_id, pi.duration_override, pi.muted,
                    c.id AS content_id, c.type, c.title, c.filename, c.mime,
                    c.size, c.duration, c.url
               FROM playlist_items pi
               JOIN content c ON c.id = pi.content_id
              WHERE pi.playlist_id IN ($in)
              ORDER BY pi.playlist_id, pi.position, pi.id"
        );
        $stmt->execute($playlistIds);
        foreach ($stmt as $row) {
            $item = [
                'type'  => $row['type'],
                'title' => $row['title'],
                'muted' => (bool) $row['muted'],
            ];
            if ($row['type'] === 'url') {
                $item['url']      = $row['url'];
                $item['duration'] = $row['duration_override'] !== null
                    ? (float) $row['duration_override'] : (float) cfg('default_duration');
            } else {
                $item['src']  = 'media.php?f=' . rawurlencode($row['filename']);
                $item['file'] = $row['filename'];
                $item['mime'] = $row['mime'];
                $item['size'] = (int) $row['size'];
                if ($row['type'] === 'image') {
                    $item['duration'] = $row['duration_override'] !== null
                        ? (float) $row['duration_override'] : (float) cfg('default_duration');
                } else { // video
                    // duration_override trims/extends; null = play to natural end
                    $item['duration'] = $row['duration_override'] !== null
                        ? (float) $row['duration_override'] : null;
                    $item['natural_duration'] = $row['duration'] !== null
                        ? (float) $row['duration'] : null;
                }
            }
            $playlists[(string) $row['playlist_id']][] = $item;
        }
        // A scheduled playlist with zero items must still exist as a key.
        foreach ($playlistIds as $pid) {
            $playlists[(string) $pid] ??= [];
        }
    }

    $manifest = [
        'version'          => SIGNAGE_VERSION,
        'generated_at'     => now(),
        'refresh'          => (int) cfg('player_refresh'),
        'screen'           => ['id' => (int) $screen['id'], 'name' => $screen['name']],
        'fallback_playlist'=> $screen['fallback_playlist_id'] !== null
                                ? (int) $screen['fallback_playlist_id'] : null,
        'schedules'        => array_map(static fn ($s) => [
            'playlist' => (int) $s['playlist_id'],
            'dow'      => (int) $s['dow_mask'],
            'start'    => $s['start_time'],
            'end'      => $s['end_time'],
            'from'     => $s['date_start'],
            'until'    => $s['date_end'],
            'priority' => (int) $s['priority'],
            'rotation' => $s['rotation_seconds'] !== null ? (float) $s['rotation_seconds'] : null,
        ], $schedules),
        'playlists'        => $playlists ?: new stdClass(),
    ];
    // Stable fingerprint so players (and ETags) can detect changes cheaply.
    $manifest['hash'] = substr(sha1(json_encode(
        [$manifest['schedules'], $manifest['playlists'], $manifest['fallback_playlist']]
    )), 0, 16);
    return $manifest;
}

/**
 * Does one raw `schedules` row's day/time/date window match at $ts? Used by the
 * admin screen-detail page to flag which schedule rule is active right now.
 * Same semantics as the per-schedule hit test inside resolve_active_playlist(),
 * just addressed by DB column names instead of the manifest's shorthand keys.
 */
function schedule_window_hit(array $row, int $ts): bool
{
    $dow  = ((int) date('N', $ts)) - 1;              // 0 = Monday
    $t    = date('H:i', $ts);
    $d    = date('Y-m-d', $ts);
    $yDow = ($dow + 6) % 7;
    if ($row['date_start'] !== null && $row['date_start'] !== '' && $d < $row['date_start']) return false;
    if ($row['date_end']   !== null && $row['date_end']   !== '' && $d > $row['date_end'])   return false;
    $mask = (int) $row['dow_mask'];
    if ($row['start_time'] <= $row['end_time']) {   // same-day window
        return (bool) (($mask >> $dow & 1) && $t >= $row['start_time'] && $t < $row['end_time']);
    }
    // overnight window
    return (bool) ((($mask >> $dow & 1) && $t >= $row['start_time'])
                 || (($mask >> $yDow & 1) && $t < $row['end_time']));
}

/** Server-side resolver (used for the admin "now playing" column). */
function resolve_active_playlist(array $manifest, ?int $ts = null): ?int
{
    $ts  = $ts ?? now();
    $dow = ((int) date('N', $ts)) - 1;              // 0 = Monday
    $t   = date('H:i', $ts);
    $d   = date('Y-m-d', $ts);
    $yDow = ($dow + 6) % 7;
    $active = [];

    foreach ($manifest['schedules'] as $s) {
        if ($s['from'] !== null && $d < $s['from']) continue;
        if ($s['until'] !== null && $d > $s['until']) continue;
        $hit = false;
        if ($s['start'] <= $s['end']) {             // same-day window
            $hit = ($s['dow'] >> $dow & 1) && $t >= $s['start'] && $t < $s['end'];
        } else {                                    // overnight window
            $hit = (($s['dow'] >> $dow & 1) && $t >= $s['start'])
                || (($s['dow'] >> $yDow & 1) && $t < $s['end']);
        }
        if ($hit) { $active[] = $s; }
    }
    if (!$active) {
        return $manifest['fallback_playlist'];
    }
    // Highest priority wins; if several schedules are tied (same day/time/priority),
    // rotate between them by wall-clock time — see rotate_tied() docblock.
    $top  = max(array_column($active, 'priority'));
    $tied = array_values(array_filter($active, static fn ($s) => $s['priority'] === $top));
    return (count($tied) === 1 ? $tied[0] : rotate_tied($tied, $ts))['playlist'];
}

/**
 * Deterministic round-robin over schedules tied on day/time/priority: each gets
 * `rotation` seconds (default ROTATION_DEFAULT_SECONDS) in the array's order —
 * mirrored in JS as activePlaylistId()'s rotation branch, keep both in sync.
 */
function rotate_tied(array $tied, int $ts): array
{
    $durations = array_map(static fn ($s) => $s['rotation'] ?? ROTATION_DEFAULT_SECONDS, $tied);
    $total = array_sum($durations);
    if ($total <= 0) {
        return $tied[0];
    }
    $cursor = fmod((float) $ts, $total);
    $acc = 0.0;
    foreach ($tied as $i => $s) {
        $acc += $durations[$i];
        if ($cursor < $acc) {
            return $s;
        }
    }
    return $tied[array_key_last($tied)];
}

/**
 * Per-schedule-rule status for the admin screen-detail page, keyed by group_id:
 *   'active'         — this rule is what's actually driving playback right now.
 *   'lower_priority' — in its time window, but overridden by a higher-priority rule.
 *   'waiting_turn'   — in its time window, tied for top priority, losing the
 *                      rotation cycle to another rule right now.
 *   'inactive'       — outside its time window entirely right now.
 * Shared by the initial page render and the schedule_status.php poll endpoint so
 * they can never drift apart.
 */
function schedule_group_statuses(array $screen, ?int $ts = null): array
{
    $ts = $ts ?? now();
    $sch = db()->prepare('SELECT * FROM schedules WHERE screen_id=? ORDER BY priority DESC, start_time');
    $sch->execute([$screen['id']]);
    $groups = [];
    foreach ($sch->fetchAll() as $r) { $groups[$r['group_id']][] = $r; }

    $activePid = resolve_active_playlist(build_manifest($screen), $ts);

    $inWindow = [];
    $topPriority = null;
    foreach ($groups as $gid => $rows) {
        $hit = false;
        foreach ($rows as $w) { if (schedule_window_hit($w, $ts)) { $hit = true; break; } }
        $inWindow[$gid] = $hit;
        if ($hit) {
            $p = (int) $rows[0]['priority'];
            if ($topPriority === null || $p > $topPriority) { $topPriority = $p; }
        }
    }

    $out = [];
    foreach ($groups as $gid => $rows) {
        $r = $rows[0];
        $hit = $inWindow[$gid];
        $active = $hit && (int) $r['playlist_id'] === (int) $activePid;
        if ($active) {
            $out[$gid] = 'active';
        } elseif ($hit) {
            $out[$gid] = (int) $r['priority'] < $topPriority ? 'lower_priority' : 'waiting_turn';
        } else {
            $out[$gid] = 'inactive';
        }
    }
    return $out;
}

/**
 * Whether a screen's panel should be powered on right now, per its power_schedules
 * rows (same dow_mask/overnight-window shape as content `schedules`, minus priority —
 * any matching window is enough). Returns null when the screen has no power schedule
 * at all, meaning it isn't power-managed (e.g. an always-on browser kiosk) — callers
 * should leave those screens' online/offline status untouched.
 */
function power_should_be_on(int $screenId, ?int $ts = null): ?bool
{
    $rows = db()->prepare('SELECT dow_mask, start_time, end_time FROM power_schedules WHERE screen_id=?');
    $rows->execute([$screenId]);
    $rows = $rows->fetchAll();
    if (!$rows) {
        return null;
    }
    $ts   = $ts ?? now();
    $dow  = ((int) date('N', $ts)) - 1;  // 0 = Monday
    $t    = date('H:i', $ts);
    $yDow = ($dow + 6) % 7;

    foreach ($rows as $r) {
        $mask = (int) $r['dow_mask'];
        if ($r['start_time'] <= $r['end_time']) {  // same-day window
            if (($mask >> $dow & 1) && $t >= $r['start_time'] && $t < $r['end_time']) { return true; }
        } else {                                    // overnight window
            if ((($mask >> $dow & 1) && $t >= $r['start_time'])
                || (($mask >> $yDow & 1) && $t < $r['end_time'])) { return true; }
        }
    }
    return false;
}

function screen_by_token(string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{16,64}$/', $token)) {
        return null;
    }
    $stmt = db()->prepare('SELECT * FROM screens WHERE token = ?');
    $stmt->execute([$token]);
    return $stmt->fetch() ?: null;
}

/** Queue (or overwrite) the single pending remote command for a screen. */
function screen_command_issue(int $screenId, string $command): void
{
    db()->prepare('UPDATE screens SET pending_command=?, pending_command_at=? WHERE id=?')
        ->execute([$command, now(), $screenId]);
}

/**
 * Atomically fetch-and-clear the pending command for a screen — at-most-once
 * delivery. The command is forgotten the instant it's read, before the companion
 * agent has executed it, so a device that loses power mid-command silently drops
 * it rather than replaying it (and rebooting again) on its next boot.
 */
function screen_command_fetch_and_clear(int $screenId): ?array
{
    $pdo = db();
    $stmt = $pdo->prepare('SELECT pending_command, pending_command_at FROM screens WHERE id=?');
    $stmt->execute([$screenId]);
    $row = $stmt->fetch();
    if (!$row || $row['pending_command'] === null) {
        return null;
    }
    $pdo->prepare('UPDATE screens SET pending_command=NULL, pending_command_at=NULL WHERE id=?')
        ->execute([$screenId]);
    return ['command' => $row['pending_command'], 'issued_at' => (int) $row['pending_command_at']];
}
