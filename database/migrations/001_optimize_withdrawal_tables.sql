-- ============================================================
-- Migration: Optimize Withdrawal Tables
-- Description: Add indexes and optimize existing tables
-- Date: 2025-01-10
-- ============================================================

-- MySQL doesn't support CREATE INDEX IF NOT EXISTS directly
-- We'll use a procedure to safely add indexes

DELIMITER $$

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
        SET @sql = CONCAT('CREATE INDEX ', indexName, ' ON ', tableName, ' (', indexColumns, ')');
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
        SELECT CONCAT('✓ Created index: ', indexName) AS status;
    ELSE
        SELECT CONCAT('⊘ Index already exists: ', indexName) AS status;
    END IF;
END$$

DELIMITER ;

-- Add indexes to withdrawal_requests table for better performance
CALL AddIndexIfNotExists('withdrawal_requests', 'idx_wr_user_status', 'user_id, status');
CALL AddIndexIfNotExists('withdrawal_requests', 'idx_wr_status_created', 'status, requested_at');
CALL AddIndexIfNotExists('withdrawal_requests', 'idx_wr_created_at', 'requested_at');
CALL AddIndexIfNotExists('withdrawal_requests', 'idx_wr_processed_at', 'processed_at');
CALL AddIndexIfNotExists('withdrawal_requests', 'idx_wr_user_created', 'user_id, requested_at');

-- Add indexes to transactions table
CALL AddIndexIfNotExists('transactions', 'idx_trans_user_type', 'user_id, type');
CALL AddIndexIfNotExists('transactions', 'idx_trans_reference', 'type, reference_id');
CALL AddIndexIfNotExists('transactions', 'idx_trans_gateway', 'gateway_transaction_id');
CALL AddIndexIfNotExists('transactions', 'idx_trans_created', 'created_at');

-- Add indexes to user_payout_methods table (if table exists)
CALL AddIndexIfNotExists('user_payout_methods', 'idx_upm_user_method', 'user_id, method_type');
CALL AddIndexIfNotExists('user_payout_methods', 'idx_upm_user_active', 'user_id, is_active');

-- Add indexes to wallets table (if not already exist)
CALL AddIndexIfNotExists('wallets', 'idx_wallets_user_type', 'user_id, type');

-- Add composite index for daily limit checks
CALL AddIndexIfNotExists('withdrawal_requests', 'idx_wr_user_date_status', 'user_id, requested_at, status');

-- Add index for admin dashboard queries
CALL AddIndexIfNotExists('withdrawal_requests', 'idx_wr_status_processed_by', 'status, processed_by');

-- Clean up procedure
DROP PROCEDURE IF EXISTS AddIndexIfNotExists;

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
