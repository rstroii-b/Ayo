import { apiFetch } from '../api.js';
import { addItem, getCart, cartSubtotalCents, cartItemCount } from '../cart.js';
import { formatMoney, escapeHtml, safeImageUrl } from '../format.js';
import { getCurrentPosition } from '../geolocation.js';
import { openModal, renderError, showToast } from '../ui.js';

// Même position de repli que l'accueil (Abidjan) : la fiche affiche ainsi les mêmes frais et
// le même délai que la liste d'où l'on vient, au lieu d'une valeur par défaut différente.
const FALLBACK_POSITION = { lat: 5.3600, lng: -4.0083 };

const restaurantId = new URLSearchParams(window.location.search).get('id');
let restaurantName = '';
const itemsById = new Map();
let closeItemModal = () => {};

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

  // Séparateurs : virgule ou retour à la ligne. Le saut de ligne DOIT être écrit \n :
  // un vrai retour à la ligne à l'intérieur d'une expression régulière est une erreur de
  // syntaxe JavaScript, et elle empêchait tout le module de se charger — la fiche commerce
  // restait bloquée sur « Chargement… », menu compris.
  const items = ingredients.split(/[,\n]/).map((s) => s.trim()).filter(Boolean);
  if (items.length === 0) return '';

  return `
    <div class="item-modal-section">
      <h3>Ingrédients</h3>
      <div class="item-modal-ingredients">${items.map((i) => `<span>${escapeHtml(i)}</span>`).join('')}</div>
    </div>
  `;
}

/**
 * En-tête de la fiche commerce.
 *
 * Aucune valeur n'est inventée ici. La version précédente affichait
 * `restaurant.rating ?? 4.8` et `review_count ?? 1200` : comme l'API ne renvoyait ni l'un ni
 * l'autre, *tous* les commerces — y compris ceux ouverts le jour même — s'affichaient avec
 * « ⭐ 4.8 · 1 200 avis ». Même chose pour les frais, plafonnés à un `?? 1500` sans rapport
 * avec le tarif réel (minimum 1 000 FCFA autour d'Abidjan). Une note inventée n'est pas un
 * détail cosmétique : c'est une information fausse sur laquelle le client décide.
 *
 * Un commerce sans avis est désormais annoncé comme tel ; les frais et le délai ne sont
 * affichés que lorsque le serveur les a calculés pour la position du client.
 */
function restaurantHeaderHtml(restaurant) {
  const pills = [];

  if (restaurant.review_count > 0 && restaurant.rating_avg !== null) {
    const rating = Number(restaurant.rating_avg).toFixed(1).replace('.', ',');
    const reviews = Number(restaurant.review_count).toLocaleString('fr-FR');
    const plural = restaurant.review_count > 1 ? 'avis' : 'avis';
    pills.push(`<span class="summary-pill rating">⭐ ${rating} · ${reviews} ${plural}</span>`);
  } else {
    pills.push('<span class="summary-pill new">Nouveau sur Ayo</span>');
  }

  if (restaurant.cuisine_origine) {
    pills.push(`<span class="summary-pill">${escapeHtml(restaurant.cuisine_origine)}</span>`);
  }

  if (restaurant.eta_low_min) {
    pills.push(`<span class="summary-pill">${restaurant.eta_low_min}–${restaurant.eta_high_min} min</span>`);
  }

  if (typeof restaurant.delivery_fee_cents === 'number') {
    pills.push(`<span class="summary-pill">Livraison ${formatMoney(restaurant.delivery_fee_cents)}</span>`);
  }

  return `
    <h1 class="title" style="margin-bottom:4px;">${escapeHtml(restaurant.name)}</h1>
    <div class="restaurant-summary">${pills.join('')}</div>
    <p class="state-msg" style="margin-top:8px;">${escapeHtml(restaurant.adresse)}</p>
  `;
}

