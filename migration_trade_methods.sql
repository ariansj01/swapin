-- Migration to add selected payment and shipping methods to trades table
ALTER TABLE `trades` 
ADD COLUMN `selected_payment_method` enum('in_person','bnpl','cash') COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Selected payment method for price difference',
ADD COLUMN `selected_shipping_method` enum('courier','post','swapin_secure') COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Selected shipping method for the trade';
