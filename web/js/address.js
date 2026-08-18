const ADDRESS_KEY = 'ayo_address';

export function getAddress() {
  return localStorage.getItem(ADDRESS_KEY);
}

export function setAddress(address) {
  localStorage.setItem(ADDRESS_KEY, address);
}

/**
 * Pas de saisie d'adresse dédiée dans ce squelette (pas d'autocomplétion/géocodage) —
 * une simple invite suffit pour l'instant. Retourne la nouvelle adresse, ou null si annulé.
 */
export function promptForAddress() {
  const current = getAddress() ?? '';
  const next = window.prompt('Adresse de livraison', current);

  if (next === null || next.trim() === '') {
    return null;
  }

  setAddress(next.trim());

  return next.trim();
}
