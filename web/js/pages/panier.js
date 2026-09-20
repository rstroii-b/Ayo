import { apiFetch } from '../api.js';
import { requireLogin } from '../auth.js';
import { getAddress } from '../address.js';
import { getCart, setQuantity, cartSubtotalCents, clearCart, removeItemById } from '../cart.js';
import { formatMoney, escapeHtml } from '../format.js';
import { getCurrentPosition } from '../geolocation.js';

const FALLBACK_POSITION = { lat: 5.3600, lng: -4.0083 };
const DELIVERY_FEES = { standard: 1500, express: 3000 };
const PROMO_CODES = { AYO10: 0.10, AYOFREE: 0.12, SAVEURS: 0.15 };
const ORDER_KEY_STORAGE = 'ayo_pending_order_key';

function getDeliveryMode() {
  return document.querySelector('input[name="delivery-mode"]:checked')?.value || 'standard';
}

function getOrderIdempotencyKey() {
  let key = sessionStorage.getItem(ORDER_KEY_STORAGE);
  if (!key) {
    key = crypto.randomUUID();
    sessionStorage.setItem(ORDER_KEY_STORAGE, key);
  }
  return key;
}

function getPromoDiscount(subtotalCents) {
  const code = document.getElementById('promo-code')?.value.trim().toUpperCase();
  return code && PROMO_CODES[code] ? Math.round(subtotalCents * PROMO_CODES[code]) : 0;
}

function renderSummary(subtotalCents) {
  const deliveryFee = DELIVERY_FEES[getDeliveryMode()] || DELIVERY_FEES.standard;
  const discount = getPromoDiscount(subtotalCents);
  const total = Math.max(0, subtotalCents + deliveryFee - discount);
  document.getElementById('sum-subtotal').textContent = formatMoney(subtotalCents);
  document.getElementById('sum-delivery').textContent = formatMoney(deliveryFee);
  document.getElementById('sum-total').textContent = formatMoney(total);
}

function renderCart() {
  const cart = getCart();
  if (cart.items.length === 0) {
    document.getElementById('empty-state').hidden = false;
    document.getElementById('cart-view').hidden = true;
    return;
  }

  document.getElementById('empty-state').hidden = true;
  document.getElementById('cart-view').hidden = false;
  document.getElementById('restaurant-name').textContent = cart.restaurantName ?? '';
  document.getElementById('cart-lines').innerHTML = cart.items.map((line, i) => `
    <div class="cart-line">
      <div class="qty" data-line-index="${i}">
        <button type="button" data-delta="-1" aria-label="Diminuer la quantité de ${escapeHtml(line.name)}">−</button>
        <span class="n">${line.quantity}</span>
        <button type="button" data-delta="1" aria-label="Augmenter la quantité de ${escapeHtml(line.name)}">+</button>
      </div>
      <div class="cline-info">
        <div class="cline-name">${escapeHtml(line.name)}</div>
        ${line.options?.length ? `<span class="state-msg">${escapeHtml(line.options.map((o) => o.name).join(', '))}</span>` : ''}
        <span class="price">${formatMoney((line.priceCents || 0) * (line.quantity || 0))}</span>
      </div>
    </div>`).join('');
  renderSummary(cartSubtotalCents(cart));
}

document.getElementById('cart-lines').addEventListener('click', (event) => {
  const btn = event.target.closest('button[data-delta]');
  if (!btn) return;
  const lineIndex = Number(btn.closest('.qty').dataset.lineIndex);
  const cart = getCart();
  const line = cart.items[lineIndex];
  if (!line) return;
  setQuantity(lineIndex, line.quantity + Number(btn.dataset.delta));
  renderCart();
});

document.querySelectorAll('input[name="delivery-mode"]').forEach((radio) => radio.addEventListener('change', renderCart));

document.getElementById('promo-btn').addEventListener('click', () => {
  const input = document.getElementById('promo-code');
  const message = document.getElementById('promo-message');
  const code = input.value.trim().toUpperCase();
  const cart = getCart();
  message.hidden = false;
  if (!code || !PROMO_CODES[code]) {
    message.textContent = 'Ce code promo est invalide ou expiré.';
    message.style.color = 'var(--chili)';
    renderSummary(cartSubtotalCents(cart));
    return;
  }
  const discount = getPromoDiscount(cartSubtotalCents(cart));
  message.textContent = `Code appliqué : ${code} (-${formatMoney(discount)})`;
  message.style.color = 'var(--accent)';
  renderSummary(cartSubtotalCents(cart));
});

async function startCheckout() {
  const errorEl = document.getElementById('checkout-error');
  const btn = document.getElementById('checkout-btn');
  errorEl.hidden = true;
  const address = document.getElementById('address').value.trim();
  if (!address) {
    errorEl.textContent = 'Renseigne une adresse de livraison.';
    errorEl.hidden = false;
    return;
  }
  if (!requireLogin('/panier.html')) return;

  const cart = getCart();
  const mode = getDeliveryMode();
  const promoCode = document.getElementById('promo-code').value.trim().toUpperCase();
  btn.disabled = true;
  btn.textContent = 'Création de la commande…';

  try {
    const position = await getCurrentPosition({ fallback: FALLBACK_POSITION });
    const order = await apiFetch('/orders', {
      method: 'POST',
      idempotencyKey: getOrderIdempotencyKey(),
      body: {
        restaurant_id: cart.restaurantId,
        items: cart.items.map((line) => ({
          menu_item_id: line.menuItemId,
          quantity: line.quantity,
          option_ids: line.options?.map((o) => o.id) ?? [],
        })),
        delivery_address: { lat: position.lat, lng: position.lng, label: address },
        delivery_mode: mode,
        promo_code: promoCode || null,
      },
    });

    const intent = await apiFetch('/payments/intent', { method: 'POST', body: { order_id: order.order_id } });
    clearCart();
    sessionStorage.removeItem(ORDER_KEY_STORAGE);
    window.location.href = intent.payment_url;
  } catch (error) {
    if (error.status === 401) {
      errorEl.textContent = 'Ta session a expiré — reconnecte-toi pour continuer.';
      errorEl.hidden = false;
      setTimeout(() => { window.location.href = `/login.html?next=${encodeURIComponent('/panier.html')}`; }, 1800);
      return;
    }
    // "Article indisponible" (422) : un plat a été retiré/mis en rupture par le restaurateur
    // pendant que le client composait son panier — le serveur bloque déjà le paiement (bonne
    // chose), mais error.detail n'est qu'un id numérique brut ("42") : sans ce cas spécial,
    // c'est CE chiffre seul qui s'affichait comme message d'erreur. On identifie l'article par
    // son nom depuis le panier local, le retire, et laisse le client réessayer avec le reste.
    if (error.message === 'Article indisponible' && error.detail) {
      const menuItemId = Number(error.detail);
      const { removed } = removeItemById(menuItemId);
      const name = removed[0]?.name ?? 'Un article';
      errorEl.textContent = `${name} n'est plus disponible et a été retiré de ton panier — vérifie ta commande et réessaie.`;
      errorEl.hidden = false;
      btn.disabled = false;
      btn.textContent = 'Commander';
      renderCart();

      return;
    }

    errorEl.textContent = error.detail ?? error.message;
    errorEl.hidden = false;
    btn.disabled = false;
    btn.textContent = 'Continuer vers le paiement';
  }
}

document.getElementById('checkout-btn').addEventListener('click', startCheckout);
if (getAddress()) document.getElementById('address').value = getAddress();
renderCart();
