import { apiFetch } from './api.js';
import { isLoggedIn } from './auth.js';

const ACTIVE_STATUSES = ['pending', 'accepted', 'preparing', 'ready_for_pickup', 'picked_up', 'delivering'];

/**
 * Cloche façon UberEats : pastille + lien direct vers le suivi de la commande en cours,
 * s'il y en a une. Sans commande active, renvoie vers l'historique.
 */
export async function initNotifBell() {
  const btn = document.getElementById('notif-btn');
  if (!btn || !isLoggedIn()) return;

  try {
    const { orders } = await apiFetch('/orders/mine');
    const active = orders.find((o) => ACTIVE_STATUSES.includes(o.status));

    if (active) {
      btn.href = `/suivi.html?order=${active.id}`;
      document.getElementById('notif-dot').hidden = false;
    } else {
      btn.href = '/orders.html';
    }
  } catch {
    // Silencieux — la cloche reste un simple lien vers l'historique en cas d'erreur réseau.
  }
}
