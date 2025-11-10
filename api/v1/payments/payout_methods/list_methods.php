<?php
// api/v1/payments/payout_methods/list_methods.php
require_once __DIR__ . '/../../../config/api_secure_bootstrap.php';

try {
    // Fetch all active payout methods for the user
    $stmt = $conn->prepare(
        "SELECT id, user_id, method_type, display_name, is_default 
         FROM user_payout_methods 
         WHERE user_id = ?
         ORDER BY is_default DESC, created_at DESC"
    );
    
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $methods = [];
    while ($row = $result->fetch_assoc()) {
        $methods[] = [
            'id' => (int)$row['id'], // This is the user_payout_methods.id
            'method_type' => $row['method_type'],
            'display_name' => $row['display_name'],
            'is_default' => (bool)$row['is_default']
        ];
    }
    
    $stmt->close();
    
    http_response_code(200);
    echo json_encode($methods);
    
} catch (Exception $e) {
    $log->error('List payout methods failed', [
        'user_id' => $user_id,
        'error' => $e->getMessage()
    ]);
    
    http_response_code(500);
    echo json_encode(['message' => 'Failed to retrieve payout methods']);
} finally {
    if ($conn) $conn->close();
}
?>