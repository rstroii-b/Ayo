-- Durcissement sécurité — à appliquer sur une base déjà installée (voir README §7/§8).
--   mysql -u root saveurs < database/migrations/2026_09_17_security_hardening.sql
-- (schema.sql contient déjà ces définitions pour les nouvelles installations.)

-- M-3 : compte désactivable — un compte inactif ne peut plus se connecter ni renouveler son jeton.
ALTER TABLE users
  ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER role;

-- M-1 : au plus un virement par bénéficiaire et par commande (filet anti-double-virement).
-- Purge préalable d'éventuels doublons historiques : on garde la ligne la plus ancienne.
DELETE p FROM payouts p
  JOIN payouts keep
    ON keep.order_id = p.order_id
   AND keep.recipient_type = p.recipient_type
   AND keep.id < p.id;

ALTER TABLE payouts
  ADD UNIQUE KEY uq_payouts_order_recipient (order_id, recipient_type);

-- L-2 : clé d'idempotence unique PAR CLIENT (et non globalement), sinon la même clé chez deux
-- clients distincts échoue à l'insertion. Sûr : les clés sont des UUID aléatoires côté front.
ALTER TABLE orders
  DROP INDEX uq_orders_idempotency,
  ADD UNIQUE KEY uq_orders_idempotency (client_id, idempotency_key);
