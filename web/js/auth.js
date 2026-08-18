import { apiFetch, setToken, clearToken } from './api.js';

const USER_KEY = 'ayo_user';

export function currentUser() {
  try {
    return JSON.parse(localStorage.getItem(USER_KEY));
  } catch {
    return null;
  }
}

export function isLoggedIn() {
  return currentUser() !== null;
}

async function persistSession(token) {
  setToken(token);
  // /auth/me est la source de vérité pour le profil (nom, email...) — jamais dans le JWT.
  const profile = await apiFetch('/auth/me');
  localStorage.setItem(USER_KEY, JSON.stringify(profile));

  return profile;
}

export async function login(email, password) {
  const data = await apiFetch('/auth/login', { method: 'POST', body: { email, password } });

  return persistSession(data.token);
}

export async function register(payload) {
  const data = await apiFetch('/auth/register', { method: 'POST', body: payload });

  return persistSession(data.token);
}

export function logout() {
  clearToken();
  localStorage.removeItem(USER_KEY);
}

/** Redirige vers la connexion si non authentifié — à appeler en haut des pages protégées. */
export function requireLogin(redirectTo = window.location.pathname + window.location.search) {
  if (!isLoggedIn()) {
    window.location.href = `/login.html?next=${encodeURIComponent(redirectTo)}`;

    return false;
  }

  return true;
}
