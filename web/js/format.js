/**
 * Formate un montant en XOF (Franc CFA) — pas de sous-unité utilisée en pratique (jamais de
 * centimes affichés). Les montants restent stockés en "centièmes" en base pour rester cohérents
 * avec le reste du code (voir database/schema.sql) ; seul l'affichage change.
 */
export function formatMoney(cents) {
  return Math.round(cents / 100).toLocaleString('fr-FR') + ' FCFA';
}

const ESCAPE_MAP = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };

/**
 * Échappe une chaîne pour une insertion sûre dans du HTML (innerHTML, attributs) — l'app est en
 * JS vanilla sans échappement automatique (pas de React/Vue), donc toute donnée venant du serveur
 * (noms, notes, adresses...) doit passer par ici avant d'être interpolée dans un template literal.
 */
export function escapeHtml(value) {
  return String(value ?? '').replace(/[&<>"']/g, (ch) => ESCAPE_MAP[ch]);
}

/**
 * N'autorise que des URL https (photos venant des restaurateurs, évite les schémas exotiques)
 * ou des chemins relatifs vers nos propres assets statiques (photos de démo fournies par Ayo).
 */
export function safeImageUrl(url) {
  if (typeof url !== 'string') return null;
  if (/^https:\/\//.test(url)) return url;
  if (/^\/assets\//.test(url)) return url;

  return null;
}

/**
 * N'autorise qu'un chemin interne (même origine) comme cible de redirection après connexion.
 * Un `?next=javascript:...` ou `?next=//evil.example` fournis dans l'URL provoquaient sinon une
 * exécution de script (DOM-XSS) ou un open redirect. On exige un chemin absolu du site, jamais
 * un schéma ni une origine externe.
 */
export function safeInternalPath(value, fallback = '/index.html') {
  if (typeof value !== 'string') return fallback;
  // Doit commencer par un seul "/", ne pas être protocol-relative "//", ni contenir ":" ou retour arrière.
  if (!/^\/[^/\\]/.test(value)) return fallback;
  if (value.includes('://') || value.includes('\\')) return fallback;

  return value;
}
