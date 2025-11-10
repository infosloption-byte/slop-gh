# 🗄️ Database Schema Documentation - Withdrawal System

## Overview

This document provides a comprehensive overview of the database schema related to the withdrawal system, including tables, relationships, indexes, and optimization recommendations.

---

## 📋 Core Tables

### 1. `withdrawal_requests`

**Purpose**: Stores all withdrawal requests with complete audit trail

```sql
CREATE TABLE withdrawal_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    wallet_id INT NOT NULL,
    user_payout_method_id INT NULL,
    payout_card_id INT NULL,
    amount INT NOT NULL COMMENT 'Amount in cents',
    status ENUM('PENDING', 'APPROVED', 'REJECTED', 'FAILED') NOT NULL DEFAULT 'PENDING',
    payout_method VARCHAR(50) NULL COMMENT 'Legacy: automated_card, manual, etc',
    withdrawal_method VARCHAR(50) NULL COMMENT 'stripe_card, paypal, binance, skrill',
    gateway_transaction_id VARCHAR(255) NULL,
    gateway_status VARCHAR(50) NULL,
    failure_reason TEXT NULL,
    admin_notes TEXT NULL,
    requested_amount_available INT NULL COMMENT 'Balance at request time',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    processed_at TIMESTAMP NULL,
    processed_by INT NULL COMMENT 'Admin user_id',

    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (wallet_id) REFERENCES wallets(id) ON DELETE CASCADE,
    FOREIGN KEY (user_payout_method_id) REFERENCES user_payout_methods(id) ON DELETE SET NULL,
    FOREIGN KEY (processed_by) REFERENCES users(id) ON DELETE SET NULL,

    INDEX idx_wr_user_status (user_id, status),
    INDEX idx_wr_status_created (status, created_at DESC),
    INDEX idx_wr_created_at (created_at DESC),
    INDEX idx_wr_processed_at (processed_at DESC),
    INDEX idx_wr_user_created (user_id, created_at DESC),
    INDEX idx_wr_user_date_status (user_id, DATE(created_at), status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**Key Columns**:
- `amount`: Stored in cents (multiply by 100)
- `status`: Current state of the request
- `gateway_transaction_id`: Transaction ID from payment provider
- `requested_amount_available`: Audit trail of balance at request time

---

### 2. `user_payout_methods`

**Purpose**: Unified storage for all user payout methods

```sql
CREATE TABLE user_payout_methods (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    method_type ENUM('stripe_card', 'paypal', 'binance', 'skrill', 'manual') NOT NULL,
    display_name VARCHAR(255) NOT NULL,
    account_identifier VARCHAR(255) NOT NULL COMMENT 'Email, ID, etc',
    payout_card_id INT NULL COMMENT 'For stripe_card type',
    is_default BOOLEAN DEFAULT FALSE,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (payout_card_id) REFERENCES payout_cards(id) ON DELETE SET NULL,

    INDEX idx_upm_user_method (user_id, method_type),
    INDEX idx_upm_user_active (user_id, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

---

### 3. `payout_cards` (Stripe-specific)

**Purpose**: Stores Stripe debit cards for payouts

```sql
CREATE TABLE payout_cards (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    stripe_connect_account_id VARCHAR(255) NOT NULL,
    external_account_id VARCHAR(255) NOT NULL,
    brand VARCHAR(50) NULL,
    last4 VARCHAR(4) NULL,
    country VARCHAR(2) DEFAULT 'US',
    is_default BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,

    UNIQUE KEY unique_external_account (external_account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

---

### 4. `transactions`

**Purpose**: Complete ledger of all financial transactions

```sql
CREATE TABLE transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    wallet_id INT NOT NULL,
    type ENUM('DEPOSIT', 'WITHDRAWAL', 'TRADE', 'FEE', 'BONUS', 'REFUND') NOT NULL,
    amount INT NOT NULL COMMENT 'Amount in cents',
    status ENUM('PENDING', 'COMPLETED', 'REJECTED', 'FAILED') NOT NULL,
    reference_id INT NULL COMMENT 'Reference to withdrawal_request or other',
    gateway_transaction_id VARCHAR(255) NULL,
    description TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (wallet_id) REFERENCES wallets(id) ON DELETE CASCADE,

    INDEX idx_trans_user_type (user_id, type),
    INDEX idx_trans_reference (type, reference_id),
    INDEX idx_trans_gateway (gateway_transaction_id),
    INDEX idx_trans_created (created_at DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

---

### 5. `wallets`

**Purpose**: User balance storage

```sql
CREATE TABLE wallets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    type ENUM('real', 'demo') NOT NULL DEFAULT 'real',
    balance INT NOT NULL DEFAULT 0 COMMENT 'Balance in cents',
    currency VARCHAR(3) DEFAULT 'USD',
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,

    INDEX idx_wallets_user_type (user_id, type),
    UNIQUE KEY unique_user_type (user_id, type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

---

### 6. `user_withdrawal_limits` (New - Performance Optimization)

**Purpose**: Fast lookup for daily withdrawal limits

```sql
CREATE TABLE user_withdrawal_limits (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    date DATE NOT NULL,
    total_amount INT NOT NULL DEFAULT 0 COMMENT 'Total amount in cents',
    withdrawal_count INT NOT NULL DEFAULT 0,
    last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY unique_user_date (user_id, date),
    INDEX idx_user_date (user_id, date DESC),
    INDEX idx_date (date),

    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

---

### 7. `user_withdrawal_monthly` (New - Analytics)

**Purpose**: Monthly withdrawal tracking for analytics

```sql
CREATE TABLE user_withdrawal_monthly (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    year INT NOT NULL,
    month INT NOT NULL,
    total_amount INT NOT NULL DEFAULT 0 COMMENT 'Total amount in cents',
    withdrawal_count INT NOT NULL DEFAULT 0,
    last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY unique_user_month (user_id, year, month),
    INDEX idx_user_year_month (user_id, year, month),

    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

---

## 🔗 Table Relationships

```
users
  ├── wallets (1:N)
  │   └── withdrawal_requests (1:N)
  │       └── transactions (1:1)
  │
  ├── user_payout_methods (1:N)
  │   ├── payout_cards (1:1 for stripe)
  │   └── withdrawal_requests (1:N)
  │
  └── user_withdrawal_limits (1:N)
      └── user_withdrawal_monthly (1:N)
```

---

## 📊 Index Strategy

### Critical Indexes for Performance

1. **User-Specific Queries**:
   - `idx_wr_user_status` on `(user_id, status)` - Withdrawal history filtering
   - `idx_wr_user_created` on `(user_id, created_at DESC)` - Recent activity
   - `idx_upm_user_active` on `(user_id, is_active)` - Active payout methods

2. **Admin Dashboard Queries**:
   - `idx_wr_status_created` on `(status, created_at DESC)` - Pending list
   - `idx_wr_processed_at` on `(processed_at DESC)` - Recent processed

3. **Analytics Queries**:
   - `idx_wr_user_date_status` on `(user_id, DATE(created_at), status)` - Daily limits
   - `idx_trans_user_type` on `(user_id, type)` - Transaction history

4. **Lookup Queries**:
   - `idx_trans_gateway` on `(gateway_transaction_id)` - External tracking
   - `idx_trans_reference` on `(type, reference_id)` - Linked transactions

---

## ⚡ Performance Optimization

### Query Performance Tips

1. **Daily Limit Checks**:
   ```sql
   -- ❌ Slow (scans all rows)
   SELECT SUM(amount) FROM withdrawal_requests
   WHERE user_id = ? AND DATE(created_at) = CURDATE();

   -- ✅ Fast (uses user_withdrawal_limits table)
   SELECT total_amount FROM user_withdrawal_limits
   WHERE user_id = ? AND date = CURDATE();
   ```

2. **Pagination**:
   ```sql
   -- ✅ Always use LIMIT and OFFSET with index
   SELECT * FROM withdrawal_requests
   WHERE user_id = ?
   ORDER BY created_at DESC
   LIMIT 20 OFFSET 0;
   ```

3. **Status Filtering**:
   ```sql
   -- ✅ Use composite index
   SELECT * FROM withdrawal_requests
   WHERE user_id = ? AND status = 'PENDING'
   ORDER BY created_at DESC;
   ```

### Table Maintenance

```sql
-- Run monthly to optimize tables
OPTIMIZE TABLE withdrawal_requests;
OPTIMIZE TABLE transactions;
OPTIMIZE TABLE user_payout_methods;

-- Check table fragmentation
SELECT
    TABLE_NAME,
    DATA_FREE / 1024 / 1024 AS 'Fragmentation (MB)'
FROM INFORMATION_SCHEMA.TABLES
WHERE TABLE_SCHEMA = DATABASE()
AND DATA_FREE > 0;
```

---

## 🔐 Data Integrity

### Constraints

1. **Foreign Keys**: All foreign keys use `ON DELETE CASCADE` for user data
2. **Unique Constraints**: Prevent duplicate external accounts
3. **Enums**: Ensure status values are always valid
4. **NOT NULL**: Critical fields cannot be null

### Audit Trail

Every withdrawal maintains:
- `created_at` - Request timestamp
- `processed_at` - Processing timestamp
- `processed_by` - Admin who processed
- `admin_notes` - Processing notes
- `requested_amount_available` - Balance snapshot

---

## 📈 Scalability Considerations

### Current Capacity
- Supports millions of withdrawal records
- Indexes optimized for sub-100ms queries
- Partition-ready for horizontal scaling

### Future Enhancements

1. **Table Partitioning** (for very large datasets):
   ```sql
   ALTER TABLE withdrawal_requests
   PARTITION BY RANGE (YEAR(created_at)) (
       PARTITION p2024 VALUES LESS THAN (2025),
       PARTITION p2025 VALUES LESS THAN (2026),
       PARTITION pfuture VALUES LESS THAN MAXVALUE
   );
   ```

2. **Archival Strategy**:
   - Move processed withdrawals > 2 years to archive table
   - Keep recent data in main table for performance

3. **Read Replicas**:
   - Use read replicas for analytics queries
   - Keep master for transactional operations

---

## 🛠️ Migration Guide

### Running Migrations

```bash
# 1. Backup database first!
mysqldump -u root -p database_name > backup_$(date +%Y%m%d).sql

# 2. Run optimization migration
mysql -u root -p database_name < database/migrations/001_optimize_withdrawal_tables.sql

# 3. Add tracking tables
mysql -u root -p database_name < database/migrations/002_add_withdrawal_limits_tracking.sql

# 4. Verify indexes
mysql -u root -p -e "SHOW INDEX FROM withdrawal_requests;" database_name
```

### Rollback Plan

```sql
-- Drop new indexes if needed
DROP INDEX idx_wr_user_status ON withdrawal_requests;
DROP INDEX idx_wr_status_created ON withdrawal_requests;
-- etc...

-- Drop new tables
DROP TABLE IF EXISTS user_withdrawal_limits;
DROP TABLE IF EXISTS user_withdrawal_monthly;
```

---

## 📊 Monitoring Queries

### Performance Monitoring

```sql
-- Check slow queries
SELECT * FROM mysql.slow_log
WHERE sql_text LIKE '%withdrawal_requests%'
ORDER BY query_time DESC LIMIT 10;

-- Index usage statistics
SELECT
    TABLE_NAME, INDEX_NAME,
    ROWS_READ, ROWS_INSERTED, ROWS_UPDATED, ROWS_DELETED
FROM performance_schema.table_io_waits_summary_by_index_usage
WHERE OBJECT_SCHEMA = DATABASE()
AND TABLE_NAME = 'withdrawal_requests';
```

### Data Health Checks

```sql
-- Check for orphaned records
SELECT COUNT(*) FROM withdrawal_requests wr
LEFT JOIN user_payout_methods pm ON wr.user_payout_method_id = pm.id
WHERE wr.user_payout_method_id IS NOT NULL AND pm.id IS NULL;

-- Check balance consistency
SELECT
    u.id, u.email,
    w.balance as wallet_balance,
    COALESCE(SUM(CASE
        WHEN t.type = 'DEPOSIT' THEN t.amount
        WHEN t.type = 'WITHDRAWAL' THEN -t.amount
        ELSE 0 END), 0) as calculated_balance
FROM users u
JOIN wallets w ON u.id = w.user_id
LEFT JOIN transactions t ON w.id = t.wallet_id AND t.status = 'COMPLETED'
GROUP BY u.id, w.balance
HAVING ABS(wallet_balance - calculated_balance) > 100;
```

---

**Last Updated**: 2025-01-10
**Version**: 2.0
**Author**: Claude (AI Assistant)
