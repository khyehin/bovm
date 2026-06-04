ALTER TABLE `customer_categories`
  ADD COLUMN IF NOT EXISTS `currency` varchar(10) NOT NULL DEFAULT 'MYR' AFTER `name`;

ALTER TABLE `customers`
  ADD COLUMN IF NOT EXISTS `currency` varchar(10) DEFAULT NULL AFTER `category_id`;

UPDATE `customer_categories`
   SET `currency` = 'MYR'
 WHERE `currency` IS NULL OR TRIM(`currency`) = '';

UPDATE `customers` c
LEFT JOIN `customer_categories` cc ON cc.id = c.category_id
   SET c.currency = COALESCE(NULLIF(TRIM(c.currency), ''), NULLIF(TRIM(cc.currency), ''), 'MYR')
 WHERE c.currency IS NULL OR TRIM(c.currency) = '';
