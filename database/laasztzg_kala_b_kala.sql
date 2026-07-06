-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Generation Time: Jun 21, 2026 at 12:10 AM
-- Server version: 8.0.44-cll-lve
-- PHP Version: 8.4.21

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `laasztzg_kala_b_kala`
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
  `city` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('active','traded','expired','deleted') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
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
  `completed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
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
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int UNSIGNED NOT NULL,
  `name` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(180) COLLATE utf8mb4_unicode_ci NOT NULL,
  `phone` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `city` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `avatar` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `bio` text COLLATE utf8mb4_unicode_ci,
  `password_hash` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `credit_balance` decimal(12,2) NOT NULL DEFAULT '0.00',
  `rating` decimal(3,2) NOT NULL DEFAULT '0.00',
  `rating_count` int UNSIGNED NOT NULL DEFAULT '0',
  `verification_level` tinyint UNSIGNED NOT NULL DEFAULT '1' COMMENT '1=email,2=phone,3=id_verified',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `last_seen` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `name`, `email`, `phone`, `city`, `avatar`, `bio`, `password_hash`, `credit_balance`, `rating`, `rating_count`, `verification_level`, `is_active`, `last_seen`, `created_at`, `updated_at`) VALUES
(1, 'Admin', 'admin@kalabkala.com', '+989000000000', 'Tehran', NULL, NULL, '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uFutB/ZEm', 500.00, 0.00, 0, 3, 1, NULL, '2026-06-14 21:08:55', '2026-06-14 21:08:55'),
(2, 'Sara Ahmadi', 'sara@example.com', '+989111111111', 'Tehran', NULL, NULL, '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uFutB/ZEm', 150.00, 0.00, 0, 2, 1, NULL, '2026-06-14 21:08:55', '2026-06-14 21:08:55'),
(3, 'Ali Rezaei', 'ali@example.com', '+989222222222', 'Isfahan', NULL, NULL, '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uFutB/ZEm', 200.00, 0.00, 0, 2, 1, NULL, '2026-06-14 21:08:55', '2026-06-14 21:08:55'),
(4, 'آرین سیدی', 'ariansj.ir@gmail.com', '09150583289', 'سبزوار', NULL, NULL, '$2y$10$xYZHLiUwpbWPeeUwJ4L2Auvux7P315mbvXsQkYADKmbLq5MJp4GxO', 50.00, 0.00, 0, 1, 1, '2026-06-15 01:07:53', '2026-06-15 01:07:53', '2026-06-15 01:07:53');

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
-- Indexes for table `listings`
--
ALTER TABLE `listings`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user` (`user_id`),
  ADD KEY `idx_category` (`category_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_city` (`city`),
  ADD KEY `idx_featured` (`is_featured`);
ALTER TABLE `listings` ADD FULLTEXT KEY `ft_search` (`title`,`description`);

--
-- Indexes for table `listing_images`
--
ALTER TABLE `listing_images`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_listing` (`listing_id`),
  ADD KEY `idx_primary` (`listing_id`,`is_primary`);

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
-- Indexes for table `trade_offers`
--
ALTER TABLE `trade_offers`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_listing` (`listing_id`),
  ADD KEY `idx_from_user` (`from_user_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `fk_offer_offer_listing` (`offer_listing_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_email` (`email`),
  ADD UNIQUE KEY `uq_phone` (`phone`),
  ADD KEY `idx_city` (`city`),
  ADD KEY `idx_active` (`is_active`);

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
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `categories`
--
ALTER TABLE `categories`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=29;

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
-- AUTO_INCREMENT for table `trades`
--
ALTER TABLE `trades`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `trade_offers`
--
ALTER TABLE `trade_offers`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `wallet_transactions`
--
ALTER TABLE `wallet_transactions`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

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
-- Constraints for table `trade_offers`
--
ALTER TABLE `trade_offers`
  ADD CONSTRAINT `fk_offer_from` FOREIGN KEY (`from_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_offer_listing` FOREIGN KEY (`listing_id`) REFERENCES `listings` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_offer_offer_listing` FOREIGN KEY (`offer_listing_id`) REFERENCES `listings` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `wallet_transactions`
--
ALTER TABLE `wallet_transactions`
  ADD CONSTRAINT `fk_wallet_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
