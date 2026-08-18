import { apiFetch } from '../api.js';
import { requireLogin, logout } from '../auth.js';
import { formatEuros } from '../format.js';

if (requireLogin('/backoffice-stats.html')) {
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
    await loadStats();
  } catch (error) {
    if (error.status === 404) {
      window.location.href = '/backoffice.html';
    } else {
      document.getElementById('stats-content').innerHTML = `<p class="state-msg">${error.message}</p>`;
    }
  }
}

function statCardHtml(label, value, sub) {
  return `
    <div class="statcard">
      <p class="statlabel">${label}</p>
      <p class="statvalue">${value}</p>
      ${sub ? `<p class="statsub">${sub}</p>` : ''}
    </div>
  `;
}

function topItemsHtml(items) {
  if (items.length === 0) {
    return '<p class="state-msg">Pas encore de commande livrée sur les 30 derniers jours.</p>';
  }

  const max = Math.max(...items.map((i) => Number(i.total_quantity)));

  return `
    <div class="topitems">
      ${items.map((item) => `
        <div class="topitem">
          <span class="tiname">${item.name}</span>
          <div class="tibar"><div class="tibarfill" style="width:${(item.total_quantity / max) * 100}%;"></div></div>
          <span class="ticount">${item.total_quantity}</span>
        </div>
      `).join('')}
    </div>
  `;
}

async function loadStats() {
  const stats = await apiFetch('/restaurant/stats');
  const content = document.getElementById('stats-content');

  content.innerHTML = `
    <div class="statgrid">
      ${statCardHtml('Ventes aujourd\'hui', formatEuros(stats.revenue_today_cents), `${stats.orders_today} commande${stats.orders_today > 1 ? 's' : ''} livrée${stats.orders_today > 1 ? 's' : ''}`)}
      ${statCardHtml('Ventes sur 7 jours', formatEuros(stats.revenue_week_cents), `${stats.orders_week} commande${stats.orders_week > 1 ? 's' : ''} livrée${stats.orders_week > 1 ? 's' : ''}`)}
      ${statCardHtml('Commandes en cours', stats.pending_orders, 'sur le kanban en direct')}
    </div>

    <p class="sectitle" style="margin-top:26px;">Plats les plus vendus (30 derniers jours)</p>
    ${topItemsHtml(stats.top_items)}
  `;
}
