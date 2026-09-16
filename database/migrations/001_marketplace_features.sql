-- Ayo — migration non destructive 001
-- Fonctionnalités : promotions serveur, avis clients et restaurants favoris.
-- À exécuter une seule fois sur une base existante :
-- mysql -u <user> -p <database> < database/migrations/001_marketplace_features.sql

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS coupons (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(40) NOT NULL,
  discount_type ENUM('percentage','fixed') NOT NULL DEFAULT 'percentage',
  discount_value INT UNSIGNED NOT NULL,
  max_discount_cents INT UNSIGNED NULL,
  min_order_cents INT UNSIGNED NOT NULL DEFAULT 0,
  usage_limit INT UNSIGNED NULL,
  usage_count INT UNSIGNED NOT NULL DEFAULT 0,
  starts_at DATETIME NULL,
  expires_at DATETIME NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_coupons_code (code),
  KEY ix_coupons_active_dates (is_active, starts_at, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS coupon_redemptions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  coupon_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  order_id BIGINT UNSIGNED NOT NULL,
  discount_cents INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_coupon_order (coupon_id, order_id),
  UNIQUE KEY uq_coupon_user_order (user_id, order_id),
  CONSTRAINT fk_redemption_coupon FOREIGN KEY (coupon_id) REFERENCES coupons(id) ON DELETE RESTRICT,
  CONSTRAINT fk_redemption_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_redemption_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS restaurant_reviews (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  restaurant_id BIGINT UNSIGNED NOT NULL,
  client_id BIGINT UNSIGNED NOT NULL,
  order_id BIGINT UNSIGNED NOT NULL,
  rating TINYINT UNSIGNED NOT NULL,
  comment VARCHAR(1000) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_review_order (order_id),
  KEY ix_reviews_restaurant (restaurant_id, created_at),
  CONSTRAINT fk_review_restaurant FOREIGN KEY (restaurant_id) REFERENCES restaurants(id) ON DELETE CASCADE,
  CONSTRAINT fk_review_client FOREIGN KEY (client_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_review_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS favorite_restaurants (
  user_id BIGINT UNSIGNED NOT NULL,
  restaurant_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, restaurant_id),
  KEY ix_favorites_restaurant (restaurant_id),
  CONSTRAINT fk_favorite_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_favorite_restaurant FOREIGN KEY (restaurant_id) REFERENCES restaurants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE orders
  ADD COLUMN promo_code VARCHAR(40) NULL AFTER total_cents,
  ADD COLUMN discount_cents INT UNSIGNED NOT NULL DEFAULT 0 AFTER promo_code,
  ADD COLUMN delivery_mode ENUM('standard','express') NOT NULL DEFAULT 'standard' AFTER discount_cents;

ALTER TABLE restaurants
  ADD COLUMN rating_avg DECIMAL(2,1) NOT NULL DEFAULT 0.0 AFTER photo_url,
  ADD COLUMN review_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER rating_avg;

-- Coupons de démonstration facultatifs. INSERT IGNORE évite de modifier un coupon existant.
INSERT IGNORE INTO coupons (code, discount_type, discount_value, max_discount_cents, min_order_cents, expires_at)
VALUES
  ('AYO10', 'percentage', 10, 3000, 5000, DATE_ADD(NOW(), INTERVAL 1 YEAR)),
  ('SAVEURS', 'percentage', 15, 5000, 10000, DATE_ADD(NOW(), INTERVAL 1 YEAR));

SET FOREIGN_KEY_CHECKS = 1;
