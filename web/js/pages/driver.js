import { apiFetch } from '../api.js';
import { requireLogin } from '../auth.js';
import { formatEuros } from '../format.js';

const NEXT_STATUS = {
  ready_for_pickup: { action: 'picked_up', label: 'Marquer récupérée' },
  picked_up: { action: 'delivering', label: 'En route vers le client' },
  delivering: { action: 'delivered', label: 'Marquer livrée' },
};

let pollTimer = null;

if (requireLogin('/driver.html')) {
  document.getElementById('avatar').textContent = 'L';
  init();
}

async function init() {
  checkStripeStatus();
  await refresh();
  pollTimer = setInterval(refresh, 5000);
}

async function checkStripeStatus() {
  const status = await apiFetch('/connect/status');
  const banner = document.getElementById('stripe-banner');

  if (!status.payouts_enabled) {
    banner.innerHTML = `
      <div class="cart-block" style="padding:14px 16px;margin:0 0 16px;">
        <p style="margin:0 0 10px;font-size:13.5px;">Active tes paiements pour recevoir tes gains de livraison.</p>
        <button class="btn btn-primary" id="onboard-btn" type="button">Activer les paiements Stripe</button>
      </div>
    `;
    document.getElementById('onboard-btn').addEventListener('click', async () => {
      const { onboarding_url } = await apiFetch('/connect/onboard', { method: 'POST', body: {} });
      window.open(onboarding_url, '_blank');
    });
  }
}

function activeOrderHtml(order) {
  const next = NEXT_STATUS[order.status];

  return `
    <div class="cart-block">
      <p class="sechead" style="margin:14px 0 4px;">${order.restaurant_name}</p>
      <p class="state-msg" style="padding:0 0 10px;">${order.restaurant_adresse}</p>
      <div class="sumrow"><span>Livrer à</span><span></span></div>
      <p style="padding:0 4px 10px;font-size:13.5px;font-weight:600;">${order.adresse_livraison}</p>
      ${order.note_livreur ? `<p class="state-msg" style="padding:0 4px 10px;">"${order.note_livreur}"</p>` : ''}
      <div class="sumrow total"><span>Ta part</span><span>${formatEuros(order.delivery_fee_cents)}</span></div>
    </div>
    ${next ? `<button class="btn btn-primary btn-block" data-action="${next.action}" data-id="${order.id}" style="margin:0 20px;width:calc(100% - 40px);">${next.label}</button>` : ''}
  `;
}

function availableOrderHtml(order) {
  return `
    <div class="cart-block">
      <p class="sechead" style="margin:14px 0 4px;">${order.restaurant_name}</p>
      <p class="state-msg" style="padding:0 0 10px;">${order.restaurant_adresse} → ${order.adresse_livraison}</p>
      <div class="sumrow"><span>Frais de livraison</span><span>${formatEuros(order.delivery_fee_cents)}</span></div>
      <div class="sumrow"><span>Plats</span><span></span></div>
      <p class="state-msg" style="padding:0 4px 10px;">${order.items_summary}</p>
    </div>
    <button class="btn btn-primary btn-block" data-claim="${order.id}" style="margin:0 20px 20px;width:calc(100% - 40px);">Prendre cette course</button>
  `;
}

async function refresh() {
  const container = document.getElementById('driver-content');
  const title = document.getElementById('page-title');

  try {
    const { orders: active } = await apiFetch('/driver/orders/active');

    if (active.length > 0) {
      title.textContent = 'Ta livraison en cours';
      container.innerHTML = active.map(activeOrderHtml).join('');

      return;
    }

    title.textContent = 'Commandes disponibles';
    const { orders: available } = await apiFetch('/driver/orders/available');
    container.innerHTML = available.length
      ? available.map(availableOrderHtml).join('')
      : '<p class="state-msg">Aucune commande disponible pour le moment.</p>';
  } catch (error) {
    container.innerHTML = `<p class="state-msg">${error.message}</p>`;
  }
}

document.getElementById('driver-content').addEventListener('click', async (event) => {
  const claimBtn = event.target.closest('button[data-claim]');
  const statusBtn = event.target.closest('button[data-action]');

  if (claimBtn) {
    claimBtn.disabled = true;
    try {
      await apiFetch(`/orders/${claimBtn.dataset.claim}/claim`, { method: 'PATCH', body: {} });
      refresh();
    } catch (error) {
      alert(error.detail ?? error.message);
      claimBtn.disabled = false;
    }
  }

  if (statusBtn) {
    statusBtn.disabled = true;
    try {
      await apiFetch(`/orders/${statusBtn.dataset.id}/status`, {
        method: 'PATCH',
        body: { status: statusBtn.dataset.action },
      });
      refresh();
    } catch (error) {
      alert(error.detail ?? error.message);
      statusBtn.disabled = false;
    }
  }
});
