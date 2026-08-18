import { requireLogin, currentUser, logout } from '../auth.js';
import { getAddress, promptForAddress } from '../address.js';

const ROLE_LABELS = { client: 'Client', restaurant_owner: 'Restaurateur', driver: 'Livreur' };

function render() {
  const user = currentUser();
  const content = document.getElementById('account-content');

  content.innerHTML = `
    <div class="cart-block" style="padding:18px 16px;margin-bottom:16px;">
      <div style="display:flex;align-items:center;gap:14px;margin-bottom:4px;">
        <div class="avatar" style="width:52px;height:52px;font-size:19px;">${(user.first_name?.[0] ?? '?').toUpperCase()}</div>
        <div>
          <div style="font-weight:700;font-size:16px;">${user.first_name} ${user.last_name}</div>
          <span class="rtag" style="margin:0;">${ROLE_LABELS[user.role] ?? user.role}</span>
        </div>
      </div>
    </div>

    <div class="cart-block">
      <div class="sumrow" style="padding:12px 4px;"><span>Email</span><span>${user.email}</span></div>
      ${user.phone ? `<div class="sumrow" style="padding:12px 4px;"><span>Téléphone</span><span>${user.phone}</span></div>` : ''}
    </div>

    <div class="cart-block">
      <button class="sumrow" id="address-row" type="button" style="width:100%;padding:12px 4px;background:none;border:none;font-family:inherit;cursor:pointer;text-align:left;">
        <span>Adresse de livraison</span>
        <span id="address-value" style="color:var(--ink);font-weight:600;">${getAddress() ?? 'Non renseignée'}</span>
      </button>
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
}

if (requireLogin('/account.html')) {
  render();
}
