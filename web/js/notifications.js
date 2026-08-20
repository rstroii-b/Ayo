import { apiFetch } from './api.js';
import { isLoggedIn } from './auth.js';
import { escapeHtml } from './format.js';

const ACTIVE_STATUSES = ['pending', 'accepted', 'preparing', 'ready_for_pickup', 'picked_up', 'delivering'];

const STATUS_LABEL = {
  pending: 'commande envoyée',
  accepted: 'acceptée par le restaurant',
  preparing: 'en préparation',
  ready_for_pickup: 'prête, en attente d\'un livreur',
  picked_up: 'récupérée par le livreur',
  delivering: 'livreur en route',
};

/**
 * Carte "commande en cours" sur l'accueil — visible d'un coup d'œil dès qu'une commande est
 * active, plutôt que le seul petit point sur la cloche de notifications.
 */
export async function initActiveOrderCard() {
  const slot = document.getElementById('active-order-slot');
  if (!slot || !isLoggedIn()) return;

  try {
    const { orders } = await apiFetch('/orders/mine');
    const active = orders.find((o) => ACTIVE_STATUSES.includes(o.status));

    if (!active) return;

    slot.innerHTML = `
      <a class="active-order" href="/suivi.html?order=${active.id}">
        <span class="pulse"></span>
        <span class="aotext">
          <span class="aotitle">${escapeHtml(active.restaurant_name)} · ${STATUS_LABEL[active.status] ?? active.status}</span>
          <span class="aosub">Suivre ma commande</span>
        </span>
        <svg class="aoarrow" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18l6-6-6-6"/></svg>
      </a>
    `;
  } catch {
    // Silencieux — pas de carte affichée en cas d'erreur réseau.
  }
}

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
