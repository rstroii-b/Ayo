import test from 'node:test';
import assert from 'node:assert/strict';

/**
 * Le panier vit dans localStorage : c'est la seule donnée métier que le navigateur détient
 * seul entre deux écrans. Ces tests couvrent ses règles (une commande = un commerce, deux
 * variantes = deux lignes) et sa résistance aux données abîmées — un localStorage corrompu ne
 * doit pas rendre l'application inutilisable jusqu'à ce que l'utilisateur vide son cache.
 *
 * Node n'a pas de localStorage : on en pose un minimal AVANT d'importer le module, exactement
 * comme le navigateur en fournit un.
 */

class LocalStorageDeTest {
  #store = new Map();

  getItem(key) {
    return this.#store.has(key) ? this.#store.get(key) : null;
  }

  setItem(key, value) {
    this.#store.set(key, String(value));
  }

  removeItem(key) {
    this.#store.delete(key);
  }

  clear() {
    this.#store.clear();
  }
}

globalThis.localStorage = new LocalStorageDeTest();

const { addItem, getCart, setQuantity, clearCart, cartSubtotalCents, cartItemCount } =
  await import('../js/cart.js');

test.beforeEach(() => {
  globalThis.localStorage.clear();
});

const PLAT = { menuItemId: 1, name: 'Thiéboudienne', priceCents: 250000 };

test('un panier neuf est vide', () => {
  const cart = getCart();

  assert.equal(cart.items.length, 0);
  assert.equal(cart.restaurantId, null);
  assert.equal(cartSubtotalCents(cart), 0);
});

test('ajouter un article le rattache à son commerce', () => {
  const cart = addItem(7, 'Maquis du Plateau', PLAT);

  assert.equal(cart.restaurantId, 7);
  assert.equal(cart.restaurantName, 'Maquis du Plateau');
  assert.equal(cart.items.length, 1);
  assert.equal(cart.items[0].quantity, 1);
});

test('ajouter deux fois le même article incrémente la ligne au lieu de la dupliquer', () => {
  addItem(7, 'Maquis', PLAT);
  const cart = addItem(7, 'Maquis', PLAT);

  assert.equal(cart.items.length, 1);
  assert.equal(cart.items[0].quantity, 2);
  assert.equal(cartItemCount(cart), 2);
});

test('deux variantes du même article restent deux lignes distinctes', () => {
  // Un t-shirt taille M et le même en taille L ne doivent pas fusionner.
  addItem(7, 'Boutique', { ...PLAT, options: [{ id: 10, name: 'M' }] });
  const cart = addItem(7, 'Boutique', { ...PLAT, options: [{ id: 11, name: 'L' }] });

  assert.equal(cart.items.length, 2);
});

test('l\'ordre des variantes ne crée pas de doublon', () => {
  // Deux sélections identiques faites dans un ordre différent désignent le même article.
  addItem(7, 'Boutique', { ...PLAT, options: [{ id: 10 }, { id: 11 }] });
  const cart = addItem(7, 'Boutique', { ...PLAT, options: [{ id: 11 }, { id: 10 }] });

  assert.equal(cart.items.length, 1);
  assert.equal(cart.items[0].quantity, 2);
});

test('changer de commerce vide le panier — une commande ne peut porter qu\'un commerce', () => {
  addItem(7, 'Maquis', PLAT);
  const cart = addItem(9, 'Supermarché', { menuItemId: 2, name: 'Riz', priceCents: 100000 });

  assert.equal(cart.restaurantId, 9);
  assert.equal(cart.items.length, 1);
  assert.equal(cart.items[0].name, 'Riz');
});

test('descendre une quantité à zéro retire la ligne', () => {
  addItem(7, 'Maquis', PLAT);
  const cart = setQuantity(0, 0);

  assert.equal(cart.items.length, 0);
});

test('une quantité négative retire aussi la ligne, sans laisser de ligne fantôme', () => {
  addItem(7, 'Maquis', PLAT);
  const cart = setQuantity(0, -3);

  assert.equal(cart.items.length, 0);
  assert.equal(cartSubtotalCents(cart), 0);
});

test('le sous-total additionne prix × quantité de chaque ligne', () => {
  addItem(7, 'Maquis', PLAT);
  addItem(7, 'Maquis', PLAT);
  addItem(7, 'Maquis', { menuItemId: 2, name: 'Bissap', priceCents: 50000 });

  // 2 × 2 500 + 1 × 500 = 5 500 FCFA
  assert.equal(cartSubtotalCents(getCart()), 550000);
});

test('vider le panier remet le commerce à zéro', () => {
  addItem(7, 'Maquis', PLAT);
  clearCart();

  const cart = getCart();
  assert.equal(cart.items.length, 0);
  assert.equal(cart.restaurantId, null);
});

test('un localStorage corrompu ne bloque pas l\'application', () => {
  // Cas réel : extension de navigateur, quota atteint, écriture interrompue.
  globalThis.localStorage.setItem('saveurs_cart', '{ceci n\'est pas du JSON');

  const cart = getCart();
  assert.equal(cart.items.length, 0);
  assert.equal(cart.restaurantId, null);
});

test('le sous-total du panier n\'est jamais la source du total facturé', () => {
  // Garde-fou documentaire : cartSubtotalCents ne couvre QUE les articles. Les frais de
  // livraison, la TVA et les remises viennent de POST /orders/quote — les recalculer ici
  // reproduirait l'écart entre le montant affiché et le montant débité.
  addItem(7, 'Maquis', PLAT);

  assert.equal(cartSubtotalCents(getCart()), PLAT.priceCents);
});
