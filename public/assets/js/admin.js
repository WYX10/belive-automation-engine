// Small admin behaviours — no framework, keep it debuggable on demo day.

// Confirm prompts on any element carrying data-confirm.
document.addEventListener('submit', (e) => {
  const msg = e.target.getAttribute('data-confirm');
  if (msg && !window.confirm(msg)) e.preventDefault();
});

// Catch an oversized room photo here rather than letting the browser POST it.
// Past the web server's body limit nginx answers with its own bare "413
// Request Entity Too Large" page, which throws away the whole admin screen and
// never explains what to do — so say it in place, before anything is sent.
const PHOTO_MAX_BYTES = 5 * 1024 * 1024; // matches RoomPhotoManager::MAX_BYTES
document.addEventListener('change', (e) => {
  const input = e.target;
  if (!(input instanceof HTMLInputElement) || input.type !== 'file') return;

  const tooBig = Array.from(input.files || []).filter((f) => f.size > PHOTO_MAX_BYTES);
  const hint = input.getAttribute('aria-describedby');
  const hintEl = hint ? document.getElementById(hint) : null;

  // Remember the hint's own wording once, so clearing the error restores it
  // instead of leaving the complaint on screen next to a valid file.
  if (hintEl && hintEl.dataset.defaultHint === undefined) {
    hintEl.dataset.defaultHint = hintEl.textContent;
  }

  if (tooBig.length === 0) {
    input.setCustomValidity('');
    if (hintEl) {
      hintEl.textContent = hintEl.dataset.defaultHint;
      hintEl.classList.remove('hint-error');
    }
    return;
  }

  const mb = (tooBig[0].size / 1048576).toFixed(1);
  const message = `That photo is ${mb} MB — the limit is 5 MB. Resize it, or export it as JPG at a smaller size, then choose it again.`;
  input.setCustomValidity(message);
  if (hintEl) {
    hintEl.textContent = message;
    hintEl.classList.add('hint-error');
  }
  input.reportValidity();
});

document.addEventListener('click', (e) => {
  const el = e.target.closest('a[data-confirm]');
  if (el && !window.confirm(el.getAttribute('data-confirm'))) e.preventDefault();
});

// Post preview: the approve button says what it is about to do, because
// "Approve" meaning "publish this second" and "Approve" meaning "queue this for
// Sunday 21:00" must not look identical. Server-side the choice is the radio
// value, so this is labelling only — it works with JS off, just less clearly.
const scheduleForm = document.querySelector('#schedule-form');
if (scheduleForm) {
  const submit = scheduleForm.querySelector('#schedule-submit');
  const custom = scheduleForm.querySelector('input[name="schedule_custom"]');
  const sync = () => {
    const chosen = scheduleForm.querySelector('input[name="schedule_when"]:checked');
    if (chosen) submit.textContent = chosen.getAttribute('data-label') || 'Approve this post';
  };
  // Reaching for the date field is itself the choice. (A nested input does not
  // activate its wrapping label, so the radio has to be ticked here.)
  if (custom) custom.addEventListener('focus', () => {
    const radio = scheduleForm.querySelector('input[name="schedule_when"][value="custom"]');
    if (radio) { radio.checked = true; sync(); }
  });
  scheduleForm.addEventListener('change', sync);
  sync();
}

// Credentials form: show the extra-field block matching the selected service
// (blocks carry data-service-fields="whatsapp" / "meta_graph" / "tiktok").
const serviceSelect = document.querySelector('#credential-service');
if (serviceSelect) {
  const blocks = document.querySelectorAll('[data-service-fields]');
  const sync = () => {
    blocks.forEach((b) => {
      b.style.display = b.getAttribute('data-service-fields') === serviceSelect.value ? '' : 'none';
    });
  };
  serviceSelect.addEventListener('change', sync);
  sync();
}
