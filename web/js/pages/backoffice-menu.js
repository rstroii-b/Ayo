import { apiFetch } from '../api.js';
import { requireLogin, logout } from '../auth.js';
import { formatEuros } from '../format.js';

let restaurantId = null;

if (requireLogin('/backoffice-menu.html')) {
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
    await loadMenu();
  } catch (error) {
    if (error.status === 404) {
      window.location.href = '/backoffice.html';
    } else {
      document.getElementById('menu-content').innerHTML = `<p class="state-msg">${error.message}</p>`;
    }
  }
}

function categoryTableHtml(category) {
  const rows = category.items.map((item) => `
    <div class="mrow">
      ${item.photo_url
        ? `<img src="${escapeAttr(item.photo_url)}" alt="" style="width:38px;height:38px;border-radius:8px;object-fit:cover;flex-shrink:0;">`
        : '<div style="width:38px;height:38px;border-radius:8px;background:var(--surface-alt);flex-shrink:0;"></div>'}
      <div class="mname">${item.name}${item.description ? `<div class="d">${item.description}</div>` : ''}</div>
      <div class="mprice">${formatEuros(item.price_cents)}</div>
      <div class="mtoggle">
        <button class="sw ${item.is_available ? 'on' : 'off'}" data-item-id="${item.id}" data-available="${item.is_available ? 1 : 0}" aria-label="Disponibilité de ${item.name}"></button>
        ${item.is_available ? 'Actif' : 'Épuisé'}
      </div>
      <button class="btn btn-ghost" type="button" data-photo-item-id="${item.id}" style="padding:6px 10px;font-size:11.5px;">Photo</button>
    </div>
  `).join('') || '<div class="mrow"><p class="state-msg" style="margin:0;">Aucun plat dans cette catégorie.</p></div>';

  return `
    <p class="sectitle">${category.name}</p>
    <div class="mtable">
      <div class="mrow head"><div style="width:38px;flex-shrink:0;"></div><div class="mname">Plat</div><div class="mprice">Prix</div><div class="mtoggle">Disponible</div><div style="width:64px;flex-shrink:0;"></div></div>
      ${rows}
    </div>
  `;
}

async function loadMenu() {
  const { categories } = await apiFetch('/restaurant/mine/menu');

  const content = document.getElementById('menu-content');
  content.innerHTML = `
    <div id="tables">${categories.map(categoryTableHtml).join('') || '<p class="state-msg">Aucune catégorie pour l\'instant — créez-en une ci-dessous.</p>'}</div>

    <p class="sectitle">Ajouter une catégorie</p>
    <form class="inline-form" id="category-form">
      <div class="field"><input name="name" placeholder="Ex : Desserts" required></div>
      <button class="btn btn-ghost" type="submit">Ajouter</button>
    </form>

    <p class="sectitle">Ajouter un plat</p>
    <form class="inline-form" id="item-form">
      <div class="field">
        <select name="category_id" required>
          ${categories.map((c) => `<option value="${c.id}">${c.name}</option>`).join('')}
        </select>
      </div>
      <div class="field"><input name="name" placeholder="Nom du plat" required></div>
      <div class="field"><input name="price" type="number" step="0.10" min="0" placeholder="Prix en €" required></div>
      <div class="field"><input name="photo_url" type="url" placeholder="URL de la photo (facultatif)"></div>
      <button class="btn btn-primary" type="submit">Ajouter le plat</button>
    </form>
    <p class="error-msg" id="menu-error" hidden></p>
  `;

  document.getElementById('category-form')?.addEventListener('submit', onAddCategory);
  document.getElementById('item-form')?.addEventListener('submit', onAddItem);

  content.querySelectorAll('.sw').forEach((btn) => btn.addEventListener('click', onToggleAvailability));
  content.querySelectorAll('[data-photo-item-id]').forEach((btn) => btn.addEventListener('click', onEditPhoto));
}

function escapeAttr(value) {
  return String(value).replace(/&/g, '&amp;').replace(/"/g, '&quot;');
}

async function onEditPhoto(event) {
  const itemId = event.currentTarget.dataset.photoItemId;
  const url = prompt('URL de la photo du plat (laisser vide pour la retirer) :');

  if (url === null) return;

  await apiFetch(`/restaurants/${restaurantId}/menu/items/${itemId}`, {
    method: 'PATCH',
    body: { photo_url: url || null },
  });
  loadMenu();
}

async function onAddCategory(event) {
  event.preventDefault();
  const name = new FormData(event.target).get('name');

  await apiFetch(`/restaurants/${restaurantId}/menu/categories`, { method: 'POST', body: { name } });
  loadMenu();
}

async function onAddItem(event) {
  event.preventDefault();
  const errorEl = document.getElementById('menu-error');
  errorEl.hidden = true;

  const form = new FormData(event.target);

  try {
    await apiFetch(`/restaurants/${restaurantId}/menu/items`, {
      method: 'POST',
      body: {
        category_id: Number(form.get('category_id')),
        name: form.get('name'),
        price_cents: Math.round(Number(form.get('price')) * 100),
        photo_url: form.get('photo_url') || null,
      },
    });
    loadMenu();
  } catch (error) {
    errorEl.textContent = error.detail ?? error.message;
    errorEl.hidden = false;
  }
}

async function onToggleAvailability(event) {
  const btn = event.currentTarget;
  const nextValue = btn.dataset.available === '1' ? 0 : 1;

  btn.disabled = true;
  await apiFetch(`/restaurants/${restaurantId}/menu/items/${btn.dataset.itemId}`, {
    method: 'PATCH',
    body: { is_available: nextValue },
  });
  loadMenu();
}
