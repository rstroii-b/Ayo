import { apiFetch } from '../api.js';
import { requireLogin } from '../auth.js';
import { formatEuros, escapeHtml } from '../format.js';
import { pushSupported, subscribeToPush } from '../push.js';
import { realtimeClient } from '../realtime.js';

const orderId = new URLSearchParams(window.location.search).get('order');

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

// Estimation grossière par statut (pas de position GPS live remontée au client) — affichée
// comme fourchette approximative, jamais comme promesse. Progress (0–1) positionne le point
// sur le tracé du restaurant vers l'adresse de livraison.
const ETA_BY_STATUS = {
  pending: { min: 35, progress: 0.05 },
  accepted: { min: 32, progress: 0.1 },
  preparing: { min: 25, progress: 0.15 },
  ready_for_pickup: { min: 18, progress: 0.3 },
  picked_up: { min: 14, progress: 0.55 },
  delivering: { min: 8, progress: 0.8 },
};

let pollTimer = null;

function routeCardHtml(order) {
  const eta = ETA_BY_STATUS[order.status];
  const dotX = 28 + (325 - 28) * (eta?.progress ?? 0);
  const dotY = 26 + (96 - 26) * (eta?.progress ?? 0);

  return `
    <div class="route-card">
      <svg viewBox="0 0 353 130" width="353" height="130">
        <path d="M28,26 C 130,10 220,110 325,96" fill="none" stroke="rgba(31,229,134,.35)" stroke-width="2" stroke-dasharray="1 9" stroke-linecap="round"/>
        <circle cx="28" cy="26" r="6" fill="#1FE586"/>
        <circle cx="325" cy="96" r="6" fill="none" stroke="#2BEBD1" stroke-width="2"/>
        <circle class="livedot-ring" cx="${dotX}" cy="${dotY}" r="9" fill="#1FE586" opacity=".18"/>
        <circle cx="${dotX}" cy="${dotY}" r="5" fill="#1FE586"/>
      </svg>
      <span class="pin-label" style="top:12px;left:40px;">${escapeHtml(order.restaurant_name ?? '')}</span>
      <span class="pin-label" style="bottom:8px;right:14px;">Chez toi</span>
    </div>
  `;
}

function renderStatusHeader(order) {
  const eta = ETA_BY_STATUS[order.status];
  const header = document.getElementById('status-header');

  if (order.status === 'cancelled') {
    header.innerHTML = `
      <div class="eta-block">
        <div class="eta-label">Commande #${order.id}</div>
        <div class="eta-status" style="color:var(--chili);font-size:16px;">Commande annulée</div>
      </div>
    `;
    document.getElementById('route-block').hidden = true;

    return;
  }

  if (order.status === 'delivered') {
    header.innerHTML = `
      <div class="eta-block">
        <div class="eta-label">Commande #${order.id}</div>
        <div class="eta-status" style="color:var(--herb);font-size:16px;">Livrée — bon appétit !</div>
      </div>
    `;
    document.getElementById('route-block').hidden = true;

    return;
  }

  header.innerHTML = `
    <div class="eta-block">
      <div class="eta-label">Commande #${order.id}</div>
      ${eta ? `<div class="eta-num">${eta.min}<span>min</span></div>` : ''}
      <div class="eta-status">${STATUS_LABELS[order.status] ?? order.status}</div>
    </div>
  `;

  const routeBlock = document.getElementById('route-block');
  routeBlock.hidden = false;
  routeBlock.innerHTML = routeCardHtml(order);
}

function renderDriver(order) {
  const block = document.getElementById('driver-block');

  if (!order.driver) {
    block.hidden = true;

    return;
  }

  block.hidden = false;
  block.innerHTML = `
    <div class="driver-avatar">${escapeHtml((order.driver.first_name?.[0] ?? '?').toUpperCase())}</div>
    <div class="driver-info">
      <div class="driver-name">${escapeHtml(order.driver.first_name)}</div>
      <div class="driver-sub">${escapeHtml(order.driver.vehicule_type)}</div>
    </div>
    ${order.driver.phone ? `
      <a class="icon-btn" href="tel:${escapeHtml(order.driver.phone)}" aria-label="Appeler le livreur">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 01-2.18 2 19.8 19.8 0 01-8.63-3.07 19.5 19.5 0 01-6-6 19.8 19.8 0 01-3.07-8.67A2 2 0 014.11 2h3a2 2 0 012 1.72c.127.96.362 1.903.7 2.81a2 2 0 01-.45 2.11L8.09 9.91a16 16 0 006 6l1.27-1.27a2 2 0 012.11-.45c.907.338 1.85.573 2.81.7A2 2 0 0122 16.92z"/></svg>
      </a>
      <a class="icon-btn" href="sms:${escapeHtml(order.driver.phone)}" aria-label="Envoyer un message au livreur">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>
      </a>
    ` : ''}
  `;
}

function renderItems(order) {
  const block = document.getElementById('items-block');
  block.hidden = false;

  const detailLines = order.items.map((item) => `
    <div class="detail-line"><span>${item.quantity}x ${escapeHtml(item.name)}</span><span>${formatEuros(item.price_cents * item.quantity)}</span></div>
  `).join('');

  block.innerHTML = `
    <button class="items-toggle" id="items-toggle" type="button">
      <span class="il">${order.items.length} article${order.items.length > 1 ? 's' : ''}</span>
      <span class="ir">${formatEuros(order.total_cents)}<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg></span>
    </button>
    <div class="items-detail" id="items-detail">
      ${detailLines}
      <div class="detail-line"><span>Livraison</span><span>${formatEuros(order.delivery_fee_cents)}</span></div>
      <div class="total-line"><span>Total</span><span>${formatEuros(order.total_cents)}</span></div>
    </div>
  `;

  document.getElementById('items-toggle').addEventListener('click', (event) => {
    event.currentTarget.classList.toggle('open');
    document.getElementById('items-detail').classList.toggle('open');
  });
}

async function poll() {
  try {
    const order = await apiFetch(`/orders/${orderId}`);

    renderStatusHeader(order);
    renderDriver(order);
    renderItems(order);

    if (order.status === 'delivered' || order.status === 'cancelled') {
      clearInterval(pollTimer);
    }
  } catch (error) {
    document.getElementById('status-header').innerHTML =
      `<p class="state-msg" style="padding:0 20px;">Impossible de charger la commande (${error.message}).</p>`;
  }
}

if (!orderId) {
  document.getElementById('status-header').innerHTML = '<p class="state-msg" style="padding:0 20px;">Aucune commande à afficher.</p>';
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
