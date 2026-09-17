import { apiFetch } from '../api.js';
import { isLoggedIn, requireLogin } from '../auth.js';
import { getAddress, setAddress } from '../address.js';
import { getCart, setQuantity, cartSubtotalCents, clearCart } from '../cart.js';
import { formatMoney, escapeHtml } from '../format.js';
import { getCurrentPosition } from '../geolocation.js';
import { hideAlert, setBusy, showAlert, showToast } from '../ui.js';

/**
 * Écran panier / checkout.
 *
 * Ce qu'il ne fait plus : calculer. La version précédente portait ses propres frais de
 * livraison (`DELIVERY_FEES = { standard: 1500, express: 3000 }`) et sa propre table de codes
 * promo (`PROMO_CODES = { AYO10: 0.10, … }`), tous deux écrits en dur dans le JavaScript.
 * Deux conséquences :
 *
 *   1. Le total affiché n'était pas celui facturé. Le serveur calcule les frais à partir de la
 *      distance et de la zone (minimum 1 000 FCFA autour d'Abidjan) ; l'écran, lui, annonçait
 *      15 FCFA. Le client validait un montant et en payait un autre.
 *   2. Les remises n'existaient que dans le navigateur. Aucune route serveur ne lisait
 *      `promo_code` : la ligne « -10 % » s'affichait, la commande partait au prix plein. Il
 *      suffisait d'ouvrir la console pour s'accorder n'importe quelle remise… sans qu'elle
 *      change quoi que ce soit au débit réel.
 *
 * Désormais, un seul endroit calcule : POST /orders/quote. Cet écran envoie le panier et
 * affiche la réponse. Le récapitulatif est donc, par construction, ce qui sera facturé.
 */

const FALLBACK_POSITION = { lat: 5.3600, lng: -4.0083 };
const ORDER_KEY_STORAGE = 'ayo_pending_order_key';
const QUOTE_DEBOUNCE_MS = 350;

/** Dernier devis serveur reçu — seule source des montants affichés. */
let currentQuote = null;
let quoteTimer = null;
let quoteRequest = null;
let position = null;

const el = (id) => document.getElementById(id);

function getDeliveryMode() {
  return document.querySelector('input[name="delivery-mode"]:checked')?.value ?? 'standard';
}

function getPromoCode() {
  return el('promo-code').value.trim().toUpperCase();
}

/**
 * Clé d'idempotence stable pour la commande en cours de préparation, conservée tant que le
 * panier n'a pas été validé. Un double-tap sur « Continuer », ou un nouvel essai après une
 * coupure réseau, présente la même clé : le serveur reconnaît le rejeu et renvoie la commande
 * déjà créée au lieu d'en créer une seconde.
 */
function getOrderIdempotencyKey() {
  let key = sessionStorage.getItem(ORDER_KEY_STORAGE);

  if (!key) {
    key = crypto.randomUUID();
    sessionStorage.setItem(ORDER_KEY_STORAGE, key);
  }

  return key;
}

/* ------------------------------------------------------------ Rendu des lignes */

function cartLineHtml(line, index, quoteLine) {
  // Prix affiché : celui du devis serveur si disponible, sinon celui figé à l'ajout au panier.
  const lineTotal = quoteLine
    ? quoteLine.line_total_cents
    : (line.priceCents ?? 0) * (line.quantity ?? 0);

  const optionNames = (line.options ?? []).map((option) => option.name).filter(Boolean);
  const name = line.name ?? 'Article';

  return `
    <div class="cart-line">
      <div class="qty" data-line-index="${index}">
        <button type="button" data-delta="-1" aria-label="Retirer un ${escapeHtml(name)}">−</button>
        <span class="n">${Number(line.quantity) || 0}</span>
        <button type="button" data-delta="1" aria-label="Ajouter un ${escapeHtml(name)}">+</button>
      </div>
      <div class="cline-info">
        <div class="cline-name">${escapeHtml(name)}</div>
        ${optionNames.length ? `<span class="field-hint">${escapeHtml(optionNames.join(', '))}</span>` : ''}
        <span class="price">${formatMoney(lineTotal)}</span>
      </div>
    </div>`;
}

function renderCart() {
  const cart = getCart();

  if (cart.items.length === 0) {
    el('empty-state').hidden = false;
    el('cart-view').hidden = true;

    return;
  }

  el('empty-state').hidden = true;
  el('cart-view').hidden = false;
  el('restaurant-name').textContent = cart.restaurantName ?? '';

  el('cart-lines').innerHTML = cart.items
    .map((line, index) => cartLineHtml(line, index, currentQuote?.lines?.[index] ?? null))
    .join('');

  renderSummary();
}

/* -------------------------------------------------------- Récapitulatif chiffré */

