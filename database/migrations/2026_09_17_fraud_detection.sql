-- Détection de fraude & journal de sécurité — à appliquer sur une base déjà installée.
--   mysql -u root saveurs < database/migrations/2026_09_17_fraud_detection.sql
-- (schema.sql contient déjà ces tables pour les nouvelles installations.)

CREATE TABLE IF NOT EXISTS security_events (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id           BIGINT UNSIGNED NULL,
  action            VARCHAR(40) NOT NULL,
  ip                VARCHAR(45) NULL,
  user_agent        VARCHAR(255) NULL,
  meta_json         JSON NULL,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_secevents_user (user_id, created_at),
  KEY ix_secevents_ip (ip, created_at),
  KEY ix_secevents_action (action, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS fraud_alerts (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  type              VARCHAR(50) NOT NULL,
  severity          ENUM('low','medium','high') NOT NULL DEFAULT 'medium',
  user_id           BIGINT UNSIGNED NULL,
  order_id          BIGINT UNSIGNED NULL,
  detail            VARCHAR(255) NOT NULL,
  meta_json         JSON NULL,
  status            ENUM('open','reviewed','dismissed') NOT NULL DEFAULT 'open',
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_fraud_status (status, created_at),
  KEY ix_fraud_type (type, created_at),
  KEY ix_fraud_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
