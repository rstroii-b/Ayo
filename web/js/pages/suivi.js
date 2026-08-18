import { apiFetch } from '../api.js';
import { requireLogin } from '../auth.js';
import { formatEuros, escapeHtml } from '../format.js';
import { pushSupported, subscribeToPush } from '../push.js';
import { realtimeClient } from '../realtime.js';

const orderId = new URLSearchParams(window.location.search).get('order');

const STEP_LABELS = ['Reçue', 'Préparation', 'En route', 'Livrée'];
// Fait correspondre le statut serveur (§3 du document d'architecture) à l'une des 4 étapes affichées.
const STEP_INDEX = {
  pending: 0, accepted: 0,
  preparing: 1,
  ready_for_pickup: 2, picked_up: 2, delivering: 2,
  delivered: 3,
};

const STATUS_LABELS = {
  pending: 'Commande envoyée au restaurant',
  accepted: 'Commande acceptée',
  preparing: 'En préparation',
  ready_for_pickup: 'Prête, en attente d\'un livreur',
  picked_up: 'Récupérée par le livreur',
  delivering: 'Le livreur est en route',
  delivered: 'Livrée — bon appétit !',
  cancelled: 'Commande annulée',
};

let pollTimer = null;

function renderSteps(status) {
  const activeIndex = STEP_INDEX[status] ?? 0;

  document.getElementById('steps').innerHTML = STEP_LABELS.map((label, i) => {
    const cls = status === 'delivered' || i < activeIndex ? '' : i === activeIndex ? 'now' : 'todo';
    const icon = status === 'delivered' || i < activeIndex
      ? '<svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M4 12l5 5L20 6"/></svg>'
      : '';

    return `<div class="step ${cls}"><div class="bar"></div><div class="circ">${icon}</div><span class="slbl">${label}</span></div>`;
  }).join('');
}

function renderDriver(order) {
  const block = document.getElementById('driver-block');

  if (!order.driver) {
    block.hidden = true;

    return;
  }

  block.hidden = false;
  block.innerHTML = `
    <div class="cart-line" style="border-bottom:none;">
      <div class="cline-info">
        <div class="cline-name">${escapeHtml(order.driver.first_name)}</div>
        <span class="state-msg" style="text-transform:capitalize;">${escapeHtml(order.driver.vehicule_type)}</span>
      </div>
    </div>
  `;
}

function renderItems(order) {
  document.getElementById('items-block').innerHTML = order.items.map((item) => `
    <div class="cart-line">
      <div class="cline-info">
        <div class="cline-name">${item.quantity}× ${escapeHtml(item.name)}</div>
      </div>
      <span class="price">${formatEuros(item.price_cents * item.quantity)}</span>
    </div>
  `).join('') + `
    <div class="sumrow total"><span>Total</span><span>${formatEuros(order.total_cents)}</span></div>
  `;
}

async function poll() {
  try {
    const order = await apiFetch(`/orders/${orderId}`);

    document.getElementById('status-header').innerHTML = `
      <h1 class="title" style="margin-bottom:4px;">${STATUS_LABELS[order.status] ?? order.status}</h1>
      <p class="state-msg">Commande #${order.id}</p>
    `;

    if (order.status === 'cancelled') {
      document.getElementById('steps').innerHTML = '';
      clearInterval(pollTimer);
    } else {
      renderSteps(order.status);
    }

    renderDriver(order);
    renderItems(order);

    if (order.status === 'delivered') {
      clearInterval(pollTimer);
    }
  } catch (error) {
    document.getElementById('status-header').innerHTML =
      `<p class="state-msg">Impossible de charger la commande (${error.message}).</p>`;
  }
}

if (!orderId) {
  document.getElementById('status-header').innerHTML = '<p class="state-msg">Aucune commande à afficher.</p>';
} else if (requireLogin(`/suivi.html?order=${orderId}`)) {
  poll();

  // Temps réel (Pusher) — mise à jour instantanée. Le polling toutes les 15s reste un filet
  // de sécurité si la connexion WebSocket tombe (réseau, onglet en arrière-plan...).
  const channel = realtimeClient().subscribe(`private-order.${orderId}`);
  channel.bind('status-updated', poll);
  channel.bind('driver-assigned', poll);
  pollTimer = setInterval(poll, 15000);

  // Moment le plus pertinent pour proposer les notifications — ne redemande jamais si
  // déjà accepté ou refusé (le navigateur bloque de toute façon la re-demande).
  if (pushSupported() && Notification.permission === 'default') {
    subscribeToPush();
  }
}
