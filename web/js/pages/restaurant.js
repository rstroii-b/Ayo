import { apiFetch } from '../api.js';
import { addItem, getCart, cartSubtotalCents, cartItemCount } from '../cart.js';
import { formatMoney, escapeHtml, safeImageUrl } from '../format.js';

const restaurantId = new URLSearchParams(window.location.search).get('id');
let currency = 'EUR'; // écrasé après le chargement de la fiche commerce (voir load())
let restaurantName = '';
const itemsById = new Map(); // reconstruit à chaque load() — sert à ouvrir la fiche produit (photo, description, ingrédients)

function renderCartBar() {
  const cart = getCart();
  const bar = document.getElementById('cart-bar');

  if (cart.restaurantId !== Number(restaurantId) || cart.items.length === 0) {
    bar.hidden = true;

    return;
  }

  bar.hidden = false;
  const count = cartItemCount(cart);
  document.getElementById('cart-summary').textContent =
    `Voir le panier · ${count} article${count > 1 ? 's' : ''} · ${formatMoney(cartSubtotalCents(cart), cart.currency)}`;
}

/** Groupe les variantes par option_group — un groupe nommé = un choix obligatoire (taille,
 * couleur...), les options sans groupe = des ajouts facultatifs (ex: suppléments). */
function groupOptions(options) {
  const named = new Map();
  const loose = [];

  for (const option of options) {
    if (option.option_group) {
      if (!named.has(option.option_group)) named.set(option.option_group, []);
      named.get(option.option_group).push(option);
    } else {
      loose.push(option);
    }
  }

  return { named, loose };
}

function variantPanelHtml(item) {
  const { named, loose } = groupOptions(item.options);

  const namedGroupsHtml = [...named.entries()].map(([group, opts]) => `
    <div class="vgroup">
      <span class="vgroup-label">${escapeHtml(group)}</span>
      <div class="vchip-row">
        ${opts.map((o) => `
          <button type="button" class="vchip" data-option-id="${o.id}" data-option-name="${escapeHtml(o.name)}" data-group="${escapeHtml(group)}" data-delta="${o.price_delta_cents}"
            ${o.stock_quantity === 0 ? 'disabled' : ''}>
            ${escapeHtml(o.name)}${o.price_delta_cents ? ` (${o.price_delta_cents > 0 ? '+' : ''}${formatMoney(o.price_delta_cents, currency)})` : ''}
          </button>
        `).join('')}
      </div>
    </div>
  `).join('');

  const looseHtml = loose.length ? `
    <div class="vgroup">
      <span class="vgroup-label">Suppléments</span>
      <div class="vchip-row">
        ${loose.map((o) => `
          <button type="button" class="vchip vchip-toggle" data-option-id="${o.id}" data-option-name="${escapeHtml(o.name)}" data-delta="${o.price_delta_cents}">
            ${escapeHtml(o.name)}${o.price_delta_cents ? ` (+${formatMoney(o.price_delta_cents, currency)})` : ''}
          </button>
        `).join('')}
      </div>
    </div>
  ` : '';

  return `
    <div class="variant-panel" id="variant-${item.id}" hidden>
      ${namedGroupsHtml}
      ${looseHtml}
      <button type="button" class="btn btn-primary btn-block variant-confirm" data-item-id="${item.id}" data-base-price="${item.price_cents}" disabled>
        Choisis une option
      </button>
    </div>
  `;
}

function menuItemHtml(item) {
  const photoUrl = safeImageUrl(item.photo_url);
  const photoStyle = photoUrl ? ` style="background-image:url('${escapeHtml(photoUrl)}')"` : '';
  const hasOptions = item.options && item.options.length > 0;

  return `
    <div class="menu-item" data-item-id="${item.id}">
      <div class="ithumb" data-open-id="${item.id}"${photoStyle}></div>
      <div class="iinfo">
        <div class="iname" data-open-id="${item.id}">${escapeHtml(item.name)}</div>
        ${item.description ? `<div class="idesc">${escapeHtml(item.description)}</div>` : ''}
        <div class="ibottom">
          <span class="price">${formatMoney(item.price_cents, currency)}</span>
          <button class="add-btn" type="button" data-item-id="${item.id}" data-name="${escapeHtml(item.name)}" data-price="${item.price_cents}"
            data-has-options="${hasOptions ? 1 : 0}" aria-label="Ajouter ${escapeHtml(item.name)} au panier">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
          </button>
        </div>
      </div>
    </div>
    ${hasOptions ? variantPanelHtml(item) : ''}
  `;
}