/**
 * Marque les montants comme « en cours de recalcul ». Un total périmé affiché comme définitif
 * pendant qu'on modifie une quantité, c'est exactement le problème qu'on corrige : tant que le
 * serveur n'a pas répondu, l'écran le dit.
 */
function markSummaryStale() {
  el('sum-total-row').classList.add('is-stale');
}

function renderSummary() {
  const cart = getCart();

  if (!isLoggedIn()) {
    // Sans compte, aucun devis n'est possible (les frais dépendent de l'adresse et les
    // remises du compte). On affiche le seul montant connu de façon fiable — la somme des
    // prix figés à l'ajout — et on annonce clairement que le reste sera calculé ensuite.
    el('sum-subtotal').textContent = formatMoney(cartSubtotalCents(cart));
    el('sum-delivery').textContent = 'Après connexion';
    el('sum-tva').textContent = 'Après connexion';
    el('sum-total').textContent = 'Après connexion';
    el('sum-discount-row').hidden = true;
    el('quote-note').textContent = 'Connecte-toi pour voir les frais de livraison et le total exact.';
    el('checkout-btn').textContent = 'Se connecter pour continuer';

    return;
  }

  if (currentQuote === null) {
    el('sum-subtotal').textContent = formatMoney(cartSubtotalCents(cart));
    el('sum-delivery').textContent = 'Calcul…';
    el('sum-tva').textContent = 'Calcul…';
    el('sum-total').textContent = 'Calcul…';

    return;
  }

  el('sum-subtotal').textContent = formatMoney(currentQuote.subtotal_cents);
  el('sum-delivery').textContent = formatMoney(currentQuote.delivery_fee_cents);
  el('sum-tva').textContent = formatMoney(currentQuote.tva_cents);
  el('sum-total').textContent = formatMoney(currentQuote.total_cents);
  el('sum-total-row').classList.remove('is-stale');

  const hasDiscount = currentQuote.discount_cents > 0;
  el('sum-discount-row').hidden = !hasDiscount;

  if (hasDiscount) {
    el('sum-discount-label').textContent = currentQuote.promo?.code
      ? `Remise ${currentQuote.promo.code}`
      : 'Remise';
    el('sum-discount').textContent = `− ${formatMoney(currentQuote.discount_cents)}`;
  }

  el('quote-note').textContent = currentQuote.distance_km
    ? `Livraison estimée ${currentQuote.eta_low_min}–${currentQuote.eta_high_min} min · ${currentQuote.distance_km} km.`
    : 'Les frais de livraison dépendent de la distance entre le commerce et ton adresse.';

  el('checkout-btn').textContent = `Payer ${formatMoney(currentQuote.total_cents)}`;
}

function renderPromoMessage() {
  const message = el('promo-message');
  const promo = currentQuote?.promo;

  if (!promo || promo.status === 'none') {
    message.hidden = true;

    return;
  }

  message.hidden = false;
  message.textContent = promo.message;
  message.className = promo.status === 'ok' ? 'field-hint' : 'field-error';
}

/* --------------------------------------------------------------- Devis serveur */

/**
 * Construit la charge utile commune au devis et à la commande : une seule définition, donc
 * aucun risque que le devis porte sur un panier différent de celui qui sera commandé.
 */
function quotePayload(cart, addressLabel) {
  return {
    restaurant_id: cart.restaurantId,
    items: cart.items.map((line) => ({
      menu_item_id: line.menuItemId,
      quantity: line.quantity,
      option_ids: (line.options ?? []).map((option) => option.id),
    })),
    delivery_address: {
      lat: position.lat,
      lng: position.lng,
      label: addressLabel,
    },
    delivery_mode: getDeliveryMode(),
    promo_code: getPromoCode() || null,
  };
}

async function fetchQuote() {
  const cart = getCart();

  if (!isLoggedIn() || cart.items.length === 0 || cart.restaurantId === null) {
    renderSummary();

    return;
  }

  position ??= await getCurrentPosition({ fallback: FALLBACK_POSITION });

  // Une saisie plus récente a déjà relancé un devis : celui-ci n'a plus d'intérêt.
  quoteRequest?.abort();
  const controller = new AbortController();
  quoteRequest = controller;

  try {
    currentQuote = await apiFetch('/orders/quote', {
      method: 'POST',
      body: quotePayload(cart, el('address').value.trim()),
      signal: controller.signal,
    });

    hideAlert(el('checkout-error'));
    renderCart();
    renderPromoMessage();
  } catch (error) {
    if (controller.signal.aborted) return;

    currentQuote = null;
    markSummaryStale();
    renderSummary();

    // Un panier devenu invalide (article retiré du menu, adresse hors zone) est une
    // information utile : on l'affiche au lieu de laisser un total en « Calcul… » perpétuel.
    showAlert(
      el('checkout-error'),
      error.detail || error.message || 'Impossible de calculer le total pour l\'instant.'
    );
  } finally {
    if (quoteRequest === controller) quoteRequest = null;
  }
}

