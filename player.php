<?php
/**
 * player.php?token=… — the one URL a screen needs.
 *
 * Design goals:
 *  1. Video first. Every file is downloaded once into the browser's Cache
 *     API and played back from local storage as a blob:// URL — playback
 *     never touches the network, so it never stutters because of it.
 *     On the very first run (file not cached yet) the player streams
 *     straight from media.php, which supports Range requests, while the
 *     cache fills in the background.
 *  2. Survive connection drops. The last good manifest lives in
 *     localStorage; media lives in the Cache API. If the network dies the
 *     loop keeps running from cache indefinitely (web-page items are
 *     skipped while offline).
 *  3. Seamless transitions. Two stacked layers; the next item is created
 *     and preloaded in the hidden layer before the current one ends, then
 *     cross-faded in.
 */
declare(strict_types=1);
require __DIR__ . '/lib.php';

$screen = screen_by_token((string) ($_GET['token'] ?? ''));
if (!$screen) {
    http_response_code(403);
    exit('Unknown screen token.');
}
$boot = [
    'token'   => $screen['token'],
    'refresh' => (int) cfg('player_refresh'),
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($screen['name']) ?> — signage</title>
<style>
  html, body { margin:0; padding:0; width:100%; height:100%; background:#000; overflow:hidden; cursor:none; }
  .layer { position:absolute; inset:0; opacity:0; transition:opacity .6s ease; background:#000; }
  .layer.active { opacity:1; }
  .layer video, .layer img { width:100%; height:100%; object-fit:contain; display:block; background:#000; }
  .layer iframe { width:100%; height:100%; border:0; display:block; background:#000; }
  #status { position:fixed; right:12px; bottom:10px; font:12px/1.4 monospace; color:#555;
            opacity:0; transition:opacity .3s; pointer-events:none; z-index:9; }
  #status.visible { opacity:1; }
  #idle { position:absolute; inset:0; display:none; align-items:center; justify-content:center;
          color:#333; font:16px monospace; }
  .layer .takeover-page {
    width:100%; height:100%; display:flex; flex-direction:column; align-items:center;
    justify-content:center; gap:5vh; box-sizing:border-box; padding:6vh 8vw; text-align:center;
    background:#000; color:#fff;
    font-family: ui-sans-serif, system-ui, sans-serif, "Apple Color Emoji", "Segoe UI Emoji",
                 "Segoe UI Symbol", "Noto Color Emoji";
    font-size: 3.5vw;
  }
  .layer .takeover-logo { width:33.333333%; height:auto; max-height:40vh; object-fit:contain; flex:0 0 auto; }
  .layer .takeover-body { max-width:100%; }
  .layer .takeover-body ul, .layer .takeover-body ol { display:inline-block; text-align:left; margin:0; }
</style>
</head>
<body>
<div class="layer" id="layerA"></div>
<div class="layer" id="layerB"></div>
<div id="idle">no content scheduled</div>
<div id="status"></div>

<script>
'use strict';
const BOOT = <?= json_encode($boot) ?>;
const CACHE = 'signage-media-v1';
const LS_KEY = 'signage-manifest-' + BOOT.token;

const state = {
  manifest: null,          // active manifest
  pending: null,           // newer manifest, applied at next item boundary
  playlistId: null,
  index: -1,
  layers: [document.getElementById('layerA'), document.getElementById('layerB')],
  front: 0,
  online: true,
  blobUrl: [null, null],   // per-layer blob URL for revocation
  advanceTimer: null,
  watchdog: null,
  caching: false,
  takeoverShown: false,    // true while the takeover page (not a scheduled item) is on screen
  takeoverHtml: null,      // last-rendered takeover body, so live edits can be patched in place
};

/* ------------------------------------------------------------ manifest -- */

async function fetchManifest() {
  const res = await fetch(`api.php?action=manifest&token=${BOOT.token}`, { cache: 'no-store' });
  if (res.status === 304) return null;               // unchanged
  if (!res.ok) throw new Error('manifest HTTP ' + res.status);
  return res.json();
}

async function refreshManifest() {
  try {
    const m = await fetchManifest();
    state.online = true;
    if (m && (!state.manifest || m.hash !== state.manifest.hash)) {
      localStorage.setItem(LS_KEY, JSON.stringify(m));
      if (!state.manifest) { state.manifest = m; }    // first load: use immediately
      else { state.pending = m; }                     // otherwise swap at boundary
      syncCache(m);
    }
  } catch (e) {
    state.online = false;                             // offline: keep playing from cache
    if (!state.manifest) {
      const saved = localStorage.getItem(LS_KEY);
      if (saved) { state.manifest = JSON.parse(saved); syncCache(state.manifest); }
    }
  }
  updateStatus();
}

/* -------------------------------------------------- schedule resolution -- */

const ROTATION_DEFAULT_SECONDS = 120;

function activePlaylistId(m, d = new Date()) {
  const dow  = (d.getDay() + 6) % 7;                  // 0 = Monday
  const yDow = (dow + 6) % 7;
  const t    = String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0');
  const date = d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0')
             + '-' + String(d.getDate()).padStart(2, '0');
  const active = [];
  for (const s of (m.schedules || [])) {
    if (s.from  && date < s.from)  continue;
    if (s.until && date > s.until) continue;
    let hit;
    if (s.start <= s.end) hit = (s.dow >> dow & 1) && t >= s.start && t < s.end;
    else hit = ((s.dow >> dow & 1) && t >= s.start) || ((s.dow >> yDow & 1) && t < s.end);
    if (hit) active.push(s);
  }
  if (!active.length) return m.fallback_playlist;
  // Highest priority wins; if several schedules are tied (same day/time/priority),
  // rotate between them by wall-clock time — mirrors PHP resolve_active_playlist()/rotate_tied().
  const top  = Math.max(...active.map(s => s.priority));
  const tied = active.filter(s => s.priority === top);
  if (tied.length === 1) return tied[0].playlist;
  const durations = tied.map(s => s.rotation ?? ROTATION_DEFAULT_SECONDS);
  const total = durations.reduce((a, b) => a + b, 0);
  if (total <= 0) return tied[0].playlist;
  const cursor = Math.floor(d.getTime() / 1000) % total;
  let acc = 0;
  for (let i = 0; i < tied.length; i++) {
    acc += durations[i];
    if (cursor < acc) return tied[i].playlist;
  }
  return tied[tied.length - 1].playlist;
}

/** Strips tags to check for real text — mirrors PHP takeover_content_empty(). */
function takeoverEmpty(html) {
  const tmp = document.createElement('div');
  tmp.innerHTML = html || '';
  return (tmp.textContent || '').trim() === '';
}

/**
 * Whether the takeover page should be showing right now — mirrors PHP
 * takeover_active(). An empty schedule list means "always on" (while enabled
 * and non-empty), since the takeover only exists once an admin has enabled it.
 */
function takeoverActive(m, d = new Date()) {
  const t = m.takeover;
  if (!t || !t.enabled || takeoverEmpty(t.html)) return false;
  if (!t.schedule || !t.schedule.length) return true;
  const dow  = (d.getDay() + 6) % 7;
  const yDow = (dow + 6) % 7;
  const time = String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0');
  for (const s of t.schedule) {
    const hit = s.start <= s.end
      ? (s.dow >> dow & 1) && time >= s.start && time < s.end
      : ((s.dow >> dow & 1) && time >= s.start) || ((s.dow >> yDow & 1) && time < s.end);
    if (hit) return true;
  }
  return false;
}

/* ------------------------------------------------------- media caching -- */

function allFileItems(m) {
  const out = new Map();
  for (const items of Object.values(m.playlists || {}))
    for (const it of items)
      if (it.file) out.set(it.src, it);
  return out;
}

async function syncCache(m) {
  if (state.caching || !('caches' in window)) return;
  state.caching = true;
  try {
    const cache = await caches.open(CACHE);
    const wanted = allFileItems(m);
    // Download anything missing, one at a time (kind to Pi-class hardware).
    for (const [src] of wanted) {
      if (await cache.match(src)) continue;
      try {
        const res = await fetch(src, { cache: 'no-store' });
        if (res.ok && res.status === 200) await cache.put(src, res);
      } catch (_) { break; }                          // offline — retry next refresh
    }
    // Evict files no longer referenced by any playlist.
    for (const req of await cache.keys()) {
      const rel = req.url.slice(req.url.indexOf('media.php'));
      if (!wanted.has(rel)) await cache.delete(req);
    }
  } finally {
    state.caching = false;
    updateStatus();
  }
}

/** Local blob URL when cached; falls back to streaming from the server. */
async function sourceFor(item, layerIdx) {
  if (item.file && 'caches' in window) {
    const hit = await caches.open(CACHE).then(c => c.match(item.src)).catch(() => null);
    if (hit) {
      const url = URL.createObjectURL(await hit.blob());
      if (state.blobUrl[layerIdx]) URL.revokeObjectURL(state.blobUrl[layerIdx]);
      state.blobUrl[layerIdx] = url;
      return { url, cached: true };
    }
  }
  if (state.blobUrl[layerIdx]) { URL.revokeObjectURL(state.blobUrl[layerIdx]); state.blobUrl[layerIdx] = null; }
  return { url: item.src, cached: false };
}

/* ------------------------------------------------------------ playback -- */

function currentItems() {
  const m = state.manifest;
  if (!m) return [];
  const pid = activePlaylistId(m);
  if (pid !== state.playlistId) { state.playlistId = pid; state.index = -1; }
  return (pid != null && m.playlists[pid]) ? m.playlists[pid] : [];
}

function clearTimers() {
  clearTimeout(state.advanceTimer);
  clearInterval(state.watchdog);
}

async function advance() {
  clearTimers();

  if (state.pending) {                                // apply updates at boundaries
    state.manifest = state.pending;
    state.pending = null;
    state.index = -1;
  }

  // Takeover pre-empts every schedule outright — checked before (and instead
  // of) normal item resolution, same boundary-only cadence as manifest swaps.
  if (state.manifest && takeoverActive(state.manifest)) {
    showTakeover();
    state.advanceTimer = setTimeout(advance, 5000);   // recheck until it lapses
    return;
  }
  state.takeoverShown = false;

  let items = currentItems();
  document.getElementById('idle').style.display = items.length ? 'none' : 'flex';
  if (!items.length) { state.advanceTimer = setTimeout(advance, 5000); return; }

  // Pick next playable item (skip web pages while offline).
  let tries = items.length;
  let item = null;
  while (tries-- > 0) {
    state.index = (state.index + 1) % items.length;
    const cand = items[state.index];
    if (cand.type === 'url' && !state.online && !navigator.onLine) continue;
    item = cand;
    break;
  }
  if (!item) { state.advanceTimer = setTimeout(advance, 5000); return; }

  const back = 1 - state.front;
  const layer = state.layers[back];
  layer.innerHTML = '';

  const show = () => {
    state.layers[back].classList.add('active');
    state.layers[state.front].classList.remove('active');
    const old = state.layers[state.front];
    setTimeout(() => { if (!old.classList.contains('active')) old.innerHTML = ''; }, 700);
    state.front = back;
  };

  try {
    if (item.type === 'video')      await playVideo(item, layer, show);
    else if (item.type === 'image') await showImage(item, layer, show);
    else                            showUrl(item, layer, show);
  } catch (_) {
    state.advanceTimer = setTimeout(advance, 1000);   // broken item → skip on
  }
  updateStatus();
}

/** Renders the takeover page into the hidden layer and cross-fades it in, same
 *  as any other item — but idempotent, since advance() re-enters this every
 *  5s for as long as takeover stays active. Already-shown calls just patch the
 *  text in place (no re-fade) so an admin editing a live takeover sees it
 *  update within one recheck cycle instead of only on the next activation. */
function showTakeover() {
  document.getElementById('idle').style.display = 'none';
  const html = state.manifest.takeover.html;

  if (state.takeoverShown) {
    if (html !== state.takeoverHtml) {
      state.takeoverHtml = html;
      const body = state.layers[state.front].querySelector('.takeover-body');
      if (body) body.innerHTML = html;
    }
    updateStatus();
    return;
  }
  state.takeoverShown = true;
  state.takeoverHtml = html;
  state.playlistId = null;
  state.index = -1;

  const back = 1 - state.front;
  const layer = state.layers[back];
  if (state.blobUrl[back]) { URL.revokeObjectURL(state.blobUrl[back]); state.blobUrl[back] = null; }
  layer.innerHTML = '';
  const wrap = document.createElement('div');
  wrap.className = 'takeover-page';
  const logo = document.createElement('img');
  logo.className = 'takeover-logo';
  logo.src = 'assets/logo_white.png';
  logo.alt = '';
  const body = document.createElement('div');
  body.className = 'takeover-body';
  body.innerHTML = html;
  wrap.append(logo, body);
  layer.appendChild(wrap);

  state.layers[back].classList.add('active');
  state.layers[state.front].classList.remove('active');
  const old = state.layers[state.front];
  setTimeout(() => { if (!old.classList.contains('active')) old.innerHTML = ''; }, 700);
  state.front = back;
  updateStatus();
}

async function playVideo(item, layer, show) {
  const { url } = await sourceFor(item, state.layers.indexOf(layer));
  const v = document.createElement('video');
  v.muted = item.muted !== false;                     // autoplay requires muted
  v.autoplay = false;
  v.playsInline = true;
  v.preload = 'auto';
  v.src = url;
  layer.appendChild(v);

  let done = false;
  const next = () => { if (!done) { done = true; advance(); } };

  v.addEventListener('ended', next);
  v.addEventListener('error', () => setTimeout(next, 500));

  // Trim / extend via duration override.
  if (item.duration) {
    v.addEventListener('timeupdate', () => { if (v.currentTime >= item.duration) next(); });
  }

  // Watchdog: if playback position stops moving for 15 s, move on.
  let lastT = -1, stuck = 0;
  state.watchdog = setInterval(() => {
    if (done) return;
    if (v.currentTime === lastT) { if (++stuck >= 3) next(); }
    else { stuck = 0; lastT = v.currentTime; }
  }, 5000);

  await new Promise((res, rej) => {
    v.addEventListener('canplay', res, { once: true });
    v.addEventListener('error', rej, { once: true });
    setTimeout(res, 8000);                            // don't hang forever on slow starts
  });
  try { await v.play(); } catch (_) { /* muted autoplay should never throw */ }
  show();
}

async function showImage(item, layer, show) {
  const { url } = await sourceFor(item, state.layers.indexOf(layer));
  const img = document.createElement('img');
  img.src = url;
  layer.appendChild(img);
  await new Promise((res) => {
    img.addEventListener('load', res, { once: true });
    img.addEventListener('error', res, { once: true });
    setTimeout(res, 5000);
  });
  show();
  state.advanceTimer = setTimeout(advance, (item.duration || 15) * 1000);
}

function embeddableUrl(url) {
  const m = url.match(/(?:youtube(?:-nocookie)?\.com\/(?:watch\?(?:.*&)?v=|embed\/|shorts\/|live\/)|youtu\.be\/)([a-zA-Z0-9_-]{11})/);
  if (!m) return url;
  const id = m[1];
  return `https://www.youtube-nocookie.com/embed/${id}?autoplay=1&mute=1&controls=0&modestbranding=1&playsinline=1&rel=0&loop=1&playlist=${id}`;
}

function showUrl(item, layer, show) {
  const f = document.createElement('iframe');
  f.src = embeddableUrl(item.url);
  f.setAttribute('allow', 'autoplay; encrypted-media; picture-in-picture');
  f.setAttribute('sandbox', 'allow-scripts allow-same-origin allow-forms allow-presentation');
  layer.appendChild(f);
  // load and the 6s fallback race each other — guard so show() (which mutates
  // state.front) never fires twice, or the second call blanks the active layer.
  let shown = false;
  const showOnce = () => { if (!shown) { shown = true; show(); } };
  f.addEventListener('load', showOnce, { once: true });
  setTimeout(showOnce, 6000);                         // show anyway if load never fires
  state.advanceTimer = setTimeout(advance, (item.duration || 15) * 1000);
}

/* ----------------------------------------------------------- liveness -- */

function heartbeat() {
  const items = (state.manifest && !state.takeoverShown) ? currentItems() : [];
  const cur = state.index >= 0 && items[state.index] ? items[state.index] : null;
  const body = JSON.stringify({
    ua: navigator.userAgent,
    res: `${screen.width}x${screen.height}`,
    playlist: state.takeoverShown ? null : state.playlistId,
    item: state.takeoverShown ? 'Takeover page' : (cur ? cur.title : null),
    type: state.takeoverShown ? 'takeover' : (cur ? cur.type : null),
  });
  fetch(`api.php?action=heartbeat&token=${BOOT.token}`, { method: 'POST', body })
    .then(() => { state.online = true; })
    .catch(() => { state.online = false; })
    .finally(updateStatus);
  captureScreenshot();
}

/**
 * Draws whatever's on the front layer to a small canvas and ships it to the
 * admin panel's thumbnail. Only video/img elements are same-origin (blob:
 * URLs or media.php) and thus readable by canvas; web-page (iframe) items
 * are silently skipped, leaving the last known screenshot in place. The
 * takeover page is skipped too — it's synthetic HTML (its only <img> is the
 * small logo), not a full-screen frame worth capturing.
 */
function captureScreenshot() {
  if (state.takeoverShown) return;
  const layer = state.layers[state.front];
  const el = layer && layer.querySelector('video, img');
  if (!el) return;
  const srcW = el.videoWidth || el.naturalWidth || el.clientWidth || 1920;
  const srcH = el.videoHeight || el.naturalHeight || el.clientHeight || 1080;
  if (!srcW || !srcH) return;
  const scale = Math.min(1, 480 / srcW);
  const canvas = document.createElement('canvas');
  canvas.width = Math.round(srcW * scale);
  canvas.height = Math.round(srcH * scale);
  try {
    canvas.getContext('2d').drawImage(el, 0, 0, canvas.width, canvas.height);
  } catch (_) { return; }                             // paranoia: tainted canvas
  canvas.toBlob((blob) => {
    if (!blob) return;
    fetch(`api.php?action=screenshot&token=${BOOT.token}`, { method: 'POST', body: blob }).catch(() => {});
  }, 'image/jpeg', 0.6);
}

function updateStatus() {
  const el = document.getElementById('status');
  el.textContent = state.online ? '' : 'offline — playing from cache';
  el.classList.toggle('visible', !state.online);
}

async function keepAwake() {
  try { if ('wakeLock' in navigator) await navigator.wakeLock.request('screen'); }
  catch (_) {}
}
document.addEventListener('visibilitychange', () => {
  if (document.visibilityState === 'visible') keepAwake();
});

/* --------------------------------------------------------------- boot -- */

(async function boot() {
  keepAwake();
  await refreshManifest();
  if (!state.manifest) {                              // nothing yet: retry until first manifest
    const retry = setInterval(async () => {
      await refreshManifest();
      if (state.manifest) { clearInterval(retry); advance(); }
    }, 5000);
  } else {
    advance();
  }
  setInterval(refreshManifest, BOOT.refresh * 1000);
  setInterval(heartbeat, BOOT.refresh * 1000);
  heartbeat();
  window.addEventListener('online',  () => { state.online = true;  refreshManifest(); });
  window.addEventListener('offline', () => { state.online = false; updateStatus(); });
})();
</script>
</body>
</html>
