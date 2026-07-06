-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Generation Time: Jul 2026
-- Server version: 8.0.44
-- PHP Version: 8.4.21
--
-- Complete Database: Swaapin (includes all migrations: v2, admin, support, wallet)
--

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `swaapin`
--

-- --------------------------------------------------------

--
-- Table structure for table `categories`
--

CREATE TABLE `categories` (
  `id` int UNSIGNED NOT NULL,
  `parent_id` int UNSIGNED DEFAULT NULL,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `icon` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'bi bi-tag',
  `sort_order` tinyint NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `categories`
--

INSERT INTO `categories` (`id`, `parent_id`, `name`, `slug`, `icon`, `sort_order`, `is_active`) VALUES
(1, NULL, 'Electronics', 'electronics', 'bi bi-cpu', 1, 1),
(2, NULL, 'Clothing', 'clothing', 'bi bi-bag', 2, 1),
(3, NULL, 'Home & Garden', 'home-garden', 'bi bi-house', 3, 1),
(4, NULL, 'Books & Media', 'books-media', 'bi bi-book', 4, 1),
(5, NULL, 'Sports', 'sports', 'bi bi-bicycle', 5, 1),
(6, NULL, 'Toys & Games', 'toys-games', 'bi bi-controller', 6, 1),
(7, NULL, 'Vehicles', 'vehicles', 'bi bi-car-front', 7, 1),
(8, NULL, 'Services', 'services', 'bi bi-tools', 8, 1),
(9, NULL, 'Food & Drink', 'food-drink', 'bi bi-cup-hot', 9, 1),
(10, NULL, 'Other', 'other', 'bi bi-three-dots', 10, 1),
(11, 1, 'Phones', 'phones', 'bi bi-phone', 1, 1),
(12, 1, 'Laptops', 'laptops', 'bi bi-laptop', 2, 1),
(13, 1, 'Cameras', 'cameras', 'bi bi-camera', 3, 1),
(14, 1, 'Audio', 'audio', 'bi bi-headphones', 4, 1),
(15, 1, 'Gaming', 'gaming', 'bi bi-joystick', 5, 1),
(16, 2, 'Men\'s Clothing', 'mens-clothing', 'bi bi-person', 1, 1),
(17, 2, 'Women\'s Clothing', 'womens-clothing', 'bi bi-person-dress', 2, 1),
(18, 2, 'Shoes', 'shoes', 'bi bi-boot', 3, 1),
(19, 3, 'Furniture', 'furniture', 'bi bi-lamp', 1, 1),
(20, 3, 'Kitchen', 'kitchen', 'bi bi-egg-fried', 2, 1),
(21, 3, 'Garden', 'garden', 'bi bi-flower1', 3, 1),
(22, 4, 'Books', 'books', 'bi bi-book', 1, 1),
(23, 4, 'Movies', 'movies', 'bi bi-film', 2, 1),
(24, 4, 'Music', 'music', 'bi bi-music-note', 3, 1),
(25, 8, 'Tutoring', 'tutoring', 'bi bi-mortarboard', 1, 1),
(26, 8, 'Repair & Fix', 'repair-fix', 'bi bi-wrench', 2, 1),
(27, 8, 'Creative', 'creative', 'bi bi-palette', 3, 1),
(28, 8, 'Transport', 'transport', 'bi bi-truck', 4, 1);

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int UNSIGNED NOT NULL,
  `name` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(180) COLLATE utf8mb4_unicode_ci NOT NULL,
  `phone` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `national_id` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `id_card_image` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `bank_account` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `kyc_status` enum('none','pending','approved','rejected') COLLATE utf8mb4_unicode_ci DEFAULT 'none',
  `seller_type` enum('personal','store') COLLATE utf8mb4_unicode_ci DEFAULT 'personal',
  `store_name` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `subscription_plan` enum('none','bronze','silver','gold') COLLATE utf8mb4_unicode_ci DEFAULT 'none',
  `subscription_until` timestamp NULL DEFAULT NULL,
  `city` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `avatar` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `bio` text COLLATE utf8mb4_unicode_ci,
  `password_hash` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `credit_balance` decimal(12,2) NOT NULL DEFAULT '0.00',
  `rating` decimal(3,2) NOT NULL DEFAULT '0.00',
  `rating_count` int UNSIGNED NOT NULL DEFAULT '0',
  `verification_level` tinyint UNSIGNED NOT NULL DEFAULT '1' COMMENT '1=email,2=phone,3=id_verified',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `role` enum('user','admin') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'user',
  `kyc_note` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_seen` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `name`, `email`, `phone`, `national_id`, `id_card_image`, `bank_account`, `kyc_status`, `seller_type`, `store_name`, `subscription_plan`, `subscription_until`, `city`, `avatar`, `bio`, `password_hash`, `credit_balance`, `rating`, `rating_count`, `verification_level`, `is_active`, `role`, `kyc_note`, `last_seen`, `created_at`, `updated_at`) VALUES
(1, 'Admin', 'admin@kalabkala.com', '+989000000000', NULL, NULL, NULL, 'none', 'personal', NULL, 'none', NULL, 'Tehran', NULL, NULL, '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uFutB/ZEm', 500.00, 0.00, 0, 3, 1, 'admin', NULL, NULL, '2026-06-14 21:08:55', '2026-06-14 21:08:55'),
(2, 'Sara Ahmadi', 'sara@example.com', '+989111111111', NULL, NULL, NULL, 'none', 'personal', NULL, 'none', NULL, 'Tehran', NULL, NULL, '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uFutB/ZEm', 150.00, 0.00, 0, 2, 1, 'user', NULL, NULL, '2026-06-14 21:08:55', '2026-06-14 21:08:55'),
(3, 'Ali Rezaei', 'ali@example.com', '+989222222222', NULL, NULL, NULL, 'none', 'personal', NULL, 'none', NULL, 'Isfahan', NULL, NULL, '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uFutB/ZEm', 200.00, 0.00, 0, 2, 1, 'user', NULL, NULL, '2026-06-14 21:08:55', '2026-06-14 21:08:55'),
(4, 'آرین سیدی', 'ariansj.ir@gmail.com', '09150583289', NULL, NULL, NULL, 'none', 'personal', NULL, 'none', NULL, 'سبزوار', NULL, NULL, '$2y$10$xYZHLiUwpbWPeeUwJ4L2Auvux7P315mbvXsQkYADKmbLq5MJp4GxO', 50.00, 0.00, 0, 1, 1, 'user', NULL, '2026-06-15 01:07:53', '2026-06-15 01:07:53', '2026-06-15 01:07:53');

-- --------------------------------------------------------

--
-- Table structure for table `listings`
--

CREATE TABLE `listings` (
  `id` int UNSIGNED NOT NULL,
  `user_id` int UNSIGNED NOT NULL,
  `category_id` int UNSIGNED NOT NULL,
  `title` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `condition` enum('new','like_new','good','fair','poor') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'good',
  `estimated_value` decimal(10,2) NOT NULL DEFAULT '0.00',
  `want_in_return` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `want_type` enum('item','service','credit','any') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'any',
  `listing_mode` enum('swap','sell','both') COLLATE utf8mb4_unicode_ci DEFAULT 'swap',
  `sell_price` decimal(15,0) UNSIGNED DEFAULT '0' COMMENT 'Price in Toman if sell mode',
  `needs_inspection` tinyint(1) DEFAULT '0',
  `inspection_status` enum('none','requested','approved','rejected') COLLATE utf8mb4_unicode_ci DEFAULT 'none',
  `bump_until` timestamp NULL DEFAULT NULL,
  `featured_until` timestamp NULL DEFAULT NULL,
  `city` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('active','traded','expired','deleted') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `review_status` enum('pending','approved','rejected') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'approved',
  `review_note` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_featured` tinyint(1) NOT NULL DEFAULT '0',
  `views` int UNSIGNED NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `listing_images`
--

CREATE TABLE `listing_images` (
  `id` int UNSIGNED NOT NULL,
  `listing_id` int UNSIGNED NOT NULL,
  `filename` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_primary` tinyint(1) NOT NULL DEFAULT '0',
  `sort_order` tinyint NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `trade_offers`
--

CREATE TABLE `trade_offers` (
  `id` int UNSIGNED NOT NULL,
  `listing_id` int UNSIGNED NOT NULL COMMENT 'The listing being offered on',
  `from_user_id` int UNSIGNED NOT NULL COMMENT 'User making the offer',
  `offer_listing_id` int UNSIGNED DEFAULT NULL COMMENT 'Item they are offering',
  `offer_credit` decimal(10,2) NOT NULL DEFAULT '0.00',
  `message` text COLLATE utf8mb4_unicode_ci,
  `status` enum('pending','accepted','rejected','cancelled','completed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `trades`
--

CREATE TABLE `trades` (
  `id` int UNSIGNED NOT NULL,
  `offer_id` int UNSIGNED NOT NULL,
  `user_a_id` int UNSIGNED NOT NULL COMMENT 'Listing owner (acceptor)',
  `user_b_id` int UNSIGNED NOT NULL COMMENT 'Offer sender',
  `listing_a_id` int UNSIGNED NOT NULL,
  `listing_b_id` int UNSIGNED DEFAULT NULL,
  `credit_diff` decimal(10,2) NOT NULL DEFAULT '0.00',
  `status` enum('in_progress','user_a_confirmed','user_b_confirmed','completed','disputed','cancelled') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'in_progress',
  `escrow_status` enum('none','held','released','refunded') COLLATE utf8mb4_unicode_ci DEFAULT 'none',
  `escrow_amount` decimal(15,0) UNSIGNED DEFAULT '0',
  `bnpl_active` tinyint(1) DEFAULT '0',
  `bnpl_months` tinyint UNSIGNED DEFAULT '0',
  `shipping_method` enum('in_person','post','tipax','courier') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tracking_code_a` varchar(60) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'User A tracking code',
  `tracking_code_b` varchar(60) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'User B tracking code',
  `contract_signed_at` timestamp NULL DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `trade_contracts`
--

CREATE TABLE IF NOT EXISTS `trade_contracts` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `trade_id` int UNSIGNED NOT NULL,
  `user_a_id` int UNSIGNED NOT NULL,
  `user_b_id` int UNSIGNED NOT NULL,
  `listing_a_title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `listing_b_title` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `diff_amount` decimal(15,0) DEFAULT '0',
  `bnpl_months` tinyint DEFAULT '0',
  `terms` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_a_signed` tinyint(1) DEFAULT '0',
  `user_b_signed` tinyint(1) DEFAULT '0',
  `user_a_signed_at` timestamp NULL DEFAULT NULL,
  `user_b_signed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_contract_trade` (`trade_id`),
  KEY `idx_contract_user_a` (`user_a_id`),
  KEY `idx_contract_user_b` (`user_b_id`),
  CONSTRAINT `fk_contract_trade` FOREIGN KEY (`trade_id`) REFERENCES `trades` (`id`),
  CONSTRAINT `fk_contract_user_a` FOREIGN KEY (`user_a_id`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_contract_user_b` FOREIGN KEY (`user_b_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `escrow_transactions`
--

CREATE TABLE IF NOT EXISTS `escrow_transactions` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `trade_id` int UNSIGNED NOT NULL,
  `user_id` int UNSIGNED NOT NULL,
  `amount` decimal(15,0) NOT NULL,
  `type` enum('hold','release','refund','fee_deduct') COLLATE utf8mb4_unicode_ci NOT NULL,
  `note` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_escrow_trade` (`trade_id`),
  KEY `idx_escrow_user` (`user_id`),
  CONSTRAINT `fk_escrow_trade` FOREIGN KEY (`trade_id`) REFERENCES `trades` (`id`),
  CONSTRAINT `fk_escrow_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `bnpl_requests`
--

CREATE TABLE IF NOT EXISTS `bnpl_requests` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `trade_id` int UNSIGNED NOT NULL,
  `user_id` int UNSIGNED NOT NULL,
  `amount` decimal(15,0) NOT NULL,
  `months` tinyint UNSIGNED NOT NULL DEFAULT '3',
  `monthly_fee` decimal(15,0) NOT NULL,
  `status` enum('pending','approved','rejected','active','completed') COLLATE utf8mb4_unicode_ci DEFAULT 'pending',
  `lendtech_ref` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'LendTech partner reference number',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `approved_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_bnpl_trade` (`trade_id`),
  KEY `idx_bnpl_user` (`user_id`),
  CONSTRAINT `fk_bnpl_trade` FOREIGN KEY (`trade_id`) REFERENCES `trades` (`id`),
  CONSTRAINT `fk_bnpl_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `subscription_plans`
--

CREATE TABLE IF NOT EXISTS `subscription_plans` (
  `id` tinyint UNSIGNED NOT NULL AUTO_INCREMENT,
  `slug` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL,
  `price_month` decimal(12,0) NOT NULL,
  `listings_max` smallint UNSIGNED NOT NULL,
  `has_api` tinyint(1) DEFAULT '0',
  `has_reports` tinyint(1) DEFAULT '0',
  `has_panel` tinyint(1) DEFAULT '0',
  `bump_credits` tinyint UNSIGNED DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_plan_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `subscription_plans`
--

INSERT IGNORE INTO `subscription_plans` (`slug`, `name`, `price_month`, `listings_max`, `has_api`, `has_reports`, `has_panel`, `bump_credits`) VALUES
('bronze', 'Bronze', 500000, 20, 0, 0, 0, 2),
('silver', 'Silver', 1500000, 60, 0, 1, 1, 5),
('gold', 'Gold', 5000000, 999, 1, 1, 1, 15);

-- --------------------------------------------------------

--
-- Table structure for table `subscription_orders`
--

CREATE TABLE IF NOT EXISTS `subscription_orders` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int UNSIGNED NOT NULL,
  `plan` enum('bronze','silver','gold') COLLATE utf8mb4_unicode_ci NOT NULL,
  `months` tinyint UNSIGNED DEFAULT '1',
  `amount_paid` decimal(12,0) NOT NULL,
  `starts_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `ends_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `status` enum('active','expired','cancelled') COLLATE utf8mb4_unicode_ci DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_subscription_user` (`user_id`),
  CONSTRAINT `fk_subscription_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `listing_bumps`
--

CREATE TABLE IF NOT EXISTS `listing_bumps` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `listing_id` int UNSIGNED NOT NULL,
  `user_id` int UNSIGNED NOT NULL,
  `type` enum('bump','feature') COLLATE utf8mb4_unicode_ci NOT NULL,
  `duration_h` smallint UNSIGNED NOT NULL DEFAULT '24',
  `amount_paid` decimal(10,0) NOT NULL,
  `starts_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `ends_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_bump_listing` (`listing_id`),
  KEY `idx_bump_user` (`user_id`),
  CONSTRAINT `fk_bump_listing` FOREIGN KEY (`listing_id`) REFERENCES `listings` (`id`),
  CONSTRAINT `fk_bump_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `inspection_requests`
--

CREATE TABLE IF NOT EXISTS `inspection_requests` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `listing_id` int UNSIGNED NOT NULL,
  `user_id` int UNSIGNED NOT NULL,
  `trade_id` int UNSIGNED DEFAULT NULL,
  `type` enum('self_request','trade_required') COLLATE utf8mb4_unicode_ci DEFAULT 'self_request',
  `status` enum('pending','scheduled','done','cancelled') COLLATE utf8mb4_unicode_ci DEFAULT 'pending',
  `report` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `result` enum('passed','failed','conditional') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `price` decimal(10,0) NOT NULL DEFAULT '300000',
  `scheduled_at` timestamp NULL DEFAULT NULL,
  `done_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_inspection_listing` (`listing_id`),
  KEY `idx_inspection_user` (`user_id`),
  KEY `idx_inspection_trade` (`trade_id`),
  CONSTRAINT `fk_inspection_listing` FOREIGN KEY (`listing_id`) REFERENCES `listings` (`id`),
  CONSTRAINT `fk_inspection_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_inspection_trade` FOREIGN KEY (`trade_id`) REFERENCES `trades` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `disputes`
--

CREATE TABLE IF NOT EXISTS `disputes` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `trade_id` int UNSIGNED NOT NULL,
  `filed_by` int UNSIGNED NOT NULL,
  `against` int UNSIGNED NOT NULL,
  `reason` enum('wrong_item','damaged','missing','fraud','other') COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `evidence` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'uploaded file',
  `status` enum('open','reviewing','resolved_a','resolved_b','dismissed') COLLATE utf8mb4_unicode_ci DEFAULT 'open',
  `admin_note` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `resolved_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_dispute_trade` (`trade_id`),
  KEY `idx_dispute_filed_by` (`filed_by`),
  KEY `idx_dispute_against` (`against`),
  CONSTRAINT `fk_dispute_trade` FOREIGN KEY (`trade_id`) REFERENCES `trades` (`id`),
  CONSTRAINT `fk_dispute_filed_by` FOREIGN KEY (`filed_by`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_dispute_against` FOREIGN KEY (`against`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `wallet_transactions`
--

CREATE TABLE `wallet_transactions` (
  `id` int UNSIGNED NOT NULL,
  `user_id` int UNSIGNED NOT NULL,
  `type` enum('deposit','withdraw','trade_credit','trade_debit','fee','refund') COLLATE utf8mb4_unicode_ci NOT NULL,
  `ref_type` enum('none','trade','trade_offer','listing','subscription_order','listing_bump','inspection_request','external') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'none' COMMENT 'Entity type that ref_id points to',
  `amount` decimal(12,2) NOT NULL,
  `balance_after` decimal(12,2) NOT NULL,
  `currency_code` char(3) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'IRT' COMMENT 'ISO 4217 (ledger in Toman)',
  `currency` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'تومان' COMMENT 'Display unit label',
  `note` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ref_id` int UNSIGNED DEFAULT NULL COMMENT 'ID within ref_type table (see ref_type column)',
  `trade_id` int UNSIGNED DEFAULT NULL COMMENT 'FK trades.id when trade-related',
  `listing_id` int UNSIGNED DEFAULT NULL COMMENT 'FK listings.id when listing-related',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `wallet_transactions`
--

INSERT INTO `wallet_transactions` (`id`, `user_id`, `type`, `ref_type`, `amount`, `balance_after`, `currency_code`, `currency`, `note`, `ref_id`, `trade_id`, `listing_id`, `created_at`) VALUES
(1, 4, 'deposit', 'none', 50.00, 50.00, 'IRT', 'تومان', 'Welcome bonus', NULL, NULL, NULL, '2026-06-15 01:07:53');

-- --------------------------------------------------------

--
-- Table structure for table `messages`
--

CREATE TABLE `messages` (
  `id` int UNSIGNED NOT NULL,
  `thread_id` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `from_user_id` int UNSIGNED NOT NULL,
  `to_user_id` int UNSIGNED NOT NULL,
  `offer_id` int UNSIGNED DEFAULT NULL,
  `body` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` int UNSIGNED NOT NULL,
  `user_id` int UNSIGNED NOT NULL,
  `type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `title` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `body` text COLLATE utf8mb4_unicode_ci,
  `link` varchar(300) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `otp_codes`
--

CREATE TABLE `otp_codes` (
  `id` int UNSIGNED NOT NULL,
  `phone` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `code` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `used` tinyint(1) NOT NULL DEFAULT '0',
  `expires_at` datetime NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `reviews`
--

CREATE TABLE `reviews` (
  `id` int UNSIGNED NOT NULL,
  `trade_id` int UNSIGNED NOT NULL,
  `from_user_id` int UNSIGNED NOT NULL,
  `to_user_id` int UNSIGNED NOT NULL,
  `rating` tinyint UNSIGNED NOT NULL COMMENT '1-5',
  `comment` text COLLATE utf8mb4_unicode_ci,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `saved_listings`
--

CREATE TABLE `saved_listings` (
  `user_id` int UNSIGNED NOT NULL,
  `listing_id` int UNSIGNED NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `support_tickets`
--

CREATE TABLE IF NOT EXISTS `support_tickets` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int UNSIGNED NOT NULL,
  `subject` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `category` enum('account','listing','payment','trade','bug','other') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'other',
  `status` enum('open','answered','closed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'open',
  `priority` enum('normal','high') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'normal',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `closed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_support_user` (`user_id`),
  KEY `idx_support_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `support_messages`
--

CREATE TABLE IF NOT EXISTS `support_messages` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `ticket_id` int UNSIGNED NOT NULL,
  `sender_type` enum('user','admin') COLLATE utf8mb4_unicode_ci NOT NULL,
  `sender_id` int UNSIGNED NOT NULL,
  `body` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_msg_ticket` (`ticket_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `error_reports`
--

CREATE TABLE IF NOT EXISTS `error_reports` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int UNSIGNED DEFAULT NULL,
  `page_url` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `message` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `steps` text COLLATE utf8mb4_unicode_ci,
  `user_agent` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('new','reviewing','resolved','dismissed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'new',
  `admin_note` text COLLATE utf8mb4_unicode_ci,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `resolved_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_error_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `categories`
--
ALTER TABLE `categories`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_slug` (`slug`),
  ADD KEY `idx_parent` (`parent_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_email` (`email`),
  ADD UNIQUE KEY `uq_phone` (`phone`),
  ADD KEY `idx_city` (`city`),
  ADD KEY `idx_active` (`is_active`),
  ADD KEY `idx_role` (`role`);

--
-- Indexes for table `listings`
--
ALTER TABLE `listings`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user` (`user_id`),
  ADD KEY `idx_category` (`category_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_city` (`city`),
  ADD KEY `idx_featured` (`is_featured`),
  ADD KEY `idx_review_status` (`review_status`),
  ADD FULLTEXT KEY `ft_search` (`title`,`description`);

--
-- Indexes for table `listing_images`
--
ALTER TABLE `listing_images`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_listing` (`listing_id`),
  ADD KEY `idx_primary` (`listing_id`,`is_primary`);

--
-- Indexes for table `trade_offers`
--
ALTER TABLE `trade_offers`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_listing` (`listing_id`),
  ADD KEY `idx_from_user` (`from_user_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `fk_offer_offer_listing` (`offer_listing_id`);

--
-- Indexes for table `trades`
--
ALTER TABLE `trades`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_a` (`user_a_id`),
  ADD KEY `idx_user_b` (`user_b_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `fk_trade_offer` (`offer_id`),
  ADD KEY `fk_trade_list_a` (`listing_a_id`),
  ADD KEY `fk_trade_list_b` (`listing_b_id`);

--
-- Indexes for table `wallet_transactions`
--
ALTER TABLE `wallet_transactions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user` (`user_id`),
  ADD KEY `idx_type` (`type`),
  ADD KEY `idx_created` (`created_at`),
  ADD KEY `idx_wallet_trade` (`trade_id`),
  ADD KEY `idx_wallet_listing` (`listing_id`),
  ADD KEY `idx_wallet_ref` (`ref_type`, `ref_id`);

--
-- Indexes for table `messages`
--
ALTER TABLE `messages`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_thread` (`thread_id`),
  ADD KEY `idx_to_user` (`to_user_id`,`is_read`),
  ADD KEY `idx_from_user` (`from_user_id`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user` (`user_id`,`is_read`),
  ADD KEY `idx_created` (`created_at`);

--
-- Indexes for table `otp_codes`
--
ALTER TABLE `otp_codes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_phone` (`phone`),
  ADD KEY `idx_expires` (`expires_at`);

--
-- Indexes for table `reviews`
--
ALTER TABLE `reviews`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_review` (`trade_id`,`from_user_id`),
  ADD KEY `idx_to_user` (`to_user_id`),
  ADD KEY `fk_review_from` (`from_user_id`);

--
-- Indexes for table `saved_listings`
--
ALTER TABLE `saved_listings`
  ADD PRIMARY KEY (`user_id`,`listing_id`),
  ADD KEY `fk_saved_listing` (`listing_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `categories`
--
ALTER TABLE `categories`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=29;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `listings`
--
ALTER TABLE `listings`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `listing_images`
--
ALTER TABLE `listing_images`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `trade_offers`
--
ALTER TABLE `trade_offers`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `trades`
--
ALTER TABLE `trades`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `wallet_transactions`
--
ALTER TABLE `wallet_transactions`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `messages`
--
ALTER TABLE `messages`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `otp_codes`
--
ALTER TABLE `otp_codes`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `reviews`
--
ALTER TABLE `reviews`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `categories`
--
ALTER TABLE `categories`
  ADD CONSTRAINT `fk_cat_parent` FOREIGN KEY (`parent_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `listings`
--
ALTER TABLE `listings`
  ADD CONSTRAINT `fk_listing_cat` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE RESTRICT,
  ADD CONSTRAINT `fk_listing_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `listing_images`
--
ALTER TABLE `listing_images`
  ADD CONSTRAINT `fk_img_listing` FOREIGN KEY (`listing_id`) REFERENCES `listings` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `trade_offers`
--
ALTER TABLE `trade_offers`
  ADD CONSTRAINT `fk_offer_from` FOREIGN KEY (`from_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_offer_listing` FOREIGN KEY (`listing_id`) REFERENCES `listings` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_offer_offer_listing` FOREIGN KEY (`offer_listing_id`) REFERENCES `listings` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `trades`
--
ALTER TABLE `trades`
  ADD CONSTRAINT `fk_trade_list_a` FOREIGN KEY (`listing_a_id`) REFERENCES `listings` (`id`) ON DELETE RESTRICT,
  ADD CONSTRAINT `fk_trade_list_b` FOREIGN KEY (`listing_b_id`) REFERENCES `listings` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_trade_offer` FOREIGN KEY (`offer_id`) REFERENCES `trade_offers` (`id`) ON DELETE RESTRICT,
  ADD CONSTRAINT `fk_trade_user_a` FOREIGN KEY (`user_a_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  ADD CONSTRAINT `fk_trade_user_b` FOREIGN KEY (`user_b_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT;

--
-- Constraints for table `wallet_transactions`
--
ALTER TABLE `wallet_transactions`
  ADD CONSTRAINT `fk_wallet_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `messages`
--
ALTER TABLE `messages`
  ADD CONSTRAINT `fk_msg_from` FOREIGN KEY (`from_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_msg_to` FOREIGN KEY (`to_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `fk_notif_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `reviews`
--
ALTER TABLE `reviews`
  ADD CONSTRAINT `fk_review_from` FOREIGN KEY (`from_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_review_to` FOREIGN KEY (`to_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_review_trade` FOREIGN KEY (`trade_id`) REFERENCES `trades` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `saved_listings`
--
ALTER TABLE `saved_listings`
  ADD CONSTRAINT `fk_saved_listing` FOREIGN KEY (`listing_id`) REFERENCES `listings` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_saved_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
