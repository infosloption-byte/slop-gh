<?php
// api/v1/admin/health_check.php

// Bootstrap and secure the endpoint
require_once __DIR__ . '/../../../config/api_secure_bootstrap.php';

// Only allow admins to access this page
if (!is_admin($conn, $user_id)) {
    http_response_code(403);
    die(json_encode(["message" => "Forbidden: You do not have administrative privileges."]));
}

// Initialize the status report array
$health_report = [
    'database_connection' => 'OK', // If we got this far, the DB connection from bootstrap is working
    'redis_connection' => 'Checking...',
    'price_cache_status' => 'Not Checked',
    'last_log_entry' => 'Checking...',
    'report_time' => date('Y-m-d H:i:s T')
];

$all_ok = true;

// --- 1. Check Redis Connection and Price Cache ---
try {
    $redis = new Predis\Client([
        'scheme' => 'redis', // Use the scheme that works for your environment
        'host'   => $_ENV['REDIS_HOST'],
        'port'   => $_ENV['REDIS_PORT'],
        'password' => $_ENV['REDIS_PASSWORD'],
    ]);
    
    // Test the connection
    $redis->ping();
    $health_report['redis_connection'] = 'OK';

    // Check if the price cache for a major pair is fresh
    $btc_price = $redis->get('price:BTCUSDT');
    if ($btc_price === null) {
        $health_report['price_cache_status'] = 'FAIL: price:BTCUSDT key is missing.';
        $all_ok = false;
    } else {
        $health_report['price_cache_status'] = 'OK (BTC Price: ' . $btc_price . ')';
    }

} catch (Exception $e) {
    $health_report['redis_connection'] = 'FAIL: ' . $e->getMessage();
    $health_report['price_cache_status'] = 'FAIL: Could not check due to Redis connection error.';
    $all_ok = false;
}

// --- 2. Check Log File Accessibility and get the last line ---
try {
    $log_file_path = __DIR__ . '/../../../logs/app.log';
    if (is_readable($log_file_path)) {
        // Read the last line from the log file
        $last_line = trim(shell_exec('tail -n 1 ' . escapeshellarg($log_file_path)));
        $health_report['last_log_entry'] = empty($last_line) ? 'Log file is empty.' : $last_line;
    } else {
        $health_report['last_log_entry'] = 'FAIL: Log file is not readable.';
        $all_ok = false;
    }
} catch (Exception $e) {
    $health_report['last_log_entry'] = 'FAIL: Could not read log file. ' . $e->getMessage();
    $all_ok = false;
}


// --- Final Report ---
// Set the overall HTTP status code
http_response_code($all_ok ? 200 : 503); // 503 Service Unavailable if any check fails

// Send the JSON response
header('Content-Type: application/json');
echo json_encode([
    'overall_status' => $all_ok ? 'OK' : 'ERROR',
    'checks' => $health_report
]);

?>