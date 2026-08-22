const CART_KEY = 'saveurs_cart';

function read() {
  try {
    return JSON.parse(localStorage.getItem(CART_KEY)) ?? { restaurantId: null, restaurantName: null, items: [] };
  } catch {
    return { restaurantId: null, restaurantName: null, items: [] };
  }
}

function write(cart) {
  localStorage.setItem(CART_KEY, JSON.stringify(cart));
}

export function getCart() {
  return read();
}

/**
 * Identifie une ligne de panier — un même article avec des variantes différentes (ex: T-shirt
 * taille M vs L) doit rester deux lignes distinctes, pas fusionnées.
 */
function lineKey(menuItemId, options = []) {
  const optionIds = options.map((o) => o.id).sort((a, b) => a - b).join(',');

  return `${menuItemId}:${optionIds}`;
}

/** Ajoute un article — vide le panier si on change de commerce (une commande = un seul commerce). */
export function addItem(restaurantId, restaurantName, item) {
  const cart = read();

  if (cart.restaurantId !== null && cart.restaurantId !== restaurantId) {
    cart.items = [];
  }

  cart.restaurantId = restaurantId;
  cart.restaurantName = restaurantName;

  const key = lineKey(item.menuItemId, item.options);
  const existing = cart.items.find((line) => lineKey(line.menuItemId, line.options) === key);
  if (existing) {
    existing.quantity += 1;
  } else {
    cart.items.push({ ...item, quantity: 1 });
  }

  write(cart);

  return cart;
}

export function setQuantity(lineIndex, quantity) {
  const cart = read();
  cart.items = quantity <= 0
    ? cart.items.filter((_, i) => i !== lineIndex)
    : cart.items.map((line, i) => (i === lineIndex ? { ...line, quantity } : line));

  write(cart);

  return cart;
}

export function clearCart() {
  write({ restaurantId: null, restaurantName: null, items: [] });
}

export function cartSubtotalCents(cart = read()) {
  return cart.items.reduce((sum, line) => sum + line.priceCents * line.quantity, 0);
}

export function cartItemCount(cart = read()) {
  return cart.items.reduce((sum, line) => sum + line.quantity, 0);
}
