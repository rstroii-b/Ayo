import { login } from '../auth.js';

document.getElementById('login-form').addEventListener('submit', async (event) => {
  event.preventDefault();

  const errorEl = document.getElementById('form-error');
  errorEl.hidden = true;

  const form = new FormData(event.target);

  try {
    const { role } = await login(form.get('email'), form.get('password'));
    const params = new URLSearchParams(window.location.search);
    const roleHome = { restaurant_owner: '/backoffice.html', driver: '/driver.html' }[role] ?? '/index.html';
    window.location.href = params.get('next') ?? roleHome;
  } catch (error) {
    errorEl.textContent = error.status === 401 ? 'Email ou mot de passe incorrect.' : error.message;
    errorEl.hidden = false;
  }
});
