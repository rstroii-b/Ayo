import test from 'node:test';
import assert from 'node:assert/strict';

import {
  ACTIVE_STATUSES,
  isActiveStatus,
  paymentStatusLabel,
  statusLabel,
  statusLongLabel,
  statusTone,
} from '../js/status.js';

/**
 * Avant status.js, chaque écran portait sa propre table de libellés : `ready_for_pickup` était
 * « Prête » dans l'historique, « prête, en attente d'un livreur » sur l'accueil et « Prêtes »
 * au back-office. Ces tests garantissent qu'il n'existe plus qu'une définition, et surtout que
 * l'ENUM SQL et le front ne divergent pas.
 */

/** Doit rester identique à l'ENUM orders.status de database/schema.sql. */
const STATUTS_SQL = [
  'pending',
  'accepted',
  'preparing',
  'ready_for_pickup',
  'picked_up',
  'delivering',
  'delivered',
  'cancelled',
];

test('chaque statut de la base a un libellé court et un libellé long', () => {
  for (const statut of STATUTS_SQL) {
    assert.notEqual(statusLabel(statut), 'Statut inconnu', `libellé court manquant pour ${statut}`);
    assert.notEqual(statusLongLabel(statut), 'Statut inconnu', `libellé long manquant pour ${statut}`);
  }
});

test('un statut inconnu ne laisse jamais fuiter la clé technique', () => {
  // Si l'API introduit un statut avant le redéploiement du front, l'utilisateur doit lire
  // une phrase, pas « awaiting_courier_reassignment ».
  assert.equal(statusLabel('statut_du_futur'), 'Statut inconnu');
  assert.equal(statusLongLabel(undefined), 'Statut inconnu');
});

test('les statuts actifs sont exactement ceux qui précèdent un état terminal', () => {
  assert.deepEqual(
    ACTIVE_STATUSES,
    STATUTS_SQL.filter((s) => s !== 'delivered' && s !== 'cancelled')
  );
});

test('isActiveStatus distingue une commande en cours d\'une commande terminée', () => {
  assert.equal(isActiveStatus('preparing'), true);
  assert.equal(isActiveStatus('delivered'), false);
  assert.equal(isActiveStatus('cancelled'), false);
  assert.equal(isActiveStatus('statut_inconnu'), false);
});

test('chaque tonalité correspond à une variante de badge définie en CSS', () => {
  // Ces valeurs alimentent [data-tone] dans components.css : une tonalité inventée donnerait
  // un badge sans couleur.
  const tonalitesConnues = ['live', 'wait', 'done', 'cancelled'];

  for (const statut of STATUTS_SQL) {
    assert.ok(
      tonalitesConnues.includes(statusTone(statut)),
      `tonalité inattendue pour ${statut} : ${statusTone(statut)}`
    );
  }
});

test('une commande annulée et une commande livrée ne partagent pas la même tonalité', () => {
  assert.notEqual(statusTone('cancelled'), statusTone('delivered'));
});

test('les libellés de paiement couvrent l\'ENUM payment_status', () => {
  for (const statut of ['unpaid', 'paid', 'failed', 'refunded']) {
    assert.ok(paymentStatusLabel(statut).length > 0, `libellé manquant pour ${statut}`);
  }

  // Repli prudent : en l'absence d'information, on n'annonce jamais « Payée ».
  assert.equal(paymentStatusLabel(undefined), paymentStatusLabel('unpaid'));
});
