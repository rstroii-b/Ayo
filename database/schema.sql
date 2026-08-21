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
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE driver_profiles (
  user_id           BIGINT UNSIGNED PRIMARY KEY,
  siret             VARCHAR(14) NOT NULL,
  statut_juridique  ENUM('auto_entrepreneur','entreprise_individuelle') NOT NULL DEFAULT 'auto_entrepreneur',
  stripe_account_id VARCHAR(64) NULL,
  vehicule_type     ENUM('velo','scooter','voiture') NOT NULL DEFAULT 'velo',
  zone_id           BIGINT UNSIGNED NULL,
  is_online         TINYINT(1) NOT NULL DEFAULT 0,
  rating_avg        DECIMAL(2,1) NOT NULL DEFAULT 5.0,
  kyc_status        ENUM('pending','verified','rejected') NOT NULL DEFAULT 'pending',
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
  siret             VARCHAR(14) NOT NULL,
  tva_regime        ENUM('mandataire','commissionnaire') NOT NULL DEFAULT 'mandataire',
  adresse           VARCHAR(255) NOT NULL,
  lat               DECIMAL(10,7) NOT NULL,
  lng               DECIMAL(10,7) NOT NULL,
  cuisine_origine   VARCHAR(80) NULL,        -- ex: 'Sénégal', 'Côte d'Ivoire'
  stripe_account_id VARCHAR(64) NULL,        -- compte Stripe Connect Express du restaurant
  commission_pct    DECIMAL(4,2) NOT NULL DEFAULT 20.00,
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
  price_cents       INT UNSIGNED NOT NULL,
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
  price_delta_cents INT NOT NULL DEFAULT 0,
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
  payment_intent_id   VARCHAR(64) NULL,
  stripe_charge_id    VARCHAR(64) NULL,     -- pour rattacher les Transfer à la charge d'origine (source_transaction)
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
  UNIQUE KEY uq_orders_idempotency (idempotency_key),
  KEY ix_orders_restaurant_status (restaurant_id, status),
  KEY ix_orders_driver_status (driver_id, status),
  KEY ix_orders_client (client_id),
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
  actor_type        ENUM('client','restaurant','driver','system') NOT NULL,
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

-- Reversements Stripe Connect (deux lignes par commande livrée : restaurant + livreur).
CREATE TABLE payouts (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id          BIGINT UNSIGNED NOT NULL,
  recipient_type    ENUM('restaurant','driver') NOT NULL,
  driver_id         BIGINT UNSIGNED NULL,
  restaurant_id     BIGINT UNSIGNED NULL,
  amount_cents      INT UNSIGNED NOT NULL,
  statut            ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
  stripe_transfer_id VARCHAR(64) NULL,
  failure_reason    VARCHAR(255) NULL,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_payouts_driver (driver_id),
  KEY ix_payouts_restaurant (restaurant_id),
  KEY ix_payouts_order (order_id),
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

SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------
-- Données de démonstration minimales
-- ---------------------------------------------------------------

INSERT INTO delivery_zones (id, name, ville, base_fee_cents, price_per_km_cents, min_fee_cents)
VALUES (1, 'Paris intra-muros', 'Paris', 150, 40, 190);
