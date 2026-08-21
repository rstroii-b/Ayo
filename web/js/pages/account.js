import { requireLogin, currentUser, logout } from '../auth.js';
import { getAddress, promptForAddress } from '../address.js';
import { cartItemCount } from '../cart.js';
import { pushSupported, isSubscribed, subscribeToPush } from '../push.js';
import { webauthnSupported, registerPasskey, listPasskeys } from '../webauthn.js';
import { escapeHtml } from '../format.js';

const CHEVRON = '<svg class="chev" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18l6-6-6-6"/></svg>';
const BELL_ICON = '<svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8a6 6 0 10-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 01-3.46 0"/></svg>';
const FINGERPRINT_ICON = '<svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2a5 5 0 00-5 5v3a5 5 0 0010 0V7a5 5 0 00-5-5z"/><path d="M7 10a5 5 0 0010 0"/><path d="M12 15v7"/><path d="M9 19h6"/></svg>';

function menuRow(href, iconSvg, label, badge = '') {
  return `
    <a class="menu-row" href="${href}">
      <span class="micon">${iconSvg}</span>
      ${label}
      ${badge}
      ${CHEVRON}
    </a>
  `;
}

const ROLE_LABELS = { client: 'Client', restaurant_owner: 'Restaurateur', driver: 'Livreur' };

function render() {
  const user = currentUser();
  const content = document.getElementById('account-content');

  content.innerHTML = `
    <div class="cart-block" style="padding:18px 16px;margin-bottom:16px;">
      <div style="display:flex;align-items:center;gap:14px;margin-bottom:4px;">
        <div class="avatar" style="width:52px;height:52px;font-size:19px;">${escapeHtml((user.first_name?.[0] ?? '?').toUpperCase())}</div>
        <div>
          <div style="font-weight:700;font-size:16px;">${escapeHtml(user.first_name)} ${escapeHtml(user.last_name)}</div>
          <span class="rtag" style="margin:0;">${ROLE_LABELS[user.role] ?? user.role}</span>
        </div>
      </div>
    </div>

    <div class="cart-block">
      <div class="sumrow" style="padding:12px 4px;"><span>Email</span><span>${escapeHtml(user.email)}</span></div>
      ${user.phone ? `<div class="sumrow" style="padding:12px 4px;"><span>Téléphone</span><span>${escapeHtml(user.phone)}</span></div>` : ''}
    </div>

    <div class="cart-block">
      <button class="sumrow" id="address-row" type="button" style="width:100%;padding:12px 4px;background:none;border:none;font-family:inherit;cursor:pointer;text-align:left;">
        <span>Adresse de livraison</span>
        <span id="address-value" style="color:var(--ink);font-weight:600;">${getAddress() ? escapeHtml(getAddress()) : 'Non renseignée'}</span>
      </button>
    </div>

    ${pushSupported() || webauthnSupported() ? `
    <div class="menu-list-account">
      ${pushSupported() ? `
      <button class="menu-row" id="notif-row" type="button">
        <span class="micon">${BELL_ICON}</span>
        Notifications
        <span class="rowbadge" id="notif-status">…</span>
      </button>
      ` : ''}
      ${webauthnSupported() ? `
      <button class="menu-row" id="passkey-row" type="button">
        <span class="micon">${FINGERPRINT_ICON}</span>
        Face ID / empreinte
        <span class="rowbadge" id="passkey-status">…</span>
      </button>
      ` : ''}
    </div>
    ` : ''}

    <div class="menu-list-account">
      ${menuRow(
        '/panier.html',
        '<svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M6 6h15l-1.5 9h-13z"/><path d="M6 6L5 3H2"/><circle cx="9" cy="20" r="1.4"/><circle cx="17" cy="20" r="1.4"/></svg>',
        'Mon panier',
        cartItemCount() > 0 ? `<span class="rowbadge">${cartItemCount()}</span>` : ''
      )}
      ${menuRow(
        '/wallet.html',
        '<svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7a2 2 0 012-2h13a2 2 0 012 2v10a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><path d="M17 12h.01"/><path d="M3 9h18"/></svg>',
        'Mon portefeuille'
      )}
    </div>

    <div class="menu-list-account">
      ${menuRow(
        '/help.html',
        '<svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M9.5 9a2.5 2.5 0 015 .5c0 1.7-2.5 1.9-2.5 3.5"/><path d="M12 17h.01"/></svg>',
        'Aide'
      )}
      ${menuRow(
        '/privacy.html',
        '<svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l7 3v6c0 4.5-3 7.5-7 9-4-1.5-7-4.5-7-9V6z"/></svg>',
        'Confidentialité'
      )}
      ${menuRow(
        '/about.html',
        '<svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 8h.01"/><path d="M11 12h1v5h1"/></svg>',
        'À propos'
      )}
    </div>

    ${user.role === 'restaurant_owner' ? '<a class="btn btn-ghost btn-block" href="/backoffice.html" style="margin-bottom:12px;">Mon espace restaurateur</a>' : ''}
    ${user.role === 'driver' ? '<a class="btn btn-ghost btn-block" href="/driver.html" style="margin-bottom:12px;">Mon espace livreur</a>' : ''}

    <button class="btn btn-ghost btn-block" id="logout-btn" type="button" style="color:var(--chili);">Se déconnecter</button>
  `;

  document.getElementById('address-row').addEventListener('click', () => {
    if (promptForAddress() !== null) {
      document.getElementById('address-value').textContent = getAddress();
    }
  });

  document.getElementById('logout-btn').addEventListener('click', () => {
    logout();
    window.location.href = '/index.html';
  });

  const notifRow = document.getElementById('notif-row');
  if (notifRow) {
    isSubscribed().then((yes) => {
      document.getElementById('notif-status').textContent = yes ? 'Activées' : 'Désactivées';
    });

    notifRow.addEventListener('click', async () => {
      const statusEl = document.getElementById('notif-status');
      statusEl.textContent = '…';
      const ok = await subscribeToPush();
      statusEl.textContent = ok ? 'Activées' : 'Refusées';
    });
  }

  const passkeyRow = document.getElementById('passkey-row');
  if (passkeyRow) {
    refreshPasskeyStatus();

    passkeyRow.addEventListener('click', async () => {
      const statusEl = document.getElementById('passkey-status');
      statusEl.textContent = '…';

      try {
        const label = `${navigator.platform || 'Appareil'} · ${new Date().toLocaleDateString('fr-FR')}`;
        await registerPasskey(label);
      } catch (error) {
        if (error.name !== 'NotAllowedError') {
          alert('Activation impossible sur cet appareil.');
        }
      }

      refreshPasskeyStatus();
    });
  }
}

async function refreshPasskeyStatus() {
  const statusEl = document.getElementById('passkey-status');
  if (!statusEl) return;

  try {
    const credentials = await listPasskeys();
    statusEl.textContent = credentials.length > 0 ? `${credentials.length} activée${credentials.length > 1 ? 's' : ''}` : 'Activer';
  } catch {
    statusEl.textContent = 'Activer';
  }
}

if (requireLogin('/account.html')) {
  render();
}
