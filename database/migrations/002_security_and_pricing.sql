-- Ayo — migration non destructive 002
-- Sécurité et cohérence des prix :
--   1. état de paiement porté par la commande elle-même (et plus seulement une ligne d'événement) ;
--   2. journal des tentatives de connexion, support du plafonnement anti-force brute ;
--   3. index manquants sur les chemins de lecture chauds.
--
-- À exécuter une seule fois sur une base existante, après 001 :
--   mysql -u <user> -p <database> < database/migrations/002_security_and_pricing.sql

SET NAMES utf8mb4;

-- ---------------------------------------------------------------
-- 1. État de paiement de la commande
-- ---------------------------------------------------------------
-- Jusqu'ici, un paiement confirmé n'écrivait qu'une ligne dans order_events. Personne ne la
-- lisait : une commande pouvait donc être acceptée, préparée, livrée et reversée sans qu'aucun
-- paiement n'ait jamais abouti. La colonne ci-dessous rend cet état interrogeable, et
-- OrderController s'en sert pour refuser le passage en "accepted" d'une commande non payée
-- (uniquement lorsque l'encaissement en ligne est configuré — voir README §5).
ALTER TABLE orders
  ADD COLUMN payment_status ENUM('unpaid','paid','failed','refunded') NOT NULL DEFAULT 'unpaid' AFTER total_cents,
  ADD COLUMN paid_at DATETIME NULL AFTER payment_status;

-- Reprise de l'historique : les commandes déjà livrées ont forcément été honorées, et les
-- commandes dont un événement "payment_succeeded" existe sont payées. Sans cette reprise, le
-- contrôle ajouté ci-dessus bloquerait des commandes en cours au moment du déploiement.
UPDATE orders o
  JOIN order_events e ON e.order_id = o.id AND e.status = 'payment_succeeded'
  SET o.payment_status = 'paid', o.paid_at = COALESCE(o.paid_at, e.created_at);

UPDATE orders SET payment_status = 'paid', paid_at = COALESCE(paid_at, delivered_at)
  WHERE status = 'delivered' AND payment_status = 'unpaid';

-- ---------------------------------------------------------------
-- 2. Tentatives de connexion (anti-force brute)
-- ---------------------------------------------------------------
-- Le point d'entrée /auth/login n'avait aucune limite : un script pouvait tester des mots de
-- passe aussi vite que le serveur répondait. On journalise les tentatives par identifiant et
-- par IP pour plafonner (voir api/src/Support/RateLimiter.php).
CREATE TABLE IF NOT EXISTS auth_attempts (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  scope       VARCHAR(30)  NOT NULL,   -- 'login', 'register', 'order'...
  identifier  VARCHAR(190) NOT NULL,   -- email normalisé, ou adresse IP
  succeeded   TINYINT(1)   NOT NULL DEFAULT 0,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_attempts_lookup (scope, identifier, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------
-- 3. Session : durée absolue
-- ---------------------------------------------------------------
-- /auth/refresh réémettait un jeton indéfiniment : un jeton volé restait valable pour toujours
-- tant qu'on le rafraîchissait. Les jetons portent désormais la date d'ouverture de session
-- (claim `sid_iat`) et sont refusés au-delà de JWT_SESSION_MAX_DAYS. Rien à migrer en base :
-- les jetons émis avant ce déploiement n'ont pas le claim et sont traités comme des sessions
-- ouvertes à l'instant de leur émission (voir api/src/Support/Jwt.php).

-- ---------------------------------------------------------------
-- 4. Index de lecture
-- ---------------------------------------------------------------
-- La file des courses disponibles (driver) et l'historique client scannaient sans index dédié.
CREATE INDEX ix_orders_available ON orders (status, driver_id, ready_at);
CREATE INDEX ix_orders_client_created ON orders (client_id, created_at);
