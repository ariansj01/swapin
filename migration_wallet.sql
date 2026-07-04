-- Wallet transactions: explicit refs + currency (run via run_wallet_migration.php)

ALTER TABLE `wallet_transactions`
  ADD COLUMN `ref_type` ENUM(
    'none',
    'trade',
    'trade_offer',
    'listing',
    'subscription_order',
    'listing_bump',
    'inspection_request',
    'external'
  ) NOT NULL DEFAULT 'none' COMMENT 'Entity type that ref_id points to' AFTER `type`;

ALTER TABLE `wallet_transactions`
  ADD COLUMN `trade_id` INT UNSIGNED NULL COMMENT 'FK trades.id when trade-related' AFTER `ref_id`;

ALTER TABLE `wallet_transactions`
  ADD COLUMN `listing_id` INT UNSIGNED NULL COMMENT 'FK listings.id when listing-related' AFTER `trade_id`;

ALTER TABLE `wallet_transactions`
  ADD COLUMN `currency_code` CHAR(3) NOT NULL DEFAULT 'IRR' COMMENT 'ISO 4217 (IRR = ledger in Toman)' AFTER `balance_after`;

ALTER TABLE `wallet_transactions`
  ADD COLUMN `currency` VARCHAR(20) NOT NULL DEFAULT 'تومان' COMMENT 'Display unit label' AFTER `currency_code`;

ALTER TABLE `wallet_transactions`
  ADD KEY `idx_wallet_trade` (`trade_id`),
  ADD KEY `idx_wallet_listing` (`listing_id`),
  ADD KEY `idx_wallet_ref` (`ref_type`, `ref_id`);

-- Backfill: old ref_id was always trades.id for trade_* types
UPDATE `wallet_transactions`
SET `ref_type` = 'trade', `trade_id` = `ref_id`
WHERE `ref_id` IS NOT NULL
  AND `type` IN ('trade_credit', 'trade_debit');

-- Link listing_id for trade transactions
UPDATE `wallet_transactions` wt
JOIN `trades` t ON t.id = wt.trade_id
SET wt.listing_id = CASE
  WHEN wt.user_id = t.user_a_id THEN t.listing_a_id
  WHEN wt.user_id = t.user_b_id THEN t.listing_b_id
  ELSE NULL
END
WHERE wt.trade_id IS NOT NULL;

-- Fee rows that mention listing #N in note
UPDATE `wallet_transactions`
SET `ref_type` = 'listing',
    `listing_id` = CAST(TRIM(SUBSTRING_INDEX(`note`, '#', -1)) AS UNSIGNED),
    `ref_id` = CAST(TRIM(SUBSTRING_INDEX(`note`, '#', -1)) AS UNSIGNED)
WHERE `type` = 'fee'
  AND `note` LIKE '%listing #%'
  AND `listing_id` IS NULL;

UPDATE `wallet_transactions`
SET `ref_type` = 'listing',
    `listing_id` = CAST(TRIM(SUBSTRING_INDEX(`note`, '#', -1)) AS UNSIGNED),
    `ref_id` = CAST(TRIM(SUBSTRING_INDEX(`note`, '#', -1)) AS UNSIGNED)
WHERE `type` = 'fee'
  AND `note` LIKE '%آگهی #%'
  AND `listing_id` IS NULL;

UPDATE `wallet_transactions`
SET `currency_code` = 'IRR', `currency` = 'تومان'
WHERE `currency_code` = 'IRR';
