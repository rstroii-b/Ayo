import { apiFetch } from '../api.js';
import { addItem, getCart, cartSubtotalCents, cartItemCount } from '../cart.js';
import { formatEuros } from '../format.js';

const restaurantId = new URLSearchParams(window.location.search).get('id');

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
    `Voir le panier · ${count} article${count > 1 ? 's' : ''} · ${formatEuros(cartSubtotalCents(cart))}`;
}

function menuItemHtml(item, restaurantName) {
  return `
    <div class="menu-item">
      <div class="ithumb"></div>
      <div class="iinfo">
        <div class="iname">${item.name}</div>
        ${item.description ? `<div class="idesc">${item.description}</div>` : ''}
        <div class="ibottom">
          <span class="price">${formatEuros(item.price_cents)}</span>
          <button class="add-btn" type="button" data-item-id="${item.id}" data-name="${item.name}" data-price="${item.price_cents}" aria-label="Ajouter ${item.name} au panier">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
          </button>
        </div>
      </div>
    </div>
  `;
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

    document.title = `${restaurant.name} — Saveurs`;
    document.getElementById('restaurant-header').innerHTML = `
      <h1 class="title" style="margin-bottom:4px;">${restaurant.name}</h1>
      ${restaurant.cuisine_origine ? `<span class="rtag">${restaurant.cuisine_origine}</span>` : ''}
      <p class="state-msg" style="margin-top:8px;">${restaurant.adresse}</p>
    `;

    const menuList = document.getElementById('menu-list');
    menuList.innerHTML = menu.categories.length
      ? menu.categories.map((category) => `
          <h2 class="sechead" style="margin:18px 0 4px;">${category.name}</h2>
          ${category.items.map((item) => menuItemHtml(item, restaurant.name)).join('')}
        `).join('')
      : '<p class="state-msg">Ce restaurant n\'a pas encore publié son menu.</p>';

    menuList.addEventListener('click', (event) => {
      const btn = event.target.closest('.add-btn');
      if (!btn) return;

      addItem(Number(restaurantId), restaurant.name, {
        menuItemId: Number(btn.dataset.itemId),
        name: btn.dataset.name,
        priceCents: Number(btn.dataset.price),
      });
      renderCartBar();
    });

    renderCartBar();
  } catch (error) {
    document.getElementById('restaurant-header').innerHTML =
      `<p class="state-msg">Impossible de charger ce restaurant (${error.message}).</p>`;
  }
}

load();
