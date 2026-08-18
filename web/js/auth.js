import { apiFetch, setToken, clearToken } from './api.js';

const USER_KEY = 'saveurs_user';

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

function persistSession(userId, role, token) {
  setToken(token);
  localStorage.setItem(USER_KEY, JSON.stringify({ id: userId, role }));
}

export async function login(email, password) {
  const data = await apiFetch('/auth/login', { method: 'POST', body: { email, password } });
  // Le rôle n'est pas renvoyé par /auth/login — décodé depuis le payload du JWT (non signé côté client, affichage seulement).
  const role = JSON.parse(atob(data.token.split('.')[1])).role;
  persistSession(data.user_id, role, data.token);

  return { userId: data.user_id, role };
}

export async function register(payload) {
  const data = await apiFetch('/auth/register', { method: 'POST', body: payload });
  persistSession(data.user_id, payload.role, data.token);

  return { userId: data.user_id, role: payload.role };
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
