<?php
// ===== FILE 1: api/v1/payments/set_default_card.php =====
require_once __DIR__ . '/../../../config/api_secure_bootstrap.php';

try {
    $data = json_decode(file_get_contents("php://input"));
    $card_id = $data->card_id ?? 0;

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

    // Set all cards to non-default
    $stmt_reset = $conn->prepare("UPDATE payout_cards SET is_default = FALSE WHERE user_id = ?");
    $stmt_reset->bind_param("i", $user_id);
    $stmt_reset->execute();
    $stmt_reset->close();

    // Set selected card as default
    $stmt_set = $conn->prepare("UPDATE payout_cards SET is_default = TRUE WHERE id = ?");
    $stmt_set->bind_param("i", $card_id);
    $stmt_set->execute();
    $stmt_set->close();

    $conn->commit();

    $log->info('Default card updated', ['user_id' => $user_id, 'card_id' => $card_id]);

    http_response_code(200);
    echo json_encode(["message" => "Default card updated successfully."]);

} catch (Exception $e) {
    if ($conn) $conn->rollback();
    
    $log->error('Set default card failed', [
        'user_id' => $user_id,
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