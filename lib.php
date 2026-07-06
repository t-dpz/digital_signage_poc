<?php
/**
 * Signage — core library (no dependencies, PHP 8.1+, SQLite)
 */
declare(strict_types=1);

const SIGNAGE_VERSION = '1.0.0';

$GLOBALS['cfg'] = require __DIR__ . '/config.php';

function cfg(string $key): mixed
{
    return $GLOBALS['cfg'][$key] ?? null;
}

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
    $fresh = !file_exists(storage_path('signage.sqlite'));
    $pdo = new PDO('sqlite:' . storage_path('signage.sqlite'), null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA foreign_keys = ON');
    if ($fresh) {
        install_schema($pdo);
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
    updated_at           INTEGER NOT NULL,
    created_at           INTEGER NOT NULL
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
    priority    INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX idx_items_playlist ON playlist_items(playlist_id, position);
CREATE INDEX idx_sched_screen   ON schedules(screen_id);
SQL);
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
        'SELECT playlist_id, dow_mask, start_time, end_time, date_start, date_end, priority
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
        ], $schedules),
        'playlists'        => $playlists ?: new stdClass(),
    ];
    // Stable fingerprint so players (and ETags) can detect changes cheaply.
    $manifest['hash'] = substr(sha1(json_encode(
        [$manifest['schedules'], $manifest['playlists'], $manifest['fallback_playlist']]
    )), 0, 16);
    return $manifest;
}

/** Server-side resolver (used for the admin "now playing" column). */
function resolve_active_playlist(array $manifest, ?int $ts = null): ?int
{
    $ts  = $ts ?? now();
    $dow = ((int) date('N', $ts)) - 1;              // 0 = Monday
    $t   = date('H:i', $ts);
    $d   = date('Y-m-d', $ts);
    $yDow = ($dow + 6) % 7;
    $best = null;

    foreach ($manifest['schedules'] as $s) {
        if ($s['from'] !== null && $d < $s['from']) continue;
        if ($s['until'] !== null && $d > $s['until']) continue;
        $active = false;
        if ($s['start'] <= $s['end']) {             // same-day window
            $active = ($s['dow'] >> $dow & 1) && $t >= $s['start'] && $t < $s['end'];
        } else {                                    // overnight window
            $active = (($s['dow'] >> $dow & 1) && $t >= $s['start'])
                   || (($s['dow'] >> $yDow & 1) && $t < $s['end']);
        }
        if ($active && ($best === null || $s['priority'] > $best['priority'])) {
            $best = $s;
        }
    }
    return $best['playlist'] ?? $manifest['fallback_playlist'];
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
