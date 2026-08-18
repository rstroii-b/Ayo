// En local (php -S), l'API tourne sur un port séparé. En production, l'API vit sur un
// sous-domaine dédié (ex: api.votredomaine.fr) dont la racine web pointe précisément sur
// api/public/ — vendor/, src/ et .env restent hors de portée du web. Voir le README pour
// la configuration IONOS. ⚠️ Remplacez l'URL ci-dessous par votre vrai sous-domaine avant déploiement.
const API_BASE_URL = ['localhost', '127.0.0.1'].includes(window.location.hostname)
  ? 'http://localhost:8000/api/v1'
  : 'https://api.votredomaine.fr/api/v1';

function getToken() {
  return localStorage.getItem('saveurs_token');
}

export function setToken(token) {
  localStorage.setItem('saveurs_token', token);
}

export function clearToken() {
  localStorage.removeItem('saveurs_token');
}

function idempotencyKey() {
  return crypto.randomUUID();
}

/**
 * Petit client fetch — ajoute automatiquement le Bearer token et, pour les
 * POST, une clé d'idempotence (voir §3 du document d'architecture).
 */
export async function apiFetch(path, { method = 'GET', body = null } = {}) {
  const headers = { 'Content-Type': 'application/json' };
  const token = getToken();
  if (token) headers.Authorization = `Bearer ${token}`;
  if (method === 'POST') headers['Idempotency-Key'] = idempotencyKey();

  const response = await fetch(`${API_BASE_URL}${path}`, {
    method,
    headers,
    body: body ? JSON.stringify(body) : null,
  });

  const data = await response.json().catch(() => null);

  if (!response.ok) {
    const error = new Error(data?.title ?? `Erreur ${response.status}`);
    error.status = response.status;
    error.detail = data?.detail;
    throw error;
  }

  return data;
}
