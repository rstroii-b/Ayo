// En local (php -S), l'API tourne sur un port séparé. En production, l'API vit sur le
// sous-domaine dédié api-ayo.jobivoire.com, dont la racine web pointe précisément sur
// api/public/ — vendor/, src/ et .env restent hors de portée du web.
const API_BASE_URL = ['localhost', '127.0.0.1'].includes(window.location.hostname)
  ? 'http://localhost:8000/api/v1'
  : 'https://api-ayo.jobivoire.com/api/v1';

/** Au-delà, la requête est considérée perdue : mieux vaut une erreur qu'un écran figé. */
const REQUEST_TIMEOUT_MS = 12000;
const MAX_RETRIES = 2;
const RETRY_BASE_DELAY_MS = 400;

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

function randomIdempotencyKey() {
  return crypto.randomUUID();
}

/**
 * Rafraîchissement silencieux du token — sans ça, le JWT (30 min) expire pendant qu'un client
 * navigue/hésite, et "Commander" échoue avec une erreur "Jeton invalide ou expiré" (constaté
 * en prod). On décode juste le payload du JWT (pas de vérification de signature nécessaire,
 * c'est uniquement pour planifier le prochain rafraîchissement) pour viser 5 min avant
 * l'expiration réelle plutôt qu'un intervalle fixe fragile face à un changement de durée serveur.
 *
 * Le serveur borne désormais la durée totale d'une session (claim `sid_iat`) : passé ce délai,
 * le rafraîchissement échoue et la prochaine action protégée redemande une connexion.
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
      // Le token n'était déjà plus valide (onglet resté ouvert des heures, ou session arrivée
      // au bout de sa durée absolue) — rien à faire ici, la prochaine action protégée
      // déclenchera normalement une redemande de connexion.
    }
  }, msUntilRefresh);
}

// Reprend le cycle de rafraîchissement pour un token déjà en localStorage au chargement de la page.
scheduleRefresh(getToken());

/**
 * Erreur d'API normalisée. `isNetwork` distingue « le serveur a répondu non » de « le serveur
 * n'a pas répondu » : ce sont deux situations différentes pour l'utilisateur (l'une se
 * réessaie, l'autre non) et l'interface doit pouvoir les traiter différemment.
 */
class ApiError extends Error {
  constructor(message, { status = 0, detail = '', isNetwork = false } = {}) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
    this.detail = detail;
    this.isNetwork = isNetwork;
  }
}

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

/**
 * Petit client fetch — ajoute automatiquement le Bearer token et, pour les
 * POST, une clé d'idempotence (voir §3 du document d'architecture).
 *
 * @param {string} path
 * @param {{
 *   method?: string,
 *   body?: unknown,
 *   idempotencyKey?: string,
 *   signal?: AbortSignal,
 *   retry?: boolean,
 * }} [options]
 */
export async function apiFetch(path, { method = 'GET', body = null, idempotencyKey = null, signal = null, retry = null } = {}) {
  const headers = { 'Content-Type': 'application/json', Accept: 'application/json' };
  const token = getToken();
  if (token) headers.Authorization = `Bearer ${token}`;

  // La clé fournie par l'appelant est respectée. Elle ne l'était pas : `panier.js` passait
  // une clé stable, conservée en sessionStorage précisément pour qu'un double envoi ne crée
  // pas deux commandes, et le client la jetait pour en générer une neuve à chaque appel —
  // c'est-à-dire exactement le comportement que la clé devait empêcher.
  if (method === 'POST') {
    headers['Idempotency-Key'] = idempotencyKey ?? randomIdempotencyKey();
  }

  // Un GET est rejouable sans effet de bord ; un POST ne l'est que si l'appelant a fourni une
  // clé d'idempotence stable — le serveur reconnaîtra alors le rejeu au lieu de dupliquer.
  const retryable = retry ?? (method === 'GET' || (method === 'POST' && idempotencyKey !== null));

  return request(path, {
    method,
    headers,
    body: body === null ? null : JSON.stringify(body),
    signal,
  }, retryable ? MAX_RETRIES : 0);
}

async function request(path, init, retriesLeft) {
  const timeoutController = new AbortController();
  const timeoutId = setTimeout(() => timeoutController.abort(), REQUEST_TIMEOUT_MS);

  // L'annulation demandée par l'appelant (changement d'écran, recherche relancée) et le
  // délai d'expiration doivent tous deux pouvoir interrompre la requête.
  const onExternalAbort = () => timeoutController.abort();
  init.signal?.addEventListener('abort', onExternalAbort, { once: true });

  let response;

  try {
    response = await fetch(`${API_BASE_URL}${path}`, { ...init, signal: timeoutController.signal });
  } catch (cause) {
    clearTimeout(timeoutId);
    init.signal?.removeEventListener('abort', onExternalAbort);

    // Annulation volontaire : on la laisse remonter telle quelle, ce n'est pas une panne.
    if (init.signal?.aborted) throw cause;

    if (retriesLeft > 0) {
      await sleep(RETRY_BASE_DELAY_MS * (MAX_RETRIES - retriesLeft + 1));

      return request(path, init, retriesLeft - 1);
    }

    throw new ApiError('Connexion au serveur impossible', { isNetwork: true });
  }

  clearTimeout(timeoutId);
  init.signal?.removeEventListener('abort', onExternalAbort);

  // 502/503/504 : le serveur est momentanément indisponible, pas en désaccord avec la
  // requête. Un nouvel essai a de bonnes chances d'aboutir.
  if (retriesLeft > 0 && [502, 503, 504].includes(response.status)) {
    await sleep(RETRY_BASE_DELAY_MS * (MAX_RETRIES - retriesLeft + 1));

    return request(path, init, retriesLeft - 1);
  }

  const data = await response.json().catch(() => null);

  if (!response.ok) {
    throw new ApiError(data?.title ?? `Erreur ${response.status}`, {
      status: response.status,
      detail: data?.detail ?? '',
    });
  }

  return data;
}

/** Envoi multipart (upload de fichier) — pas de Content-Type manuel, le navigateur fixe la boundary. */
export async function apiFetchFile(path, formData) {
  const headers = { Accept: 'application/json' };
  const token = getToken();
  if (token) headers.Authorization = `Bearer ${token}`;

  // Jamais de nouvel essai automatique sur un téléversement : sans clé d'idempotence, un
  // rejeu déposerait un second fichier.
  return request(path, { method: 'POST', headers, body: formData }, 0);
}

/** Récupère une réponse binaire authentifiée (ex: document KYC) — un <img src="..."> ne peut pas envoyer de Bearer token. */
export async function apiFetchBlob(path) {
  const headers = {};
  const token = getToken();
  if (token) headers.Authorization = `Bearer ${token}`;

  const response = await fetch(`${API_BASE_URL}${path}`, { headers });

  if (!response.ok) {
    throw new ApiError(`Erreur ${response.status}`, { status: response.status });
  }

  return { blob: await response.blob(), contentType: response.headers.get('Content-Type') };
}

export { ApiError };
