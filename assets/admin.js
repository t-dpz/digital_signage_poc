// Click-to-copy player URLs (expands relative path to a full URL).
document.querySelectorAll('.copy').forEach((el) => {
  el.addEventListener('click', async () => {
    const rel = el.dataset.copy || el.textContent;
    const abs = new URL(rel, location.href).href;
    try {
      await navigator.clipboard.writeText(abs);
      const old = el.textContent;
      el.textContent = 'copied!';
      setTimeout(() => { el.textContent = old; }, 900);
    } catch (_) {
      prompt('Copy the player URL:', abs);
    }
  });
});

// Chunked, resumable upload with a progress bar per file. Falls back to a plain
// multipart form post (server-side `content_upload` action) when fetch/FormData
// aren't available — same form, no JS changes needed on that path.
(() => {
  const form = document.getElementById('uploadForm');
  if (!form || !window.fetch || !window.FormData) { return; }

  const fileInput = form.querySelector('input[type="file"]');
  const folderSelect = form.querySelector('select[name="folder_id"]');
  const csrf = form.querySelector('input[name="csrf"]').value;
  const submitBtn = form.querySelector('button');
  const busyEl = document.getElementById('uploadBusy');
  const listEl = document.getElementById('uploadList');
  const FALLBACK_CHUNK_BYTES = 1024 * 1024;

  form.addEventListener('submit', (ev) => {
    const files = fileInput.files;
    if (!files || !files.length) { return; }
    ev.preventDefault();
    submitBtn.disabled = true;
    busyEl.hidden = false;
    listEl.innerHTML = '';
    uploadAll(Array.from(files), folderSelect ? folderSelect.value : '').finally(() => {
      submitBtn.disabled = false;
      busyEl.hidden = true;
      fileInput.value = '';
    });
  });

  async function uploadAll(files, folderId) {
    for (const file of files) {
      const row = document.createElement('div');
      row.className = 'upload-row';
      const label = document.createElement('span');
      label.className = 'upload-name';
      label.textContent = file.name;
      const bar = document.createElement('progress');
      bar.max = 100;
      bar.value = 0;
      const status = document.createElement('span');
      status.className = 'hint';
      row.append(label, bar, status);
      listEl.appendChild(row);
      try {
        await uploadOne(file, folderId, (pct, msg) => {
          bar.value = pct;
          status.textContent = msg;
        });
        bar.value = 100;
        status.textContent = 'done';
        row.classList.add('upload-ok');
      } catch (err) {
        status.textContent = 'failed — ' + (err && err.message ? err.message : err);
        row.classList.add('upload-fail');
      }
    }
    location.reload();
  }

  async function postJson(body) {
    const res = await fetch('index.php', { method: 'POST', body });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) { throw new Error(data.error || ('HTTP ' + res.status)); }
    return data;
  }

  function fields(obj) {
    const fd = new FormData();
    for (const k in obj) { fd.append(k, obj[k]); }
    return fd;
  }

  async function uploadOne(file, folderId, onProgress) {
    const storeKey = 'signage-upload:' + file.name + ':' + file.size + ':' + (file.lastModified || 0);
    let uploadId = localStorage.getItem(storeKey);
    let chunkSize = FALLBACK_CHUNK_BYTES;
    let received = new Set();

    if (uploadId) {
      try {
        const st = await postJson(fields({ action: 'content_upload_status', csrf, upload_id: uploadId }));
        received = new Set(st.received || []);
      } catch (_) {
        uploadId = null; // server no longer knows this upload (swept, or a fresh install) — restart it
      }
    }
    if (!uploadId) {
      const init = await postJson(fields({
        action: 'content_upload_init', csrf, filename: file.name, size: String(file.size), folder_id: folderId,
      }));
      uploadId = init.upload_id;
      chunkSize = init.chunk_size;
      localStorage.setItem(storeKey, uploadId);
    }

    const total = Math.ceil(file.size / chunkSize) || 1;
    for (let i = 0; i < total; i++) {
      if (received.has(i)) {
        onProgress(Math.round(((i + 1) / total) * 100), `resuming… ${i + 1}/${total}`);
        continue;
      }
      const blob = file.slice(i * chunkSize, Math.min((i + 1) * chunkSize, file.size));
      const fd = fields({ action: 'content_upload_chunk', csrf, upload_id: uploadId, index: String(i) });
      fd.append('chunk', blob, 'chunk.bin');
      for (let attempt = 1; ; attempt++) {
        try {
          await postJson(fd);
          break;
        } catch (err) {
          if (attempt >= 3) { throw err; }
          await new Promise((resolve) => setTimeout(resolve, 800 * attempt));
        }
      }
      onProgress(Math.round(((i + 1) / total) * 100), `${i + 1}/${total} chunks`);
    }

    await postJson(fields({ action: 'content_upload_finish', csrf, upload_id: uploadId }));
    localStorage.removeItem(storeKey);
  }
})();
