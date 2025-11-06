<?php
require_once __DIR__ . '/../../../config/api_secure_bootstrap.php';

try {
    // using the $conn and $user_id variables provided by the bootstrap.
    $query = "SELECT t.*, w.type as wallet_type 
              FROM trades t JOIN wallets w ON t.wallet_id = w.id
              WHERE t.user_id = ? ORDER BY t.created_at DESC";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $history_frontend = [];
    while ($row = $result->fetch_assoc()) {
        // --- START: CONVERT FOR FRONT-END ---
        $row['bid_amount'] = (int)$row['bid_amount'] / 100.0;
        if ($row['profit_loss'] !== null) {
            $row['profit_loss'] = (int)$row['profit_loss'] / 100.0;
        }
        $row['entry_price'] = (int)$row['entry_price'] / 1000000.0;
        if ($row['close_price'] !== null) {
            $row['close_price'] = (int)$row['close_price'] / 1000000.0;
        }
        $history_frontend[] = $row;
        // --- END: CONVERT FOR FRONT-END ---
    }

    http_response_code(200);
    echo json_encode($history_frontend);

} catch (Exception $e) {
    $log->error('Get trade history failed.', ['error' => $e->getMessage()]);
    http_response_code($e->getCode() > 0 ? $e->getCode() : 500);
    echo json_encode(["message" => "Could not retrieve trade history."]);
} finally {
    if ($conn) $conn->close();
}
?>
