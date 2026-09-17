/**
 * Libellés de statut de commande — source de vérité unique.
 *
 * Le même statut portait jusqu'ici trois noms selon l'écran : `ready_for_pickup` était
 * « Prête » dans l'historique, « prête, en attente d'un livreur » sur l'accueil et « Prêtes »
 * dans le back-office. Pour un client qui passe de l'un à l'autre, ce sont trois états
 * différents. Un seul dictionnaire, quatre usages : texte court (listes), texte long (suivi),
 * tonalité (couleur du badge) et progression (barre d'étapes).
 *
 * Les clés correspondent exactement à l'ENUM `orders.status` de database/schema.sql.
 */

const STATUSES = {
  pending: {
    short: 'Envoyée',
    long: 'Commande envoyée au commerce',
    tone: 'wait',
    step: 0,
  },
  accepted: {
    short: 'Acceptée',
    long: 'Commande acceptée',
    tone: 'live',
    step: 1,
  },
  preparing: {
    short: 'En préparation',
    long: 'En préparation',
    tone: 'live',
    step: 1,
  },
  ready_for_pickup: {
    short: 'Prête',
    long: 'Prête, en attente d\'un livreur',
    tone: 'wait',
    step: 2,
  },
  picked_up: {
    short: 'Récupérée',
    long: 'Récupérée par le livreur',
    tone: 'live',
    step: 3,
  },
  delivering: {
    short: 'En route',
    long: 'Le livreur est en route',
    tone: 'live',
    step: 3,
  },
  delivered: {
    short: 'Livrée',
    long: 'Livrée — bon appétit !',
    tone: 'done',
    step: 4,
  },
  cancelled: {
    short: 'Annulée',
    long: 'Commande annulée',
    tone: 'cancelled',
    step: -1,
  },
};

/** Statuts pour lesquels une commande est encore « en cours » côté client. */
export const ACTIVE_STATUSES = [
  'pending',
  'accepted',
  'preparing',
  'ready_for_pickup',
  'picked_up',
  'delivering',
];

export function isActiveStatus(status) {
  return ACTIVE_STATUSES.includes(status);
}

/**
 * Repli explicite pour un statut inconnu : l'API peut en introduire un nouveau avant que le
 * front ne soit redéployé. Mieux vaut « Statut inconnu » qu'une clé technique brute affichée
 * au client, ou pire, `undefined`.
 */
const UNKNOWN = { short: 'Statut inconnu', long: 'Statut inconnu', tone: 'wait', step: 0 };

export function statusInfo(status) {
  return STATUSES[status] ?? UNKNOWN;
}

export function statusLabel(status) {
  return statusInfo(status).short;
}

export function statusLongLabel(status) {
  return statusInfo(status).long;
}

export function statusTone(status) {
  return statusInfo(status).tone;
}

/** Libellés du mode de livraison (orders.delivery_mode). */
export const DELIVERY_MODE_LABELS = {
  standard: 'Standard',
  express: 'Express',
};

export function deliveryModeLabel(mode) {
  return DELIVERY_MODE_LABELS[mode] ?? DELIVERY_MODE_LABELS.standard;
}

/** Libellés de l'état de paiement (orders.payment_status). */
export const PAYMENT_STATUS_LABELS = {
  unpaid: 'En attente de paiement',
  paid: 'Payée',
  failed: 'Paiement échoué',
  refunded: 'Remboursée',
};

export function paymentStatusLabel(paymentStatus) {
  return PAYMENT_STATUS_LABELS[paymentStatus] ?? PAYMENT_STATUS_LABELS.unpaid;
}
