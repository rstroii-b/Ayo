import { register } from '../auth.js';

let selectedRole = 'client';

function updateVisibleFields() {
  document.getElementById('siret-field').hidden = selectedRole !== 'driver';
  document.getElementById('vehicule-field').hidden = selectedRole !== 'driver';
}

document.getElementById('role-tabs').addEventListener('click', (event) => {
  const chip = event.target.closest('.chip');
  if (!chip) return;

  document.querySelectorAll('#role-tabs .chip').forEach((c) => c.classList.remove('active'));
  chip.classList.add('active');
  selectedRole = chip.dataset.role;
  updateVisibleFields();
});

document.getElementById('register-form').addEventListener('submit', async (event) => {
  event.preventDefault();

  const errorEl = document.getElementById('form-error');
  errorEl.hidden = true;

  const form = new FormData(event.target);
  const payload = {
    first_name: form.get('first_name'),
    last_name: form.get('last_name'),
    email: form.get('email'),
    password: form.get('password'),
    role: selectedRole,
  };

  if (selectedRole === 'driver') {
    payload.siret = form.get('siret');
    payload.vehicule_type = form.get('vehicule_type');
  }

  try {
    await register(payload);
    const params = new URLSearchParams(window.location.search);
    const roleHome = { restaurant_owner: '/backoffice.html', driver: '/driver.html' }[selectedRole] ?? '/index.html';
    window.location.href = params.get('next') ?? roleHome;
  } catch (error) {
    errorEl.textContent = error.detail ?? error.message;
    errorEl.hidden = false;
  }
});
