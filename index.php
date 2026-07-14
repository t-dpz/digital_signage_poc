<?php
/**
 * index.php — admin panel (screens · playlists · content · schedules)
 */
declare(strict_types=1);
require __DIR__ . '/lib.php';

$pdo    = db();
$page   = $_GET['page'] ?? 'screens';
$action = $_POST['action'] ?? null;

// PHP silently empties $_POST/$_FILES (no warning to the app) when a POST body
// exceeds post_max_size — most relevant to the chunked upload endpoints, since a
// misconfigured/stock php.ini could cap post_max_size below UPLOAD_CHUNK_BYTES.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$_POST && !$_FILES && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    http_response_code(413);
    header('Content-Type: application/json');
    exit(json_encode(['error' => 'Request body exceeds the server\'s post_max_size/upload_max_filesize (php.ini).']));
}

/* ----------------------------------------------------------------- login -- */

if ($page === 'login') {
    admin_session_start();
    $err = null;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (hash_equals((string) cfg('admin_password'), (string) ($_POST['password'] ?? ''))) {
            session_regenerate_id(true);
            $_SESSION['is_admin'] = true;
            redirect('index.php');
        }
        $err = 'Wrong password.';
        usleep(400_000);
    }
    layout_top('Sign in', false); ?>
    <div class="login">
      <h1>Signage</h1>
      <form method="post">
        <?php if ($err): ?><p class="error"><?= e($err) ?></p><?php endif ?>
        <input type="password" name="password" placeholder="Admin password" autofocus required>
        <button>Sign in</button>
        <?php if (cfg('admin_password') === 'change-me'): ?>
          <p class="hint">Default password is <code>change-me</code> — set your own in <code>config.php</code>.</p>
        <?php endif ?>
      </form>
    </div>
    <?php layout_bottom(); exit;
}

require_admin();

if (($_GET['do'] ?? '') === 'logout') {
    session_destroy();
    redirect('index.php?page=login');
}

/* --------------------------------------------------------------- actions -- */

