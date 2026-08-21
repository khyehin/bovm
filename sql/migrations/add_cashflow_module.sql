-- Cashflow module. This migration creates structure only; it imports no data.
CREATE TABLE IF NOT EXISTS cashflow_entries (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id INT UNSIGNED NULL COMMENT 'NULL = Master Cashflow',
  user_id INT NULL,
  display_order INT NULL,
  entry_date DATE NOT NULL,
  deposit DECIMAL(18,2) NULL,
  withdrawal DECIMAL(18,2) NULL,
  affin DECIMAL(18,2) NOT NULL DEFAULT 0,
  total DECIMAL(18,2) NOT NULL DEFAULT 0,
  xe_usdt DECIMAL(18,2) NOT NULL DEFAULT 0,
  remark TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_cashflow_company_date (company_id, entry_date),
  KEY idx_cashflow_display_order (company_id, display_order),
  CONSTRAINT fk_cashflow_company FOREIGN KEY (company_id) REFERENCES payer_companies(id) ON DELETE CASCADE,
  CONSTRAINT fk_cashflow_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS cashflow_extra_columns (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(64) NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS cashflow_entry_extra_values (
  cashflow_entry_id BIGINT UNSIGNED NOT NULL,
  cashflow_extra_column_id BIGINT UNSIGNED NOT NULL,
  value DECIMAL(18,2) NOT NULL DEFAULT 0,
  PRIMARY KEY (cashflow_entry_id, cashflow_extra_column_id),
  CONSTRAINT fk_cf_extra_entry FOREIGN KEY (cashflow_entry_id) REFERENCES cashflow_entries(id) ON DELETE CASCADE,
  CONSTRAINT fk_cf_extra_column FOREIGN KEY (cashflow_extra_column_id) REFERENCES cashflow_extra_columns(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS cashflow_column_orders (
  company_id INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0 = Master Cashflow',
  column_order TEXT NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS cashflow_column_labels (
  company_id INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0 = Master Cashflow',
  column_key VARCHAR(64) NOT NULL,
  label VARCHAR(64) NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (company_id, column_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Existing installations already have an Admin role. Grant the new module to
-- active roles during installation so current administrators are not locked out.
INSERT IGNORE INTO role_permissions (role_id, perm_code)
SELECT r.id, p.perm_code
  FROM roles r
 CROSS JOIN (
    SELECT 'CASHFLOW.V' perm_code
    UNION ALL SELECT 'CASHFLOW.E'
    UNION ALL SELECT 'CASHFLOW.D'
    UNION ALL SELECT 'CASHFLOW.EXPORT'
 ) p
 WHERE r.is_active = 1;
