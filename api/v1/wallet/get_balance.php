<?php
// api/v1/wallet/get_balance.php

// Load dependencies and bootstrap
require_once __DIR__ . '/../../../config/api_secure_bootstrap.php';

try {
    // The bootstrap file provides the authenticated $user_id
    
    // Fetch the user's wallets
    $query = "SELECT type, balance FROM wallets WHERE user_id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $wallets = []; // Use modern array syntax
    while ($row = $result->fetch_assoc()) {
        // --- START: CENTS CONVERSION FIX ---
        // Convert the balance from cents (BIGINT) to dollars (float)
        $wallets[$row['type']] = (int)$row['balance'] / 100.0;
        // --- END: CENTS CONVERSION FIX ---
    }
    $stmt->close();

    http_response_code(200);
    echo json_encode($wallets);

} catch (Exception $e) {
    // Log the actual error for debugging
    $log->error('Get balance failed.', ['user_id' => $user_id ?? 0, 'error' => $e->getMessage()]);
    
    // Send a generic error response to the client
    $code = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 401;
    http_response_code($code);
    echo json_encode(["message" => "Access denied or an error occurred."]);
    
} finally {
    if ($conn) {
        $conn->close();
    }
}
?>