import { login } from '../auth.js';
import { safeInternalPath } from '../format.js';
import { webauthnSupported, loginWithPasskey } from '../webauthn.js';

const ROLE_HOME = { restaurant_owner: '/backoffice.html', driver: '/driver.html', admin: '/admin.html' };

function redirectAfterLogin(role) {
  const params = new URLSearchParams(window.location.search);
  window.location.href = safeInternalPath(params.get('next'), ROLE_HOME[role] ?? '/index.html');
}

document.getElementById('login-form').addEventListener('submit', async (event) => {
  event.preventDefault();

  const errorEl = document.getElementById('form-error');
  errorEl.hidden = true;

  const form = new FormData(event.target);

  try {
    const { role } = await login(form.get('email'), form.get('password'));
    redirectAfterLogin(role);
  } catch (error) {
    errorEl.textContent = error.status === 401 ? 'Email ou mot de passe incorrect.' : error.message;
    errorEl.hidden = false;
  }
});

if (webauthnSupported()) {
  const passkeyBtn = document.getElementById('passkey-btn');
  passkeyBtn.hidden = false;

  passkeyBtn.addEventListener('click', async () => {
    const errorEl = document.getElementById('form-error');
    errorEl.hidden = true;

    const email = document.getElementById('email').value.trim() || prompt('Ton email pour retrouver ta clé biométrique :');
    if (!email) return;

    try {
      const { role } = await loginWithPasskey(email);
      redirectAfterLogin(role);
    } catch (error) {
      if (error.name === 'NotAllowedError') return; // l'utilisateur a annulé — pas d'erreur à afficher
      errorEl.textContent = 'Connexion biométrique impossible. Utilise ton mot de passe.';
      errorEl.hidden = false;
    }
  });
}
