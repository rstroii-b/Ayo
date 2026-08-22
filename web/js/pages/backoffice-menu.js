import { apiFetch } from '../api.js';
import { requireLogin, logout } from '../auth.js';
import { formatMoney, escapeHtml, safeImageUrl } from '../format.js';

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

function variantsPanelHtml(item) {
  const rows = (item.options ?? []).map((o) => `
    <div class="mrow" style="padding:8px 18px;">
      <div class="mname" style="flex:1;font-weight:600;font-size:12.5px;">
        ${escapeHtml(o.name)}${o.option_group ? `<div class="d">${escapeHtml(o.option_group)}</div>` : ''}
      </div>
      <div class="mprice" style="font-size:12px;">${o.price_delta_cents ? formatMoney(o.price_delta_cents) : '—'}</div>
      <div class="mtoggle" style="font-size:12px;">${o.stock_quantity === null ? 'Stock illimité' : `Stock : ${o.stock_quantity}`}</div>
      <button class="btn btn-ghost" type="button" data-delete-option-id="${o.id}" data-item-id="${item.id}" style="padding:5px 9px;font-size:11px;">Retirer</button>
    </div>
  `).join('') || '<div class="mrow" style="padding:8px 18px;"><p class="state-msg" style="margin:0;">Aucune variante pour l\'instant.</p></div>';

  return `
    <div class="variants-panel" id="variants-${item.id}" hidden>
      ${rows}
      <form class="inline-form" data-add-option-item-id="${item.id}" style="padding:10px 18px;margin-bottom:0;">
        <div class="field"><input name="name" placeholder="Ex : M, Bleu, Fort…" required style="min-width:100px;"></div>
        <div class="field"><input name="option_group" placeholder="Groupe (ex : Taille)" style="min-width:100px;"></div>
        <div class="field"><input name="price_delta_cents" type="number" step="0.10" placeholder="Delta prix €" style="min-width:100px;"></div>
        <div class="field"><input name="stock_quantity" type="number" min="0" placeholder="Stock (vide = illimité)" style="min-width:120px;"></div>
        <button class="btn btn-ghost" type="submit" style="padding:8px 14px;font-size:12px;">Ajouter</button>
      </form>
    </div>
  `;
}

function categoryTableHtml(category) {
  const rows = category.items.map((item) => {
    const photoUrl = safeImageUrl(item.photo_url);
    const optionCount = item.options?.length ?? 0;

    return `
    <div class="mrow">
      ${photoUrl
        ? `<img src="${escapeHtml(photoUrl)}" alt="" style="width:38px;height:38px;border-radius:8px;object-fit:cover;flex-shrink:0;">`
        : '<div style="width:38px;height:38px;border-radius:8px;background:var(--surface-alt);flex-shrink:0;"></div>'}
      <div class="mname">${escapeHtml(item.name)}${item.description ? `<div class="d">${escapeHtml(item.description)}</div>` : ''}</div>
      <div class="mprice">${formatMoney(item.price_cents)}</div>
      <div class="mtoggle">
        <button class="sw ${item.is_available ? 'on' : 'off'}" data-item-id="${item.id}" data-available="${item.is_available ? 1 : 0}" aria-label="Disponibilité de ${escapeHtml(item.name)}"></button>
        ${item.is_available ? 'Actif' : 'Épuisé'}
      </div>
      <button class="btn btn-ghost" type="button" data-photo-item-id="${item.id}" style="padding:6px 10px;font-size:11.5px;">Photo</button>
      <button class="btn btn-ghost" type="button" data-details-item-id="${item.id}"
        data-current-description="${escapeHtml(item.description ?? '')}" data-current-ingredients="${escapeHtml(item.ingredients ?? '')}"
        style="padding:6px 10px;font-size:11.5px;">Détails</button>
      <button class="btn btn-ghost" type="button" data-toggle-variants="${item.id}" style="padding:6px 10px;font-size:11.5px;">Variantes${optionCount ? ` (${optionCount})` : ''}</button>
    </div>
    ${variantsPanelHtml(item)}
  `;
  }).join('') || '<div class="mrow"><p class="state-msg" style="margin:0;">Aucun article dans cette catégorie.</p></div>';

  return `
    <p class="sectitle">${escapeHtml(category.name)}</p>
    <div class="mtable">
      <div class="mrow head"><div style="width:38px;flex-shrink:0;"></div><div class="mname">Article</div><div class="mprice">Prix</div><div class="mtoggle">Disponible</div><div style="width:150px;flex-shrink:0;"></div></div>
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

    <p class="sectitle">Ajouter un article</p>
    <form class="inline-form" id="item-form">
      <div class="field">
        <select name="category_id" required>
          ${categories.map((c) => `<option value="${c.id}">${escapeHtml(c.name)}</option>`).join('')}
        </select>
      </div>
      <div class="field"><input name="name" placeholder="Nom de l'article" required></div>
      <div class="field"><input name="description" placeholder="Description (facultatif)"></div>
      <div class="field"><input name="ingredients" placeholder="Ingrédients, séparés par des virgules (facultatif)"></div>
      <div class="field"><input name="price" type="number" step="0.10" min="0" placeholder="Prix en €" required></div>
      <div class="field"><input name="vat_rate" type="number" step="0.1" min="0" max="100" placeholder="TVA % (10 par défaut)"></div>
      <div class="field"><input name="photo_url" type="url" placeholder="URL de la photo (facultatif)"></div>
      <button class="btn btn-primary" type="submit">Ajouter l'article</button>
    </form>
    <p class="error-msg" id="menu-error" hidden></p>
  `;

  document.getElementById('category-form')?.addEventListener('submit', onAddCategory);
  document.getElementById('item-form')?.addEventListener('submit', onAddItem);

  content.querySelectorAll('.sw').forEach((btn) => btn.addEventListener('click', onToggleAvailability));
  content.querySelectorAll('[data-photo-item-id]').forEach((btn) => btn.addEventListener('click', onEditPhoto));
  content.querySelectorAll('[data-details-item-id]').forEach((btn) => btn.addEventListener('click', onEditDetails));
  content.querySelectorAll('[data-toggle-variants]').forEach((btn) => btn.addEventListener('click', onToggleVariants));
  content.querySelectorAll('[data-delete-option-id]').forEach((btn) => btn.addEventListener('click', onDeleteOption));
  content.querySelectorAll('[data-add-option-item-id]').forEach((form) => form.addEventListener('submit', onAddOption));
}

