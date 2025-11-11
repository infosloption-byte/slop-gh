-- ============================================================
-- Migration: Create Payout Methods Tables
-- Description: Create tables for user payout methods and cards
-- Date: 2025-01-10
-- ============================================================

-- Create user_payout_methods table if not exists
-- NOTE: This table likely already exists in your database
-- This is a safe CREATE IF NOT EXISTS that won't overwrite existing data
CREATE TABLE IF NOT EXISTS user_payout_methods (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    method_type ENUM('stripe_card', 'paypal', 'binance', 'skrill', 'manual') NOT NULL,
    display_name VARCHAR(255) NOT NULL,
    account_identifier VARCHAR(255) NOT NULL COMMENT 'Email, ID, etc',
    payout_card_id INT NULL COMMENT 'For stripe_card type',
    is_default TINYINT(1) DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_upm_user_method (user_id, method_type),
    INDEX idx_upm_user_active (user_id, is_active),

    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create payout_cards table if not exists (for Stripe)
-- NOTE: This table likely already exists in your database
-- This is a safe CREATE IF NOT EXISTS that won't overwrite existing data
CREATE TABLE IF NOT EXISTS payout_cards (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    stripe_card_id VARCHAR(255) NULL,
    stripe_connect_account_id VARCHAR(255) NULL,
    external_account_id VARCHAR(255) NULL,
    card_brand VARCHAR(50) NULL,
    card_last4 VARCHAR(4) NULL,
    card_holder_name VARCHAR(255) NULL,
    card_country VARCHAR(2) DEFAULT 'US',
    is_default TINYINT(1) DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_payout_cards_user (user_id),
    INDEX idx_payout_cards_default (user_id, is_default),

    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add foreign key for payout_card_id if not exists
SET @query = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
     WHERE CONSTRAINT_SCHEMA = DATABASE()
     AND TABLE_NAME = 'user_payout_methods'
     AND CONSTRAINT_NAME = 'fk_upm_payout_card') = 0,
    'ALTER TABLE user_payout_methods ADD CONSTRAINT fk_upm_payout_card
     FOREIGN KEY (payout_card_id) REFERENCES payout_cards(id) ON DELETE SET NULL',
    'SELECT "Foreign key already exists" AS message'
);

PREPARE stmt FROM @query;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================================
-- Verification Queries
-- ============================================================

-- Check if tables exist
SELECT
    TABLE_NAME,
    TABLE_ROWS,
    CREATE_TIME
FROM INFORMATION_SCHEMA.TABLES
WHERE TABLE_SCHEMA = DATABASE()
AND TABLE_NAME IN ('user_payout_methods', 'payout_cards')
ORDER BY TABLE_NAME;

-- Check indexes
SELECT
    TABLE_NAME,
    INDEX_NAME,
    COLUMN_NAME,
    SEQ_IN_INDEX
FROM INFORMATION_SCHEMA.STATISTICS
WHERE TABLE_SCHEMA = DATABASE()
AND TABLE_NAME IN ('user_payout_methods', 'payout_cards')
ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX;

-- Sample data
SELECT 'user_payout_methods' as table_name, COUNT(*) as row_count FROM user_payout_methods
UNION ALL
SELECT 'payout_cards' as table_name, COUNT(*) as row_count FROM payout_cards;
