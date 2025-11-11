# 🔧 Troubleshooting Guide - Withdrawal System

## Common Errors and Solutions

---

## ❌ Error: 500 Internal Server Error on `/list_methods.php`

### Symptoms
```
GET /api/v1/payments/payout_methods/list_methods.php
Status: 500 Internal Server Error
```

### Root Causes

1. **Missing Database Tables**
   - The `user_payout_methods` or `payout_cards` tables don't exist

2. **Missing Columns**
   - Table exists but missing required columns like `is_active`

3. **Database Connection Issues**
   - Can't connect to database
   - Wrong credentials in `.env`

### Solutions

#### Solution 1: Run Database Setup Script

```bash
cd /path/to/slop-gh
php database/setup_database.php
```

This will:
- Create all necessary tables
- Add required indexes
- Verify table structure

#### Solution 2: Manual Migration

Run migrations in order:

```bash
mysql -u root -p database_name < database/migrations/000_create_payout_tables.sql
mysql -u root -p database_name < database/migrations/001_optimize_withdrawal_tables.sql
mysql -u root -p database_name < database/migrations/002_add_withdrawal_limits_tracking.sql
```

#### Solution 3: Verify Table Structure

```sql
-- Check if table exists
SHOW TABLES LIKE 'user_payout_methods';

-- Check table structure
DESCRIBE user_payout_methods;

-- Expected columns:
-- id, user_id, method_type, display_name, account_identifier,
-- payout_card_id, is_default, is_active, created_at, updated_at
```

#### Solution 4: Check Logs

```bash
# Check PHP error logs
tail -f /var/log/php_errors.log

# Check application logs
tail -f storage/logs/app.log

# Check MySQL error logs
tail -f /var/log/mysql/error.log
```

---

## ❌ Error: "Column 'is_active' doesn't exist"

### Solution

The table was created before the `is_active` column was added.

**Add the missing column:**

```sql
ALTER TABLE user_payout_methods
ADD COLUMN is_active BOOLEAN DEFAULT TRUE AFTER is_default;
```

---

## ❌ Error: "Table 'database_name.user_payout_methods' doesn't exist"

### Solution

Run the table creation migration:

```bash
mysql -u root -p database_name < database/migrations/000_create_payout_tables.sql
```

Or create manually:

```sql
CREATE TABLE user_payout_methods (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    method_type ENUM('stripe_card', 'paypal', 'binance', 'skrill', 'manual') NOT NULL,
    display_name VARCHAR(255) NOT NULL,
    account_identifier VARCHAR(255) NOT NULL,
    payout_card_id INT NULL,
    is_default BOOLEAN DEFAULT FALSE,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
```

---

## ❌ Error: Empty Response or "methods: []"

### Possible Causes

1. **No payout methods added yet** (Normal for new users)
2. **All methods are inactive** (`is_active = 0`)
3. **Wrong user_id** (not authenticated properly)

### Solutions

**1. Check if user has payout methods:**

```sql
SELECT * FROM user_payout_methods WHERE user_id = YOUR_USER_ID;
```

**2. Add a test payout method:**

```bash
curl -X POST https://your-domain.com/api/v1/payments/payout_methods/add_simple_method.php \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "method_type": "paypal",
    "identifier": "your-paypal@email.com"
  }'
```

**3. Check authentication:**

```bash
# Verify JWT token is valid
curl https://your-domain.com/api/v1/payments/payout_methods/list_methods.php \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -v
```

---

## ❌ Error: "Failed to retrieve payout methods"

### Debug Steps

**1. Check database connection:**

```php
// Test in config/database.php
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
echo "Connected successfully to: " . DB_NAME;
```

**2. Enable detailed error reporting:**

```php
// Add to top of list_methods.php temporarily
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
```

**3. Check MySQL error log:**

```bash
sudo tail -f /var/log/mysql/error.log
```

**4. Test query directly:**

```sql
SELECT id, user_id, method_type, display_name, account_identifier, is_default, is_active, created_at
FROM user_payout_methods
WHERE user_id = 1 AND is_active = 1
ORDER BY is_default DESC, created_at DESC;
```

---

## ⚠️ Error: Frontend receives malformed JSON

### Solution

Check for PHP warnings/notices before JSON output:

```bash
# Check PHP error log
tail -f /var/log/php_errors.log
```

Ensure no output before `echo json_encode()`:
- Remove any `echo`, `var_dump()`, `print_r()` statements
- Check for PHP warnings
- Verify proper encoding

---

## 🔍 General Debugging Steps

### 1. Check Environment

```bash
# Verify .env file exists
ls -la .env

# Check database credentials
grep "DB_" .env
```

### 2. Test Database Connection

```bash
mysql -u DB_USER -p DB_NAME -e "SELECT 1"
```

### 3. Check PHP Version

```bash
php -v
# Should be PHP 7.4+ or 8.x
```

### 4. Check Required Extensions

```bash
php -m | grep -E "mysqli|json|curl"
```

### 5. Check File Permissions

```bash
# API files should be readable
ls -l api/v1/payments/payout_methods/list_methods.php

# Logs directory should be writable
ls -ld storage/logs/
```

---

## 🚀 Quick Fix Script

Create a file `fix_payout_methods.sh`:

```bash
#!/bin/bash

echo "🔧 Fixing payout methods issue..."

# 1. Run migrations
echo "Running migrations..."
mysql -u root -p database_name < database/migrations/000_create_payout_tables.sql

# 2. Verify tables
echo "Verifying tables..."
mysql -u root -p database_name -e "SHOW TABLES LIKE 'user_payout_methods';"

# 3. Check table structure
echo "Checking table structure..."
mysql -u root -p database_name -e "DESCRIBE user_payout_methods;"

# 4. Test endpoint
echo "Testing endpoint..."
curl -s https://your-domain.com/api/v1/payments/payout_methods/list_methods.php \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" | jq .

echo "✅ Done!"
```

Run it:
```bash
chmod +x fix_payout_methods.sh
./fix_payout_methods.sh
```

---

## 📞 Still Having Issues?

### Check These Files

1. **Configuration**: `config/database.php`, `.env`
2. **Logs**: `storage/logs/app.log`
3. **Migration Status**: `database/migrations/`
4. **API File**: `api/v1/payments/payout_methods/list_methods.php`

### Collect Debug Information

```bash
# Create debug report
cat > debug_report.txt <<EOF
=== System Info ===
PHP Version: $(php -v | head -n 1)
MySQL Version: $(mysql --version)

=== Database Tables ===
$(mysql -u root -p database_name -e "SHOW TABLES;" 2>&1)

=== user_payout_methods Structure ===
$(mysql -u root -p database_name -e "DESCRIBE user_payout_methods;" 2>&1)

=== Recent Logs ===
$(tail -n 50 storage/logs/app.log 2>&1)
EOF

cat debug_report.txt
```

---

## ✅ Verification Checklist

After applying fixes, verify:

- [ ] Database tables exist
- [ ] All required columns present
- [ ] Foreign keys created
- [ ] Indexes added
- [ ] Test user can add payout method
- [ ] API returns 200 status
- [ ] JSON response is valid
- [ ] Frontend displays methods correctly

---

**Last Updated**: 2025-01-10
**Version**: 2.0
