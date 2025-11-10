<?php
// ===== FILE: api/v1/payments/stripe/cards/set_default_card.php =====
require_once __DIR__ . '/../../../config/api_secure_bootstrap.php';

try {
    $data = json_decode(file_get_contents("php://input"));
    $card_id = $data->card_id ?? 0; // This is the ID from the `payout_cards` table

    if (!$card_id) {
        throw new Exception("Card ID is required.", 400);
    }

    $conn->autocommit(FALSE);

    // Verify card belongs to user
    $stmt_verify = $conn->prepare("SELECT id FROM payout_cards WHERE id = ? AND user_id = ? AND is_active = TRUE");
    $stmt_verify->bind_param("ii", $card_id, $user_id);
    $stmt_verify->execute();
    $exists = $stmt_verify->get_result()->num_rows > 0;
    $stmt_verify->close();

    if (!$exists) {
        throw new Exception("Card not found.", 404);
    }

    // Set all cards to non-default in original table
    $stmt_reset = $conn->prepare("UPDATE payout_cards SET is_default = FALSE WHERE user_id = ?");
    $stmt_reset->bind_param("i", $user_id);
    $stmt_reset->execute();
    $stmt_reset->close();

    // Set selected card as default in original table
    $stmt_set = $conn->prepare("UPDATE payout_cards SET is_default = TRUE WHERE id = ?");
    $stmt_set->bind_param("i", $card_id);
    $stmt_set->execute();
    $stmt_set->close();
    
    // --- NEW LOGIC: Update hub table ---
    // Set all methods to non-default
    $stmt_reset_hub = $conn->prepare("UPDATE user_payout_methods SET is_default = FALSE WHERE user_id = ?");
    $stmt_reset_hub->bind_param("i", $user_id);
    $stmt_reset_hub->execute();
    $stmt_reset_hub->close();
    
    // Set the selected card as default in the hub table
    $stmt_set_hub = $conn->prepare("UPDATE user_payout_methods SET is_default = TRUE WHERE payout_card_id = ? AND user_id = ? AND method_type = 'stripe_card'");
    $stmt_set_hub->bind_param("ii", $card_id, $user_id);
    $stmt_set_hub->execute();
    $stmt_set_hub->close();
    // --- END NEW LOGIC ---

    $conn->commit();

    $log->info('Default card updated in all tables', ['user_id' => $user_id, 'card_id' => $card_id]);

    http_response_code(200);
    echo json_encode(["message" => "Default card updated successfully."]);

} catch (Exception $e) {
    if ($conn) $conn->rollback();
    
    $log->error('Set default card failed', [
        'user_id' => $user_id ?? 0,
        'error' => $e->getMessage()
    ]);

    $code = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 500;
    http_response_code($code);
    echo json_encode(["message" => $e->getMessage()]);
} finally {
    if ($conn) {
        $conn->autocommit(TRUE);
        $conn->close();
    }
}
?>