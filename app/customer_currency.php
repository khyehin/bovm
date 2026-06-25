<?php
// app/customer_currency.php
declare(strict_types=1);

if (!function_exists('app_table_has_column')) {
    function app_table_has_column(PDO $pdo, string $table, string $column): bool
    {
        try {
            $st = $pdo->prepare("
                SELECT COUNT(*)
                  FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = :table
                   AND COLUMN_NAME = :column
            ");
            $st->execute([':table' => $table, ':column' => $column]);
            return (int)$st->fetchColumn() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('app_ensure_customer_currency_schema')) {
    function app_ensure_customer_currency_schema(PDO $pdo): void
    {
        try {
            if (!app_table_has_column($pdo, 'customer_categories', 'currency')) {
                $pdo->exec("ALTER TABLE `customer_categories` ADD COLUMN `currency` varchar(10) NOT NULL DEFAULT 'MYR' AFTER `name`");
            }

            if (!app_table_has_column($pdo, 'customers', 'currency')) {
                $pdo->exec("ALTER TABLE `customers` ADD COLUMN `currency` varchar(10) DEFAULT NULL AFTER `category_id`");
            }

            if (!app_table_has_column($pdo, 'customer_txn', 'pay_source_method')) {
                $pdo->exec("ALTER TABLE `customer_txn` ADD COLUMN `pay_source_method` varchar(20) DEFAULT NULL AFTER `pay_source_customer_id`");
            }

            if (!app_table_has_column($pdo, 'customer_txn', 'pay_source_bank_account_id')) {
                $pdo->exec("ALTER TABLE `customer_txn` ADD COLUMN `pay_source_bank_account_id` int(10) unsigned DEFAULT NULL AFTER `pay_source_method`");
            }

            $pdo->exec("
                UPDATE `customer_categories`
                   SET `currency` = 'MYR'
                 WHERE `currency` IS NULL OR TRIM(`currency`) = ''
            ");

            $pdo->exec("
                UPDATE `customers` c
                LEFT JOIN `customer_categories` cc ON cc.id = c.category_id
                   SET c.currency = COALESCE(NULLIF(TRIM(c.currency), ''), NULLIF(TRIM(cc.currency), ''), 'MYR')
                 WHERE c.currency IS NULL OR TRIM(c.currency) = ''
            ");
        } catch (Throwable $e) {
            // Pages remain schema-safe and will fall back to MYR if the DB user cannot ALTER.
        }
    }
}

if (!function_exists('app_customer_currency')) {
    function app_customer_currency(PDO $pdo, array $customer, string $fallback = 'MYR'): string
    {
        $cur = strtoupper(trim((string)($customer['currency'] ?? '')));
        if ($cur !== '') return $cur;

        $catId = (int)($customer['category_id'] ?? 0);
        if ($catId > 0 && app_table_has_column($pdo, 'customer_categories', 'currency')) {
            try {
                $st = $pdo->prepare("SELECT currency FROM customer_categories WHERE id = :id LIMIT 1");
                $st->execute([':id' => $catId]);
                $cur = strtoupper(trim((string)($st->fetchColumn() ?: '')));
                if ($cur !== '') return $cur;
            } catch (Throwable $e) {
            }
        }

        return strtoupper(trim($fallback ?: 'MYR')) ?: 'MYR';
    }
}
