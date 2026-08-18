import { apiFetch } from '../api.js';
import { requireLogin } from '../auth.js';
import { getAddress } from '../address.js';
import { getCart, setQuantity, cartSubtotalCents, clearCart } from '../cart.js';
import { formatEuros } from '../format.js';

// Pas de géocodage dans ce squelette — position fixe (Paris) pour la démo,
// seule l'adresse texte saisie par le client est réellement utilisée pour la livraison.
const DEMO_LAT = 48.8566;
const DEMO_LNG = 2.3522;

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

  document.getElementById('cart-lines').innerHTML = cart.items.map((line) => `
    <div class="cart-line">
      <div class="qty" data-item-id="${line.menuItemId}">
        <button type="button" data-delta="-1" aria-label="Diminuer la quantité de ${line.name}">−</button>
        <span class="n">${line.quantity}</span>
        <button type="button" data-delta="1" aria-label="Augmenter la quantité de ${line.name}">+</button>
      </div>
      <div class="cline-info">
        <div class="cline-name">${line.name}</div>
        <span class="price">${formatEuros(line.priceCents * line.quantity)}</span>
      </div>
    </div>
  `).join('');

  document.getElementById('sum-subtotal').textContent = formatEuros(cartSubtotalCents(cart));
}

document.getElementById('cart-lines').addEventListener('click', (event) => {
  const btn = event.target.closest('button[data-delta]');
  if (!btn) return;

  const itemId = Number(btn.closest('.qty').dataset.itemId);
  const cart = getCart();
  const line = cart.items.find((l) => l.menuItemId === itemId);
  setQuantity(itemId, line.quantity + Number(btn.dataset.delta));
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
    const order = await apiFetch('/orders', {
      method: 'POST',
      body: {
        restaurant_id: cart.restaurantId,
        items: cart.items.map((line) => ({ menu_item_id: line.menuItemId, quantity: line.quantity })),
        delivery_address: { lat: DEMO_LAT, lng: DEMO_LNG, label: address },
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
