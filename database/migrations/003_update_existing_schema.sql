-- ============================================================
-- Migration: Update Existing Tables for Withdrawal System
-- Description: Add missing columns and update existing tables
-- Date: 2025-01-11
-- Note: This updates YOUR existing database structure
-- ============================================================

-- 1. ADD MISSING COLUMNS TO user_payout_methods
ALTER TABLE `user_payout_methods`
ADD COLUMN IF NOT EXISTS `is_active` TINYINT(1) NOT NULL DEFAULT 1 AFTER `is_default`,
ADD COLUMN IF NOT EXISTS `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`;

-- 2. UPDATE user_payout_methods method_type ENUM to include 'manual'
ALTER TABLE `user_payout_methods`
MODIFY COLUMN `method_type` ENUM('stripe_card','paypal','binance','skrill','manual') NOT NULL COMMENT 'The type of payout service';

-- 3. Keep created_at as DATETIME (matching your existing schema)
-- NOTE: Your database uses DATETIME for created_at, which is fine. We'll keep it consistent.

-- 4. ADD INDEX for user_payout_methods
ALTER TABLE `user_payout_methods`
ADD INDEX IF NOT EXISTS `idx_user_method` (`user_id`, `method_type`),
ADD INDEX IF NOT EXISTS `idx_user_active` (`user_id`, `is_active`);

-- 5. payout_cards table check
-- NOTE: Your payout_cards table already has all required columns:
-- - card_brand, card_last4, card_holder_name, card_country, is_default, is_active
-- No modifications needed for this table!

-- 6. ADD INDEXES to withdrawal_requests for performance (if not exist)
CREATE INDEX IF NOT EXISTS `idx_wr_user_status` ON `withdrawal_requests`(`user_id`, `status`);
CREATE INDEX IF NOT EXISTS `idx_wr_status_created` ON `withdrawal_requests`(`status`, `requested_at` DESC);
CREATE INDEX IF NOT EXISTS `idx_wr_created_at` ON `withdrawal_requests`(`requested_at` DESC);
CREATE INDEX IF NOT EXISTS `idx_wr_processed_at` ON `withdrawal_requests`(`processed_at` DESC);
CREATE INDEX IF NOT EXISTS `idx_wr_user_created` ON `withdrawal_requests`(`user_id`, `requested_at` DESC);

-- 7. ADD INDEXES to transactions table
CREATE INDEX IF NOT EXISTS `idx_trans_user_type` ON `transactions`(`user_id`, `type`);
CREATE INDEX IF NOT EXISTS `idx_trans_gateway` ON `transactions`(`gateway_transaction_id`);
CREATE INDEX IF NOT EXISTS `idx_trans_created` ON `transactions`(`created_at` DESC);

-- 8. ADD INDEXES to wallets table (if not exist)
CREATE INDEX IF NOT EXISTS `idx_wallets_user_type` ON `wallets`(`user_id`, `type`);

-- 9. CREATE user_withdrawal_limits table for daily/monthly tracking
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

-- 10. CREATE user_withdrawal_monthly table
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

-- 11. POPULATE initial data for daily limits from existing withdrawals
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

-- 12. POPULATE monthly data
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
AND COLUMN_NAME IN ('is_active', 'updated_at')
ORDER BY ORDINAL_POSITION;

SELECT '✅ Migration completed successfully!' as status;
