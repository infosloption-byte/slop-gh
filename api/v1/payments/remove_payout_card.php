<?php
// ===== FILE 2: api/v1/payments/remove_payout_card.php =====
require_once __DIR__ . '/../../../config/api_secure_bootstrap.php';

try {
    $data = json_decode(file_get_contents("php://input"));
    $card_id = $data->card_id ?? 0;

    if (!$card_id) {
        throw new Exception("Card ID is required.", 400);
    }

    $conn->autocommit(FALSE);

    // Get card details
    $stmt_get = $conn->prepare("SELECT stripe_card_id FROM payout_cards WHERE id = ? AND user_id = ? AND is_active = TRUE");
    $stmt_get->bind_param("ii", $card_id, $user_id);
    $stmt_get->execute();
    $card = $stmt_get->get_result()->fetch_assoc();
    $stmt_get->close();

    if (!$card) {
        throw new Exception("Card not found.", 404);
    }

    // Check if card is being used in pending withdrawals
    $stmt_pending = $conn->prepare("SELECT COUNT(*) as count FROM withdrawal_requests WHERE payout_card_id = ? AND status = 'PENDING'");
    $stmt_pending->bind_param("i", $card_id);
    $stmt_pending->execute();
    $pending_count = $stmt_pending->get_result()->fetch_assoc()['count'];
    $stmt_pending->close();

    if ($pending_count > 0) {
        throw new Exception("Cannot remove card with pending withdrawal requests.", 400);
    }

    // Remove from Stripe (optional - can keep for history)
    try {
        list($customer_id, $stripe_card_id) = explode(':', $card['stripe_card_id']);
        $stripeService->removeCard($customer_id, $stripe_card_id);
    } catch (Exception $e) {
        // Log but don't fail if Stripe removal fails
        $log->warning('Stripe card removal failed', ['error' => $e->getMessage()]);
    }

    // Soft delete from database
    $stmt_delete = $conn->prepare("UPDATE payout_cards SET is_active = FALSE WHERE id = ?");
    $stmt_delete->bind_param("i", $card_id);
    $stmt_delete->execute();
    $stmt_delete->close();

    // If this was the default card, set another as default
    $stmt_check_default = $conn->prepare("SELECT COUNT(*) as count FROM payout_cards WHERE user_id = ? AND is_default = TRUE AND is_active = TRUE");
    $stmt_check_default->bind_param("i", $user_id);
    $stmt_check_default->execute();
    $has_default = $stmt_check_default->get_result()->fetch_assoc()['count'] > 0;
    $stmt_check_default->close();

    if (!$has_default) {
        // Set the most recent card as default
        $stmt_new_default = $conn->prepare("UPDATE payout_cards SET is_default = TRUE WHERE user_id = ? AND is_active = TRUE ORDER BY created_at DESC LIMIT 1");
        $stmt_new_default->bind_param("i", $user_id);
        $stmt_new_default->execute();
        $stmt_new_default->close();
    }

    $conn->commit();

    $log->info('Card removed', ['user_id' => $user_id, 'card_id' => $card_id]);

    http_response_code(200);
    echo json_encode(["message" => "Card removed successfully."]);

} catch (Exception $e) {
    if ($conn) $conn->rollback();
    
    $log->error('Remove card failed', [
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