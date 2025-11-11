<?php
/**
 * Database Setup Script
 * Run this script to create all necessary tables for the withdrawal system
 *
 * Usage: php database/setup_database.php
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/logger.php';

$log->info('Starting database setup...');

function runMigration($conn, $migration_file, $log) {
    echo "\n========================================\n";
    echo "Running: $migration_file\n";
    echo "========================================\n";

    if (!file_exists($migration_file)) {
        echo "❌ Migration file not found: $migration_file\n";
        return false;
    }

    $sql = file_get_contents($migration_file);

    // Remove comments for cleaner execution
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);

    // For MySQL 8, use multi_query to handle stored procedures and DELIMITER
    if ($conn->multi_query($sql)) {
        $result_count = 0;
        $error_count = 0;

        // Process all results
        do {
            // Store first result set
            if ($result = $conn->store_result()) {
                // Output any SELECT results
                while ($row = $result->fetch_assoc()) {
                    // Check if this is a status message from our procedures
                    if (isset($row['status'])) {
                        echo $row['status'] . "\n";
                    } else {
                        // Print other SELECT results
                        echo json_encode($row) . "\n";
                    }
                }
                $result->free();
                $result_count++;
            } else {
                // No result set (INSERT, UPDATE, etc.)
                if ($conn->error) {
                    echo "⚠ Warning: " . $conn->error . "\n";
                    $error_count++;
                } else {
                    $result_count++;
                }
            }

            // Check for more results
            if ($conn->more_results()) {
                // Move to next result
            } else {
                break;
            }
        } while ($conn->next_result());

        // Check for any final errors
        if ($conn->error) {
            echo "⚠ Final error: " . $conn->error . "\n";
            $error_count++;
        }

        echo "\n✅ Migration completed: $result_count operations processed";
        if ($error_count > 0) {
            echo ", $error_count warnings/errors";
        }
        echo "\n";

        return true;
    } else {
        echo "❌ Migration failed: " . $conn->error . "\n";
        $log->error('Migration execution failed', [
            'error' => $conn->error,
            'file' => $migration_file
        ]);
        return false;
    }
}

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
    foreach ($required_tables as $table) {
        $exists = in_array($table, $existing_tables);
        $status = $exists ? "✅" : "❌";
        echo "$status $table\n";

        if ($exists) {
            // Get row count
            $count_result = $conn->query("SELECT COUNT(*) as count FROM $table");
            $count = $count_result->fetch_assoc()['count'];
            echo "   └─ Rows: $count\n";
        }
    }

    return true;
}

try {
    // 1. Run migrations in order
    $migrations = [
        __DIR__ . '/migrations/000_create_payout_tables.sql',
        __DIR__ . '/migrations/001_optimize_withdrawal_tables.sql',
        __DIR__ . '/migrations/002_add_withdrawal_limits_tracking.sql',
        __DIR__ . '/migrations/003_update_existing_schema.sql'
    ];

    foreach ($migrations as $migration) {
        runMigration($conn, $migration, $log);
    }

    // 2. Verify tables
    verifyTables($conn);

    echo "\n========================================\n";
    echo "✅ Database setup completed successfully!\n";
    echo "========================================\n\n";

} catch (Exception $e) {
    echo "\n❌ Database setup failed: " . $e->getMessage() . "\n";
    $log->error('Database setup failed', ['error' => $e->getMessage()]);
    exit(1);
} finally {
    if ($conn) $conn->close();
}
?>
