-- Saveurs — schéma MySQL 8 (InnoDB, utf8mb4)
-- Correspond à la section 2 du document d'architecture.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------
-- Comptes & identité
-- ---------------------------------------------------------------

CREATE TABLE users (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email             VARCHAR(190) NOT NULL,
  phone             VARCHAR(20)  NULL,
  password_hash     VARCHAR(255) NOT NULL,
  first_name        VARCHAR(100) NOT NULL,
  last_name         VARCHAR(100) NOT NULL,
  role              ENUM('client','restaurant_owner','driver','admin') NOT NULL,
  -- Compte désactivable (M-3) : un compte inactif ne peut plus se connecter ni renouveler son
  -- jeton, ce qui coupe l'accès dans la durée de vie du jeton (30 min) sans rotation de secret.
  is_active         TINYINT(1) NOT NULL DEFAULT 1,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE driver_profiles (
  user_id           BIGINT UNSIGNED PRIMARY KEY,
  rccm              VARCHAR(32) NULL, -- numéro RCCM (registre du commerce ivoirien) ; facultatif (voir CGU §2)
  mobile_money_operator VARCHAR(20) NULL, -- ex: OM_CI, MTN_CI, MOOV_CI, WAVE_CI
  mobile_money_number   VARCHAR(20) NULL, -- format E.164
  vehicule_type     ENUM('velo','scooter','voiture') NOT NULL DEFAULT 'velo',
  zone_id           BIGINT UNSIGNED NULL,
  is_online         TINYINT(1) NOT NULL DEFAULT 0,
  rating_avg        DECIMAL(2,1) NOT NULL DEFAULT 5.0,
  kyc_status        ENUM('pending','verified','rejected') NOT NULL DEFAULT 'pending',
  kyc_document_path VARCHAR(255) NULL, -- nom de fichier stocké dans api/storage/kyc/, jamais une URL publique
  kyc_rejection_reason VARCHAR(255) NULL, -- motif renvoyé au livreur en cas de rejet
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_driver_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------
-- Zones de livraison (référencées par restaurants, drivers, orders)
-- ---------------------------------------------------------------

CREATE TABLE delivery_zones (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name              VARCHAR(120) NOT NULL,
  ville             VARCHAR(120) NOT NULL,
  polygon_geojson   JSON NULL,
  currency          CHAR(3) NOT NULL DEFAULT 'XOF', -- ISO 4217 ; XOF (Franc CFA) n'a pas de sous-unité utilisée en pratique
  base_fee_cents    INT UNSIGNED NOT NULL DEFAULT 150,
  price_per_km_cents INT UNSIGNED NOT NULL DEFAULT 40,
  min_fee_cents     INT UNSIGNED NOT NULL DEFAULT 190,
  surge_multiplier  DECIMAL(3,2) NOT NULL DEFAULT 1.00,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE driver_profiles
  ADD CONSTRAINT fk_driver_zone FOREIGN KEY (zone_id) REFERENCES delivery_zones(id) ON DELETE SET NULL;

-- ---------------------------------------------------------------
-- Restaurants & menus
-- ---------------------------------------------------------------

CREATE TABLE restaurants (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  owner_id          BIGINT UNSIGNED NOT NULL,
  zone_id           BIGINT UNSIGNED NULL,
  name              VARCHAR(150) NOT NULL,
  slug              VARCHAR(160) NOT NULL,
  rccm              VARCHAR(32) NULL,        -- numéro RCCM (registre du commerce ivoirien) ; facultatif
  adresse           VARCHAR(255) NOT NULL,
  lat               DECIMAL(10,7) NOT NULL,
  lng               DECIMAL(10,7) NOT NULL,
  cuisine_origine   VARCHAR(80) NULL,        -- ex: 'Sénégal', 'Côte d'Ivoire'
  photo_url         VARCHAR(255) NULL,       -- bannière affichée sur la fiche et la carte d'accueil
  mobile_money_operator VARCHAR(20) NULL,    -- ex: OM_CI, MTN_CI, MOOV_CI, WAVE_CI
  mobile_money_number   VARCHAR(20) NULL,    -- format E.164
  commission_pct    DECIMAL(4,2) NOT NULL DEFAULT 20.00,
  business_type     ENUM('food','fashion','furniture','grocery') NOT NULL DEFAULT 'food',
  delivery_mode     ENUM('instant','scheduled') NOT NULL DEFAULT 'instant', -- 'scheduled' pour les meubles (phase 2)
  is_active         TINYINT(1) NOT NULL DEFAULT 1,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_restaurants_slug (slug),
  KEY ix_restaurants_zone (zone_id),
  KEY ix_restaurants_location (lat, lng),
  CONSTRAINT fk_restaurant_owner FOREIGN KEY (owner_id) REFERENCES users(id),
  CONSTRAINT fk_restaurant_zone FOREIGN KEY (zone_id) REFERENCES delivery_zones(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE menu_categories (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  restaurant_id     BIGINT UNSIGNED NOT NULL,
  name              VARCHAR(80) NOT NULL,
  sort_order        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  KEY ix_menucat_restaurant (restaurant_id),
  CONSTRAINT fk_menucat_restaurant FOREIGN KEY (restaurant_id) REFERENCES restaurants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE menu_items (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  restaurant_id     BIGINT UNSIGNED NOT NULL,
  category_id       BIGINT UNSIGNED NOT NULL,
  name              VARCHAR(150) NOT NULL,
  description       VARCHAR(500) NULL,
  ingredients       TEXT NULL,               -- liste libre (ex: "Riz, poisson, tomate, oignon") ; affichée dans la fiche produit, pas de sens hors alimentaire
  price_cents       INT UNSIGNED NOT NULL,
  vat_rate          DECIMAL(4,2) NOT NULL DEFAULT 18.00, -- taux normal CI (18%) par défaut ; à ajuster par article si un taux réduit s'applique
  photo_url         VARCHAR(255) NULL,
  is_available      TINYINT(1) NOT NULL DEFAULT 1,
  allergenes        VARCHAR(255) NULL,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY ix_menuitems_restaurant (restaurant_id),
  KEY ix_menuitems_category (category_id),
  CONSTRAINT fk_menuitem_restaurant FOREIGN KEY (restaurant_id) REFERENCES restaurants(id) ON DELETE CASCADE,
  CONSTRAINT fk_menuitem_category FOREIGN KEY (category_id) REFERENCES menu_categories(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE item_options (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  menu_item_id      BIGINT UNSIGNED NOT NULL,
  name              VARCHAR(100) NOT NULL,   -- ex: 'Niveau de piment: fort'
  option_group      VARCHAR(50) NULL,        -- ex: 'Taille' — regroupe les choix mutuellement exclusifs
  price_delta_cents INT NOT NULL DEFAULT 0,
  stock_quantity    INT NULL,                -- NULL = stock non suivi
  KEY ix_options_item (menu_item_id),
  CONSTRAINT fk_option_item FOREIGN KEY (menu_item_id) REFERENCES menu_items(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------
-- Commandes
-- ---------------------------------------------------------------

CREATE TABLE orders (
  id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_id           BIGINT UNSIGNED NOT NULL,
  restaurant_id       BIGINT UNSIGNED NOT NULL,
  driver_id           BIGINT UNSIGNED NULL,
  status              ENUM('pending','accepted','preparing','ready_for_pickup',
                            'picked_up','delivering','delivered','cancelled') NOT NULL DEFAULT 'pending',
  subtotal_cents      INT UNSIGNED NOT NULL,
  delivery_fee_cents  INT UNSIGNED NOT NULL,
  tva_cents           INT UNSIGNED NOT NULL,
  total_cents         INT UNSIGNED NOT NULL,
  -- État de l'encaissement, distinct de "status" qui ne décrit que la livraison : sans cette
  -- colonne, répondre à "cette commande est-elle payée ?" imposait de fouiller order_events.
  payment_status      ENUM('unpaid','paid','failed') NOT NULL DEFAULT 'unpaid',
  paid_at             DATETIME NULL,
  payment_intent_id   VARCHAR(64) NULL,     -- merchant_transaction_id CinetPay
  cinetpay_notify_token VARCHAR(255) NULL,  -- pour vérifier l'authenticité du webhook CinetPay
  cinetpay_payment_url VARCHAR(500) NULL,   -- pour renvoyer le même lien de paiement si le client recharge la page
  idempotency_key     VARCHAR(80) NOT NULL,
  adresse_livraison   VARCHAR(255) NOT NULL,
  lat                 DECIMAL(10,7) NOT NULL,
  lng                 DECIMAL(10,7) NOT NULL,
  note_livreur        VARCHAR(255) NULL,
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  accepted_at         DATETIME NULL,
  ready_at            DATETIME NULL,
  picked_up_at        DATETIME NULL,
  delivered_at        DATETIME NULL,
  -- Unicité composite (L-2) : la même clé chez deux clients distincts ne doit pas se percuter.
  UNIQUE KEY uq_orders_idempotency (client_id, idempotency_key),
  KEY ix_orders_restaurant_status (restaurant_id, status),
  KEY ix_orders_driver_status (driver_id, status),
  KEY ix_orders_client (client_id),
  KEY ix_orders_payment_status (payment_status, created_at),
  CONSTRAINT fk_order_client FOREIGN KEY (client_id) REFERENCES users(id),
  CONSTRAINT fk_order_restaurant FOREIGN KEY (restaurant_id) REFERENCES restaurants(id),
  CONSTRAINT fk_order_driver FOREIGN KEY (driver_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE order_items (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id          BIGINT UNSIGNED NOT NULL,
  menu_item_id      BIGINT UNSIGNED NOT NULL,
  quantity          SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  price_cents       INT UNSIGNED NOT NULL,      -- prix figé au moment de la commande
  options_json      JSON NULL,
  KEY ix_orderitems_order (order_id),
  CONSTRAINT fk_orderitem_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_orderitem_menuitem FOREIGN KEY (menu_item_id) REFERENCES menu_items(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE order_events (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id          BIGINT UNSIGNED NOT NULL,
  status            VARCHAR(30) NOT NULL,
  actor_type        ENUM('client','restaurant','driver','admin','system') NOT NULL,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_orderevents_order (order_id),
  CONSTRAINT fk_orderevent_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------
-- Livraison & paiements livreurs
-- ---------------------------------------------------------------

CREATE TABLE driver_locations (
  driver_id         BIGINT UNSIGNED PRIMARY KEY,
  lat               DECIMAL(10,7) NOT NULL,
  lng               DECIMAL(10,7) NOT NULL,
  updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_driverloc_user FOREIGN KEY (driver_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Reversements (deux lignes par commande livrée : restaurant + livreur), via CinetPay.
CREATE TABLE payouts (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id          BIGINT UNSIGNED NOT NULL,
  recipient_type    ENUM('restaurant','driver') NOT NULL,
  driver_id         BIGINT UNSIGNED NULL,
  restaurant_id     BIGINT UNSIGNED NULL,
  amount_cents      INT UNSIGNED NOT NULL,
  statut            ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
  cinetpay_transfer_id  VARCHAR(64) NULL,  -- merchant_transaction_id qu'on a généré
  cinetpay_notify_token VARCHAR(255) NULL, -- pour vérifier l'authenticité du webhook de virement
  failure_reason    VARCHAR(255) NULL,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_payouts_driver (driver_id),
  KEY ix_payouts_restaurant (restaurant_id),
  KEY ix_payouts_order (order_id),
  KEY ix_payouts_statut (statut, created_at),
  -- Filet anti-double-virement (M-1) : au plus une ligne par bénéficiaire et par commande.
  UNIQUE KEY uq_payouts_order_recipient (order_id, recipient_type),
  CONSTRAINT fk_payout_driver FOREIGN KEY (driver_id) REFERENCES users(id),
  CONSTRAINT fk_payout_restaurant FOREIGN KEY (restaurant_id) REFERENCES restaurants(id),
  CONSTRAINT fk_payout_order FOREIGN KEY (order_id) REFERENCES orders(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Abonnements aux notifications push (Web Push) — un navigateur/appareil par ligne.
CREATE TABLE push_subscriptions (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id           BIGINT UNSIGNED NOT NULL,
  endpoint          VARCHAR(512) NOT NULL,
  p256dh            VARCHAR(255) NOT NULL,
  auth              VARCHAR(255) NOT NULL,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_push_endpoint (endpoint(255)),
  KEY ix_push_user (user_id),
  CONSTRAINT fk_push_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------
-- Connexion biométrique (WebAuthn / passkeys)
-- ---------------------------------------------------------------

CREATE TABLE webauthn_credentials (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id           BIGINT UNSIGNED NOT NULL,
  credential_id     VARCHAR(255) NOT NULL,
  public_key        TEXT NOT NULL,
  sign_count        INT UNSIGNED NOT NULL DEFAULT 0,
  label             VARCHAR(100) NULL,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_webauthn_credential_id (credential_id),
  KEY ix_webauthn_user (user_id),
  CONSTRAINT fk_webauthn_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Défi temporaire entre la génération des options et la vérification de la réponse — l'API
-- étant sans session (JWT), ce défi doit survivre entre deux requêtes HTTP distinctes.
CREATE TABLE webauthn_challenges (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id           BIGINT UNSIGNED NOT NULL,
  challenge         VARCHAR(255) NOT NULL,
  expires_at        DATETIME NOT NULL,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_webauthn_challenge_user (user_id),
  CONSTRAINT fk_webauthn_challenge_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------
-- Détection de fraude & journal de sécurité
-- ---------------------------------------------------------------

-- Journal des actions sensibles (inscription, connexion, commande, changement mobile money…)
-- avec le contexte réseau. Alimente la détection multi-comptes (même IP / même empreinte) et
-- l'investigation a posteriori. Volontairement séparé des logs fichier : requêtable en SQL.
CREATE TABLE security_events (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id           BIGINT UNSIGNED NULL,
  action            VARCHAR(40) NOT NULL,       -- ex: 'register', 'login', 'order_create', 'mobile_money_update'
  ip                VARCHAR(45) NULL,           -- IPv4/IPv6
  user_agent        VARCHAR(255) NULL,
  meta_json         JSON NULL,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_secevents_user (user_id, created_at),
  KEY ix_secevents_ip (ip, created_at),
  KEY ix_secevents_action (action, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Alertes de fraude levées par FraudDetector. Une alerte non résolue remonte dans le panel admin.
CREATE TABLE fraud_alerts (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  type              VARCHAR(50) NOT NULL,       -- ex: 'mobile_money_reuse', 'gps_teleport', 'impossible_delivery'
  severity          ENUM('low','medium','high') NOT NULL DEFAULT 'medium',
  user_id           BIGINT UNSIGNED NULL,       -- acteur concerné (livreur, client, restaurateur)
  order_id          BIGINT UNSIGNED NULL,
  detail            VARCHAR(255) NOT NULL,
  meta_json         JSON NULL,
  status            ENUM('open','reviewed','dismissed') NOT NULL DEFAULT 'open',
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_fraud_status (status, created_at),
  KEY ix_fraud_type (type, created_at),
  KEY ix_fraud_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------
-- Données de démonstration minimales
-- ---------------------------------------------------------------

-- Tarifs de départ approximatifs, à ajuster. id=2 conservé pour rester aligné avec la zone
-- Abidjan déjà en place sur la base de prod (voir mémoire de déploiement).
INSERT INTO delivery_zones (id, name, ville, currency, base_fee_cents, price_per_km_cents, min_fee_cents)
VALUES (2, 'Abidjan', 'Abidjan', 'XOF', 50000, 15000, 100000);
