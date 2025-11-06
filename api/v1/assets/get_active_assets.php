<?php
// api/v1/assets/get_active_assets.php

// --- TEMPORARY DEBUGGING: These lines will force the server to show the exact error ---
ini_set('display_errors', 1);
error_reporting(E_ALL);
// --- END DEBUGGING ---

// Use the public bootstrap, as this data is not sensitive
require_once __DIR__ . '/../../../config/api_bootstrap.php';

try {
    // MODIFIED: The query now selects the `payout_rate` column
    // This is required by the updated front-end to display the rate in the asset list.
    $query = "SELECT symbol, display_name, icon_url, payout_rate FROM trading_assets WHERE is_active = TRUE ORDER BY display_name ASC";
    
    $result = $conn->query($query);
    if (!$result) {
        throw new Exception("Database query failed.");
    }

    // Fetch all results into an associative array
    $assets = $result->fetch_all(MYSQLI_ASSOC);

    // Return the assets as a JSON response
    header('Content-Type: application/json');
    http_response_code(200);
    echo json_encode($assets);

} catch (Exception $e) {
    // Log any errors
    $log->error('Failed to fetch active assets.', ['error' => $e->getMessage()]);
    http_response_code(500);
    echo json_encode(['message' => 'An internal server error occurred.']);
}

$conn->close();
?>