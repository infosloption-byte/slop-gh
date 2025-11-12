-- ============================================================
-- Migration: Update Existing Tables for Withdrawal System
-- Description: Add missing columns and update existing tables
-- Date: 2025-01-11
-- Fixed: Removed DELIMITER for PHP compatibility
-- Note: This updates YOUR existing database structure
-- ============================================================

-- 1. ADD MISSING COLUMNS TO user_payout_methods

-- Add is_active column if not exists
SET @colExists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'user_payout_methods'
    AND COLUMN_NAME = 'is_active'
);
SET @sql = IF(@colExists = 0,
    'ALTER TABLE `user_payout_methods` ADD COLUMN `is_active` TINYINT(1) NOT NULL DEFAULT 1 AFTER `is_default`',
    'SELECT ''⊘ Column is_active already exists'' AS msg'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add updated_at column if not exists
SET @colExists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'user_payout_methods'
    AND COLUMN_NAME = 'updated_at'
);
SET @sql = IF(@colExists = 0,
    'ALTER TABLE `user_payout_methods` ADD COLUMN `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`',
    'SELECT ''⊘ Column updated_at already exists'' AS msg'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 2. UPDATE user_payout_methods method_type ENUM to include 'manual'
SET @currentEnum = (
    SELECT COLUMN_TYPE
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'user_payout_methods'
    AND COLUMN_NAME = 'method_type'
);

SET @hasManual = IF(@currentEnum LIKE '%manual%', 1, 0);

SET @alterEnumSQL = IF(@hasManual = 0,
    'ALTER TABLE `user_payout_methods` MODIFY COLUMN `method_type` ENUM(''stripe_card'',''paypal'',''binance'',''skrill'',''manual'') NOT NULL',
    'SELECT ''⊘ ENUM already includes manual'' AS msg'
);

PREPARE stmt FROM @alterEnumSQL;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 3. ADD INDEXES for user_payout_methods
SET @indexExists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'user_payout_methods'
    AND INDEX_NAME = 'idx_user_method'
);
SET @sql = IF(@indexExists = 0,
    'CREATE INDEX `idx_user_method` ON `user_payout_methods`(`user_id`, `method_type`)',
    'SELECT ''⊘ Index idx_user_method already exists'' AS msg'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @indexExists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'user_payout_methods'
    AND INDEX_NAME = 'idx_user_active'
);
SET @sql = IF(@indexExists = 0,
    'CREATE INDEX `idx_user_active` ON `user_payout_methods`(`user_id`, `is_active`)',
    'SELECT ''⊘ Index idx_user_active already exists'' AS msg'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 4. payout_cards table check
-- NOTE: Your payout_cards table already has all required columns:
-- - card_brand, card_last4, card_holder_name, card_country, is_default, is_active
-- No modifications needed for this table!

-- 5. ADD INDEXES to withdrawal_requests for performance
SET @indexExists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'withdrawal_requests'
    AND INDEX_NAME = 'idx_wr_user_status'
);
SET @sql = IF(@indexExists = 0,
    'CREATE INDEX `idx_wr_user_status` ON `withdrawal_requests`(`user_id`, `status`)',
    'SELECT ''⊘ Index idx_wr_user_status already exists'' AS msg'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @indexExists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'withdrawal_requests'
    AND INDEX_NAME = 'idx_wr_status_created'
);
SET @sql = IF(@indexExists = 0,
    'CREATE INDEX `idx_wr_status_created` ON `withdrawal_requests`(`status`, `requested_at`)',
    'SELECT ''⊘ Index idx_wr_status_created already exists'' AS msg'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @indexExists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'withdrawal_requests'
    AND INDEX_NAME = 'idx_wr_created_at'
);
SET @sql = IF(@indexExists = 0,
    'CREATE INDEX `idx_wr_created_at` ON `withdrawal_requests`(`requested_at`)',
    'SELECT ''⊘ Index idx_wr_created_at already exists'' AS msg'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @indexExists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'withdrawal_requests'
    AND INDEX_NAME = 'idx_wr_processed_at'
);
SET @sql = IF(@indexExists = 0,
    'CREATE INDEX `idx_wr_processed_at` ON `withdrawal_requests`(`processed_at`)',
    'SELECT ''⊘ Index idx_wr_processed_at already exists'' AS msg'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @indexExists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'withdrawal_requests'
    AND INDEX_NAME = 'idx_wr_user_created'
);
SET @sql = IF(@indexExists = 0,
    'CREATE INDEX `idx_wr_user_created` ON `withdrawal_requests`(`user_id`, `requested_at`)',
    'SELECT ''⊘ Index idx_wr_user_created already exists'' AS msg'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 6. ADD INDEXES to transactions table
SET @indexExists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'transactions'
    AND INDEX_NAME = 'idx_trans_user_type'
);
SET @sql = IF(@indexExists = 0,
    'CREATE INDEX `idx_trans_user_type` ON `transactions`(`user_id`, `type`)',
    'SELECT ''⊘ Index idx_trans_user_type already exists'' AS msg'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @indexExists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'transactions'
    AND INDEX_NAME = 'idx_trans_gateway'
);
SET @sql = IF(@indexExists = 0,
    'CREATE INDEX `idx_trans_gateway` ON `transactions`(`gateway_transaction_id`)',
    'SELECT ''⊘ Index idx_trans_gateway already exists'' AS msg'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @indexExists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'transactions'
    AND INDEX_NAME = 'idx_trans_created'
);
SET @sql = IF(@indexExists = 0,
    'CREATE INDEX `idx_trans_created` ON `transactions`(`created_at`)',
    'SELECT ''⊘ Index idx_trans_created already exists'' AS msg'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 7. ADD INDEXES to wallets table
SET @indexExists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'wallets'
    AND INDEX_NAME = 'idx_wallets_user_type'
);
SET @sql = IF(@indexExists = 0,
    'CREATE INDEX `idx_wallets_user_type` ON `wallets`(`user_id`, `type`)',
    'SELECT ''⊘ Index idx_wallets_user_type already exists'' AS msg'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 8. CREATE user_withdrawal_limits table for daily/monthly tracking
CREATE TABLE IF NOT EXISTS `user_withdrawal_limits` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `date` DATE NOT NULL,
    `total_amount` INT NOT NULL DEFAULT 0 COMMENT 'Total amount in cents',
    `withdrawal_count` INT NOT NULL DEFAULT 0,
    `last_updated` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY `unique_user_date` (`user_id`, `date`),
    INDEX `idx_user_date` (`user_id`, `date` DESC),
    INDEX `idx_date` (`date`),

    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. CREATE user_withdrawal_monthly table
CREATE TABLE IF NOT EXISTS `user_withdrawal_monthly` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `year` INT NOT NULL,
    `month` INT NOT NULL,
    `total_amount` INT NOT NULL DEFAULT 0 COMMENT 'Total amount in cents',
    `withdrawal_count` INT NOT NULL DEFAULT 0,
    `last_updated` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY `unique_user_month` (`user_id`, `year`, `month`),
    INDEX `idx_user_year_month` (`user_id`, `year`, `month`),

    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 10. POPULATE initial data for daily limits from existing withdrawals
INSERT INTO `user_withdrawal_limits` (`user_id`, `date`, `total_amount`, `withdrawal_count`)
SELECT
    `user_id`,
    DATE(`requested_at`) as `date`,
    SUM(CASE WHEN `status` IN ('APPROVED', 'PENDING') THEN `amount` ELSE 0 END) as `total_amount`,
    COUNT(*) as `withdrawal_count`
FROM `withdrawal_requests`
GROUP BY `user_id`, DATE(`requested_at`)
ON DUPLICATE KEY UPDATE
    `total_amount` = VALUES(`total_amount`),
    `withdrawal_count` = VALUES(`withdrawal_count`);

-- 11. POPULATE monthly data
INSERT INTO `user_withdrawal_monthly` (`user_id`, `year`, `month`, `total_amount`, `withdrawal_count`)
SELECT
    `user_id`,
    YEAR(`requested_at`) as `year`,
    MONTH(`requested_at`) as `month`,
    SUM(CASE WHEN `status` IN ('APPROVED', 'PENDING') THEN `amount` ELSE 0 END) as `total_amount`,
    COUNT(*) as `withdrawal_count`
FROM `withdrawal_requests`
GROUP BY `user_id`, YEAR(`requested_at`), MONTH(`requested_at`)
ON DUPLICATE KEY UPDATE
    `total_amount` = VALUES(`total_amount`),
    `withdrawal_count` = VALUES(`withdrawal_count`);

-- 12. OPTIMIZE TABLES
OPTIMIZE TABLE `withdrawal_requests`;
OPTIMIZE TABLE `transactions`;
OPTIMIZE TABLE `user_payout_methods`;
OPTIMIZE TABLE `wallets`;

-- ============================================================
-- VERIFICATION QUERIES
-- ============================================================

-- Check user_payout_methods structure
DESCRIBE `user_payout_methods`;

-- Check new indexes
SHOW INDEX FROM `withdrawal_requests` WHERE Key_name LIKE 'idx_wr%';

-- Check new tables
SHOW TABLES LIKE 'user_withdrawal%';

-- Check data counts
SELECT
    'user_payout_methods' as table_name, COUNT(*) as row_count FROM `user_payout_methods`
UNION ALL
SELECT 'user_withdrawal_limits' as table_name, COUNT(*) as row_count FROM `user_withdrawal_limits`
UNION ALL
SELECT 'user_withdrawal_monthly' as table_name, COUNT(*) as row_count FROM `user_withdrawal_monthly`;

-- Verify columns were added
SELECT
    COLUMN_NAME,
    COLUMN_TYPE,
    IS_NULLABLE,
    COLUMN_DEFAULT
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
AND TABLE_NAME = 'user_payout_methods'
AND COLUMN_NAME IN ('is_active', 'updated_at', 'method_type')
ORDER BY ORDINAL_POSITION;

SELECT '✅ Migration 003 completed successfully!' as status;
