-- ═══════════════════════════════════════════════════════════════════
-- Kala B Kala — Migration: Business Plan Features
-- Run AFTER existing database.sql
-- ═══════════════════════════════════════════════════════════════════

-- ─── 1. Users: KYC fields ────────────────────────────────────────
ALTER TABLE `users`
  ADD COLUMN `national_id`       VARCHAR(10)  NULL AFTER `phone`,
  ADD COLUMN `id_card_image`     VARCHAR(255) NULL AFTER `national_id`,
  ADD COLUMN `bank_account`      VARCHAR(30)  NULL AFTER `id_card_image`,
  ADD COLUMN `kyc_status`        ENUM('none','pending','approved','rejected') DEFAULT 'none' AFTER `bank_account`,
  ADD COLUMN `seller_type`       ENUM('personal','store') DEFAULT 'personal' AFTER `kyc_status`,
  ADD COLUMN `store_name`        VARCHAR(120) NULL AFTER `seller_type`,
  ADD COLUMN `subscription_plan` ENUM('none','bronze','silver','gold') DEFAULT 'none' AFTER `store_name`,
  ADD COLUMN `subscription_until` TIMESTAMP NULL AFTER `subscription_plan`;

-- ─── 2. Listings: trade mode + bump ─────────────────────────────
ALTER TABLE `listings`
  ADD COLUMN `listing_mode`     ENUM('swap','sell','both') DEFAULT 'swap' AFTER `want_type`,
  ADD COLUMN `sell_price`       DECIMAL(15,0) UNSIGNED DEFAULT 0 COMMENT 'Price in Toman if sell mode' AFTER `listing_mode`,
  ADD COLUMN `needs_inspection` TINYINT(1) DEFAULT 0 AFTER `sell_price`,
  ADD COLUMN `inspection_status` ENUM('none','requested','approved','rejected') DEFAULT 'none' AFTER `needs_inspection`,
  ADD COLUMN `bump_until`       TIMESTAMP NULL AFTER `inspection_status`,
  ADD COLUMN `featured_until`   TIMESTAMP NULL AFTER `bump_until`;

-- ─── 3. Trades: Escrow + shipping + contract ────────────────────
ALTER TABLE `trades`
  ADD COLUMN `escrow_status`    ENUM('none','held','released','refunded') DEFAULT 'none' AFTER `status`,
  ADD COLUMN `escrow_amount`    DECIMAL(15,0) UNSIGNED DEFAULT 0 AFTER `escrow_status`,
  ADD COLUMN `bnpl_active`      TINYINT(1) DEFAULT 0 AFTER `escrow_amount`,
  ADD COLUMN `bnpl_months`      TINYINT UNSIGNED DEFAULT 0 AFTER `bnpl_active`,
  ADD COLUMN `shipping_method`  ENUM('in_person','post','tipax','courier') NULL AFTER `bnpl_months`,
  ADD COLUMN `tracking_code_a`  VARCHAR(60) NULL COMMENT 'User A tracking code' AFTER `shipping_method`,
  ADD COLUMN `tracking_code_b`  VARCHAR(60) NULL COMMENT 'User B tracking code' AFTER `tracking_code_a`,
  ADD COLUMN `contract_signed_at` TIMESTAMP NULL AFTER `tracking_code_b`;

-- ─── 4. Digital Contracts ───────────────────────────────────────
CREATE TABLE IF NOT EXISTS `trade_contracts` (
  `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `trade_id`        INT UNSIGNED NOT NULL,
  `user_a_id`       INT UNSIGNED NOT NULL,
  `user_b_id`       INT UNSIGNED NOT NULL,
  `listing_a_title` VARCHAR(255) NOT NULL,
  `listing_b_title` VARCHAR(255),
  `diff_amount`     DECIMAL(15,0) DEFAULT 0,
  `bnpl_months`     TINYINT DEFAULT 0,
  `terms`           TEXT NOT NULL,
  `user_a_signed`   TINYINT(1) DEFAULT 0,
  `user_b_signed`   TINYINT(1) DEFAULT 0,
  `user_a_signed_at` TIMESTAMP NULL,
  `user_b_signed_at` TIMESTAMP NULL,
  `created_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`trade_id`)   REFERENCES `trades`(`id`),
  FOREIGN KEY (`user_a_id`)  REFERENCES `users`(`id`),
  FOREIGN KEY (`user_b_id`)  REFERENCES `users`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── 5. Escrow Transactions ──────────────────────────────────────
