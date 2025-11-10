-- ============================================================
-- Migration: Add Withdrawal Limits Tracking
-- Description: Add daily/monthly tracking for withdrawal limits
-- Date: 2025-01-10
-- ============================================================

-- Create table for tracking daily withdrawal totals (for quick lookups)
CREATE TABLE IF NOT EXISTS user_withdrawal_limits (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    date DATE NOT NULL,
    total_amount INT NOT NULL DEFAULT 0 COMMENT 'Total amount in cents',
    withdrawal_count INT NOT NULL DEFAULT 0,
    last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY unique_user_date (user_id, date),
    KEY idx_user_date (user_id, date DESC),
    KEY idx_date (date),

    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create table for monthly withdrawal tracking
CREATE TABLE IF NOT EXISTS user_withdrawal_monthly (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    year INT NOT NULL,
    month INT NOT NULL,
    total_amount INT NOT NULL DEFAULT 0 COMMENT 'Total amount in cents',
    withdrawal_count INT NOT NULL DEFAULT 0,
    last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY unique_user_month (user_id, year, month),
    KEY idx_user_year_month (user_id, year, month),

    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Populate initial data from withdrawal_requests
INSERT INTO user_withdrawal_limits (user_id, date, total_amount, withdrawal_count)
SELECT
    user_id,
    DATE(created_at) as date,
    SUM(CASE WHEN status IN ('APPROVED', 'PENDING') THEN amount ELSE 0 END) as total_amount,
    COUNT(*) as withdrawal_count
FROM withdrawal_requests
GROUP BY user_id, DATE(created_at)
ON DUPLICATE KEY UPDATE
    total_amount = VALUES(total_amount),
    withdrawal_count = VALUES(withdrawal_count);

-- Populate monthly data
INSERT INTO user_withdrawal_monthly (user_id, year, month, total_amount, withdrawal_count)
SELECT
    user_id,
    YEAR(created_at) as year,
    MONTH(created_at) as month,
    SUM(CASE WHEN status IN ('APPROVED', 'PENDING') THEN amount ELSE 0 END) as total_amount,
    COUNT(*) as withdrawal_count
FROM withdrawal_requests
GROUP BY user_id, YEAR(created_at), MONTH(created_at)
ON DUPLICATE KEY UPDATE
    total_amount = VALUES(total_amount),
    withdrawal_count = VALUES(withdrawal_count);

-- ============================================================
-- Verification Queries
-- ============================================================

-- Check daily limits table
SELECT COUNT(*) as total_records FROM user_withdrawal_limits;

-- Check monthly limits table
SELECT COUNT(*) as total_records FROM user_withdrawal_monthly;

-- Sample daily limits
SELECT * FROM user_withdrawal_limits ORDER BY date DESC LIMIT 10;

-- Sample monthly limits
SELECT * FROM user_withdrawal_monthly ORDER BY year DESC, month DESC LIMIT 10;