if ($action !== null) {
    csrf_check();

    switch ($action) {

    case 'screen_save':
        $id   = (int) ($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $fallback = ($_POST['fallback_playlist_id'] ?? '') !== '' ? (int) $_POST['fallback_playlist_id'] : null;
        if ($name === '') { flash('Screen needs a name.'); redirect('index.php'); }
        $powerDriver = array_key_exists($_POST['power_driver'] ?? '', POWER_DRIVERS) ? $_POST['power_driver'] : '';
        $powerHost   = trim($_POST['power_host'] ?? '');
        $powerPort   = ($_POST['power_port'] ?? '') !== '' ? (int) $_POST['power_port'] : null;
        $powerMac    = trim($_POST['power_mac'] ?? '');
        if ($id) {
            $pdo->prepare('UPDATE screens SET name=?, notes=?, fallback_playlist_id=?,
                           power_driver=?, power_host=?, power_port=?, power_mac=?, updated_at=? WHERE id=?')
                ->execute([$name, trim($_POST['notes'] ?? ''), $fallback,
                           $powerDriver, $powerHost, $powerPort, $powerMac, now(), $id]);
        } else {
            $pdo->prepare('INSERT INTO screens (name, token, notes, fallback_playlist_id,
                           power_driver, power_host, power_port, power_mac, updated_at, created_at)
                           VALUES (?,?,?,?,?,?,?,?,?,?)')
                ->execute([$name, bin2hex(random_bytes(16)), trim($_POST['notes'] ?? ''), $fallback,
                           $powerDriver, $powerHost, $powerPort, $powerMac, now(), now()]);
            $id = (int) $pdo->lastInsertId();
        }
        flash('Screen saved.');
        redirect('index.php?page=screen&id=' . $id);

    case 'screen_token':
        $pdo->prepare('UPDATE screens SET token=?, updated_at=? WHERE id=?')
            ->execute([bin2hex(random_bytes(16)), now(), (int) $_POST['id']]);
        flash('New player URL generated — update the kiosk.');
        redirect('index.php?page=screen&id=' . (int) $_POST['id']);

    case 'screen_delete':
        $pdo->prepare('DELETE FROM screens WHERE id=?')->execute([(int) $_POST['id']]);
        flash('Screen deleted.');
        redirect('index.php');

    case 'screen_command':
        $cmd = $_POST['command'] ?? '';
        if (!in_array($cmd, ['restart_kiosk', 'reboot'], true)) {
            flash('Unknown command.');
            redirect('index.php?page=screen&id=' . (int) $_POST['id']);
        }
        screen_command_issue((int) $_POST['id'], $cmd);
        flash($cmd === 'reboot' ? 'Reboot queued — will apply next time the device checks in.'
                                 : 'Kiosk restart queued — will apply next time the device checks in.');
        redirect('index.php?page=screen&id=' . (int) $_POST['id']);

    case 'schedule_add':
        $mask = 0;
        foreach ($_POST['dow'] ?? [] as $d) { $mask |= 1 << (int) $d; }
        $st = preg_match('/^\d{2}:\d{2}$/', $_POST['start_time'] ?? '') ? $_POST['start_time'] : '00:00';
        $en = preg_match('/^\d{2}:\d{2}$/', $_POST['end_time'] ?? '')   ? $_POST['end_time']   : '24:00';
        $rot = ($_POST['rotation_seconds'] ?? '') !== '' ? (float) $_POST['rotation_seconds'] : null;
        if ($rot !== null && $rot < ROTATION_DEFAULT_SECONDS) { $rot = ROTATION_DEFAULT_SECONDS; }
        $pdo->prepare('INSERT INTO schedules (screen_id, playlist_id, dow_mask, start_time, end_time,
                       date_start, date_end, priority, rotation_seconds) VALUES (?,?,?,?,?,?,?,?,?)')
            ->execute([
                (int) $_POST['screen_id'], (int) $_POST['playlist_id'],
                $mask ?: 127, $st, $en,
                ($_POST['date_start'] ?? '') ?: null, ($_POST['date_end'] ?? '') ?: null,
                (int) ($_POST['priority'] ?? 0), $rot,
            ]);
        touch_screen((int) $_POST['screen_id']);
        flash('Schedule added.');
        redirect('index.php?page=screen&id=' . (int) $_POST['screen_id']);

    case 'schedule_update':
        $mask = 0;
        foreach ($_POST['dow'] ?? [] as $d) { $mask |= 1 << (int) $d; }
        $st = preg_match('/^\d{2}:\d{2}$/', $_POST['start_time'] ?? '') ? $_POST['start_time'] : '00:00';
        $en = preg_match('/^\d{2}:\d{2}$/', $_POST['end_time'] ?? '')   ? $_POST['end_time']   : '24:00';
        $rot = ($_POST['rotation_seconds'] ?? '') !== '' ? (float) $_POST['rotation_seconds'] : null;
        if ($rot !== null && $rot < ROTATION_DEFAULT_SECONDS) { $rot = ROTATION_DEFAULT_SECONDS; }
        $pdo->prepare('UPDATE schedules SET playlist_id=?, dow_mask=?, start_time=?, end_time=?,
                       date_start=?, date_end=?, priority=?, rotation_seconds=? WHERE id=? AND screen_id=?')
            ->execute([
                (int) $_POST['playlist_id'], $mask ?: 127, $st, $en,
                ($_POST['date_start'] ?? '') ?: null, ($_POST['date_end'] ?? '') ?: null,
                (int) ($_POST['priority'] ?? 0), $rot,
                (int) $_POST['id'], (int) $_POST['screen_id'],
            ]);
        touch_screen((int) $_POST['screen_id']);
        flash('Schedule updated.');
        redirect('index.php?page=screen&id=' . (int) $_POST['screen_id']);

    case 'schedule_delete':
        $pdo->prepare('DELETE FROM schedules WHERE id=?')->execute([(int) $_POST['id']]);
        touch_screen((int) $_POST['screen_id']);
        flash('Schedule removed.');
        redirect('index.php?page=screen&id=' . (int) $_POST['screen_id']);

    case 'power_schedule_add':
        $mask = 0;
        foreach ($_POST['dow'] ?? [] as $d) { $mask |= 1 << (int) $d; }
        $st = preg_match('/^\d{2}:\d{2}$/', $_POST['start_time'] ?? '') ? $_POST['start_time'] : '08:00';
        $en = preg_match('/^\d{2}:\d{2}$/', $_POST['end_time'] ?? '')   ? $_POST['end_time']   : '18:00';
        $pdo->prepare('INSERT INTO power_schedules (screen_id, dow_mask, start_time, end_time, created_at)
                       VALUES (?,?,?,?,?)')
            ->execute([(int) $_POST['screen_id'], $mask ?: 127, $st, $en, now()]);
        flash('Power window added.');
        redirect('index.php?page=screen&id=' . (int) $_POST['screen_id']);

    case 'power_schedule_delete':
        $pdo->prepare('DELETE FROM power_schedules WHERE id=?')->execute([(int) $_POST['id']]);
        flash('Power window removed.');
        redirect('index.php?page=screen&id=' . (int) $_POST['screen_id']);

    case 'content_upload':
        $ok = 0; $failed = [];
        $folderId = ($_POST['folder_id'] ?? '') !== '' ? (int) $_POST['folder_id'] : ensure_unsorted_folder($pdo);
        $files = $_FILES['files'] ?? null;
        if ($files) {
            foreach ((array) $files['name'] as $i => $orig) {
                if ($files['error'][$i] !== UPLOAD_ERR_OK) { $failed[] = $orig ?: '(upload error)'; continue; }
                $tmp  = $files['tmp_name'][$i];
                $mime = mime_content_type($tmp) ?: '';
                $ext  = cfg('allowed_mime')[$mime] ?? null;
                if (!$ext) { $failed[] = "$orig ($mime not allowed)"; continue; }
                $hash = sha1_file($tmp);
                $name = $hash . '.' . $ext;
                $dest = storage_path('media/' . $name);
                if (!file_exists($dest) && !move_uploaded_file($tmp, $dest)) { $failed[] = $orig; continue; }
                $type = str_starts_with($mime, 'video/') ? 'video' : 'image';
                $dur  = $type === 'video' ? probe_duration($dest) : null;
                $pdo->prepare('INSERT INTO content (type, title, filename, mime, size, duration, folder_id, created_at)
                               VALUES (?,?,?,?,?,?,?,?)')
                    ->execute([$type, pathinfo($orig, PATHINFO_FILENAME) ?: $name, $name, $mime,
                               filesize($dest), $dur, $folderId, now()]);
                $ok++;
            }
        }
        flash($ok . ' file(s) uploaded.' . ($failed ? ' Failed: ' . implode(', ', $failed) : ''));
        redirect('index.php?page=content');

    /* Chunked/resumable upload, driven by assets/admin.js. Falls back transparently
       to the plain content_upload form post above if JS/fetch is unavailable. */

    case 'content_upload_init':
        upload_sweep_stale();
        header('Content-Type: application/json');
        $filename = trim((string) ($_POST['filename'] ?? ''));
        $size     = (int) ($_POST['size'] ?? 0);
        $folderId = ($_POST['folder_id'] ?? '') !== '' ? (int) $_POST['folder_id'] : ensure_unsorted_folder($pdo);
        if ($filename === '' || $size <= 0) {
            http_response_code(400);
            exit(json_encode(['error' => 'Bad filename/size.']));
        }
        $id  = bin2hex(random_bytes(16));
        $dir = upload_tmp_dir($id);
        mkdir($dir, 0775, true);
        file_put_contents($dir . '/meta.json', json_encode([
            'filename' => $filename, 'size' => $size, 'folder_id' => $folderId, 'created_at' => now(),
        ]));
        exit(json_encode(['upload_id' => $id, 'chunk_size' => UPLOAD_CHUNK_BYTES]));

    case 'content_upload_status':
        header('Content-Type: application/json');
        $id  = (string) ($_POST['upload_id'] ?? '');
        $dir = upload_tmp_dir($id);
        if (!preg_match('/^[a-f0-9]{32}$/', $id) || !is_file($dir . '/meta.json')) {
            http_response_code(404);
            exit(json_encode(['error' => 'unknown upload']));
        }
        $received = [];
        foreach (glob($dir . '/*.part') ?: [] as $f) {
            $received[] = (int) basename($f, '.part');
        }
        sort($received);
        exit(json_encode(['received' => $received]));

    case 'content_upload_chunk':
        header('Content-Type: application/json');
        $id  = (string) ($_POST['upload_id'] ?? '');
        $idx = (int) ($_POST['index'] ?? -1);
        $dir = upload_tmp_dir($id);
        if (!preg_match('/^[a-f0-9]{32}$/', $id) || !is_file($dir . '/meta.json') || $idx < 0) {
            http_response_code(404);
            exit(json_encode(['error' => 'unknown upload']));
        }
        $chunk = $_FILES['chunk'] ?? null;
        if (!$chunk || $chunk['error'] !== UPLOAD_ERR_OK) {
            http_response_code(400);
            exit(json_encode(['error' => 'chunk upload failed']));
        }
        if (!move_uploaded_file($chunk['tmp_name'], $dir . '/' . $idx . '.part')) {
            http_response_code(500);
            exit(json_encode(['error' => 'could not store chunk']));
        }
        exit(json_encode(['ok' => true]));

    case 'content_upload_finish':
        header('Content-Type: application/json');
        $id   = (string) ($_POST['upload_id'] ?? '');
        $dir  = upload_tmp_dir($id);
        $meta = is_file($dir . '/meta.json') ? json_decode((string) file_get_contents($dir . '/meta.json'), true) : null;
        if (!preg_match('/^[a-f0-9]{32}$/', $id) || !$meta) {
            http_response_code(404);
            exit(json_encode(['error' => 'unknown upload']));
        }
        set_time_limit(0); // assembling a multi-GB file from chunks can outrun the default limit
        $expected = (int) ceil($meta['size'] / UPLOAD_CHUNK_BYTES);
        for ($i = 0; $i < $expected; $i++) {
            if (!is_file("$dir/$i.part")) {
                http_response_code(409);
                exit(json_encode(['error' => "missing chunk $i"]));
            }
        }
        $assembled = $dir . '/assembled.bin';
        $out = fopen($assembled, 'wb');
        $total = 0;
        for ($i = 0; $i < $expected; $i++) {
            $in = fopen("$dir/$i.part", 'rb');
            while (!feof($in)) { $buf = fread($in, 1024 * 1024); $total += strlen($buf); fwrite($out, $buf); }
            fclose($in);
        }
        fclose($out);
        if ($total !== (int) $meta['size']) {
            upload_tmp_rm($dir);
            http_response_code(409);
            exit(json_encode(['error' => 'size mismatch — please retry the upload']));
        }
        $mime = mime_content_type($assembled) ?: '';
        $ext  = cfg('allowed_mime')[$mime] ?? null;
        if (!$ext) {
            upload_tmp_rm($dir);
            http_response_code(415);
            exit(json_encode(['error' => "$mime not allowed"]));
        }
        $hash = sha1_file($assembled);
        $name = $hash . '.' . $ext;
        $dest = storage_path('media/' . $name);
        if (!file_exists($dest)) {
            rename($assembled, $dest);
        }
        $type = str_starts_with($mime, 'video/') ? 'video' : 'image';
        $dur  = $type === 'video' ? probe_duration($dest) : null;
        $title = pathinfo((string) $meta['filename'], PATHINFO_FILENAME) ?: $name;
        $pdo->prepare('INSERT INTO content (type, title, filename, mime, size, duration, folder_id, created_at)
                       VALUES (?,?,?,?,?,?,?,?)')
            ->execute([$type, $title, $name, $mime, filesize($dest), $dur, (int) $meta['folder_id'], now()]);
        upload_tmp_rm($dir);
        exit(json_encode(['ok' => true, 'title' => $title]));

    case 'content_upload_cancel':
        header('Content-Type: application/json');
        $id = (string) ($_POST['upload_id'] ?? '');
        if (preg_match('/^[a-f0-9]{32}$/', $id)) {
            upload_tmp_rm(upload_tmp_dir($id));
        }
        exit(json_encode(['ok' => true]));

    case 'content_url':
        $url = trim($_POST['url'] ?? '');
        if (!preg_match('#^https?://#i', $url)) { flash('URL must start with http(s)://'); redirect('index.php?page=content'); }
        $folderId = ($_POST['folder_id'] ?? '') !== '' ? (int) $_POST['folder_id'] : ensure_unsorted_folder($pdo);
        $pdo->prepare('INSERT INTO content (type, title, url, folder_id, created_at) VALUES (?,?,?,?,?)')
            ->execute(['url', trim($_POST['title'] ?? '') ?: $url, $url, $folderId, now()]);
        flash('Web page added.');
        redirect('index.php?page=content');

    case 'content_delete':
        $c = $pdo->prepare('SELECT * FROM content WHERE id=?');
        $c->execute([(int) $_POST['id']]);
        if ($c = $c->fetch()) {
            $pdo->prepare('DELETE FROM content WHERE id=?')->execute([$c['id']]);
            if ($c['filename']) {
                // Only unlink when no other content row references the same file.
                $ref = $pdo->prepare('SELECT COUNT(*) FROM content WHERE filename=?');
                $ref->execute([$c['filename']]);
                if (!$ref->fetchColumn()) { @unlink(storage_path('media/' . $c['filename'])); }
            }
        }
        touch_all_playlists();
        flash('Content deleted.');
        redirect('index.php?page=content');

    case 'content_move_folder':
        $folderId = ($_POST['folder_id'] ?? '') !== '' ? (int) $_POST['folder_id'] : ensure_unsorted_folder($pdo);
        $pdo->prepare('UPDATE content SET folder_id=? WHERE id=?')->execute([$folderId, (int) $_POST['id']]);
        redirect('index.php?page=content');

    case 'folder_create':
        $name = trim($_POST['name'] ?? '');
        if ($name === '') { flash('Folder needs a name.'); redirect('index.php?page=content'); }
        $pdo->prepare('INSERT INTO folders (name, created_at) VALUES (?,?)')->execute([$name, now()]);
        flash('Folder created.');
        redirect('index.php?page=content');

    case 'folder_delete':
        $fid = (int) $_POST['id'];
        // Deleting a folder deletes everything filed inside it — mirrors "delete folder" on a filesystem.
        $c = $pdo->prepare('SELECT * FROM content WHERE folder_id=?');
        $c->execute([$fid]);
        $n = 0;
        foreach ($c->fetchAll() as $row) {
            $pdo->prepare('DELETE FROM content WHERE id=?')->execute([$row['id']]);
            if ($row['filename']) {
                $ref = $pdo->prepare('SELECT COUNT(*) FROM content WHERE filename=?');
                $ref->execute([$row['filename']]);
                if (!$ref->fetchColumn()) { @unlink(storage_path('media/' . $row['filename'])); }
            }
            $n++;
        }
        $pdo->prepare('DELETE FROM folders WHERE id=?')->execute([$fid]);
        touch_all_playlists();
        flash("Folder deleted ($n item(s) removed with it).");
        redirect('index.php?page=content');

    case 'playlist_save':
        $id = (int) ($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '') ?: 'Untitled playlist';
        if ($id) { $pdo->prepare('UPDATE playlists SET name=?, updated_at=? WHERE id=?')->execute([$name, now(), $id]); }
        else {
            $pdo->prepare('INSERT INTO playlists (name, updated_at, created_at) VALUES (?,?,?)')
                ->execute([$name, now(), now()]);
            $id = (int) $pdo->lastInsertId();
        }
        flash('Playlist saved.');
        redirect('index.php?page=playlist&id=' . $id);

    case 'playlist_delete':
        $pdo->prepare('DELETE FROM playlists WHERE id=?')->execute([(int) $_POST['id']]);
        flash('Playlist deleted.');
        redirect('index.php?page=playlists');

    case 'item_add':
        $pid = (int) $_POST['playlist_id'];
        $pos = (int) $pdo->query('SELECT COALESCE(MAX(position),0)+1 FROM playlist_items WHERE playlist_id=' . $pid)->fetchColumn();
        $dur = ($_POST['duration'] ?? '') !== '' ? (float) $_POST['duration'] : null;
        $pdo->prepare('INSERT INTO playlist_items (playlist_id, content_id, position, duration_override, muted)
                       VALUES (?,?,?,?,?)')
            ->execute([$pid, (int) $_POST['content_id'], $pos, $dur, isset($_POST['muted']) ? 1 : 0]);
        touch_playlist($pid);
        redirect('index.php?page=playlist&id=' . $pid);

    case 'item_add_folder':
        $pid = (int) $_POST['playlist_id'];
        $fid = (int) $_POST['folder_id'];
        $dur = ($_POST['duration'] ?? '') !== '' ? (float) $_POST['duration'] : null;
        $muted = isset($_POST['muted']) ? 1 : 0;
        $pos = (int) $pdo->query('SELECT COALESCE(MAX(position),0) FROM playlist_items WHERE playlist_id=' . $pid)->fetchColumn();
        $folderContent = $pdo->prepare('SELECT id FROM content WHERE folder_id=? ORDER BY title, id');
        $folderContent->execute([$fid]);
        $ins = $pdo->prepare('INSERT INTO playlist_items (playlist_id, content_id, position, duration_override, muted)
                               VALUES (?,?,?,?,?)');
        foreach ($folderContent->fetchAll() as $row) {
            $ins->execute([$pid, $row['id'], ++$pos, $dur, $muted]);
        }
        touch_playlist($pid);
        redirect('index.php?page=playlist&id=' . $pid);

    case 'item_set_duration':
        $pid = (int) $_POST['playlist_id'];
        $dur = ($_POST['duration'] ?? '') !== '' ? (float) $_POST['duration'] : null;
        $pdo->prepare('UPDATE playlist_items SET duration_override=? WHERE id=? AND playlist_id=?')
            ->execute([$dur, (int) $_POST['id'], $pid]);
        touch_playlist($pid);
        redirect('index.php?page=playlist&id=' . $pid);

    case 'item_delete':
        $pdo->prepare('DELETE FROM playlist_items WHERE id=?')->execute([(int) $_POST['id']]);
        touch_playlist((int) $_POST['playlist_id']);
        redirect('index.php?page=playlist&id=' . (int) $_POST['playlist_id']);

    case 'item_move':
        $pid = (int) $_POST['playlist_id'];
        move_item((int) $_POST['id'], $pid, $_POST['dir'] === 'up' ? -1 : 1);
        touch_playlist($pid);
        redirect('index.php?page=playlist&id=' . $pid);
    }
    redirect('index.php');
}

function touch_playlist(int $id): void
{
    db()->prepare('UPDATE playlists SET updated_at=? WHERE id=?')->execute([now(), $id]);
}
function touch_all_playlists(): void
{
    db()->prepare('UPDATE playlists SET updated_at=?')->execute([now()]);
}
function touch_screen(int $id): void
{
    db()->prepare('UPDATE screens SET updated_at=? WHERE id=?')->execute([now(), $id]);
}
function move_item(int $id, int $pid, int $dir): void
{
    $pdo = db();
    $items = $pdo->prepare('SELECT id FROM playlist_items WHERE playlist_id=? ORDER BY position, id');
    $items->execute([$pid]);
    $ids = array_column($items->fetchAll(), 'id');
    $i = array_search($id, $ids, false);
    if ($i === false) { return; }
    $j = $i + $dir;
    if ($j < 0 || $j >= count($ids)) { return; }
    [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
    foreach ($ids as $pos => $iid) {
        $pdo->prepare('UPDATE playlist_items SET position=? WHERE id=?')->execute([$pos + 1, $iid]);
    }
}

/* ---------------------------------------------------------------- layout -- */

function layout_top(string $title, bool $nav = true): void
{
    $flash = flash(); ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> · Signage</title>
<link rel="stylesheet" href="assets/admin.css">
</head>
<body>
<?php if ($nav): ?>
<header>
  <span class="brand">SIGNAGE<em>/on-prem</em></span>
  <nav>
    <a href="index.php" class="<?= ($_GET['page'] ?? 'screens') === 'screens' || ($_GET['page'] ?? '') === 'screen' ? 'on' : '' ?>">Screens</a>
    <a href="index.php?page=playlists" class="<?= in_array($_GET['page'] ?? '', ['playlists', 'playlist']) ? 'on' : '' ?>">Playlists</a>
    <a href="index.php?page=content" class="<?= ($_GET['page'] ?? '') === 'content' ? 'on' : '' ?>">Content</a>
  </nav>
  <a class="logout" href="index.php?do=logout">Sign out</a>
</header>
<?php endif ?>
<main>
<?php if ($flash): ?><div class="flash"><?= e($flash) ?></div><?php endif;
}

function layout_bottom(): void
{ ?>
</main>
<script src="assets/admin.js"></script>
</body>
</html>
<?php }

function dow_label(int $mask): string
{
    if ($mask === 127) { return 'Every day'; }
    $days = ['Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su'];
    $out = [];
    foreach ($days as $i => $d) { if ($mask >> $i & 1) { $out[] = $d; } }
    return implode(' ', $out) ?: '—';
}

$csrf = csrf_token();
$allPlaylists = $pdo->query('SELECT id, name FROM playlists ORDER BY name')->fetchAll();

/* ----------------------------------------------------------------- pages -- */

/* Screens overview */
if ($page === 'screens') {
    $screens = $pdo->query('SELECT * FROM screens ORDER BY name')->fetchAll();
    layout_top('Screens'); ?>
    <div class="pagehead">
      <h1>Screens</h1>
      <a class="btn" href="index.php?page=screen">+ New screen</a>
    </div>
    <?php if (!$screens): ?>
      <p class="empty">No screens yet. Create one, point its kiosk browser at the player URL, and it starts polling.</p>
    <?php else: ?>
    <table>
      <tr><th>Status</th><th>Name</th><th>Now playing</th><th>Last seen</th><th>Player URL</th></tr>
      <?php foreach ($screens as $s):
          $online = $s['last_seen'] && (now() - $s['last_seen']) < cfg('player_refresh') * 2.5;
          $powerOn = power_should_be_on((int) $s['id']);
          $asleep = !$online && $powerOn === false;
          $m = build_manifest($s);
          $active = resolve_active_playlist($m);
          $activeName = null;
          foreach ($allPlaylists as $p) { if ((int) $p['id'] === $active) { $activeName = $p['name']; } }
          $url = 'player.php?token=' . $s['token']; ?>
      <tr>
        <td><span class="dot <?= $online ? 'ok' : ($asleep ? 'zzz' : '') ?>"></span><?=
              $online ? 'online' : ($asleep ? 'asleep (scheduled off)' : 'offline') ?></td>
        <td><a href="index.php?page=screen&id=<?= $s['id'] ?>"><?= e($s['name']) ?></a></td>
        <td><?= $activeName ? e($activeName) : '<span class="muted">nothing scheduled</span>' ?></td>
        <td class="muted"><?= $s['last_seen'] ? date('d M H:i', (int) $s['last_seen']) : 'never' ?></td>
        <td><code class="copy" data-copy="<?= e($url) ?>" title="Click to copy full URL"><?= e($url) ?></code></td>
      </tr>
      <?php endforeach ?>
    </table>
    <?php endif;
    layout_bottom(); exit;
}

/* Screen detail + schedules */
if ($page === 'screen') {
    $id = (int) ($_GET['id'] ?? 0);
    $s = null;
    if ($id) {
        $q = $pdo->prepare('SELECT * FROM screens WHERE id=?');
        $q->execute([$id]);
        $s = $q->fetch();
        if (!$s) { redirect('index.php'); }
    }
    layout_top($s ? $s['name'] : 'New screen'); ?>
    <div class="pagehead"><h1><?= $s ? e($s['name']) : 'New screen' ?></h1></div>

    <section class="card">
      <h2>Details</h2>
      <form method="post" class="grid">
        <input type="hidden" name="csrf" value="<?= $csrf ?>">
        <input type="hidden" name="action" value="screen_save">
        <input type="hidden" name="id" value="<?= (int) ($s['id'] ?? 0) ?>">
        <label>Name <input name="name" value="<?= e($s['name'] ?? '') ?>" required></label>
        <label>Fallback playlist <span class="hint">plays when no schedule matches</span>
          <select name="fallback_playlist_id">
            <option value="">— none (black screen) —</option>
            <?php foreach ($allPlaylists as $p): ?>
              <option value="<?= $p['id'] ?>" <?= (int) ($s['fallback_playlist_id'] ?? 0) === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option>
            <?php endforeach ?>
          </select>
        </label>
        <label class="wide">Notes <input name="notes" value="<?= e($s['notes'] ?? '') ?>" placeholder="location, switch port, …"></label>
        <label>Power control <span class="hint">LAN protocol used to switch the panel on/off — see the Panel power card below</span>
          <select name="power_driver">
            <?php foreach (POWER_DRIVERS as $key => $label): ?>
              <option value="<?= e($key) ?>" <?= ($s['power_driver'] ?? '') === $key ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach ?>
          </select>
        </label>
        <label>Power IP/host <input name="power_host" value="<?= e($s['power_host'] ?? '') ?>" placeholder="192.168.1.50"></label>
        <label>Power port <span class="hint">blank = driver default</span>
          <input name="power_port" type="number" min="1" max="65535" value="<?= e((string) ($s['power_port'] ?? '')) ?>"></label>
        <label>Power MAC <span class="hint">for Wake-on-LAN</span>
          <input name="power_mac" value="<?= e($s['power_mac'] ?? '') ?>" placeholder="aa:bb:cc:dd:ee:ff"></label>
        <button>Save screen</button>
      </form>
    </section>

    <?php if ($s):
        $url = 'player.php?token=' . $s['token']; ?>
    <section class="card">
      <h2>Player</h2>
      <p>Point the monitor's kiosk browser at:</p>
      <p><code class="copy big" data-copy="<?= e($url) ?>"><?= e($url) ?></code> <span class="hint">click to copy the full URL</span></p>
      <p class="muted">Last heartbeat: <?= $s['last_seen'] ? date('d M Y H:i:s', (int) $s['last_seen']) : 'never' ?>
        <?php if ($s['player_info']): ?> · <?= e(mb_strimwidth($s['player_info'], 0, 120, '…')) ?><?php endif ?></p>
      <div class="row">
        <form method="post" onsubmit="return confirm('Generate a new URL? The kiosk must be repointed.')">
          <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="screen_token">
          <input type="hidden" name="id" value="<?= $s['id'] ?>">
          <button class="ghost">Regenerate URL</button>
        </form>
        <form method="post" onsubmit="return confirm('Delete this screen and its schedules?')">
          <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="screen_delete">
          <input type="hidden" name="id" value="<?= $s['id'] ?>">
          <button class="danger">Delete screen</button>
        </form>
      </div>
    </section>

    <section class="card">
      <h2>Kiosk control</h2>
      <?php if ($s['pending_command']): ?>
        <p class="hint"><?= e($s['pending_command'] === 'reboot' ? 'Reboot' : 'Kiosk restart') ?>
          queued at <?= date('d M Y H:i:s', (int) $s['pending_command_at']) ?> — waiting for the device to check in.</p>
      <?php endif ?>
      <p class="muted">Requires the companion agent running on the device (see
        <code>agent/README.md</code>). Not supported on Tizen SoC displays.</p>
      <div class="row">
        <form method="post" onsubmit="return confirm('Restart the kiosk browser/process on this device? The screen will go blank briefly.')">
          <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="screen_command">
          <input type="hidden" name="command" value="restart_kiosk"><input type="hidden" name="id" value="<?= $s['id'] ?>">
          <button class="ghost">Restart kiosk</button>
        </form>
        <form method="post" onsubmit="return confirm('Reboot the physical device? It will be offline until it powers back on.')">
          <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="screen_command">
          <input type="hidden" name="command" value="reboot"><input type="hidden" name="id" value="<?= $s['id'] ?>">
          <button class="danger">Reboot device</button>
        </form>
      </div>
    </section>

    <section class="card">
      <h2>Panel power</h2>
      <?php
      $pwrOn = power_should_be_on((int) $s['id']);
      $pwrSch = $pdo->prepare('SELECT * FROM power_schedules WHERE screen_id=? ORDER BY start_time');
      $pwrSch->execute([$s['id']]);
      $pwrSch = $pwrSch->fetchAll(); ?>
      <p class="muted">
        <?php if (!$pwrSch): ?>
          No power windows set — the panel is never told to switch off.
        <?php else: ?>
          Should currently be <strong><?= $pwrOn ? 'ON' : 'OFF' ?></strong> per the windows below.
        <?php endif ?>
        <?php if ($s['power_driver'] === ''): ?>
          <span class="hint">Power control is set to "Not managed" above — nothing will actually send on/off commands yet.</span>
        <?php endif ?>
      </p>
      <?php if ($pwrSch): ?>
      <table>
        <tr><th>Days</th><th>On from</th><th>Off at</th><th></th></tr>
        <?php foreach ($pwrSch as $r): ?>
        <tr>
          <td><?= dow_label((int) $r['dow_mask']) ?></td>
          <td><?= e($r['start_time']) ?></td>
          <td><?= e($r['end_time']) ?><?= $r['start_time'] > $r['end_time'] ? ' <span class="hint">(overnight)</span>' : '' ?></td>
          <td>
            <form method="post"><input type="hidden" name="csrf" value="<?= $csrf ?>">
              <input type="hidden" name="action" value="power_schedule_delete">
              <input type="hidden" name="id" value="<?= $r['id'] ?>">
              <input type="hidden" name="screen_id" value="<?= $s['id'] ?>">
              <button class="danger sm">remove</button></form>
          </td>
        </tr>
        <?php endforeach ?>
      </table>
      <?php endif ?>

      <h3>Add power window</h3>
      <form method="post" class="grid">
        <input type="hidden" name="csrf" value="<?= $csrf ?>">
        <input type="hidden" name="action" value="power_schedule_add">
        <input type="hidden" name="screen_id" value="<?= $s['id'] ?>">
        <label>On from <input type="time" name="start_time" value="08:00" required></label>
        <label>Off at <span class="hint">earlier than "On from" = overnight</span>
          <input type="time" name="end_time" value="18:00" required></label>
        <fieldset class="wide"><legend>Days</legend>
          <?php foreach (['Mon','Tue','Wed','Thu','Fri','Sat','Sun'] as $i => $d): ?>
            <label class="inline"><input type="checkbox" name="dow[]" value="<?= $i ?>" checked> <?= $d ?></label>
          <?php endforeach ?>
        </fieldset>
        <button>Add power window</button>
      </form>
    </section>

    <section class="card" id="schedules">
      <h2>Schedules</h2>
      <?php
      $sch = $pdo->prepare('SELECT sc.*, p.name AS pname FROM schedules sc
                            JOIN playlists p ON p.id = sc.playlist_id
                            WHERE sc.screen_id=? ORDER BY sc.priority DESC, sc.start_time');
      $sch->execute([$s['id']]);
      $sch = $sch->fetchAll();
      $editScheduleId = (int) ($_GET['edit_schedule'] ?? 0);
      if ($sch): ?>
      <table>
        <tr><th>Playlist</th><th>Days</th><th>Time</th><th>Date range</th><th>Priority</th><th>Rotation</th><th></th></tr>
        <?php foreach ($sch as $r): if ((int) $r['id'] === $editScheduleId): ?>
        <tr>
          <td colspan="7">
            <form method="post" class="grid">
              <input type="hidden" name="csrf" value="<?= $csrf ?>">
              <input type="hidden" name="action" value="schedule_update">
              <input type="hidden" name="id" value="<?= $r['id'] ?>">
              <input type="hidden" name="screen_id" value="<?= $s['id'] ?>">
              <label>Playlist
                <select name="playlist_id"><?php foreach ($allPlaylists as $p): ?>
                  <option value="<?= $p['id'] ?>" <?= (int) $r['playlist_id'] === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option>
                <?php endforeach ?></select>
              </label>
              <label>From <input type="time" name="start_time" value="<?= e($r['start_time']) ?>" required></label>
              <label>Until <span class="hint">earlier than From = overnight</span>
                <input type="time" name="end_time" value="<?= e($r['end_time']) ?>" required></label>
              <label>Priority <span class="hint">higher wins on overlap</span>
                <input type="number" name="priority" value="<?= (int) $r['priority'] ?>" style="width:5em"></label>
              <label>Rotation (s) <span class="hint">min/blank = <?= round(ROTATION_DEFAULT_SECONDS) ?>s</span>
                <input type="number" step="1" min="<?= round(ROTATION_DEFAULT_SECONDS) ?>" name="rotation_seconds"
                       value="<?= e($r['rotation_seconds'] !== null ? (string) (float) $r['rotation_seconds'] : '') ?>"
                       placeholder="<?= round(ROTATION_DEFAULT_SECONDS) ?>" style="width:6em"></label>
              <fieldset class="wide"><legend>Days</legend>
                <?php foreach (['Mon','Tue','Wed','Thu','Fri','Sat','Sun'] as $i => $d): ?>
                  <label class="inline"><input type="checkbox" name="dow[]" value="<?= $i ?>"
                    <?= (int) $r['dow_mask'] >> $i & 1 ? 'checked' : '' ?>> <?= $d ?></label>
                <?php endforeach ?>
              </fieldset>
              <label>Start date <span class="hint">optional</span> <input type="date" name="date_start" value="<?= e($r['date_start'] ?? '') ?>"></label>
              <label>End date <span class="hint">optional, inclusive</span> <input type="date" name="date_end" value="<?= e($r['date_end'] ?? '') ?>"></label>
              <div class="row">
                <button>Save</button>
                <a class="btn ghost" href="index.php?page=screen&id=<?= $s['id'] ?>#schedules">Cancel</a>
              </div>
            </form>
          </td>
        </tr>
        <?php else: ?>
        <tr>
          <td><a href="index.php?page=playlist&id=<?= $r['playlist_id'] ?>"><?= e($r['pname']) ?></a></td>
          <td><?= dow_label((int) $r['dow_mask']) ?></td>
          <td><?= e($r['start_time']) ?>–<?= e($r['end_time']) ?><?= $r['start_time'] > $r['end_time'] ? ' <span class="hint">(overnight)</span>' : '' ?></td>
          <td class="muted"><?= $r['date_start'] || $r['date_end'] ? e(($r['date_start'] ?? '…') . ' → ' . ($r['date_end'] ?? '…')) : 'always' ?></td>
          <td><?= (int) $r['priority'] ?></td>
          <td class="muted"><?= $r['rotation_seconds'] !== null ? (float) $r['rotation_seconds'] . ' s' : round(ROTATION_DEFAULT_SECONDS) . ' s (default)' ?>
            <span class="hint">if tied</span></td>
          <td class="row">
            <a class="btn ghost sm" href="index.php?page=screen&id=<?= $s['id'] ?>&edit_schedule=<?= $r['id'] ?>#schedules">edit</a>
            <form method="post"><input type="hidden" name="csrf" value="<?= $csrf ?>">
              <input type="hidden" name="action" value="schedule_delete">
              <input type="hidden" name="id" value="<?= $r['id'] ?>">
              <input type="hidden" name="screen_id" value="<?= $s['id'] ?>">
              <button class="danger sm">remove</button></form>
          </td>
        </tr>
        <?php endif; endforeach ?>
      </table>
      <?php else: ?><p class="empty">No schedules — the fallback playlist (if set) plays 24/7.</p><?php endif ?>

      <h3>Add schedule</h3>
      <?php if (!$allPlaylists): ?>
        <p class="empty">Create a playlist first.</p>
      <?php else: ?>
      <form method="post" class="grid">
        <input type="hidden" name="csrf" value="<?= $csrf ?>">
        <input type="hidden" name="action" value="schedule_add">
        <input type="hidden" name="screen_id" value="<?= $s['id'] ?>">
        <label>Playlist
          <select name="playlist_id"><?php foreach ($allPlaylists as $p): ?>
            <option value="<?= $p['id'] ?>"><?= e($p['name']) ?></option><?php endforeach ?>
          </select>
        </label>
        <label>From <input type="time" name="start_time" value="09:00" required></label>
        <label>Until <span class="hint">earlier than From = overnight</span>
          <input type="time" name="end_time" value="18:00" required></label>
        <label>Priority <span class="hint">higher wins on overlap</span>
          <input type="number" name="priority" value="0" style="width:5em"></label>
        <label>Rotation (s) <span class="hint">only used if tied with another schedule; min/blank = <?= round(ROTATION_DEFAULT_SECONDS) ?>s</span>
          <input type="number" step="1" min="<?= round(ROTATION_DEFAULT_SECONDS) ?>" name="rotation_seconds" placeholder="<?= round(ROTATION_DEFAULT_SECONDS) ?>" style="width:6em"></label>
        <fieldset class="wide"><legend>Days</legend>
          <?php foreach (['Mon','Tue','Wed','Thu','Fri','Sat','Sun'] as $i => $d): ?>
            <label class="inline"><input type="checkbox" name="dow[]" value="<?= $i ?>" checked> <?= $d ?></label>
          <?php endforeach ?>
        </fieldset>
        <label>Start date <span class="hint">optional</span> <input type="date" name="date_start"></label>
        <label>End date <span class="hint">optional, inclusive</span> <input type="date" name="date_end"></label>
        <button>Add schedule</button>
      </form>
      <?php endif ?>
    </section>
    <?php endif;
    layout_bottom(); exit;
}

/* Playlists overview */
if ($page === 'playlists') {
    layout_top('Playlists'); ?>
    <div class="pagehead">
      <h1>Playlists</h1>
      <form method="post" class="row">
        <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="playlist_save">
        <input name="name" placeholder="New playlist name" required>
        <button>Create</button>
      </form>
    </div>
    <?php
    $rows = $pdo->query('SELECT p.*, COUNT(pi.id) AS n FROM playlists p
                         LEFT JOIN playlist_items pi ON pi.playlist_id = p.id
                         GROUP BY p.id ORDER BY p.name')->fetchAll();
    if ($rows): ?>
    <table>
      <tr><th>Name</th><th>Items</th><th>Updated</th></tr>
      <?php foreach ($rows as $r): ?>
      <tr>
        <td><a href="index.php?page=playlist&id=<?= $r['id'] ?>"><?= e($r['name']) ?></a></td>
        <td><?= (int) $r['n'] ?></td>
        <td class="muted"><?= date('d M Y H:i', (int) $r['updated_at']) ?></td>
      </tr>
      <?php endforeach ?>
    </table>
    <?php else: ?><p class="empty">No playlists yet.</p><?php endif;
    layout_bottom(); exit;
}

/* Playlist detail */
if ($page === 'playlist') {
    $id = (int) ($_GET['id'] ?? 0);
    $q = $pdo->prepare('SELECT * FROM playlists WHERE id=?');
    $q->execute([$id]);
    $pl = $q->fetch();
    if (!$pl) { redirect('index.php?page=playlists'); }

    $items = $pdo->prepare('SELECT pi.*, c.type, c.title, c.size, c.duration AS nat, c.url
                            FROM playlist_items pi JOIN content c ON c.id = pi.content_id
                            WHERE pi.playlist_id=? ORDER BY pi.position, pi.id');
    $items->execute([$id]);
    $items = $items->fetchAll();
    $unsortedId = ensure_unsorted_folder($pdo);
    $folders = $pdo->query('SELECT f.*, COUNT(c.id) AS n FROM folders f
                            JOIN content c ON c.folder_id = f.id
                            GROUP BY f.id ORDER BY f.name')->fetchAll();
    usort($folders, fn ($a, $b) => ((int) $a['id'] === $unsortedId) <=> ((int) $b['id'] === $unsortedId));
    $allContent = $pdo->query('SELECT id, type, title, folder_id FROM content ORDER BY type, title')->fetchAll();
    $contentByFolder = [];
    foreach ($allContent as $c) { $contentByFolder[(int) $c['folder_id']][] = $c; }

    layout_top($pl['name']); ?>
    <div class="pagehead">
      <h1><?= e($pl['name']) ?></h1>
      <div class="row">
        <form method="post" class="row">
          <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="playlist_save">
          <input type="hidden" name="id" value="<?= $pl['id'] ?>">
          <input name="name" value="<?= e($pl['name']) ?>"><button class="ghost">Rename</button>
        </form>
        <form method="post" onsubmit="return confirm('Delete playlist? Schedules using it are removed too.')">
          <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="playlist_delete">
          <input type="hidden" name="id" value="<?= $pl['id'] ?>"><button class="danger">Delete</button>
        </form>
      </div>
    </div>

    <section class="card">
      <h2>Items <span class="hint">played top to bottom, then loops</span></h2>
      <?php if ($items): ?>
      <table>
        <tr><th>#</th><th>Type</th><th>Title</th><th>Duration</th><th>Sound</th><th></th></tr>
        <?php foreach ($items as $i => $it): ?>
        <tr>
          <td><?= $i + 1 ?></td>
          <td><span class="tag <?= e($it['type']) ?>"><?= e($it['type']) ?></span></td>
          <td><?= e($it['title']) ?><?= $it['type'] === 'url' ? ' <span class="muted">' . e($it['url']) . '</span>' : '' ?></td>
          <td>
            <form method="post" class="row">
              <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="item_set_duration">
              <input type="hidden" name="id" value="<?= $it['id'] ?>"><input type="hidden" name="playlist_id" value="<?= $pl['id'] ?>">
              <input type="number" step="0.5" min="1" name="duration" style="width:5em"
                     value="<?= $it['duration_override'] !== null ? (float) $it['duration_override'] : '' ?>"
                     placeholder="<?= $it['type'] === 'video' ? 'full' : (int) cfg('default_duration') ?>">
              <button class="ghost sm">set</button>
            </form>
            <?php if ($it['type'] === 'video' && $it['nat']): ?><span class="hint"><?= round((float) $it['nat']) ?> s natural</span><?php endif ?>
          </td>
          <td><?= $it['type'] === 'video' ? ($it['muted'] ? 'muted' : '🔊 on') : '—' ?></td>
          <td class="row">
            <form method="post"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="item_move">
              <input type="hidden" name="id" value="<?= $it['id'] ?>"><input type="hidden" name="playlist_id" value="<?= $pl['id'] ?>">
              <input type="hidden" name="dir" value="up"><button class="ghost sm" <?= $i === 0 ? 'disabled' : '' ?>>↑</button></form>
            <form method="post"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="item_move">
              <input type="hidden" name="id" value="<?= $it['id'] ?>"><input type="hidden" name="playlist_id" value="<?= $pl['id'] ?>">
              <input type="hidden" name="dir" value="down"><button class="ghost sm" <?= $i === count($items) - 1 ? 'disabled' : '' ?>>↓</button></form>
            <form method="post"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="item_delete">
              <input type="hidden" name="id" value="<?= $it['id'] ?>"><input type="hidden" name="playlist_id" value="<?= $pl['id'] ?>">
              <button class="danger sm">remove</button></form>
          </td>
        </tr>
        <?php endforeach ?>
      </table>
      <?php else: ?><p class="empty">Empty playlist — screens scheduled to it will show black.</p><?php endif ?>

      <h3>Add content</h3>
      <?php if (!$folders): ?><p class="empty">Upload some <a href="index.php?page=content">content</a> first.</p>
      <?php else: foreach ($folders as $f):
        $group = $contentByFolder[(int) $f['id']] ?? []; ?>
      <details class="card foldergroup">
        <summary><?= e($f['name']) ?> <span class="hint">(<?= (int) $f['n'] ?>)</span></summary>
        <div class="details-body">
          <form method="post" class="row">
            <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="item_add_folder">
            <input type="hidden" name="playlist_id" value="<?= $pl['id'] ?>">
            <input type="hidden" name="folder_id" value="<?= $f['id'] ?>">
            <label>Duration (s) <input type="number" step="0.5" min="1" name="duration" placeholder="auto" style="width:6em"></label>
            <label class="inline"><input type="checkbox" name="muted" checked> mute video</label>
            <button>Add all <?= (int) $f['n'] ?> item(s) from this folder</button>
          </form>
          <?php if ($group): ?>
          <table>
            <tr><th>Type</th><th>Title</th><th></th></tr>
            <?php foreach ($group as $c): ?>
            <tr>
              <td><span class="tag <?= e($c['type']) ?>"><?= e($c['type']) ?></span></td>
              <td><?= e($c['title']) ?></td>
              <td>
                <form method="post" class="row">
                  <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="item_add">
                  <input type="hidden" name="playlist_id" value="<?= $pl['id'] ?>">
                  <input type="hidden" name="content_id" value="<?= $c['id'] ?>">
                  <input type="number" step="0.5" min="1" name="duration" placeholder="auto" style="width:6em">
                  <label class="inline"><input type="checkbox" name="muted" checked> mute</label>
                  <button class="ghost sm">add</button>
                </form>
              </td>
            </tr>
            <?php endforeach ?>
          </table>
          <?php endif ?>
        </div>
      </details>
      <?php endforeach; endif ?>
    </section>
    <?php layout_bottom(); exit;
}

/* Content library */
if ($page === 'content') {
    $unsortedId = ensure_unsorted_folder($pdo);
    $folders = $pdo->query('SELECT f.*, COUNT(c.id) AS n FROM folders f
                            LEFT JOIN content c ON c.folder_id = f.id
                            GROUP BY f.id ORDER BY f.name')->fetchAll();
    // Unsorted is the fallback bucket, not a folder the admin made — keep it last.
    usort($folders, fn ($a, $b) => ((int) $a['id'] === $unsortedId) <=> ((int) $b['id'] === $unsortedId));

    $rows = $pdo->query('SELECT c.*, COUNT(pi.id) AS used FROM content c
                         LEFT JOIN playlist_items pi ON pi.content_id = c.id
                         GROUP BY c.id ORDER BY c.type, c.title')->fetchAll();

    $byFolder = [];
    foreach ($rows as $c) { $byFolder[(int) $c['folder_id']][] = $c; }

    // Every content row belongs to a folder now; the dropdowns' "default" option
    // means "Unsorted" rather than listing it a second time as a real choice.
    $pickableFolders = array_values(array_filter($folders, fn ($f) => (int) $f['id'] !== $unsortedId));

    layout_top('Content'); ?>
    <div class="pagehead"><h1>Content</h1></div>

    <div class="cols">
      <section class="card">
        <h2>Upload files</h2>
        <form method="post" enctype="multipart/form-data" id="uploadForm">
          <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="content_upload">
          <input type="file" name="files[]" multiple required
                 accept="<?= e(implode(',', array_keys(cfg('allowed_mime')))) ?>">
          <label>Folder
            <select name="folder_id">
              <option value="">Unsorted (default)</option>
              <?php foreach ($pickableFolders as $f): ?>
                <option value="<?= $f['id'] ?>"><?= e($f['name']) ?></option>
              <?php endforeach ?>
            </select>
          </label>
          <p class="hint">MP4 (H.264) and WebM for video; JPG/PNG/WebP/GIF for images.
             Uploaded in <?= e(human_size(UPLOAD_CHUNK_BYTES)) ?> chunks with resume support, so large video
             isn't limited by the server's per-request size (php.ini) — a dropped connection just picks up
             where it left off.</p>
          <button>Upload</button> <span id="uploadBusy" class="hint" hidden>uploading…</span>
          <div id="uploadList" class="upload-list"></div>
        </form>
      </section>
      <section class="card">
        <h2>Add a web page</h2>
        <form method="post" class="grid">
          <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="content_url">
          <label>Title <input name="title" placeholder="Weather dashboard"></label>
          <label class="wide">URL <input name="url" type="url" placeholder="https://…" required></label>
          <label>Folder
            <select name="folder_id">
              <option value="">Unsorted (default)</option>
              <?php foreach ($pickableFolders as $f): ?>
                <option value="<?= $f['id'] ?>"><?= e($f['name']) ?></option>
              <?php endforeach ?>
            </select>
          </label>
          <p class="hint wide">The page must allow embedding (no <code>X-Frame-Options: deny</code> /
             restrictive CSP) and is skipped automatically while a player is offline.</p>
          <button>Add page</button>
        </form>
      </section>
    </div>

    <section class="card">
      <h2>Folders</h2>
      <table>
        <tr><th>Name</th><th>Items</th><th></th></tr>
        <?php foreach ($folders as $f): ?>
        <tr>
          <td><?= e($f['name']) ?><?= (int) $f['id'] === $unsortedId ? ' <span class="hint">(default, always exists)</span>' : '' ?></td>
          <td><?= (int) $f['n'] ?></td>
          <td>
            <?php if ((int) $f['id'] !== $unsortedId): ?>
            <form method="post" onsubmit="return confirm('Delete folder &quot;<?= e($f['name']) ?>&quot; and all <?= (int) $f['n'] ?> item(s) inside it? This cannot be undone.')">
              <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="folder_delete">
              <input type="hidden" name="id" value="<?= $f['id'] ?>"><button class="danger sm">delete folder + contents</button>
            </form>
            <?php endif ?>
          </td>
        </tr>
        <?php endforeach ?>
      </table>
      <h3>New folder</h3>
      <form method="post" class="row">
        <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="folder_create">
        <input name="name" placeholder="e.g. Lobby loop" required>
        <button>Create folder</button>
      </form>
    </section>

    <?php if (!$rows): ?>
      <p class="empty">Library is empty.</p>
    <?php else:
      foreach ($folders as $f):
        $group = $byFolder[(int) $f['id']] ?? [];
        if (!$group) { continue; } ?>
    <details class="card foldergroup">
      <summary><?= e($f['name']) ?> <span class="hint">(<?= count($group) ?>)</span></summary>
      <div class="details-body">
      <table>
        <tr><th>Type</th><th>Title</th><th>Details</th><th>Used in</th><th>Added</th><th>Folder</th><th></th></tr>
        <?php foreach ($group as $c): ?>
        <tr>
          <td><span class="tag <?= e($c['type']) ?>"><?= e($c['type']) ?></span></td>
          <td><?= e($c['title']) ?></td>
          <td class="muted">
            <?php if ($c['type'] === 'url'): ?><?= e($c['url']) ?>
            <?php else: ?><?= human_size((int) $c['size']) ?><?= $c['duration'] ? ' · ' . round((float) $c['duration']) . ' s' : '' ?>
              · <a href="media.php?f=<?= e($c['filename']) ?>" target="_blank">preview</a><?php endif ?>
          </td>
          <td><?= (int) $c['used'] ?> playlist item(s)</td>
          <td class="muted"><?= date('d M Y', (int) $c['created_at']) ?></td>
          <td>
            <form method="post" class="row">
              <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="content_move_folder">
              <input type="hidden" name="id" value="<?= $c['id'] ?>">
              <select name="folder_id">
                <option value="" <?= (int) $c['folder_id'] === $unsortedId ? 'selected' : '' ?>>Unsorted</option>
                <?php foreach ($pickableFolders as $ff): ?>
                  <option value="<?= $ff['id'] ?>" <?= (int) $c['folder_id'] === (int) $ff['id'] ? 'selected' : '' ?>><?= e($ff['name']) ?></option>
                <?php endforeach ?>
              </select>
              <button class="ghost sm">move</button>
            </form>
          </td>
          <td>
            <form method="post" onsubmit="return confirm('Delete this content? It is removed from all playlists.')">
              <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="content_delete">
              <input type="hidden" name="id" value="<?= $c['id'] ?>"><button class="danger sm">delete</button>
            </form>
          </td>
        </tr>
        <?php endforeach ?>
      </table>
      </div>
    </details>
    <?php endforeach;
    endif;
    layout_bottom(); exit;
}

redirect('index.php');
