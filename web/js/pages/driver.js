import { apiFetch, apiFetchFile } from '../api.js';
import { requireLogin, logout, currentUser } from '../auth.js';
import { formatMoney, escapeHtml } from '../format.js';
import { pushSupported, subscribeToPush } from '../push.js';
import { realtimeClient } from '../realtime.js';

const NEXT_STATUS = {
  ready_for_pickup: { action: 'picked_up', label: 'Marquer récupérée' },
  picked_up: { action: 'delivering', label: 'En route vers le client' },
  delivering: { action: 'delivered', label: 'Marquer livrée' },
};

const LOCATION_INTERVAL_MS = 45000;

let pollTimer = null;
let locationTimer = null;

if (requireLogin('/driver.html')) {
  document.getElementById('avatar').textContent = 'L';
  document.getElementById('avatar').addEventListener('click', () => {
    if (confirm('Se déconnecter ?')) {
      goOffline();
      logout();
      window.location.href = '/login.html';
    }
  });
  document.getElementById('online-switch').addEventListener('click', toggleOnline);
  init();
}

async function init() {
  loadKycStatus();
  loadMobileMoneyStatus();
  await refresh();

  // Temps réel (Pusher) — la liste des courses disponibles se met à jour dès qu'une commande
  // devient éligible à proximité. Le polling toutes les 20s reste un filet de sécurité.
  const channel = realtimeClient().subscribe(`private-driver.${currentUser().id}`);
  channel.bind('order-available', refresh);
  pollTimer = setInterval(refresh, 20000);
}

function reportLocation() {
  if (!('geolocation' in navigator)) return;

  navigator.geolocation.getCurrentPosition(
    (position) => {
      apiFetch('/driver/location', {
        method: 'POST',
        body: { lat: position.coords.latitude, lng: position.coords.longitude },
      }).catch(() => {});
    },
    () => {},
    { enableHighAccuracy: false, maximumAge: 30000 }
  );
}

async function goOnline() {
  await apiFetch('/driver/status', { method: 'PATCH', body: { is_online: true } });
  document.getElementById('online-switch').className = 'sw on';
  document.getElementById('online-label').textContent = 'En ligne';

  if (pushSupported() && Notification.permission === 'default') {
    subscribeToPush();
  }

  reportLocation();
  locationTimer = setInterval(reportLocation, LOCATION_INTERVAL_MS);
}

async function goOffline() {
  await apiFetch('/driver/status', { method: 'PATCH', body: { is_online: false } }).catch(() => {});
  document.getElementById('online-switch').className = 'sw off';
  document.getElementById('online-label').textContent = 'Hors ligne';
  clearInterval(locationTimer);
}

function toggleOnline() {
  const isOnline = document.getElementById('online-switch').classList.contains('on');

  if (isOnline) {
    goOffline();
  } else {
    goOnline();
  }
}

async function loadKycStatus() {
  const driver = await apiFetch('/driver/me');
  renderKycBanner(document.getElementById('kyc-banner'), driver);
}

function renderKycBanner(banner, driver) {
  if (driver.kyc_status === 'verified') {
    banner.innerHTML = `
      <div class="cart-block" style="padding:14px 16px;margin:0 0 16px;">
        <span class="pill ok">Identité vérifiée</span>
      </div>
    `;

    return;
  }

  if (driver.kyc_status === 'pending' && driver.has_kyc_document) {
    banner.innerHTML = `
      <div class="cart-block" style="padding:14px 16px;margin:0 0 16px;">
        <p style="margin:0;font-size:13.5px;">Pièce d'identité envoyée — vérification en cours.</p>
      </div>
    `;

    return;
  }

  const rejectedNote = driver.kyc_status === 'rejected'
    ? `<p class="error-msg" style="margin:0 0 10px;">Document refusé${driver.kyc_rejection_reason ? ` — ${escapeHtml(driver.kyc_rejection_reason)}` : ''}. Envoie une nouvelle pièce.</p>`
    : '';

  banner.innerHTML = `
    <div class="cart-block" style="padding:14px 16px;margin:0 0 16px;">
      ${rejectedNote}
      <p style="margin:0 0 10px;font-size:13.5px;">Envoie une pièce d'identité (JPEG, PNG ou PDF, 8 Mo max) pour activer ton compte livreur.</p>
      <input type="file" id="kyc-file" accept="image/jpeg,image/png,application/pdf">
      <button class="btn btn-primary" id="kyc-upload-btn" type="button" style="margin-top:8px;">Envoyer</button>
      <p class="state-msg" id="kyc-upload-status" style="margin:8px 0 0;"></p>
    </div>
  `;

  document.getElementById('kyc-upload-btn').addEventListener('click', async () => {
    const fileInput = document.getElementById('kyc-file');
    const statusEl = document.getElementById('kyc-upload-status');

    if (!fileInput.files[0]) {
      statusEl.textContent = 'Choisis un fichier.';

      return;
    }

    const formData = new FormData();
    formData.append('document', fileInput.files[0]);

    try {
      await apiFetchFile('/driver/kyc-document', formData);
      loadKycStatus();
    } catch (error) {
      statusEl.textContent = error.detail ?? error.message;
    }
  });
}

async function loadMobileMoneyStatus() {
  const status = await apiFetch('/connect/status');
  const banner = document.getElementById('mobile-money-banner');
  renderMobileMoneyBanner(banner, status);
}

function renderMobileMoneyBanner(banner, status) {
  banner.innerHTML = `
    <div class="cart-block" style="padding:14px 16px;margin:0 0 16px;">
      <p style="margin:0 0 10px;font-size:13.5px;">
        ${status.mobile_money_configured ? 'Compte mobile money enregistré — modifie-le ici si besoin.' : 'Renseigne ton compte mobile money pour recevoir tes gains de livraison.'}
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

function activeOrderHtml(order) {
  const next = NEXT_STATUS[order.status];

  return `
    <div class="cart-block">
      <p class="sechead" style="margin:14px 0 4px;">${escapeHtml(order.restaurant_name)}</p>
      <p class="state-msg" style="padding:0 0 10px;">${escapeHtml(order.restaurant_adresse)}</p>
      <div class="sumrow"><span>Livrer à</span><span></span></div>
      <p style="padding:0 4px 10px;font-size:13.5px;font-weight:600;">${escapeHtml(order.adresse_livraison)}</p>
      ${order.note_livreur ? `<p class="state-msg" style="padding:0 4px 10px;">"${escapeHtml(order.note_livreur)}"</p>` : ''}
      <div class="sumrow total"><span>Ta part</span><span>${formatMoney(order.delivery_fee_cents)}</span></div>
    </div>
    ${next ? `<button class="btn btn-primary btn-block" data-action="${next.action}" data-id="${order.id}" style="margin:0 20px;width:calc(100% - 40px);">${next.label}</button>` : ''}
  `;
}

function availableOrderHtml(order) {
  return `
    <div class="cart-block">
      <p class="sechead" style="margin:14px 0 4px;">${escapeHtml(order.restaurant_name)}</p>
      <p class="state-msg" style="padding:0 0 10px;">${escapeHtml(order.restaurant_adresse)} → ${escapeHtml(order.adresse_livraison)}</p>
      <div class="sumrow"><span>Frais de livraison</span><span>${formatMoney(order.delivery_fee_cents)}</span></div>
      <div class="sumrow"><span>Plats</span><span></span></div>
      <p class="state-msg" style="padding:0 4px 10px;">${escapeHtml(order.items_summary)}</p>
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
