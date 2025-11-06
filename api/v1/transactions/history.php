<?php
// Load dependencies
require_once __DIR__ . '/../../../config/api_secure_bootstrap.php';

try {
    // Bootstrap provides: $conn, $log, $user_id.
    // Authenticate the user
    // using the $conn and $user_id variables provided by the bootstrap.

    // Fetch all transactions for the user, newest first.
    $query = "SELECT 
                type, 
                amount, 
                status, 
                gateway,
                gateway_transaction_id,
                created_at 
              FROM transactions 
              WHERE user_id = ? 
              ORDER BY created_at DESC";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $transactions_frontend = [];
    while ($row_db = $result->fetch_assoc()) {
        // --- START: CONVERT FOR FRONT-END ---
        // Convert the 'amount' from cents to dollars
        $row_frontend = $row_db;
        $row_frontend['amount'] = (int)$row_db['amount'] / 100.0;
        $transactions_frontend[] = $row_frontend;
        // --- END: CONVERT FOR FRONT-END ---
    }

    http_response_code(200);
    echo json_encode($transactions_frontend);

} catch (Exception $e) {
    $log->error('Get transaction history failed.', ['user_id' => $user_id ?? 0, 'error' => $e->getMessage()]);
    $code = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 500;
    http_response_code($code);
    echo json_encode(["message" => "Could not retrieve transaction history."]);
} finally {
    if ($conn) $conn->close();
}
?>