function ingredientsHtml(ingredients) {
  if (!ingredients) return '';

  const items = ingredients.split(/[,\n]/).map((s) => s.trim()).filter(Boolean);
  if (items.length === 0) return '';

  return `
    <div class="item-modal-section">
      <h3>Ingrédients</h3>
      <div class="item-modal-ingredients">${items.map((i) => `<span>${escapeHtml(i)}</span>`).join('')}</div>
    </div>
  `;
}

/** Ouvre la fiche produit (photo en grand, description, ingrédients) — même mécanique pour
 * tous les types de commerce (repas, mode, meubles, épicerie), seuls description/ingrédients
 * varient selon ce que le commerçant a renseigné. */
function openItemModal(item) {
  const overlay = document.getElementById('item-modal-overlay');
  const photoUrl = safeImageUrl(item.photo_url);

  document.getElementById('item-modal-photo').style.backgroundImage = photoUrl ? `url('${photoUrl}')` : 'none';

  const hasOptions = item.options && item.options.length > 0;

  document.getElementById('item-modal-body').innerHTML = `
    <div class="iname">${escapeHtml(item.name)}</div>
    <span class="price">${formatMoney(item.price_cents, currency)}</span>
    ${item.description ? `<div class="item-modal-section"><h3>Description</h3><p>${escapeHtml(item.description)}</p></div>` : ''}
    ${ingredientsHtml(item.ingredients)}
    <button type="button" class="btn btn-primary btn-block" id="item-modal-add" data-item-id="${item.id}" data-has-options="${hasOptions ? 1 : 0}">
      Ajouter au panier
    </button>
  `;

  overlay.hidden = false;
}

function closeItemModal() {
  document.getElementById('item-modal-overlay').hidden = true;
}

document.getElementById('item-modal-close').addEventListener('click', closeItemModal);
document.getElementById('item-modal-overlay').addEventListener('click', (event) => {
  if (event.target.id === 'item-modal-overlay') closeItemModal();
});
document.getElementById('item-modal-body').addEventListener('click', (event) => {
  const btn = event.target.closest('#item-modal-add');
  if (!btn) return;

  closeItemModal();

  const itemId = btn.dataset.itemId;
  if (btn.dataset.hasOptions === '1') {
    const panel = document.getElementById(`variant-${itemId}`);
    panel.hidden = false;
    panel.scrollIntoView({ behavior: 'smooth', block: 'center' });

    return;
  }

  const item = itemsById.get(Number(itemId));
  addItem(Number(restaurantId), restaurantName, {
    menuItemId: item.id,
    name: item.name,
    priceCents: item.price_cents,
  }, currency);
  renderCartBar();
});

function updateVariantConfirmButton(panel) {
  const groups = new Set([...panel.querySelectorAll('.vchip[data-group]')].map((c) => c.dataset.group));
  const confirmBtn = panel.querySelector('.variant-confirm');
  let priceCents = Number(confirmBtn.dataset.basePrice);
  let allGroupsChosen = true;

  for (const group of groups) {
    const selected = panel.querySelector(`.vchip[data-group="${CSS.escape(group)}"].selected`);
    if (!selected) {
      allGroupsChosen = false;
    } else {
      priceCents += Number(selected.dataset.delta);
    }
  }

  panel.querySelectorAll('.vchip-toggle.selected').forEach((c) => {
    priceCents += Number(c.dataset.delta);
  });

  confirmBtn.disabled = !allGroupsChosen;
  confirmBtn.textContent = allGroupsChosen ? `Ajouter · ${formatMoney(priceCents, currency)}` : 'Choisis une option';
}

