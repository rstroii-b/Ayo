import { apiFetch } from '../api.js';
import { escapeHtml, formatMoney, safeImageUrl } from '../format.js';
import { getCurrentPosition } from '../geolocation.js';

// Position par défaut si la géolocalisation est refusée/indisponible (Abidjan) — sert de repli,
// plus la position réelle pour trier par distance et estimer frais/délai de livraison.
const FALLBACK_POSITION = { lat: 5.3600, lng: -4.0083 };
let userPosition = null;

const CATEGORY_COPY = {
  food: { heading: 'Restaurants près de toi', placeholder: 'Plat, restaurant, région d\'Afrique…', empty: 'Aucun restaurant ne correspond à ta recherche.' },
  fashion: { heading: 'Boutiques mode près de toi', placeholder: 'Vêtement, boutique…', empty: 'Aucune boutique ne correspond à ta recherche.' },
  furniture: { heading: 'Meubles près de toi', placeholder: 'Meuble, magasin…', empty: 'Aucun magasin ne correspond à ta recherche.' },
  grocery: { heading: 'Supermarchés près de toi', placeholder: 'Produit, supermarché…', empty: 'Aucun supermarché ne correspond à ta recherche.' },
};

function restaurantCardHtml(restaurant, isFeatured) {
  const hasEstimate = restaurant.delivery_fee_cents !== undefined;
  const photoUrl = safeImageUrl(restaurant.photo_url);
  const photoStyle = photoUrl ? ` style="background-image:url('${escapeHtml(photoUrl)}')"` : '';

  return `
    <a class="rcard ${isFeatured ? 'bento-feature' : 'bento-card'}" href="/restaurant.html?id=${restaurant.id}">
      <div class="rphoto"${photoStyle}></div>
      <div class="rinfo">
        <div class="rtoprow">
          <div class="rname">${escapeHtml(restaurant.name)}</div>
          ${hasEstimate ? `<span class="rfee">${formatMoney(restaurant.delivery_fee_cents)}</span>` : ''}
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

async function loadRestaurants({ businessType = 'food', region = '', q = '' } = {}) {
  const list = document.getElementById('restaurant-list');
  list.innerHTML = skeletonHtml();

  if (userPosition === null) {
    userPosition = await getCurrentPosition({ fallback: FALLBACK_POSITION });
  }

  const params = new URLSearchParams({ lat: userPosition.lat, lng: userPosition.lng, business_type: businessType });
  if (region) params.set('region', region);
  if (q) params.set('q', q);

  try {
    const { restaurants } = await apiFetch(`/restaurants?${params}`);

    list.innerHTML = restaurants.length
      ? `<div class="bento-grid">${restaurants.map((r, i) => restaurantCardHtml(r, i === 0)).join('')}</div>`
      : `<p class="state-msg">${CATEGORY_COPY[businessType].empty}</p>`;
  } catch (error) {
    list.innerHTML = `<p class="state-msg">Impossible de charger les commerces (${error.message}).</p>`;
  }
}

export function initHomePage() {
  let activeRegion = '';
  let activeType = 'food';

  document.getElementById('business-type-tabs').addEventListener('click', (event) => {
    const tile = event.target.closest('.cat-tile');
    if (!tile || tile.classList.contains('active')) return;

    document.querySelectorAll('.cat-tile').forEach((t) => t.classList.remove('active'));
    tile.classList.add('active');
    activeType = tile.dataset.type;

    const copy = CATEGORY_COPY[activeType];
    document.getElementById('list-heading').textContent = copy.heading;
    document.getElementById('search-input').placeholder = copy.placeholder;

    // Les chips région (cuisine d'origine) n'ont de sens que pour les repas.
    document.getElementById('region-chips').hidden = activeType !== 'food';
    activeRegion = '';
    document.querySelectorAll('.chip').forEach((c) => c.classList.toggle('active', c.dataset.region === ''));

    loadRestaurants({ businessType: activeType, q: document.getElementById('search-input').value });
  });

  document.getElementById('region-chips').addEventListener('click', (event) => {
    const chip = event.target.closest('.chip');
    if (!chip) return;

    document.querySelectorAll('.chip').forEach((c) => c.classList.remove('active'));
    chip.classList.add('active');
    activeRegion = chip.dataset.region;
    loadRestaurants({ businessType: activeType, region: activeRegion, q: document.getElementById('search-input').value });
  });

  let debounceTimer;
  document.getElementById('search-input').addEventListener('input', (event) => {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(() => {
      loadRestaurants({ businessType: activeType, region: activeRegion, q: event.target.value });
    }, 300);
  });

  loadRestaurants();
}