CREATE TABLE IF NOT EXISTS `escrow_transactions` (
  `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `trade_id`   INT UNSIGNED NOT NULL,
  `user_id`    INT UNSIGNED NOT NULL,
  `amount`     DECIMAL(15,0) NOT NULL,
  `type`       ENUM('hold','release','refund','fee_deduct') NOT NULL,
  `note`       VARCHAR(255),
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`trade_id`) REFERENCES `trades`(`id`),
  FOREIGN KEY (`user_id`)  REFERENCES `users`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── 6. BNPL Requests ───────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `bnpl_requests` (
  `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `trade_id`     INT UNSIGNED NOT NULL,
  `user_id`      INT UNSIGNED NOT NULL,
  `amount`       DECIMAL(15,0) NOT NULL,
  `months`       TINYINT UNSIGNED NOT NULL DEFAULT 3,
  `monthly_fee`  DECIMAL(15,0) NOT NULL,
  `status`       ENUM('pending','approved','rejected','active','completed') DEFAULT 'pending',
  `lendtech_ref` VARCHAR(100) NULL COMMENT 'LendTech partner reference ID',
  `created_at`   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `approved_at`  TIMESTAMP NULL,
  FOREIGN KEY (`trade_id`) REFERENCES `trades`(`id`),
  FOREIGN KEY (`user_id`)  REFERENCES `users`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── 7. Subscription Plans ──────────────────────────────────────
CREATE TABLE IF NOT EXISTS `subscription_orders` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`     INT UNSIGNED NOT NULL,
  `plan`        ENUM('bronze','silver','gold') NOT NULL,
  `months`      TINYINT UNSIGNED DEFAULT 1,
  `amount_paid` DECIMAL(12,0) NOT NULL,
  `starts_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `ends_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `status`      ENUM('active','expired','cancelled') DEFAULT 'active',
  `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── 8. Listing Bumps ───────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `listing_bumps` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `listing_id`  INT UNSIGNED NOT NULL,
  `user_id`     INT UNSIGNED NOT NULL,
  `type`        ENUM('bump','feature') NOT NULL,
  `duration_h`  SMALLINT UNSIGNED NOT NULL DEFAULT 24,
  `amount_paid` DECIMAL(10,0) NOT NULL,
  `starts_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `ends_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`listing_id`) REFERENCES `listings`(`id`),
  FOREIGN KEY (`user_id`)    REFERENCES `users`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── 9. Expert Inspection Requests ──────────────────────────────
CREATE TABLE IF NOT EXISTS `inspection_requests` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `listing_id`  INT UNSIGNED NOT NULL,
  `user_id`     INT UNSIGNED NOT NULL,
  `trade_id`    INT UNSIGNED NULL,
  `type`        ENUM('self_request','trade_required') DEFAULT 'self_request',
  `status`      ENUM('pending','scheduled','done','cancelled') DEFAULT 'pending',
  `report`      TEXT NULL,
  `result`      ENUM('passed','failed','conditional') NULL,
  `price`       DECIMAL(10,0) NOT NULL DEFAULT 300000,
  `scheduled_at` TIMESTAMP NULL,
  `done_at`     TIMESTAMP NULL,
  `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`listing_id`) REFERENCES `listings`(`id`),
  FOREIGN KEY (`user_id`)    REFERENCES `users`(`id`),
  FOREIGN KEY (`trade_id`)   REFERENCES `trades`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── 10. Dispute Tickets ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `disputes` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `trade_id`    INT UNSIGNED NOT NULL,
  `filed_by`    INT UNSIGNED NOT NULL,
  `against`     INT UNSIGNED NOT NULL,
  `reason`      ENUM('wrong_item','damaged','missing','fraud','other') NOT NULL,
  `description` TEXT NOT NULL,
  `evidence`    VARCHAR(255) NULL COMMENT 'uploaded file',
  `status`      ENUM('open','reviewing','resolved_a','resolved_b','dismissed') DEFAULT 'open',
  `admin_note`  TEXT NULL,
  `resolved_at` TIMESTAMP NULL,
  `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`trade_id`)  REFERENCES `trades`(`id`),
  FOREIGN KEY (`filed_by`)  REFERENCES `users`(`id`),
  FOREIGN KEY (`against`)   REFERENCES `users`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Subscription plan config (static reference) ─────────────────
CREATE TABLE IF NOT EXISTS `subscription_plans` (
  `id`           TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `slug`         VARCHAR(20) UNIQUE NOT NULL,
  `name`         VARCHAR(60) NOT NULL,
  `price_month`  DECIMAL(12,0) NOT NULL,
  `listings_max` SMALLINT UNSIGNED NOT NULL,
  `has_api`      TINYINT(1) DEFAULT 0,
  `has_reports`  TINYINT(1) DEFAULT 0,
  `has_panel`    TINYINT(1) DEFAULT 0,
  `bump_credits` TINYINT UNSIGNED DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `subscription_plans` (`slug`,`name`,`price_month`,`listings_max`,`has_api`,`has_reports`,`has_panel`,`bump_credits`) VALUES
  ('bronze', 'Bronze',  500000,  20, 0, 0, 0, 2),
  ('silver', 'Silver', 1500000,  60, 0, 1, 1, 5),
  ('gold',   'Gold',   5000000, 999, 1, 1, 1, 15);