/** Regroupe les rafales de modifications (clics répétés sur +) en un seul appel. */
function scheduleQuote() {
  markSummaryStale();
  clearTimeout(quoteTimer);
  quoteTimer = setTimeout(fetchQuote, QUOTE_DEBOUNCE_MS);
}

/* ---------------------------------------------------------------- Interactions */

el('cart-lines').addEventListener('click', (event) => {
  const button = event.target.closest('button[data-delta]');
  if (!button) return;

  const lineIndex = Number(button.closest('.qty').dataset.lineIndex);
  const line = getCart().items[lineIndex];
  if (!line) return;

  setQuantity(lineIndex, line.quantity + Number(button.dataset.delta));
  currentQuote = null;
  renderCart();
  scheduleQuote();
});

document.querySelectorAll('input[name="delivery-mode"]').forEach((radio) => {
  radio.addEventListener('change', scheduleQuote);
});

el('promo-btn').addEventListener('click', () => {
  if (!isLoggedIn()) {
    requireLogin('/panier.html');

    return;
  }

  fetchQuote();
});

el('promo-code').addEventListener('keydown', (event) => {
  if (event.key === 'Enter') {
    event.preventDefault();
    el('promo-btn').click();
  }
});

// L'adresse conditionne les frais : on relance le devis quand elle change, sans attendre le
// clic sur « Payer ».
el('address').addEventListener('change', () => {
  const value = el('address').value.trim();
  if (value) setAddress(value);
  scheduleQuote();
});

/* -------------------------------------------------------------------- Checkout */

async function startCheckout() {
  const errorEl = el('checkout-error');
  const button = el('checkout-btn');
  hideAlert(errorEl);

  const address = el('address').value.trim();
  if (!address) {
    showAlert(errorEl, 'Renseigne une adresse de livraison.');
    el('address').focus();

    return;
  }

  if (!requireLogin('/panier.html')) return;

  const cart = getCart();
  if (cart.items.length === 0) return;

  // Le devis doit être à jour : commander sans lui reviendrait à valider un montant que
  // l'utilisateur n'a pas vu.
  if (currentQuote === null) {
    setBusy(button, true, 'Calcul du total…');
    await fetchQuote();
    setBusy(button, false);

    if (currentQuote === null) return;
  }

  setBusy(button, true, 'Création de la commande…');

  try {
    const order = await apiFetch('/orders', {
      method: 'POST',
      idempotencyKey: getOrderIdempotencyKey(),
      body: quotePayload(cart, address),
    });

    // Garde-fou : si le total réellement enregistré diffère de celui affiché (prix modifié par
    // le commerçant entre le devis et la validation), on ne redirige pas vers le paiement sans
    // le dire. Le client doit revoir le montant avant d'être débité.
    if (order.total_cents !== currentQuote.total_cents) {
      currentQuote = { ...currentQuote, ...order };
      renderSummary();
      showAlert(
        errorEl,
        `Le montant a changé depuis ton dernier calcul : ${formatMoney(order.total_cents)}. Vérifie puis relance le paiement.`,
        'info'
      );
      setBusy(button, false);

      return;
    }

    const intent = await apiFetch('/payments/intent', {
      method: 'POST',
      body: { order_id: order.order_id },
    });

    clearCart();
    sessionStorage.removeItem(ORDER_KEY_STORAGE);
    window.location.href = intent.payment_url;
  } catch (error) {
    if (error.status === 401) {
      showAlert(errorEl, 'Ta session a expiré — reconnecte-toi pour continuer.');
      setTimeout(() => {
        window.location.href = `/login.html?next=${encodeURIComponent('/panier.html')}`;
      }, 1800);

      return;
    }

    // Paiement indisponible (CinetPay non configuré ou en panne) : la commande, elle, existe.
    // Le dire évite que le client la repasse une seconde fois.
    if (error.status === 502 || error.status === 503) {
      clearCart();
      sessionStorage.removeItem(ORDER_KEY_STORAGE);
      showToast('Commande enregistrée — le paiement suivra.', { tone: 'error' });
      setTimeout(() => { window.location.href = '/orders.html'; }, 1500);

      return;
    }

    showAlert(errorEl, error.detail || error.message);
    setBusy(button, false);
  }
}

el('checkout-btn').addEventListener('click', startCheckout);

/* ------------------------------------------------------------------ Démarrage */

const savedAddress = getAddress();
if (savedAddress) el('address').value = savedAddress;

renderCart();
fetchQuote();
