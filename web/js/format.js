export function formatEuros(cents) {
  return (cents / 100).toLocaleString('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' €';
}

/**
 * Formate un montant selon la devise de la zone du commerce — XOF (Franc CFA) n'a pas de
 * sous-unité utilisée en pratique (jamais de centimes affichés), contrairement à l'euro. Les
 * montants restent stockés en "centièmes" en base pour rester cohérents avec le reste du code
 * (voir database/schema.sql) ; seul l'affichage change.
 */
export function formatMoney(cents, currency = 'EUR') {
  if (currency === 'XOF') {
    return Math.round(cents / 100).toLocaleString('fr-FR') + ' FCFA';
  }

  return formatEuros(cents);
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

/** N'autorise que des URL http(s) pour les images venant des restaurateurs (évite les schémas exotiques). */
export function safeImageUrl(url) {
  return typeof url === 'string' && /^https:\/\//.test(url) ? url : null;
}
