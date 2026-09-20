import { apiFetch } from '../api.js';
import { requireLogin, logout } from '../auth.js';
import { formatMoney, escapeHtml } from '../format.js';


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
      document.getElementById('stats-content').innerHTML = `<p class="state-msg">${escapeHtml(error.message)}</p>`;
    }
  }
}

function sparklineHtml(dailyRevenue) {
  const max = Math.max(1, ...dailyRevenue.map((d) => d.revenue_cents));

  return `
    <div class="spark">
      ${dailyRevenue.map((d, i) => `<i class="${i === dailyRevenue.length - 1 ? 'now' : ''}" style="height:${Math.max(6, (d.revenue_cents / max) * 100)}%;"></i>`).join('')}
    </div>
  `;
}

function statCardHtml({ label, value, sub, glow, extra }) {
  return `
    <div class="statcard">
      <p class="statlabel">${label}</p>
      <p class="statvalue${glow ? ' glow' : ''}">${value}</p>
      ${sub ? `<p class="statsub">${sub}</p>` : ''}
      ${extra ?? ''}
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
      ${items.map((item, i) => `
        <div class="topitem">
          <span class="rank">${String(i + 1).padStart(2, '0')}</span>
          <span class="tiname">${escapeHtml(item.name)}</span>
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
      ${statCardHtml({
        label: 'Ventes aujourd\'hui',
        value: formatMoney(stats.revenue_today_cents),
        sub: `${stats.orders_today} commande${stats.orders_today > 1 ? 's' : ''} livrée${stats.orders_today > 1 ? 's' : ''}`,
        glow: true,
        extra: sparklineHtml(stats.daily_revenue),
      })}
      ${statCardHtml({
        label: 'Ventes sur 7 jours',
        value: formatMoney(stats.revenue_week_cents),
        sub: `${stats.orders_week} commande${stats.orders_week > 1 ? 's' : ''} livrée${stats.orders_week > 1 ? 's' : ''}`,
      })}
      ${statCardHtml({
        label: 'Commandes en cours',
        value: stats.pending_orders,
        extra: `<div class="pendingrow"><span class="pulse-dot"></span><span>sur le kanban en direct</span></div>`,
      })}
    </div>

    <p class="sectitle" style="margin-top:26px;">Plats les plus vendus (30 derniers jours)</p>
    ${topItemsHtml(stats.top_items)}
  `;
}
