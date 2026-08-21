import { apiFetch } from '../api.js';
import { escapeHtml, formatEuros } from '../format.js';

// Pas de géocodage dans ce squelette (voir panier.js) — position fixe (Paris) pour la démo,
// sert à trier par distance et estimer frais/délai de livraison sur la liste.
const DEMO_LAT = 48.8566;
const DEMO_LNG = 2.3522;

function restaurantCardHtml(restaurant, isFeatured) {
  const hasEstimate = restaurant.delivery_fee_cents !== undefined;

  return `
    <a class="rcard ${isFeatured ? 'bento-feature' : 'bento-card'}" href="/restaurant.html?id=${restaurant.id}">
      <div class="rphoto"></div>
      <div class="rinfo">
        <div class="rtoprow">
          <div class="rname">${escapeHtml(restaurant.name)}</div>
          ${hasEstimate ? `<span class="rfee">${formatEuros(restaurant.delivery_fee_cents)}</span>` : ''}
        </div>
        ${restaurant.cuisine_origine ? `<span class="rtag">${escapeHtml(restaurant.cuisine_origine)}</span>` : ''}
        <div class="rmetarow">
          ${restaurant.distance_km ? `${restaurant.distance_km.toFixed(1)} km` : ''}
          ${restaurant.distance_km && hasEstimate ? '<span class="dotsep"></span>' : ''}
          ${hasEstimate ? `${restaurant.eta_low_min}–${restaurant.eta_high_min} min` : ''}
        </div>
      </div>
    </a>
  `;
}

function skeletonHtml() {
  const line = (w) => `<div class="skel skel-line ${w}"></div>`;

  return `
    <div class="bento-grid">
      <div class="skeleton-rcard bento-feature" style="flex-direction:column;">
        <div class="skel skel-photo" style="width:100%;height:150px;"></div>
        <div class="skel-lines">${line('w60')}${line('w30')}${line('w40')}</div>
      </div>
      <div class="skeleton-rcard bento-card" style="flex-direction:column;">
        <div class="skel skel-photo" style="width:100%;height:92px;"></div>
        <div class="skel-lines">${line('w60')}${line('w40')}</div>
      </div>
      <div class="skeleton-rcard bento-card" style="flex-direction:column;">
        <div class="skel skel-photo" style="width:100%;height:92px;"></div>
        <div class="skel-lines">${line('w60')}${line('w40')}</div>
      </div>
    </div>
  `;
}

async function loadRestaurants({ region = '', q = '' } = {}) {
  const list = document.getElementById('restaurant-list');
  list.innerHTML = skeletonHtml();

  const params = new URLSearchParams({ lat: DEMO_LAT, lng: DEMO_LNG });
  if (region) params.set('region', region);
  if (q) params.set('q', q);

  try {
    const { restaurants } = await apiFetch(`/restaurants?${params}`);

    list.innerHTML = restaurants.length
      ? `<div class="bento-grid">${restaurants.map((r, i) => restaurantCardHtml(r, i === 0)).join('')}</div>`
      : '<p class="state-msg">Aucun restaurant ne correspond à ta recherche.</p>';
  } catch (error) {
    list.innerHTML = `<p class="state-msg">Impossible de charger les restaurants (${error.message}).</p>`;
  }
}

export function initHomePage() {
  let activeRegion = '';

  document.getElementById('region-chips').addEventListener('click', (event) => {
    const chip = event.target.closest('.chip');
    if (!chip) return;

    document.querySelectorAll('.chip').forEach((c) => c.classList.remove('active'));
    chip.classList.add('active');
    activeRegion = chip.dataset.region;
    loadRestaurants({ region: activeRegion, q: document.getElementById('search-input').value });
  });

  let debounceTimer;
  document.getElementById('search-input').addEventListener('input', (event) => {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(() => {
      loadRestaurants({ region: activeRegion, q: event.target.value });
    }, 300);
  });

  loadRestaurants();
}
