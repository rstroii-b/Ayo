import { apiFetch } from '../api.js';
import { requireLogin, logout } from '../auth.js';
import { formatMoney, escapeHtml } from '../format.js';


if (requireLogin('/backoffice-history.html')) {
  init();
}

document.getElementById('logout-btn').addEventListener('click', () => {
  logout();
  window.location.href = '/login.html';
});

async function init() {
  try {
    const restaurant = await apiFetch('/restaurant/mine');
    document.getElementById('restaurant-name-foot').textContent = restaurant.name;
    await loadHistory();
  } catch (error) {
    if (error.status === 404) {
      window.location.href = '/backoffice.html';
    } else {
      document.getElementById('history-content').innerHTML = `<p class="state-msg">${escapeHtml(error.message)}</p>`;
    }
  }
}

const STATUS_LABEL = { delivered: 'Livrée', cancelled: 'Annulée' };

function rowHtml(order) {
  // MySQL stocke created_at en heure locale du serveur (pas de fuseau) — même traitement que le kanban.
  const date = new Date(order.created_at.replace(' ', 'T'));
  const dateLabel = date.toLocaleDateString('fr-FR', { day: '2-digit', month: '2-digit' })
    + ' à ' + date.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' });

  return `
    <div class="mrow">
      <div class="mname">#SV-${order.id} — ${escapeHtml(order.client_first_name)}<div class="d">${order.items_summary ? escapeHtml(order.items_summary) : 'Aucun détail'}</div></div>
      <div class="mprice">${formatMoney(order.total_cents)}</div>
      <div class="mtoggle">
        <span class="pill ${order.status === 'delivered' ? 'ok' : 'warn'}" style="padding:4px 10px;font-size:11px;">${STATUS_LABEL[order.status]}</span>
      </div>
      <div class="mtoggle" style="width:150px;justify-content:flex-end;">
        <span class="state-msg" style="margin:0;">${dateLabel}</span>
      </div>
    </div>
  `;
}

async function loadHistory() {
  const { orders } = await apiFetch('/restaurant/orders/history');
  const content = document.getElementById('history-content');

  if (orders.length === 0) {
    content.innerHTML = '<p class="state-msg">Aucune commande livrée ou annulée pour l\'instant.</p>';

    return;
  }

  content.innerHTML = `
    <div class="mtable">
      <div class="mrow head">
        <div class="mname">Commande</div>
        <div class="mprice">Total</div>
        <div class="mtoggle">Statut</div>
        <div class="mtoggle" style="width:150px;justify-content:flex-end;">Date</div>
      </div>
      ${orders.map(rowHtml).join('')}
    </div>
  `;
}
