// Small admin behaviours — no framework, keep it debuggable on demo day.

// Confirm prompts on any element carrying data-confirm.
document.addEventListener('submit', (e) => {
  const msg = e.target.getAttribute('data-confirm');
  if (msg && !window.confirm(msg)) e.preventDefault();
});

document.addEventListener('click', (e) => {
  const el = e.target.closest('a[data-confirm]');
  if (el && !window.confirm(el.getAttribute('data-confirm'))) e.preventDefault();
});

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
