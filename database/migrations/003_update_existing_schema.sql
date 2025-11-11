-- ============================================================
-- Migration: Update Existing Tables for Withdrawal System
-- Description: Add missing columns and update existing tables
-- Date: 2025-01-11
-- Note: This updates YOUR existing database structure
-- ============================================================

-- Helper procedure to safely add columns
DELIMITER $$

DROP PROCEDURE IF EXISTS AddColumnIfNotExists$$
CREATE PROCEDURE AddColumnIfNotExists(
    IN tableName VARCHAR(128),
    IN columnName VARCHAR(128),
    IN columnDefinition VARCHAR(512)
)
BEGIN
    DECLARE colExists INT DEFAULT 0;

    SELECT COUNT(*) INTO colExists
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = tableName
    AND COLUMN_NAME = columnName;

    IF colExists = 0 THEN
        SET @sql = CONCAT('ALTER TABLE `', tableName, '` ADD COLUMN `', columnName, '` ', columnDefinition);
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
        SELECT CONCAT('✓ Added column: ', columnName) AS status;
    ELSE
        SELECT CONCAT('⊘ Column already exists: ', columnName) AS status;
    END IF;
END$$

DROP PROCEDURE IF EXISTS AddIndexIfNotExists$$
CREATE PROCEDURE AddIndexIfNotExists(
    IN tableName VARCHAR(128),
    IN indexName VARCHAR(128),
    IN indexColumns VARCHAR(255)
)
BEGIN
    DECLARE indexExists INT DEFAULT 0;

    SELECT COUNT(*) INTO indexExists
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = tableName
    AND INDEX_NAME = indexName;

    IF indexExists = 0 THEN
        SET @sql = CONCAT('CREATE INDEX `', indexName, '` ON `', tableName, '` (', indexColumns, ')');
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
        SELECT CONCAT('✓ Created index: ', indexName) AS status;
    ELSE
        SELECT CONCAT('⊘ Index already exists: ', indexName) AS status;
    END IF;
END$$

DELIMITER ;

-- 1. ADD MISSING COLUMNS TO user_payout_methods
CALL AddColumnIfNotExists('user_payout_methods', 'is_active', 'TINYINT(1) NOT NULL DEFAULT 1 AFTER `is_default`');
CALL AddColumnIfNotExists('user_payout_methods', 'updated_at', 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`');

-- 2. UPDATE user_payout_methods method_type ENUM to include 'manual'
-- Note: This will fail silently if 'manual' already exists
SET @currentEnum = (
    SELECT COLUMN_TYPE
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'user_payout_methods'
    AND COLUMN_NAME = 'method_type'
);

SET @hasManual = IF(@currentEnum LIKE '%manual%', 1, 0);

SET @alterEnumSQL = IF(@hasManual = 0,
    'ALTER TABLE `user_payout_methods` MODIFY COLUMN `method_type` ENUM(''stripe_card'',''paypal'',''binance'',''skrill'',''manual'') NOT NULL COMMENT ''The type of payout service''',
    'SELECT ''⊘ ENUM already includes manual'' AS status'
);

PREPARE stmt FROM @alterEnumSQL;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 3. ADD INDEXES for user_payout_methods
CALL AddIndexIfNotExists('user_payout_methods', 'idx_user_method', '`user_id`, `method_type`');
CALL AddIndexIfNotExists('user_payout_methods', 'idx_user_active', '`user_id`, `is_active`');

-- 4. payout_cards table check
-- NOTE: Your payout_cards table already has all required columns:
-- - card_brand, card_last4, card_holder_name, card_country, is_default, is_active
-- No modifications needed for this table!

-- 5. ADD INDEXES to withdrawal_requests for performance
CALL AddIndexIfNotExists('withdrawal_requests', 'idx_wr_user_status', '`user_id`, `status`');
CALL AddIndexIfNotExists('withdrawal_requests', 'idx_wr_status_created', '`status`, `requested_at`');
CALL AddIndexIfNotExists('withdrawal_requests', 'idx_wr_created_at', '`requested_at`');
CALL AddIndexIfNotExists('withdrawal_requests', 'idx_wr_processed_at', '`processed_at`');
CALL AddIndexIfNotExists('withdrawal_requests', 'idx_wr_user_created', '`user_id`, `requested_at`');

-- 6. ADD INDEXES to transactions table
CALL AddIndexIfNotExists('transactions', 'idx_trans_user_type', '`user_id`, `type`');
CALL AddIndexIfNotExists('transactions', 'idx_trans_gateway', '`gateway_transaction_id`');
CALL AddIndexIfNotExists('transactions', 'idx_trans_created', '`created_at`');

-- 7. ADD INDEXES to wallets table
CALL AddIndexIfNotExists('wallets', 'idx_wallets_user_type', '`user_id`, `type`');

-- Clean up procedures
DROP PROCEDURE IF EXISTS AddColumnIfNotExists;
DROP PROCEDURE IF EXISTS AddIndexIfNotExists;

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

-- ============================================================
-- OPTIMIZE TABLES
-- ============================================================

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
