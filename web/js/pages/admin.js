import { apiFetch, apiFetchBlob } from '../api.js';
import { requireLogin, logout } from '../auth.js';
import { escapeHtml } from '../format.js';
import { renderError } from '../ui.js';

const STATUS_LABEL = { pending: 'En attente', verified: 'Vérifié', rejected: 'Rejeté' };
let currentDocumentUrl = null;

if (requireLogin('/admin.html')) {
  init();
}

document.getElementById('logout-btn').addEventListener('click', () => {
  logout();
  window.location.href = '/login.html';
});

function init() {
  document.getElementById('status-filter').addEventListener('change', loadDrivers);
  document.getElementById('kyc-modal-close').addEventListener('click', closeModal);
  loadDrivers();
}

async function loadDrivers() {
  const status = document.getElementById('status-filter').value;
  const list = document.getElementById('drivers-list');
  list.innerHTML = '<p class="state-msg">Chargement…</p>';

  try {
    const { drivers } = await apiFetch(`/admin/drivers?kyc_status=${status}`);

    if (drivers.length === 0) {
      list.innerHTML = '<p class="state-msg">Aucun livreur dans cette catégorie.</p>';

      return;
    }

    list.innerHTML = `
      <div class="mtable">
        <div class="mrow head">
          <div class="mname">Livreur</div>
          <div class="mtoggle">Véhicule</div>
          <div class="mtoggle">Statut</div>
          <div class="mtoggle" style="width:120px;justify-content:flex-end;">Document</div>
        </div>
        ${drivers.map(rowHtml).join('')}
      </div>
    `;
  } catch (error) {
    renderError(list, error, { onRetry: () => loadDrivers() });
  }
}

function rowHtml(driver) {
  return `
    <div class="mrow" data-driver-id="${driver.id}">
      <div class="mname">${escapeHtml(driver.first_name)} ${escapeHtml(driver.last_name)}<div class="d">${escapeHtml(driver.phone ?? driver.email)}${driver.rccm ? ` — RCCM ${escapeHtml(driver.rccm)}` : ''}</div></div>
      <div class="mtoggle">${escapeHtml(driver.vehicule_type)}</div>
      <div class="mtoggle"><span class="pill ${driver.kyc_status === 'verified' ? 'ok' : 'warn'}" style="padding:4px 10px;font-size:11px;">${STATUS_LABEL[driver.kyc_status]}</span></div>
      <div class="mtoggle" style="width:120px;justify-content:flex-end;">
        ${driver.has_kyc_document
          ? `<button class="btn btn-ghost" type="button" data-open="${driver.id}">Voir</button>`
          : '<span class="state-msg">Aucun document</span>'}
      </div>
    </div>
  `;
}

document.getElementById('drivers-list').addEventListener('click', (event) => {
  const btn = event.target.closest('button[data-open]');
  if (btn) openModal(Number(btn.dataset.open));
});

async function openModal(driverId) {
  const overlay = document.getElementById('kyc-modal-overlay');
  const body = document.getElementById('kyc-modal-body');
  body.innerHTML = '<p class="state-msg">Chargement du document…</p>';
  overlay.hidden = false;

  try {
    const { blob, contentType } = await apiFetchBlob(`/admin/drivers/${driverId}/kyc-document`);
    revokeCurrentDocumentUrl();
    currentDocumentUrl = URL.createObjectURL(blob);

    const preview = (contentType ?? '').startsWith('image/')
      ? `<img src="${currentDocumentUrl}" style="max-width:100%;border-radius:8px;">`
      : `<iframe src="${currentDocumentUrl}" style="width:100%;height:420px;border:1px solid var(--line);border-radius:8px;"></iframe>`;

    body.innerHTML = `
      ${preview}
      <div class="field" style="margin-top:14px;">
        <label for="kyc-reject-reason">Motif de rejet (facultatif)</label>
        <input id="kyc-reject-reason" placeholder="Ex: photo illisible, document expiré…">
      </div>
      <div style="display:flex;gap:10px;margin-top:10px;">
        <button class="btn btn-primary" type="button" id="kyc-approve-btn">Approuver</button>
        <button class="btn btn-ghost" type="button" id="kyc-reject-btn">Rejeter</button>
      </div>
      <p class="error-msg" id="kyc-decision-error" hidden></p>
    `;

    document.getElementById('kyc-approve-btn').addEventListener('click', () => decide(driverId, 'verified'));
    document.getElementById('kyc-reject-btn').addEventListener('click', () => {
      const reason = document.getElementById('kyc-reject-reason').value.trim();
      decide(driverId, 'rejected', reason || undefined);
    });
  } catch (error) {
    renderError(body, error);
  }
}

async function decide(driverId, status, reason) {
  const errorEl = document.getElementById('kyc-decision-error');
  errorEl.hidden = true;

  try {
    await apiFetch(`/admin/drivers/${driverId}/kyc`, { method: 'PATCH', body: { status, reason } });
    closeModal();
    loadDrivers();
  } catch (error) {
    errorEl.textContent = error.detail ?? error.message;
    errorEl.hidden = false;
  }
}

function revokeCurrentDocumentUrl() {
  if (currentDocumentUrl) {
    URL.revokeObjectURL(currentDocumentUrl);
    currentDocumentUrl = null;
  }
}

function closeModal() {
  document.getElementById('kyc-modal-overlay').hidden = true;
  revokeCurrentDocumentUrl();
}
