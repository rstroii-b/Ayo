import { apiFetch } from '../api.js';
import { requireLogin, logout } from '../auth.js';
import { escapeHtml } from '../format.js';
import { getCurrentPosition } from '../geolocation.js';

let restaurantId = null;

if (requireLogin('/backoffice-settings.html')) {
  init();
}

document.getElementById('logout-btn').addEventListener('click', () => {
  logout();
  window.location.href = '/login.html';
});

async function init() {
  try {
    const restaurant = await apiFetch('/restaurant/mine');
    restaurantId = restaurant.id;
    document.getElementById('restaurant-name-foot').textContent = restaurant.name;
    renderStripeStatus(restaurant);
    renderForm(restaurant);
  } catch (error) {
    if (error.status === 404) {
      window.location.href = '/backoffice.html';
    } else {
      document.getElementById('settings-content').innerHTML = `<p class="state-msg">${error.message}</p>`;
    }
  }
}

function renderStripeStatus(restaurant) {
  const el = document.getElementById('stripe-status');

  if (restaurant.stripe_connected) {
    el.innerHTML = '<span class="pill ok">Paiements activés</span>';

    return;
  }

  el.innerHTML = '<button class="btn btn-primary" id="onboard-btn" type="button">Activer les paiements Stripe</button>';
  document.getElementById('onboard-btn').addEventListener('click', async () => {
    const { onboarding_url } = await apiFetch('/connect/onboard', { method: 'POST', body: {} });
    window.open(onboarding_url, '_blank');
  });
}

const BUSINESS_TYPE_LABEL = { food: 'Repas', fashion: 'Mode', furniture: 'Meubles', grocery: 'Supermarché' };

function renderForm(restaurant) {
  const content = document.getElementById('settings-content');

  content.innerHTML = `
    <p class="sectitle">Fiche commerce</p>
    <div class="cart-block" style="max-width:420px;margin:0 0 18px;">
      <div class="sumrow" style="padding:12px 4px;">
        <span>Type de commerce</span>
        <span style="color:var(--ink);font-weight:600;">${BUSINESS_TYPE_LABEL[restaurant.business_type] ?? restaurant.business_type}</span>
      </div>
    </div>
    <form class="form" id="settings-form" style="padding:0;max-width:420px;">
      <div class="field"><label for="name">Nom du commerce</label><input id="name" name="name" value="${escapeHtml(restaurant.name)}" required></div>
      <div class="field"><label for="cuisine_origine">Cuisine</label><input id="cuisine_origine" name="cuisine_origine" value="${escapeHtml(restaurant.cuisine_origine ?? '')}" placeholder="Sénégal, Cameroun…"></div>
      <div class="field"><label for="adresse">Adresse</label><input id="adresse" name="adresse" value="${escapeHtml(restaurant.adresse)}" required></div>
      <button type="button" class="btn btn-ghost" id="locate-btn">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3"/></svg>
        Utiliser ma position actuelle
      </button>
      <p class="state-msg" id="locate-status" style="margin:-6px 0 0;"></p>
      <div class="field"><label for="lat">Latitude</label><input id="lat" name="lat" type="number" step="any" value="${restaurant.lat}" required></div>
      <div class="field"><label for="lng">Longitude</label><input id="lng" name="lng" type="number" step="any" value="${restaurant.lng}" required></div>
      <button type="submit" class="btn btn-primary">Enregistrer</button>
      <p class="error-msg" id="settings-error" hidden></p>
      <p class="state-msg" id="settings-saved" hidden>Fiche mise à jour.</p>
    </form>
  `;

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
    statusEl.textContent = 'Position enregistrée — n\'oublie pas d\'enregistrer.';
  });

  document.getElementById('settings-form').addEventListener('submit', onSubmit);
}

async function onSubmit(event) {
  event.preventDefault();
  const errorEl = document.getElementById('settings-error');
  const savedEl = document.getElementById('settings-saved');
  errorEl.hidden = true;
  savedEl.hidden = true;

  const form = new FormData(event.target);

  try {
    await apiFetch(`/restaurants/${restaurantId}`, {
      method: 'PATCH',
      body: {
        name: form.get('name'),
        cuisine_origine: form.get('cuisine_origine') || null,
        adresse: form.get('adresse'),
        lat: Number(form.get('lat')),
        lng: Number(form.get('lng')),
      },
    });
    document.getElementById('restaurant-name-foot').textContent = form.get('name');
    savedEl.hidden = false;
  } catch (error) {
    errorEl.textContent = error.detail ?? error.message;
    errorEl.hidden = false;
  }
}
