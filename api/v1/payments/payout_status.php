<?php
// api/v1/payments/payout_status.php (UPDATED for card-based payouts)
require_once __DIR__ . '/../../../config/api_secure_bootstrap.php';

try {
    // Check if user has any active payout cards
    $stmt = $conn->prepare(
        "SELECT COUNT(*) as card_count,
                MAX(is_default) as has_default
         FROM payout_cards 
         WHERE user_id = ? AND is_active = TRUE"
    );
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    $card_count = (int)$result['card_count'];
    $has_default = (bool)$result['has_default'];
    
    // Get the default card details if exists
    $default_card = null;
    if ($has_default) {
        $stmt_card = $conn->prepare(
            "SELECT id, card_brand, card_last4, card_holder_name, created_at
             FROM payout_cards 
             WHERE user_id = ? AND is_default = TRUE AND is_active = TRUE
             LIMIT 1"
        );
        $stmt_card->bind_param("i", $user_id);
        $stmt_card->execute();
        $card_data = $stmt_card->get_result()->fetch_assoc();
        $stmt_card->close();
        
        if ($card_data) {
            $default_card = [
                'id' => (int)$card_data['id'],
                'brand' => $card_data['card_brand'],
                'last4' => $card_data['card_last4'],
                'holder_name' => $card_data['card_holder_name'],
                'added_at' => $card_data['created_at']
            ];
        }
    }
    
    http_response_code(200);
    echo json_encode([
        'payout_method' => 'card',
        'payout_enabled' => $card_count > 0,
        'card_count' => $card_count,
        'has_default_card' => $has_default,
        'default_card' => $default_card
    ]);
    
} catch (Exception $e) {
    $log->error('Payout status check failed', [
        'user_id' => $user_id,
        'error' => $e->getMessage()
    ]);
    http_response_code(500);
    echo json_encode([
        'message' => 'Failed to check payout status',
        'error' => $e->getMessage()
    ]);
} finally {
    if ($conn) $conn->close();
}
?>