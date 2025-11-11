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

    // Split by semicolon (simple approach, won't work with all SQL)
    $statements = array_filter(
        array_map('trim', explode(';', $sql)),
        function($statement) {
            // Skip comments and empty statements
            return !empty($statement) &&
                   strpos($statement, '--') !== 0 &&
                   strpos($statement, '/*') !== 0;
        }
    );

    $success_count = 0;
    $error_count = 0;

    foreach ($statements as $statement) {
        if (empty(trim($statement))) continue;

        try {
            if ($conn->query($statement)) {
                $success_count++;
                echo "✓ Statement executed successfully\n";
            } else {
                $error_count++;
                echo "⚠ Warning: " . $conn->error . "\n";
                $log->warning('SQL statement failed', [
                    'error' => $conn->error,
                    'statement' => substr($statement, 0, 100) . '...'
                ]);
            }
        } catch (Exception $e) {
            $error_count++;
            echo "⚠ Error: " . $e->getMessage() . "\n";
            $log->error('SQL statement exception', [
                'error' => $e->getMessage(),
                'statement' => substr($statement, 0, 100) . '...'
            ]);
        }
    }

    echo "\n✅ Migration completed: $success_count successful, $error_count warnings/errors\n";
    return true;
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
        'transactions'
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
        __DIR__ . '/migrations/002_add_withdrawal_limits_tracking.sql'
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
