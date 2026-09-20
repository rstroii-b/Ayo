import { apiFetch } from '../api.js';
import { requireLogin } from '../auth.js';
import { formatMoney, escapeHtml } from '../format.js';

const STATUS_LABELS = {
  pending: 'Envoyée',
  accepted: 'Acceptée',
  preparing: 'En préparation',
  ready_for_pickup: 'Prête',
  picked_up: 'Récupérée',
  delivering: 'En route',
  delivered: 'Livrée',
  cancelled: 'Annulée',
};

const STATUS_COLOR = {
  delivered: 'var(--ink-dim)',
  cancelled: 'var(--chili)',
  ready_for_pickup: 'var(--herb)',
  picked_up: 'var(--herb)',
  delivering: 'var(--herb)',
};

function orderRowHtml(order) {
  const date = new Date(order.created_at.replace(' ', 'T'));
  const color = STATUS_COLOR[order.status] ?? 'var(--ink)';

  return `
    <a class="rcard" href="/suivi.html?order=${order.id}" style="align-items:center;">
      <div class="cline-info">
        <div class="cline-name">${escapeHtml(order.restaurant_name)}</div>
        <span class="state-msg">${date.toLocaleDateString('fr-FR', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })}</span>
      </div>
      <div style="text-align:right;">
        <div class="price">${formatMoney(order.total_cents)}</div>
        <span style="font-size:11.5px;font-weight:600;color:${color};">${STATUS_LABELS[order.status] ?? order.status}</span>
      </div>
    </a>
  `;
}

async function load() {
  const content = document.getElementById('orders-content');

  try {
    const { orders } = await apiFetch('/orders/mine');

    content.innerHTML = orders.length
      ? `<div class="rlist">${orders.map(orderRowHtml).join('')}</div>`
      : `
        <p class="state-msg">Tu n'as pas encore passé de commande.</p>
        <a class="btn btn-primary" href="/index.html" style="margin-top:12px;">Découvrir des restaurants</a>
      `;
  } catch (error) {
    content.innerHTML = `<p class="state-msg">Impossible de charger tes commandes (${escapeHtml(error.message)}).</p>`;
  }
}

if (requireLogin('/orders.html')) {
  load();
}
