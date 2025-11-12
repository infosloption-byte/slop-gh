-- ============================================================
-- Migration: Optimize Withdrawal Tables
-- Description: Add indexes and optimize existing tables
-- Date: 2025-01-10
-- Fixed: Removed DELIMITER for PHP compatibility
-- ============================================================

-- Helper function to add index if not exists
-- Using prepared statements instead of stored procedures

-- Add indexes to withdrawal_requests table for better performance
SET @indexExists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'withdrawal_requests'
    AND INDEX_NAME = 'idx_wr_user_status'
);
SET @sql = IF(@indexExists = 0,
    'CREATE INDEX idx_wr_user_status ON withdrawal_requests(user_id, status)',
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
    'CREATE INDEX idx_wr_status_created ON withdrawal_requests(status, requested_at)',
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
    'CREATE INDEX idx_wr_created_at ON withdrawal_requests(requested_at)',
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
    'CREATE INDEX idx_wr_processed_at ON withdrawal_requests(processed_at)',
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
    'CREATE INDEX idx_wr_user_created ON withdrawal_requests(user_id, requested_at)',
    'SELECT ''⊘ Index idx_wr_user_created already exists'' AS msg'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add indexes to transactions table
SET @indexExists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'transactions'
    AND INDEX_NAME = 'idx_trans_user_type'
);
SET @sql = IF(@indexExists = 0,
    'CREATE INDEX idx_trans_user_type ON transactions(user_id, type)',
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
    AND INDEX_NAME = 'idx_trans_reference'
);
SET @sql = IF(@indexExists = 0,
    'CREATE INDEX idx_trans_reference ON transactions(type, reference_id)',
    'SELECT ''⊘ Index idx_trans_reference already exists'' AS msg'
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
    'CREATE INDEX idx_trans_gateway ON transactions(gateway_transaction_id)',
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
    'CREATE INDEX idx_trans_created ON transactions(created_at)',
    'SELECT ''⊘ Index idx_trans_created already exists'' AS msg'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add indexes to user_payout_methods table (if table exists)
SET @indexExists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'user_payout_methods'
    AND INDEX_NAME = 'idx_upm_user_method'
);
SET @sql = IF(@indexExists = 0,
    'CREATE INDEX idx_upm_user_method ON user_payout_methods(user_id, method_type)',
    'SELECT ''⊘ Index idx_upm_user_method already exists'' AS msg'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @indexExists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'user_payout_methods'
    AND INDEX_NAME = 'idx_upm_user_active'
);
SET @sql = IF(@indexExists = 0,
    'CREATE INDEX idx_upm_user_active ON user_payout_methods(user_id, is_active)',
    'SELECT ''⊘ Index idx_upm_user_active already exists'' AS msg'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add indexes to wallets table (if not already exist)
SET @indexExists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'wallets'
    AND INDEX_NAME = 'idx_wallets_user_type'
);
SET @sql = IF(@indexExists = 0,
    'CREATE INDEX idx_wallets_user_type ON wallets(user_id, type)',
    'SELECT ''⊘ Index idx_wallets_user_type already exists'' AS msg'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add composite index for daily limit checks
SET @indexExists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'withdrawal_requests'
    AND INDEX_NAME = 'idx_wr_user_date_status'
);
SET @sql = IF(@indexExists = 0,
    'CREATE INDEX idx_wr_user_date_status ON withdrawal_requests(user_id, requested_at, status)',
    'SELECT ''⊘ Index idx_wr_user_date_status already exists'' AS msg'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add index for admin dashboard queries
SET @indexExists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'withdrawal_requests'
    AND INDEX_NAME = 'idx_wr_status_processed_by'
);
SET @sql = IF(@indexExists = 0,
    'CREATE INDEX idx_wr_status_processed_by ON withdrawal_requests(status, processed_by)',
    'SELECT ''⊘ Index idx_wr_status_processed_by already exists'' AS msg'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Optimize table storage
OPTIMIZE TABLE withdrawal_requests;
OPTIMIZE TABLE transactions;
OPTIMIZE TABLE wallets;

-- ============================================================
-- Verification Queries
-- ============================================================

-- Check indexes on withdrawal_requests
SELECT
    INDEX_NAME,
    COLUMN_NAME,
    SEQ_IN_INDEX,
    INDEX_TYPE
FROM INFORMATION_SCHEMA.STATISTICS
WHERE TABLE_SCHEMA = DATABASE()
AND TABLE_NAME = 'withdrawal_requests'
ORDER BY INDEX_NAME, SEQ_IN_INDEX;

-- Check table sizes and row counts
SELECT
    TABLE_NAME,
    TABLE_ROWS,
    ROUND((DATA_LENGTH + INDEX_LENGTH) / 1024 / 1024, 2) AS 'Size (MB)',
    ROUND(DATA_LENGTH / 1024 / 1024, 2) AS 'Data (MB)',
    ROUND(INDEX_LENGTH / 1024 / 1024, 2) AS 'Index (MB)'
FROM INFORMATION_SCHEMA.TABLES
WHERE TABLE_SCHEMA = DATABASE()
AND TABLE_NAME IN ('withdrawal_requests', 'transactions', 'user_payout_methods', 'wallets')
ORDER BY (DATA_LENGTH + INDEX_LENGTH) DESC;
