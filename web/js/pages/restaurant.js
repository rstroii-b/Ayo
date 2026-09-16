import { apiFetch } from '../api.js';
import { addItem, getCart, cartSubtotalCents, cartItemCount } from '../cart.js';
import { formatMoney, escapeHtml, safeImageUrl } from '../format.js';

const restaurantId = new URLSearchParams(window.location.search).get('id');
let restaurantName = '';
const itemsById = new Map();
let toastTimer = null;

function showToast(message) {
  let toast = document.getElementById('ayo-toast');
  if (!toast) {
    toast = document.createElement('div');
    toast.id = 'ayo-toast';
    toast.setAttribute('role', 'status');
    toast.setAttribute('aria-live', 'polite');
    document.body.appendChild(toast);
  }

  toast.textContent = message;
  toast.classList.add('show');
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => toast.classList.remove('show'), 1800);
}

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
    `Voir le panier · ${count} article${count > 1 ? 's' : ''} · ${formatMoney(cartSubtotalCents(cart))}`;
}

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
            ${escapeHtml(o.name)}${o.price_delta_cents ? ` (${o.price_delta_cents > 0 ? '+' : ''}${formatMoney(o.price_delta_cents)})` : ''}
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
            ${escapeHtml(o.name)}${o.price_delta_cents ? ` (+${formatMoney(o.price_delta_cents)})` : ''}
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
          <span class="price">${formatMoney(item.price_cents)}</span>
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

  const items = ingredients.split(/[,
]/).map((s) => s.trim()).filter(Boolean);
  if (items.length === 0) return '';

  return `
    <div class="item-modal-section">
      <h3>Ingrédients</h3>
      <div class="item-modal-ingredients">${items.map((i) => `<span>${escapeHtml(i)}</span>`).join('')}</div>
    </div>
  `;
}

function restaurantHeaderHtml(restaurant) {
  const rating = Number(restaurant.rating ?? 4.8);
  const reviews = Number(restaurant.review_count ?? 1200);
  const etaLow = Number(restaurant.eta_low_min ?? 20);
  const etaHigh = Number(restaurant.eta_high_min ?? 30);
  const deliveryFee = typeof restaurant.delivery_fee_cents !== 'undefined'
    ? formatMoney(restaurant.delivery_fee_cents)
    : 'Livraison gratuite';

  return `
    <h1 class="title" style="margin-bottom:4px;">${escapeHtml(restaurant.name)}</h1>
    <div class="restaurant-summary">
      <span class="summary-pill rating">⭐ ${rating.toFixed(1)} · ${reviews.toLocaleString('fr-FR')} avis</span>
      <span class="summary-pill">${escapeHtml(restaurant.cuisine_origine || 'Cuisine africaine')}</span>
      <span class="summary-pill">${etaLow}-${etaHigh} min</span>
      <span class="summary-pill">${deliveryFee}</span>
    </div>
    <p class="state-msg" style="margin-top:8px;">${escapeHtml(restaurant.adresse)}</p>
  `;
}

function openItemModal(item) {
  const overlay = document.getElementById('item-modal-overlay');
  const photoUrl = safeImageUrl(item.photo_url);

  document.getElementById('item-modal-photo').style.backgroundImage = photoUrl ? `url('${photoUrl}')` : 'none';
  const hasOptions = item.options && item.options.length > 0;

  document.getElementById('item-modal-body').innerHTML = `
    <div class="iname" id="item-modal-title">${escapeHtml(item.name)}</div>
    <span class="price">${formatMoney(item.price_cents)}</span>
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
  });
  renderCartBar();
  showToast(`${item.name} ajouté au panier`);
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
  confirmBtn.textContent = allGroupsChosen ? `Ajouter · ${formatMoney(priceCents)}` : 'Choisis une option';
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

    restaurantName = restaurant.name;
    document.title = `${restaurant.name} — Ayo`;

    const bannerPhoto = safeImageUrl(restaurant.photo_url);
    if (bannerPhoto) {
      document.querySelector('.banner').style.backgroundImage = `url('${bannerPhoto}')`;
    }

    document.getElementById('restaurant-header').innerHTML = restaurantHeaderHtml({
      ...restaurant,
      rating: restaurant.rating ?? 4.8,
      review_count: restaurant.review_count ?? 1200,
      eta_low_min: restaurant.eta_low_min ?? 20,
      eta_high_min: restaurant.eta_high_min ?? 30,
      delivery_fee_cents: restaurant.delivery_fee_cents ?? 1500,
    });

    itemsById.clear();
    for (const category of menu.categories) {
      for (const item of category.items) itemsById.set(item.id, item);
    }

    const menuList = document.getElementById('menu-list');
    menuList.innerHTML = menu.categories.length
      ? menu.categories.map((category) => `
          <div class="menu-heading">
            <h2 class="sechead">${escapeHtml(category.name)}</h2>
            <small>${category.items.length} plats</small>
          </div>
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

        addItem(Number(restaurantId), restaurantName, {
          menuItemId: Number(confirmBtn.dataset.itemId),
          name: itemRow.querySelector('.iname').textContent,
          priceCents: Number(confirmBtn.dataset.basePrice) + selected.reduce((s, o) => s + o.priceDeltaCents, 0),
          options: selected,
        });
        renderCartBar();
        showToast('Article ajouté au panier');
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

      const item = itemsById.get(Number(btn.dataset.itemId));
      addItem(Number(restaurantId), restaurantName, {
        menuItemId: Number(btn.dataset.itemId),
        name: btn.dataset.name,
        priceCents: Number(btn.dataset.price),
      });
      renderCartBar();
      showToast(`${item.name} ajouté au panier`);
    });

    renderCartBar();
  } catch (error) {
    document.getElementById('restaurant-header').innerHTML =
      `<p class="state-msg">Impossible de charger ce restaurant (${error.message}).</p>`;
  }
}

load();

window.addEventListener('storage', () => {
  renderCartBar();
});
