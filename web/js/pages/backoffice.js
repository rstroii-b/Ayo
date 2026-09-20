import { apiFetch } from '../api.js';
import { requireLogin, logout } from '../auth.js';
import { formatMoney, escapeHtml } from '../format.js';
import { realtimeClient } from '../realtime.js';
import { getCurrentPosition } from '../geolocation.js';

if (requireLogin('/backoffice.html')) {
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
    renderMobileMoneyStatus();
    document.getElementById('board').hidden = false;
    loadBoard();

    // Temps réel (Pusher) — le kanban se met à jour dès qu'une commande arrive ou change de
    // statut. Le polling toutes les 20s reste un filet de sécurité.
    const channel = realtimeClient().subscribe(`private-restaurant.${restaurant.id}`);
    channel.bind('new-order', loadBoard);
    channel.bind('order-updated', loadBoard);
    setInterval(loadBoard, 20000);
  } catch (error) {
    if (error.status === 404) {
      document.getElementById('setup-block').hidden = false;
      wireSetupForm();
    } else {
      document.querySelector('.dcontent').innerHTML = `<p class="state-msg">${escapeHtml(error.message)}</p>`;
    }
  }
}

function wireSetupForm() {
  document.getElementById('locate-btn').addEventListener('click', async () => {
    const statusEl = document.getElementById('locate-status');
    statusEl.textContent = 'Repérage en cours…';

    const position = await getCurrentPosition();
    if (position === null) {
      statusEl.textContent = 'Position indisponible — vérifie que la localisation est autorisée pour ce site.';

      return;
    }

    document.getElementById('lat').value = position.lat;
    document.getElementById('lng').value = position.lng;
    statusEl.textContent = 'Position enregistrée.';
  });

  document.getElementById('setup-form').addEventListener('submit', async (event) => {
    event.preventDefault();
    const errorEl = document.getElementById('setup-error');
    errorEl.hidden = true;

    const form = new FormData(event.target);

    try {
      await apiFetch('/restaurants', {
        method: 'POST',
        body: Object.fromEntries(form.entries()),
      });
      window.location.reload();
    } catch (error) {
      errorEl.textContent = error.detail ?? error.message;
      errorEl.hidden = false;
    }
  });
}

async function renderMobileMoneyStatus() {
  const el = document.getElementById('mobile-money-status');
  const status = await apiFetch('/connect/status');

  el.innerHTML = `
    <div class="cart-block" style="padding:14px 16px;margin:0 0 18px;max-width:420px;">
      <p style="margin:0 0 10px;font-size:13.5px;">
        ${status.mobile_money_configured ? 'Compte mobile money enregistré.' : 'Renseigne le compte mobile money du commerce pour recevoir tes reversements.'}
      </p>
      <div class="field"><label for="mm-operator">Opérateur</label>
        <input id="mm-operator" placeholder="ex: OM_CI, MTN_CI, MOOV_CI, WAVE_CI" value="${escapeHtml(status.mobile_money_operator ?? '')}"></div>
      <div class="field"><label for="mm-number">Numéro</label>
        <input id="mm-number" placeholder="+2250700000000" value="${escapeHtml(status.mobile_money_number ?? '')}"></div>
      <button class="btn btn-primary" id="mm-save-btn" type="button">Enregistrer</button>
      <p class="state-msg" id="mm-status" style="margin:8px 0 0;"></p>
    </div>
  `;

  document.getElementById('mm-save-btn').addEventListener('click', async () => {
    const statusEl = document.getElementById('mm-status');

    try {
      await apiFetch('/connect/mobile-money', {
        method: 'PATCH',
        body: {
          operator: document.getElementById('mm-operator').value.trim(),
          phone_number: document.getElementById('mm-number').value.trim(),
        },
      });
      statusEl.textContent = 'Enregistré.';
    } catch (error) {
      statusEl.textContent = error.detail ?? error.message;
    }
  });
}

const COLUMNS = [
  { key: 'pending', title: 'Nouvelles', dot: 'var(--chili)', cls: 'urgent' },
  { key: 'preparing', title: 'En préparation', dot: 'var(--accent)', cls: 'prep' },
  { key: 'ready_for_pickup', title: 'Prêtes', dot: 'var(--cola)', cls: 'ready' },
];

function bucketFor(status) {
  if (status === 'accepted' || status === 'preparing') return 'preparing';

  return status;
}

function orderCardHtml(order, columnKey) {
  // MySQL stocke created_at en heure locale du serveur (pas de fuseau) — on l'interprète
  // comme locale ici aussi plutôt que de forcer UTC, pour ne pas décaler le calcul.
  const elapsedMin = Math.max(0, Math.round((Date.now() - new Date(order.created_at.replace(' ', 'T'))) / 60000));

  let actions = '';
  if (columnKey === 'pending' && order.status === 'pending') {
    actions = `
      <div class="actions">
        <button class="btn btn-ghost" data-action="cancelled" data-id="${order.id}">Refuser</button>
        <button class="btn btn-primary" data-action="accepted" data-id="${order.id}">Accepter</button>
      </div>`;
  } else if (order.status === 'accepted') {
    actions = `<div class="actions"><button class="btn btn-primary" data-action="preparing" data-id="${order.id}">En préparation</button></div>`;
  } else if (order.status === 'preparing') {
    actions = `<div class="actions"><button class="btn btn-primary" data-action="ready_for_pickup" data-id="${order.id}">Marquer prête</button></div>`;
  } else if (order.status === 'ready_for_pickup') {
    actions = `<p class="state-msg" style="margin:8px 0 0;">En attente d'un livreur…</p>`;
  }

  return `
    <div class="ocard ${COLUMNS.find((c) => c.key === columnKey).cls}">
      <div class="crow"><span class="code">#SV-${order.id}</span><span class="state-msg">${elapsedMin} min</span></div>
      <div class="client">${escapeHtml(order.client_first_name)}</div>
      <div class="items">${escapeHtml(order.items_summary)}</div>
      ${order.note_livreur ? `<div class="note">"${escapeHtml(order.note_livreur)}"</div>` : ''}
      <span class="total">${formatMoney(order.total_cents)}</span>
      ${actions}
    </div>
  `;
}

async function loadBoard() {
  const board = document.getElementById('board');

  try {
    const { orders } = await apiFetch('/restaurant/orders/live');

    board.innerHTML = COLUMNS.map((col) => {
      const colOrders = orders.filter((o) => bucketFor(o.status) === col.key);

      return `
        <div class="col">
          <div class="colhead"><div class="dotc" style="background:${col.dot};"></div>${col.title}<span class="count">${colOrders.length}</span></div>
          ${colOrders.map((o) => orderCardHtml(o, col.key)).join('') || '<p class="state-msg">Rien ici pour l\'instant.</p>'}
        </div>
      `;
    }).join('');

    document.getElementById('nav-count').hidden = orders.length === 0;
    document.getElementById('nav-count').textContent = orders.length;
  } catch (error) {
    board.innerHTML = `<p class="state-msg">${escapeHtml(error.message)}</p>`;
  }
}

document.getElementById('board').addEventListener('click', async (event) => {
  const btn = event.target.closest('button[data-action]');
  if (!btn) return;

  btn.disabled = true;

  try {
    await apiFetch(`/orders/${btn.dataset.id}/status`, { method: 'PATCH', body: { status: btn.dataset.action } });
    loadBoard();
  } catch (error) {
    alert(error.detail ?? error.message);
    btn.disabled = false;
  }
});
