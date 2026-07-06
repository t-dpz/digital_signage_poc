<?php
/**
 * index.php — admin panel (screens · playlists · content · schedules)
 */
declare(strict_types=1);
require __DIR__ . '/lib.php';

$pdo    = db();
$page   = $_GET['page'] ?? 'screens';
$action = $_POST['action'] ?? null;

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
        if ($id) {
            $pdo->prepare('UPDATE screens SET name=?, notes=?, fallback_playlist_id=?, updated_at=? WHERE id=?')
                ->execute([$name, trim($_POST['notes'] ?? ''), $fallback, now(), $id]);
        } else {
            $pdo->prepare('INSERT INTO screens (name, token, notes, fallback_playlist_id, updated_at, created_at)
                           VALUES (?,?,?,?,?,?)')
                ->execute([$name, bin2hex(random_bytes(16)), trim($_POST['notes'] ?? ''), $fallback, now(), now()]);
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

    case 'schedule_add':
        $mask = 0;
        foreach ($_POST['dow'] ?? [] as $d) { $mask |= 1 << (int) $d; }
        $st = preg_match('/^\d{2}:\d{2}$/', $_POST['start_time'] ?? '') ? $_POST['start_time'] : '00:00';
        $en = preg_match('/^\d{2}:\d{2}$/', $_POST['end_time'] ?? '')   ? $_POST['end_time']   : '24:00';
        $pdo->prepare('INSERT INTO schedules (screen_id, playlist_id, dow_mask, start_time, end_time,
                       date_start, date_end, priority) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([
                (int) $_POST['screen_id'], (int) $_POST['playlist_id'],
                $mask ?: 127, $st, $en,
                ($_POST['date_start'] ?? '') ?: null, ($_POST['date_end'] ?? '') ?: null,
                (int) ($_POST['priority'] ?? 0),
            ]);
        touch_screen((int) $_POST['screen_id']);
        flash('Schedule added.');
        redirect('index.php?page=screen&id=' . (int) $_POST['screen_id']);

    case 'schedule_delete':
        $pdo->prepare('DELETE FROM schedules WHERE id=?')->execute([(int) $_POST['id']]);
        touch_screen((int) $_POST['screen_id']);
        flash('Schedule removed.');
        redirect('index.php?page=screen&id=' . (int) $_POST['screen_id']);

    case 'content_upload':
        $ok = 0; $failed = [];
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
                $pdo->prepare('INSERT INTO content (type, title, filename, mime, size, duration, created_at)
                               VALUES (?,?,?,?,?,?,?)')
                    ->execute([$type, pathinfo($orig, PATHINFO_FILENAME) ?: $name, $name, $mime,
                               filesize($dest), $dur, now()]);
                $ok++;
            }
        }
        flash($ok . ' file(s) uploaded.' . ($failed ? ' Failed: ' . implode(', ', $failed) : ''));
        redirect('index.php?page=content');

    case 'content_url':
        $url = trim($_POST['url'] ?? '');
        if (!preg_match('#^https?://#i', $url)) { flash('URL must start with http(s)://'); redirect('index.php?page=content'); }
        $pdo->prepare('INSERT INTO content (type, title, url, created_at) VALUES (?,?,?,?)')
            ->execute(['url', trim($_POST['title'] ?? '') ?: $url, $url, now()]);
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
          $m = build_manifest($s);
          $active = resolve_active_playlist($m);
          $activeName = null;
          foreach ($allPlaylists as $p) { if ((int) $p['id'] === $active) { $activeName = $p['name']; } }
          $url = 'player.php?token=' . $s['token']; ?>
      <tr>
        <td><span class="dot <?= $online ? 'ok' : '' ?>"></span><?= $online ? 'online' : 'offline' ?></td>
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
      <h2>Schedules</h2>
      <?php
      $sch = $pdo->prepare('SELECT sc.*, p.name AS pname FROM schedules sc
                            JOIN playlists p ON p.id = sc.playlist_id
                            WHERE sc.screen_id=? ORDER BY sc.priority DESC, sc.start_time');
      $sch->execute([$s['id']]);
      $sch = $sch->fetchAll();
      if ($sch): ?>
      <table>
        <tr><th>Playlist</th><th>Days</th><th>Time</th><th>Date range</th><th>Priority</th><th></th></tr>
        <?php foreach ($sch as $r): ?>
        <tr>
          <td><?= e($r['pname']) ?></td>
          <td><?= dow_label((int) $r['dow_mask']) ?></td>
          <td><?= e($r['start_time']) ?>–<?= e($r['end_time']) ?><?= $r['start_time'] > $r['end_time'] ? ' <span class="hint">(overnight)</span>' : '' ?></td>
          <td class="muted"><?= $r['date_start'] || $r['date_end'] ? e(($r['date_start'] ?? '…') . ' → ' . ($r['date_end'] ?? '…')) : 'always' ?></td>
          <td><?= (int) $r['priority'] ?></td>
          <td>
            <form method="post"><input type="hidden" name="csrf" value="<?= $csrf ?>">
              <input type="hidden" name="action" value="schedule_delete">
              <input type="hidden" name="id" value="<?= $r['id'] ?>">
              <input type="hidden" name="screen_id" value="<?= $s['id'] ?>">
              <button class="danger sm">remove</button></form>
          </td>
        </tr>
        <?php endforeach ?>
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
        <label>Until <input type="time" name="end_time" value="18:00" required>
          <span class="hint">earlier than From = overnight</span></label>
        <label>Priority <input type="number" name="priority" value="0" style="width:5em">
          <span class="hint">higher wins on overlap</span></label>
        <fieldset class="wide"><legend>Days</legend>
          <?php foreach (['Mon','Tue','Wed','Thu','Fri','Sat','Sun'] as $i => $d): ?>
            <label class="inline"><input type="checkbox" name="dow[]" value="<?= $i ?>" checked> <?= $d ?></label>
          <?php endforeach ?>
        </fieldset>
        <label>Start date <input type="date" name="date_start"> <span class="hint">optional</span></label>
        <label>End date <input type="date" name="date_end"> <span class="hint">optional, inclusive</span></label>
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
    $allContent = $pdo->query('SELECT id, type, title FROM content ORDER BY type, title')->fetchAll();

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
            <?php if ($it['duration_override'] !== null): ?><?= (float) $it['duration_override'] ?> s
            <?php elseif ($it['type'] === 'video'): ?><span class="muted">full length<?= $it['nat'] ? ' (' . round((float) $it['nat']) . ' s)' : '' ?></span>
            <?php else: ?><span class="muted"><?= (int) cfg('default_duration') ?> s (default)</span><?php endif ?>
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

      <h3>Add item</h3>
      <?php if (!$allContent): ?><p class="empty">Upload some <a href="index.php?page=content">content</a> first.</p>
      <?php else: ?>
      <form method="post" class="grid">
        <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="item_add">
        <input type="hidden" name="playlist_id" value="<?= $pl['id'] ?>">
        <label>Content
          <select name="content_id"><?php foreach ($allContent as $c): ?>
            <option value="<?= $c['id'] ?>">[<?= e($c['type']) ?>] <?= e($c['title']) ?></option><?php endforeach ?>
          </select>
        </label>
        <label>Duration (s) <input type="number" step="0.5" min="1" name="duration" placeholder="auto">
          <span class="hint">videos: blank = full length</span></label>
        <label class="inline"><input type="checkbox" name="muted" checked> mute video</label>
        <button>Add to playlist</button>
      </form>
      <?php endif ?>
    </section>
    <?php layout_bottom(); exit;
}

/* Content library */
if ($page === 'content') {
    $rows = $pdo->query('SELECT c.*, COUNT(pi.id) AS used FROM content c
                         LEFT JOIN playlist_items pi ON pi.content_id = c.id
                         GROUP BY c.id ORDER BY c.created_at DESC')->fetchAll();
    layout_top('Content'); ?>
    <div class="pagehead"><h1>Content</h1></div>

    <div class="cols">
      <section class="card">
        <h2>Upload files</h2>
        <form method="post" enctype="multipart/form-data" id="uploadForm">
          <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="content_upload">
          <input type="file" name="files[]" multiple required
                 accept="<?= e(implode(',', array_keys(cfg('allowed_mime')))) ?>">
          <p class="hint">MP4 (H.264) and WebM for video; JPG/PNG/WebP/GIF for images.
             Max ≈ <?= e(cfg('max_upload_hint')) ?> per request (php.ini).</p>
          <button>Upload</button> <span id="uploadBusy" class="hint" hidden>uploading…</span>
        </form>
      </section>
      <section class="card">
        <h2>Add a web page</h2>
        <form method="post" class="grid">
          <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="content_url">
          <label>Title <input name="title" placeholder="Weather dashboard"></label>
          <label class="wide">URL <input name="url" type="url" placeholder="https://…" required></label>
          <p class="hint wide">The page must allow embedding (no <code>X-Frame-Options: deny</code> /
             restrictive CSP) and is skipped automatically while a player is offline.</p>
          <button>Add page</button>
        </form>
      </section>
    </div>

    <?php if ($rows): ?>
    <table>
      <tr><th>Type</th><th>Title</th><th>Details</th><th>Used in</th><th>Added</th><th></th></tr>
      <?php foreach ($rows as $c): ?>
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
          <form method="post" onsubmit="return confirm('Delete this content? It is removed from all playlists.')">
            <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="content_delete">
            <input type="hidden" name="id" value="<?= $c['id'] ?>"><button class="danger sm">delete</button>
          </form>
        </td>
      </tr>
      <?php endforeach ?>
    </table>
    <?php else: ?><p class="empty">Library is empty.</p><?php endif;
    layout_bottom(); exit;
}

redirect('index.php');