function onToggleVariants(event) {
  const panel = document.getElementById(`variants-${event.currentTarget.dataset.toggleVariants}`);
  panel.hidden = !panel.hidden;
}

async function onAddOption(event) {
  event.preventDefault();
  const itemId = event.target.dataset.addOptionItemId;
  const form = new FormData(event.target);

  await apiFetch(`/restaurants/${restaurantId}/menu/items/${itemId}/options`, {
    method: 'POST',
    body: {
      name: form.get('name'),
      option_group: form.get('option_group') || null,
      price_delta_cents: form.get('price_delta_cents') ? Math.round(Number(form.get('price_delta_cents')) * 100) : 0,
      stock_quantity: form.get('stock_quantity') ? Number(form.get('stock_quantity')) : null,
    },
  });
  loadMenu();
}

async function onDeleteOption(event) {
  const { deleteOptionId, itemId } = event.currentTarget.dataset;
  await apiFetch(`/restaurants/${restaurantId}/menu/items/${itemId}/options/${deleteOptionId}`, { method: 'DELETE' });
  loadMenu();
}

async function onEditPhoto(event) {
  const itemId = event.currentTarget.dataset.photoItemId;
  const url = prompt('URL de la photo (laisser vide pour la retirer) :');

  if (url === null) return;

  await apiFetch(`/restaurants/${restaurantId}/menu/items/${itemId}`, {
    method: 'PATCH',
    body: { photo_url: url || null },
  });
  loadMenu();
}

/** Description + ingrédients partagent un seul bouton "Détails" (deux prompts successifs),
 * même schéma minimaliste que "Photo" ci-dessus plutôt qu'un formulaire dédié. */
async function onEditDetails(event) {
  const btn = event.currentTarget;
  const itemId = btn.dataset.detailsItemId;

  const description = prompt('Description (laisser vide pour la retirer) :', btn.dataset.currentDescription);
  if (description === null) return;

  const ingredients = prompt('Ingrédients, séparés par des virgules (laisser vide pour la retirer) :', btn.dataset.currentIngredients);
  if (ingredients === null) return;

  await apiFetch(`/restaurants/${restaurantId}/menu/items/${itemId}`, {
    method: 'PATCH',
    body: { description: description || null, ingredients: ingredients || null },
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
        description: form.get('description') || null,
        ingredients: form.get('ingredients') || null,
        price_cents: Math.round(Number(form.get('price')) * 100),
        vat_rate: form.get('vat_rate') ? Number(form.get('vat_rate')) : 10.00,
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
