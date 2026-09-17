import test from 'node:test';
import assert from 'node:assert/strict';

import { escapeHtml, formatMoney, safeImageUrl } from '../js/format.js';

/**
 * L'application est en JavaScript sans framework : rien n'échappe automatiquement ce qui est
 * injecté via innerHTML. `escapeHtml` et `safeImageUrl` sont donc les deux seules barrières
 * entre une donnée saisie par un tiers (nom de commerce, libellé d'article, motif de refus
 * KYC) et le DOM. Elles méritent des tests à part entière.
 */

test('escapeHtml neutralise une balise script', () => {
  assert.equal(
    escapeHtml('<script>alert(1)</script>'),
    '&lt;script&gt;alert(1)&lt;/script&gt;'
  );
});

test('escapeHtml neutralise une sortie d\'attribut', () => {
  // Cas réel : un nom d'article injecté dans data-name="…" ou dans un aria-label.
  assert.equal(
    escapeHtml('" onmouseover="alert(1)'),
    '&quot; onmouseover=&quot;alert(1)'
  );
});

test('escapeHtml neutralise aussi l\'apostrophe', () => {
  assert.equal(escapeHtml("' onerror='x"), '&#39; onerror=&#39;x');
});

test('escapeHtml échappe l\'esperluette en premier, sans double échappement', () => {
  // Si & était traité en dernier, "&lt;" deviendrait "&amp;amp;lt;".
  assert.equal(escapeHtml('a & b'), 'a &amp; b');
  assert.equal(escapeHtml('&lt;'), '&amp;lt;');
});

test('escapeHtml gère null et undefined sans planter', () => {
  assert.equal(escapeHtml(null), '');
  assert.equal(escapeHtml(undefined), '');
});

test('escapeHtml convertit les nombres', () => {
  assert.equal(escapeHtml(42), '42');
});

test('escapeHtml laisse passer un texte normal, accents compris', () => {
  assert.equal(escapeHtml('Thiéboudiènne à emporter'), 'Thiéboudiènne à emporter');
});

test('safeImageUrl accepte https et les assets locaux', () => {
  assert.equal(safeImageUrl('https://cdn.example.com/p.jpg'), 'https://cdn.example.com/p.jpg');
  assert.equal(safeImageUrl('/assets/dishes/mafe.jpg'), '/assets/dishes/mafe.jpg');
});

test('safeImageUrl refuse les schémas dangereux', () => {
  // Cette valeur est posée dans un background-image : elle vient de la fiche d'un commerçant.
  assert.equal(safeImageUrl('javascript:alert(1)'), null);
  assert.equal(safeImageUrl('data:text/html,<script>alert(1)</script>'), null);
  assert.equal(safeImageUrl('http://example.com/p.jpg'), null);
});

test('safeImageUrl refuse une évasion de chemin', () => {
  assert.equal(safeImageUrl('../../etc/passwd'), null);
  assert.equal(safeImageUrl('//evil.example.com/p.jpg'), null);
});

test('safeImageUrl refuse une valeur non textuelle', () => {
  assert.equal(safeImageUrl(null), null);
  assert.equal(safeImageUrl(42), null);
  assert.equal(safeImageUrl({ toString: () => 'https://x' }), null);
});

test('formatMoney affiche des francs CFA sans sous-unité', () => {
  // Convention du projet : montants stockés en centièmes, XOF affiché sans décimales.
  assert.match(formatMoney(100000), /^1\s?000 FCFA$/);
  assert.match(formatMoney(0), /^0 FCFA$/);
});

test('formatMoney arrondit plutôt que de tronquer', () => {
  assert.match(formatMoney(150), /^2 FCFA$/);
  assert.match(formatMoney(149), /^1 FCFA$/);
});

test('formatMoney gère un montant négatif (remise affichée séparément)', () => {
  assert.match(formatMoney(-50000), /^-\s?500 FCFA$/);
});
