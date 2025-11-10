<?php
// api/v1/payments/stripe/cards/list_payout_cards.php
require_once __DIR__ . '/../../../config/api_secure_bootstrap.php';

try {
    $stmt = $conn->prepare(
        "SELECT id, card_brand, card_last4, card_holder_name, card_country, 
                is_default, is_active, created_at 
         FROM payout_cards 
         WHERE user_id = ? AND is_active = TRUE 
         ORDER BY is_default DESC, created_at DESC"
    );
    
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $cards = [];
    while ($row = $result->fetch_assoc()) {
        $cards[] = [
            'id' => (int)$row['id'],
            'brand' => $row['card_brand'],
            'last4' => $row['card_last4'],
            'holder_name' => $row['card_holder_name'],
            'country' => $row['card_country'],
            'is_default' => (bool)$row['is_default'],
            'added_at' => $row['created_at']
        ];
    }
    
    $stmt->close();
    
    http_response_code(200);
    echo json_encode([
        'cards' => $cards,
        'count' => count($cards)
    ]);
    
} catch (Exception $e) {
    $log->error('List payout cards failed', [
        'user_id' => $user_id,
        'error' => $e->getMessage()
    ]);
    
    http_response_code(500);
    echo json_encode(['message' => 'Failed to retrieve cards']);
} finally {
    if ($conn) $conn->close();
}
?>