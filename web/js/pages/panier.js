import { apiFetch } from '../api.js';
import { requireLogin } from '../auth.js';
import { getAddress } from '../address.js';
import { getCart, setQuantity, cartSubtotalCents, clearCart } from '../cart.js';
import { formatMoney, escapeHtml } from '../format.js';
import { getCurrentPosition } from '../geolocation.js';

// Pas de géocodage d'adresse texte→coordonnées dans ce squelette — la position réelle de
// l'appareil sert de point de livraison (repli Paris si refusée/indisponible). Le libellé
// d'adresse saisi par le client reste ce qui s'affiche au restaurant/livreur.
const FALLBACK_POSITION = { lat: 48.8566, lng: 2.3522 };

let phase = 'review'; // 'review' -> 'paying'
let orderId = null;
let stripe = null;
let elements = null;

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
        <span class="price">${formatMoney(line.priceCents * line.quantity, cart.currency)}</span>
      </div>
    </div>
  `).join('');

  document.getElementById('sum-subtotal').textContent = formatMoney(cartSubtotalCents(cart), cart.currency);
}

document.getElementById('cart-lines').addEventListener('click', (event) => {
  const btn = event.target.closest('button[data-delta]');
  if (!btn) return;

  const lineIndex = Number(btn.closest('.qty').dataset.lineIndex);
  const cart = getCart();
  const line = cart.items[lineIndex];
  setQuantity(lineIndex, line.quantity + Number(btn.dataset.delta));
  renderCart();
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
  btn.disabled = true;
  btn.textContent = 'Création de la commande…';

  try {
    const position = await getCurrentPosition({ fallback: FALLBACK_POSITION });

    const order = await apiFetch('/orders', {
      method: 'POST',
      body: {
        restaurant_id: cart.restaurantId,
        items: cart.items.map((line) => ({
          menu_item_id: line.menuItemId,
          quantity: line.quantity,
          option_ids: line.options?.map((o) => o.id) ?? [],
        })),
        delivery_address: { lat: position.lat, lng: position.lng, label: address },
      },
    });
    orderId = order.order_id;

    const intent = await apiFetch('/payments/intent', { method: 'POST', body: { order_id: orderId } });

    stripe = Stripe(intent.publishable_key);
    elements = stripe.elements({ clientSecret: intent.client_secret });
    elements.create('payment').mount('#payment-element');

    document.getElementById('payment-step').hidden = false;
    phase = 'paying';
    btn.textContent = 'Payer maintenant';
    btn.disabled = false;
  } catch (error) {
    if (error.status === 401) {
      errorEl.textContent = 'Ta session a expiré — reconnecte-toi pour continuer.';
      errorEl.hidden = false;
      setTimeout(() => {
        window.location.href = `/login.html?next=${encodeURIComponent('/panier.html')}`;
      }, 1800);

      return;
    }

    errorEl.textContent = error.detail ?? error.message;
    errorEl.hidden = false;
    btn.disabled = false;
    btn.textContent = 'Commander';
  }
}

async function confirmPayment() {
  const errorEl = document.getElementById('checkout-error');
  const btn = document.getElementById('checkout-btn');
  errorEl.hidden = true;
  btn.disabled = true;
  btn.textContent = 'Paiement en cours…';

  const { error, paymentIntent } = await stripe.confirmPayment({
    elements,
    redirect: 'if_required',
  });

  if (error) {
    errorEl.textContent = error.message;
    errorEl.hidden = false;
    btn.disabled = false;
    btn.textContent = 'Payer maintenant';

    return;
  }

  if (paymentIntent.status === 'succeeded') {
    clearCart();
    window.location.href = `/suivi.html?order=${orderId}`;
  }
}

document.getElementById('checkout-btn').addEventListener('click', () => {
  if (phase === 'review') {
    startCheckout();
  } else {
    confirmPayment();
  }
});

if (getAddress()) {
  document.getElementById('address').value = getAddress();
}

renderCart();
