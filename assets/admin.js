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

// Show busy state during (potentially long) uploads.
const uf = document.getElementById('uploadForm');
if (uf) {
  uf.addEventListener('submit', () => {
    uf.querySelector('button').disabled = true;
    document.getElementById('uploadBusy').hidden = false;
  });
}
