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

/** Ajoute un plat — vide le panier si on change de restaurant (une commande = un seul restaurant). */
export function addItem(restaurantId, restaurantName, item) {
  const cart = read();

  if (cart.restaurantId !== null && cart.restaurantId !== restaurantId) {
    cart.items = [];
  }

  cart.restaurantId = restaurantId;
  cart.restaurantName = restaurantName;

  const existing = cart.items.find((line) => line.menuItemId === item.menuItemId);
  if (existing) {
    existing.quantity += 1;
  } else {
    cart.items.push({ ...item, quantity: 1 });
  }

  write(cart);

  return cart;
}

export function setQuantity(menuItemId, quantity) {
  const cart = read();
  cart.items = quantity <= 0
    ? cart.items.filter((line) => line.menuItemId !== menuItemId)
    : cart.items.map((line) => (line.menuItemId === menuItemId ? { ...line, quantity } : line));

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
