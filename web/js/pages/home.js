import { apiFetch } from '../api.js';
import { escapeHtml } from '../format.js';

function restaurantCardHtml(restaurant) {
  return `
    <a class="rcard" href="/restaurant.html?id=${restaurant.id}">
      <div class="rphoto"></div>
      <div class="rinfo">
        <div class="rname">${escapeHtml(restaurant.name)}</div>
        ${restaurant.cuisine_origine ? `<span class="rtag">${escapeHtml(restaurant.cuisine_origine)}</span>` : ''}
        ${restaurant.distance_km ? `<div class="rmeta">${restaurant.distance_km.toFixed(1)} km</div>` : ''}
      </div>
    </a>
  `;
}

async function loadRestaurants({ region = '', q = '' } = {}) {
  const list = document.getElementById('restaurant-list');
  list.innerHTML = '<p class="state-msg">Chargement des restaurants…</p>';

  const params = new URLSearchParams();
  if (region) params.set('region', region);
  if (q) params.set('q', q);

  try {
    const { restaurants } = await apiFetch(`/restaurants?${params}`);

    list.innerHTML = restaurants.length
      ? restaurants.map(restaurantCardHtml).join('')
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
