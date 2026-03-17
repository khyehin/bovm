-- Quotation line items and doc-level require-sign flags.
-- Run once. If you get "Duplicate column" on ALTER, skip that line or run ALTERs one by one.

-- Line items for quotation/invoice (description, qty, unit price, amount)
CREATE TABLE IF NOT EXISTS `customer_txn_lines` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `customer_txn_id` int(11) NOT NULL,
  `line_seq` int(11) NOT NULL DEFAULT 1,
  `description` varchar(500) DEFAULT NULL,
  `quantity` decimal(18,4) NOT NULL DEFAULT 1.0000,
  `unit_price` decimal(18,2) NOT NULL DEFAULT 0.00,
  `amount` decimal(18,2) NOT NULL DEFAULT 0.00,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_customer_txn_lines_txn` (`customer_txn_id`),
  CONSTRAINT `fk_ctxl_txn` FOREIGN KEY (`customer_txn_id`) REFERENCES `customer_txn` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Optional columns on customer_txn. Run each line once; skip if column already exists.
-- discount: total discount amount
ALTER TABLE `customer_txn` ADD COLUMN `discount` decimal(18,2) NOT NULL DEFAULT 0.00;
ALTER TABLE `customer_txn` ADD COLUMN `deliver_to` varchar(500) DEFAULT NULL;
ALTER TABLE `customer_txn` ADD COLUMN `terms` text DEFAULT NULL;
ALTER TABLE `customer_txn` ADD COLUMN `do_number` varchar(100) DEFAULT NULL;
ALTER TABLE `customer_txn` ADD COLUMN `require_sign_quotation` tinyint(1) NOT NULL DEFAULT 0;
ALTER TABLE `customer_txn` ADD COLUMN `require_sign_invoice` tinyint(1) NOT NULL DEFAULT 0;
ALTER TABLE `customer_txn` ADD COLUMN `require_sign_do` tinyint(1) NOT NULL DEFAULT 0;

-- Optional per-document signature images (run only if columns do not exist)
ALTER TABLE `customer_txn` ADD COLUMN `quotation_customer_signature_image` longtext NULL;
ALTER TABLE `customer_txn` ADD COLUMN `quotation_company_signature_image` longtext NULL;
ALTER TABLE `customer_txn` ADD COLUMN `invoice_customer_signature_image` longtext NULL;
ALTER TABLE `customer_txn` ADD COLUMN `invoice_company_signature_image` longtext NULL;
ALTER TABLE `customer_txn` ADD COLUMN `do_customer_signature_image` longtext NULL;
ALTER TABLE `customer_txn` ADD COLUMN `do_company_signature_image` longtext NULL;