async function load() {
  if (!restaurantId) {
    document.getElementById('restaurant-header').innerHTML = '<p class="state-msg">Restaurant introuvable.</p>';

    return;
  }

  try {
    const [restaurant, menu] = await Promise.all([
      apiFetch(`/restaurants/${restaurantId}`),
      apiFetch(`/restaurants/${restaurantId}/menu`),
    ]);

    currency = restaurant.currency ?? 'EUR';
    restaurantName = restaurant.name;
    document.title = `${restaurant.name} — Saveurs`;

    const bannerPhoto = safeImageUrl(restaurant.photo_url);
    if (bannerPhoto) {
      document.querySelector('.banner').style.backgroundImage = `url('${bannerPhoto}')`;
    }

    document.getElementById('restaurant-header').innerHTML = `
      <h1 class="title" style="margin-bottom:4px;">${escapeHtml(restaurant.name)}</h1>
      ${restaurant.cuisine_origine ? `<span class="rtag">${escapeHtml(restaurant.cuisine_origine)}</span>` : ''}
      <p class="state-msg" style="margin-top:8px;">${escapeHtml(restaurant.adresse)}</p>
    `;

    itemsById.clear();
    for (const category of menu.categories) {
      for (const item of category.items) itemsById.set(item.id, item);
    }

    const menuList = document.getElementById('menu-list');
    menuList.innerHTML = menu.categories.length
      ? menu.categories.map((category) => `
          <h2 class="sechead" style="margin:18px 0 4px;">${escapeHtml(category.name)}</h2>
          ${category.items.map((item) => menuItemHtml(item)).join('')}
        `).join('')
      : '<p class="state-msg">Ce restaurant n\'a pas encore publié son menu.</p>';

    menuList.addEventListener('click', (event) => {
      const openTarget = event.target.closest('[data-open-id]');
      if (openTarget) {
        openItemModal(itemsById.get(Number(openTarget.dataset.openId)));

        return;
      }

      const chip = event.target.closest('.vchip');
      if (chip) {
        if (chip.classList.contains('vchip-toggle')) {
          chip.classList.toggle('selected');
        } else {
          chip.parentElement.querySelectorAll('.vchip').forEach((c) => c.classList.remove('selected'));
          chip.classList.add('selected');
        }
        updateVariantConfirmButton(chip.closest('.variant-panel'));

        return;
      }

      const confirmBtn = event.target.closest('.variant-confirm');
      if (confirmBtn) {
        const panel = confirmBtn.closest('.variant-panel');
        const itemRow = panel.previousElementSibling;
        const selected = [...panel.querySelectorAll('.vchip.selected')].map((c) => ({
          id: Number(c.dataset.optionId),
          name: c.dataset.optionName,
          priceDeltaCents: Number(c.dataset.delta),
        }));

        addItem(Number(restaurantId), restaurant.name, {
          menuItemId: Number(confirmBtn.dataset.itemId),
          name: itemRow.querySelector('.iname').textContent,
          priceCents: Number(confirmBtn.dataset.basePrice) + selected.reduce((s, o) => s + o.priceDeltaCents, 0),
          options: selected,
        }, currency);
        renderCartBar();
        panel.hidden = true;

        return;
      }

      const btn = event.target.closest('.add-btn');
      if (!btn) return;

      if (btn.dataset.hasOptions === '1') {
        const panel = document.getElementById(`variant-${btn.dataset.itemId}`);
        panel.hidden = !panel.hidden;

        return;
      }

      addItem(Number(restaurantId), restaurant.name, {
        menuItemId: Number(btn.dataset.itemId),
        name: btn.dataset.name,
        priceCents: Number(btn.dataset.price),
      }, currency);
      renderCartBar();
    });

    renderCartBar();
  } catch (error) {
    document.getElementById('restaurant-header').innerHTML =
      `<p class="state-msg">Impossible de charger ce restaurant (${error.message}).</p>`;
  }
}

load();
