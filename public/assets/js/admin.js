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

// Credentials form: show WhatsApp-specific fields only for the whatsapp service.
const serviceSelect = document.querySelector('#credential-service');
if (serviceSelect) {
  const waFields = document.querySelector('#whatsapp-extra-fields');
  const sync = () => {
    if (waFields) waFields.style.display = serviceSelect.value === 'whatsapp' ? '' : 'none';
  };
  serviceSelect.addEventListener('change', sync);
  sync();
}