/**
 * Ouvre la fiche produit. Passe par ui.js::openModal : défilement de la page bloqué, focus
 * déplacé dans la modale et piégé dedans, fermeture à l'Échap, focus rendu à l'élément
 * d'origine. Auparavant la modale s'ouvrait en retirant simplement l'attribut `hidden` : au
 * clavier, on continuait à tabuler dans le menu resté derrière, sans jamais l'atteindre.
 */
function openItemModal(item) {
  // Un identifiant qui ne correspond à rien (menu rechargé entre-temps) ne doit pas faire
  // planter le rendu de toute la page.
  if (!item) return;

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

  closeItemModal = openModal(overlay);
}

document.getElementById('item-modal-close').addEventListener('click', () => closeItemModal());
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

/**
 * Nom d'un article selon la catégorie du commerce. « 4 plats » sous le rayon d'un magasin de
 * meubles ou d'un supermarché n'avait aucun sens — le libellé était écrit en dur, hérité de
 * l'époque où Ayo ne faisait que de la restauration.
 */
const ITEM_NOUNS = {
  food: ['plat', 'plats'],
  fashion: ['article', 'articles'],
  furniture: ['meuble', 'meubles'],
  grocery: ['produit', 'produits'],
};

function itemCountLabel(count, businessType) {
  const [singular, plural] = ITEM_NOUNS[businessType] ?? ITEM_NOUNS.food;

  return `${count} ${count > 1 ? plural : singular}`;
}

async function load() {
  const header = document.getElementById('restaurant-header');

  if (!restaurantId) {
    renderError(header, { status: 404, message: 'Commerce introuvable' }, {
      title: 'Commerce introuvable',
    });

    return;
  }

  try {
    // La position sert au serveur à calculer les frais et le délai réels pour ce client :
    // la fiche annonce alors le même montant que la liste et que le panier.
    const position = await getCurrentPosition({ fallback: FALLBACK_POSITION });
    const query = new URLSearchParams({ lat: position.lat, lng: position.lng });

    const [restaurant, menu] = await Promise.all([
      apiFetch(`/restaurants/${restaurantId}?${query}`),
      apiFetch(`/restaurants/${restaurantId}/menu`),
    ]);

    restaurantName = restaurant.name;
    document.title = `${restaurant.name} — Ayo`;

    const bannerPhoto = safeImageUrl(restaurant.photo_url);
    if (bannerPhoto) {
      document.querySelector('.banner').style.backgroundImage = `url('${bannerPhoto}')`;
    }

    // Les données partent telles quelles au rendu : aucune valeur de repli inventée.
    header.innerHTML = restaurantHeaderHtml(restaurant);

    itemsById.clear();
    for (const category of menu.categories) {
      for (const item of category.items) itemsById.set(item.id, item);
    }

    const businessType = menu.business_type ?? restaurant.business_type;
    const menuList = document.getElementById('menu-list');
    const filledCategories = menu.categories.filter((category) => category.items.length > 0);

    menuList.innerHTML = filledCategories.length
      ? filledCategories.map((category) => `
          <div class="menu-heading">
            <h2 class="sechead">${escapeHtml(category.name)}</h2>
            <small>${itemCountLabel(category.items.length, businessType)}</small>
          </div>
          ${category.items.map((item) => menuItemHtml(item)).join('')}
        `).join('')
      : `
        <div class="state state--empty">
          <div class="state-icon" aria-hidden="true">🍽️</div>
          <p class="state-title">Menu en préparation</p>
          <p class="state-text">Ce commerce n'a pas encore publié ses articles. Reviens un peu plus tard.</p>
        </div>`;

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
    // Message échappé et bouton « Réessayer » : une coupure réseau ne laisse plus la page
    // vide sans issue, et le texte d'erreur n'est plus injecté brut dans le HTML.
    renderError(header, error, {
      title: 'Impossible d\'afficher ce commerce',
      onRetry: load,
    });
  }
}

load();

window.addEventListener('storage', () => {
  renderCartBar();
});
