// En local (php -S), l'API tourne sur un port séparé. En production, l'API vit sur le
// sous-domaine dédié api-ayo.jobivoire.com, dont la racine web pointe précisément sur
// api/public/ — vendor/, src/ et .env restent hors de portée du web.
const API_BASE_URL = ['localhost', '127.0.0.1'].includes(window.location.hostname)
  ? 'http://localhost:8000/api/v1'
  : 'https://api-ayo.jobivoire.com/api/v1';

export function getToken() {
  return localStorage.getItem('saveurs_token');
}

export function setToken(token) {
  localStorage.setItem('saveurs_token', token);
  scheduleRefresh(token);
}

export function clearToken() {
  localStorage.removeItem('saveurs_token');
  clearTimeout(refreshTimer);
}

function idempotencyKey() {
  return crypto.randomUUID();
}

/**
 * Rafraîchissement silencieux du token — sans ça, le JWT (30 min) expire pendant qu'un client
 * navigue/hésite, et "Commander" échoue avec une erreur "Jeton invalide ou expiré" (constaté
 * en prod). On décode juste le payload du JWT (pas de vérification de signature nécessaire,
 * c'est uniquement pour planifier le prochain rafraîchissement) pour viser 5 min avant
 * l'expiration réelle plutôt qu'un intervalle fixe fragile face à un changement de durée serveur.
 */
let refreshTimer = null;

function decodeJwtExp(token) {
  try {
    const payload = JSON.parse(atob(token.split('.')[1].replace(/-/g, '+').replace(/_/g, '/')));

    return typeof payload.exp === 'number' ? payload.exp : null;
  } catch {
    return null;
  }
}

function scheduleRefresh(token) {
  clearTimeout(refreshTimer);

  const exp = decodeJwtExp(token);
  if (exp === null) return;

  const msUntilRefresh = Math.max(0, exp * 1000 - Date.now() - 5 * 60 * 1000);

  refreshTimer = setTimeout(async () => {
    try {
      const data = await apiFetch('/auth/refresh', { method: 'POST', body: {} });
      setToken(data.token);
    } catch {
      // Le token n'était déjà plus valide (onglet resté ouvert des heures) — rien à faire ici,
      // la prochaine action protégée déclenchera normalement une redemande de connexion.
    }
  }, msUntilRefresh);
}

// Reprend le cycle de rafraîchissement pour un token déjà en localStorage au chargement de la page.
scheduleRefresh(getToken());

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

/** Envoi multipart (upload de fichier) — pas de Content-Type manuel, le navigateur fixe la boundary. */
export async function apiFetchFile(path, formData) {
  const headers = {};
  const token = getToken();
  if (token) headers.Authorization = `Bearer ${token}`;

  const response = await fetch(`${API_BASE_URL}${path}`, { method: 'POST', headers, body: formData });
  const data = await response.json().catch(() => null);

  if (!response.ok) {
    const error = new Error(data?.title ?? `Erreur ${response.status}`);
    error.status = response.status;
    error.detail = data?.detail;
    throw error;
  }

  return data;
}

/** Récupère une réponse binaire authentifiée (ex: document KYC) — un <img src="..."> ne peut pas envoyer de Bearer token. */
export async function apiFetchBlob(path) {
  const headers = {};
  const token = getToken();
  if (token) headers.Authorization = `Bearer ${token}`;

  const response = await fetch(`${API_BASE_URL}${path}`, { headers });
  if (!response.ok) {
    throw new Error(`Erreur ${response.status}`);
  }

  return { blob: await response.blob(), contentType: response.headers.get('Content-Type') };
}
