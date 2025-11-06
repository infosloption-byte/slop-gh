<?php
// Load dependencies
require_once __DIR__ . '/../../../config/api_secure_bootstrap.php';

try {
    
    // Authenticate the user
    // using the $conn and $user_id variables provided by the bootstrap.

    $query = "UPDATE wallets SET balance = 10000.00 WHERE user_id = ? AND type = 'demo'";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $user_id);
    
    if ($stmt->execute()) {
        if ($stmt->affected_rows > 0) {
            http_response_code(200);
            echo json_encode(["message" => "Demo wallet recharged successfully."]);
        } else {
            throw new Exception("Demo wallet not found for this user.", 404);
        }
    } else {
        throw new Exception("Failed to recharge demo wallet.", 500);
    }
    
} catch (Exception $e) {
    // --- NEW: LOG THE ERROR ---
    $log->error('Demo wallet recharge failed.', [
        'user_id' => $user_id ?? 'unknown',
        'error' => $e->getMessage()
    ]);
    
    http_response_code($e->getCode() > 0 ? $e->getCode() : 500);
    echo json_encode(["message" => "An internal error occurred."]);

} finally {
    if ($conn) $conn->close();
}
?>