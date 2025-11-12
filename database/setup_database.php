<?php
/**
 * Database Setup Script - Fixed Version
 * Run this script to create all necessary tables for the withdrawal system
 *
 * Usage: php database/setup_database.php
 *
 * Fixed: Better handling of multi_query execution without DELIMITER commands
 */

// Determine the correct path to config files
$config_path = __DIR__ . '/../config';
if (!file_exists($config_path . '/database.php')) {
    $config_path = __DIR__ . '/config';
}

require_once $config_path . '/database.php';
require_once $config_path . '/logger.php';

$log->info('Starting database setup...');

/**
 * Execute a single SQL statement
 */
function executeSingleStatement($conn, $sql, &$results) {
    $sql = trim($sql);
    if (empty($sql)) {
        return true;
    }

    // Execute the statement
    if ($conn->query($sql)) {
        // Check if there's a result set
        if ($result = $conn->store_result()) {
            while ($row = $result->fetch_assoc()) {
                // Check for status/message columns from our conditional queries
                if (isset($row['msg']) || isset($row['status'])) {
                    $results[] = $row['msg'] ?? $row['status'];
                } else {
                    // Other SELECT results
                    $results[] = json_encode($row);
                }
            }
            $result->free();
        }
        return true;
    } else {
        $error = $conn->error;
        // Ignore certain expected errors
        if (
            strpos($error, 'already exists') !== false ||
            strpos($error, 'Duplicate key name') !== false ||
            strpos($error, 'Duplicate column name') !== false
        ) {
            $results[] = "⊘ Skipped: " . $error;
            return true; // Treat as success
        }
        $results[] = "❌ Error: " . $error;
        return false;
    }
}

/**
 * Parse and execute SQL file with prepared statements
 */
function runMigration($conn, $migration_file, $log) {
    echo "\n========================================\n";
    echo "Running: " . basename($migration_file) . "\n";
    echo "========================================\n";

    if (!file_exists($migration_file)) {
        echo "❌ Migration file not found: $migration_file\n";
        return false;
    }

    $sql = file_get_contents($migration_file);

    // Remove SQL comments
    $sql = preg_replace('/^-- .*$/m', '', $sql);
    $sql = preg_replace('#/\*.*?\*/#s', '', $sql);

    // Split into statements by semicolon (but keep PREPARE/EXECUTE blocks together)
    $statements = [];
    $current = '';
    $inPrepare = false;

    foreach (preg_split('/;/m', $sql) as $part) {
        $part = trim($part);
        if (empty($part)) continue;

        $current .= $part . ';';

        // Detect PREPARE statements (they come in blocks: PREPARE, EXECUTE, DEALLOCATE)
        if (stripos($part, 'PREPARE stmt FROM') !== false) {
            $inPrepare = true;
        }

        // Keep collecting until we see DEALLOCATE
        if ($inPrepare) {
            if (stripos($part, 'DEALLOCATE PREPARE') !== false) {
                $statements[] = trim($current);
                $current = '';
                $inPrepare = false;
            }
        } else {
            // Regular statement - add it
            $statements[] = trim($current);
            $current = '';
        }
    }

    // Add any remaining
    if (!empty(trim($current))) {
        $statements[] = trim($current);
    }

    $results = [];
    $success_count = 0;
    $error_count = 0;

    // Execute each statement
    foreach ($statements as $statement) {
        $statement = trim($statement);
        if (empty($statement)) continue;

        // Skip pure comment lines
        if (strpos($statement, '--') === 0) continue;

        if (executeSingleStatement($conn, $statement, $results)) {
            $success_count++;
        } else {
            $error_count++;
        }
    }

    // Output results
    foreach ($results as $result) {
        echo "$result\n";
    }

    echo "\n";
    if ($error_count > 0) {
        echo "⚠️  Migration completed with $error_count errors and $success_count successes\n";
    } else {
        echo "✅ Migration completed: $success_count statements executed\n";
    }

    return $error_count === 0;
}

/**
 * Verify that required tables exist
 */
function verifyTables($conn) {
    echo "\n========================================\n";
    echo "Verifying Tables\n";
    echo "========================================\n";

    $required_tables = [
        'users',
        'wallets',
        'user_payout_methods',
        'payout_cards',
        'withdrawal_requests',
        'transactions',
        'user_withdrawal_limits',
        'user_withdrawal_monthly'
    ];

    $existing_tables = [];
    $result = $conn->query("SHOW TABLES");

    while ($row = $result->fetch_array()) {
        $existing_tables[] = $row[0];
    }

    echo "\nTable Status:\n";
    $all_exist = true;
    foreach ($required_tables as $table) {
        $exists = in_array($table, $existing_tables);
        $status = $exists ? "✅" : "❌";
        echo "$status $table";

        if ($exists) {
            // Get row count
            $count_result = $conn->query("SELECT COUNT(*) as count FROM $table");
            if ($count_result) {
                $count = $count_result->fetch_assoc()['count'];
                echo " (rows: $count)";
            }
        } else {
            $all_exist = false;
        }
        echo "\n";
    }

    return $all_exist;
}

try {
    // Start transaction for safety
    $conn->autocommit(FALSE);

    // 1. Run migrations in order
    $migrations_dir = __DIR__ . '/migrations';
    if (!is_dir($migrations_dir)) {
        throw new Exception("Migrations directory not found: $migrations_dir");
    }

    $migrations = [
        $migrations_dir . '/000_create_payout_tables.sql',
        $migrations_dir . '/001_optimize_withdrawal_tables.sql',
        $migrations_dir . '/002_add_withdrawal_limits_tracking.sql',
        $migrations_dir . '/003_update_existing_schema.sql'
    ];

    $all_success = true;
    foreach ($migrations as $migration) {
        if (!runMigration($conn, $migration, $log)) {
            $all_success = false;
            echo "⚠️  Migration had errors but continuing...\n";
            // Continue with other migrations
        }
    }

    // Commit all changes
    $conn->commit();
    $conn->autocommit(TRUE);

    // 2. Verify tables
    $tables_ok = verifyTables($conn);

    echo "\n========================================\n";
    if ($tables_ok) {
        echo "✅ Database setup completed successfully!\n";
    } else {
        echo "⚠️  Database setup completed with some missing tables\n";
    }
    echo "========================================\n\n";

    $log->info('Database setup completed', ['success' => $all_success]);

} catch (Exception $e) {
    // Rollback on error
    if ($conn) {
        $conn->rollback();
        $conn->autocommit(TRUE);
    }

    echo "\n❌ Database setup failed: " . $e->getMessage() . "\n";
    $log->error('Database setup failed', ['error' => $e->getMessage()]);
    exit(1);
} finally {
    if ($conn) $conn->close();
}
?>
