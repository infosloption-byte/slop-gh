-- ============================================================
-- Migration: Optimize Withdrawal Tables
-- Description: Add indexes and optimize existing tables
-- Date: 2025-01-10
-- ============================================================

-- Add indexes to withdrawal_requests table for better performance
CREATE INDEX IF NOT EXISTS idx_wr_user_status ON withdrawal_requests(user_id, status);
CREATE INDEX IF NOT EXISTS idx_wr_status_created ON withdrawal_requests(status, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_wr_created_at ON withdrawal_requests(created_at DESC);
CREATE INDEX IF NOT EXISTS idx_wr_processed_at ON withdrawal_requests(processed_at DESC);
CREATE INDEX IF NOT EXISTS idx_wr_user_created ON withdrawal_requests(user_id, created_at DESC);

-- Add indexes to transactions table
CREATE INDEX IF NOT EXISTS idx_trans_user_type ON transactions(user_id, type);
CREATE INDEX IF NOT EXISTS idx_trans_reference ON transactions(type, reference_id);
CREATE INDEX IF NOT EXISTS idx_trans_gateway ON transactions(gateway_transaction_id);
CREATE INDEX IF NOT EXISTS idx_trans_created ON transactions(created_at DESC);

-- Add indexes to user_payout_methods table
CREATE INDEX IF NOT EXISTS idx_upm_user_method ON user_payout_methods(user_id, method_type);
CREATE INDEX IF NOT EXISTS idx_upm_user_active ON user_payout_methods(user_id, is_active);

-- Add indexes to wallets table (if not already exist)
CREATE INDEX IF NOT EXISTS idx_wallets_user_type ON wallets(user_id, type);

-- Add composite index for daily limit checks
CREATE INDEX IF NOT EXISTS idx_wr_user_date_status ON withdrawal_requests(user_id, DATE(created_at), status);

-- Add index for admin dashboard queries
CREATE INDEX IF NOT EXISTS idx_wr_status_processed_by ON withdrawal_requests(status, processed_by);

-- Optimize table storage
OPTIMIZE TABLE withdrawal_requests;
OPTIMIZE TABLE transactions;
OPTIMIZE TABLE user_payout_methods;
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
