import { apiFetch } from '../api.js';
import { requireLogin } from '../auth.js';
import { formatMoney, escapeHtml } from '../format.js';
import { statusLabel, statusTone } from '../status.js';
import { renderEmpty, renderError, renderLoading } from '../ui.js';

// Les libellés et les couleurs de statut ne sont plus redéfinis ici : web/js/status.js en est
// la seule source, partagée avec l'accueil, le suivi et le back-office. Trois écrans
// nommaient différemment le même état de commande.

function orderRowHtml(order) {
  // MySQL renvoie « 2026-09-17 14:32:00 » ; Safari refuse ce format sans le « T ». Une date
  // invalide donnait « Invalid Date » dans la liste des commandes sur iPhone.
  const date = new Date(String(order.created_at ?? '').replace(' ', 'T'));
  const dateLabel = Number.isNaN(date.getTime())
    ? ''
    : date.toLocaleDateString('fr-FR', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });

  return `
    <a class="rcard" href="/suivi.html?order=${order.id}" style="align-items:center;">
      <div class="cline-info">
        <div class="cline-name">${escapeHtml(order.restaurant_name)}</div>
        ${dateLabel ? `<span class="field-hint">${escapeHtml(dateLabel)}</span>` : ''}
      </div>
      <div style="text-align:right;">
        <div class="price">${formatMoney(order.total_cents)}</div>
        <span class="status-badge" data-tone="${escapeHtml(statusTone(order.status))}">${escapeHtml(statusLabel(order.status))}</span>
      </div>
    </a>
  `;
}

async function load() {
  const content = document.getElementById('orders-content');
  renderLoading(content, 3);

  try {
    const { orders } = await apiFetch('/orders/mine');

    if (orders.length === 0) {
      renderEmpty(content, {
        icon: '🧾',
        title: 'Aucune commande pour l\'instant',
        text: 'Tes commandes et leur suivi apparaîtront ici.',
        action: { label: 'Découvrir les commerces', href: '/index.html' },
      });

      return;
    }

    content.removeAttribute('aria-busy');
    content.innerHTML = `<div class="rlist">${orders.map(orderRowHtml).join('')}</div>`;
  } catch (error) {
    renderError(content, error, { title: 'Impossible de charger tes commandes', onRetry: load });
  }
}

if (requireLogin('/orders.html')) {
  load();
}
