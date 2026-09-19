-- Supervision des transactions — à appliquer sur une base déjà installée :
--   mysql -u root saveurs < database/migrations/2026_09_16_transaction_monitoring.sql
-- (schema.sql contient déjà ces définitions pour les nouvelles installations.)
-- Migration à passage unique (ALTER sans IF NOT EXISTS, non supporté par MySQL) : la rejouer
-- sur une base déjà migrée échoue volontairement sur "Duplicate column name".

-- État d'encaissement, indépendant du statut de livraison.
ALTER TABLE orders
  ADD COLUMN payment_status ENUM('unpaid','paid','failed') NOT NULL DEFAULT 'unpaid' AFTER total_cents,
  ADD COLUMN paid_at DATETIME NULL AFTER payment_status,
  ADD KEY ix_orders_payment_status (payment_status, created_at);

-- Rattrapage des commandes déjà encaissées avant la migration : la seule trace existante
-- était l'événement "payment_succeeded" écrit par le webhook CinetPay.
UPDATE orders o
   SET o.payment_status = 'paid',
       o.paid_at = (SELECT MIN(e.created_at) FROM order_events e
                     WHERE e.order_id = o.id AND e.status = 'payment_succeeded')
 WHERE EXISTS (SELECT 1 FROM order_events e
                WHERE e.order_id = o.id AND e.status = 'payment_succeeded');

UPDATE orders o
   SET o.payment_status = 'failed'
 WHERE o.payment_status = 'unpaid'
   AND EXISTS (SELECT 1 FROM order_events e
                WHERE e.order_id = o.id AND e.status = 'payment_failed');

-- Filtrage des virements par statut (écran admin "virements échoués").
ALTER TABLE payouts
  ADD KEY ix_payouts_statut (statut, created_at);
