<?php
// api/v1/payments/stripe/cards/remove_card.php
require_once __DIR__ . '/../../../config/api_secure_bootstrap.php';

try {
    $data = json_decode(file_get_contents("php://input"));
    $card_id = $data->card_id ?? 0; // This is payout_card_id

    if (!$card_id) {
        throw new Exception("Card ID is required.", 400);
    }

    $conn->autocommit(FALSE);

    // Get card details including Connect info
    $stmt_get = $conn->prepare(
        "SELECT stripe_connect_account_id, external_account_id, is_default 
         FROM payout_cards 
         WHERE id = ? AND user_id = ? AND is_active = TRUE"
    );
    $stmt_get->bind_param("ii", $card_id, $user_id);
    $stmt_get->execute();
    $card = $stmt_get->get_result()->fetch_assoc();
    $stmt_get->close();

    if (!$card) {
        throw new Exception("Card not found.", 404);
    }

    // Check if card is being used in pending withdrawals
    $stmt_pending = $conn->prepare(
        "SELECT COUNT(*) as count 
         FROM withdrawal_requests 
         WHERE payout_card_id = ? AND status = 'PENDING'"
    );
    $stmt_pending->bind_param("i", $card_id);
    $stmt_pending->execute();
    $pending_count = $stmt_pending->get_result()->fetch_assoc()['count'];
    $stmt_pending->close();

    if ($pending_count > 0) {
        throw new Exception("Cannot remove card with pending withdrawal requests.", 400);
    }

    // Remove from Stripe Connect account
    try {
        if (!empty($card['stripe_connect_account_id']) && !empty($card['external_account_id'])) {
            $stripeService->removeExternalAccount(
                $card['stripe_connect_account_id'], 
                $card['external_account_id']
            );
        }
    } catch (Exception $e) {
        $log->warning('Stripe external account removal failed, proceeding with DB removal', [
            'card_id' => $card_id,
            'error' => $e->getMessage()
        ]);
    }

    // Soft delete from original table
    $stmt_delete = $conn->prepare("UPDATE payout_cards SET is_active = FALSE WHERE id = ?");
    $stmt_delete->bind_param("i", $card_id);
    $stmt_delete->execute();
    $stmt_delete->close();

    // --- NEW LOGIC: Remove from hub table ---
    $stmt_hub_delete = $conn->prepare("DELETE FROM user_payout_methods WHERE user_id = ? AND payout_card_id = ?");
    $stmt_hub_delete->bind_param("ii", $user_id, $card_id);
    $stmt_hub_delete->execute();
    $stmt_hub_delete->close();
    // --- END NEW LOGIC ---

    // If this was the default card, set another as default
    if ($card['is_default']) {
        // Find the most recent card in the original table
        $stmt_new_default_card = $conn->prepare(
            "SELECT id FROM payout_cards 
             WHERE user_id = ? AND is_active = TRUE 
             ORDER BY created_at DESC LIMIT 1"
        );
        $stmt_new_default_card->bind_param("i", $user_id);
        $stmt_new_default_card->execute();
        $new_default_card = $stmt_new_default_card->get_result()->fetch_assoc();
        $stmt_new_default_card->close();

        if ($new_default_card) {
            $new_default_card_id = $new_default_card['id'];
            
            // Set in original table
            $stmt_update_card = $conn->prepare("UPDATE payout_cards SET is_default = TRUE WHERE id = ?");
            $stmt_update_card->bind_param("i", $new_default_card_id);
            $stmt_update_card->execute();
            $stmt_update_card->close();
            
            // --- NEW LOGIC: Set in hub table ---
            $stmt_update_hub = $conn->prepare("UPDATE user_payout_methods SET is_default = TRUE WHERE payout_card_id = ? AND user_id = ?");
            $stmt_update_hub->bind_param("ii", $new_default_card_id, $user_id);
            $stmt_update_hub->execute();
            $stmt_update_hub->close();
            // --- END NEW LOGIC ---
        }
    }

    $conn->commit();

    $log->info('Card removed from all tables', ['user_id' => $user_id, 'card_id' => $card_id]);

    http_response_code(200);
    echo json_encode(["message" => "Card removed successfully."]);

} catch (Exception $e) {
    if ($conn) $conn->rollback();
    
    $log->error('Remove card failed', [
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