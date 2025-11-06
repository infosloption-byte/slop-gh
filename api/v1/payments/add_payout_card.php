<?php
// api/v1/payments/add_payout_card.php
require_once __DIR__ . '/../../../config/api_secure_bootstrap.php';

try {
    $data = json_decode(file_get_contents("php://input"));
    
    $card_token = $data->card_token ?? ''; // From Stripe.js (tok_xxx)
    $card_holder_name = $data->card_holder_name ?? '';

    if (empty($card_token)) {
        throw new Exception("Card token is required.", 400);
    }

    if (empty($card_holder_name)) {
        throw new Exception("Cardholder name is required.", 400);
    }

    // Get user details
    $stmt_user = $conn->prepare("SELECT email FROM users WHERE id = ?");
    $stmt_user->bind_param("i", $user_id);
    $stmt_user->execute();
    $user = $stmt_user->get_result()->fetch_assoc();
    $stmt_user->close();

    if (!$user) {
        throw new Exception("User not found.", 404);
    }

    $log->info('Adding payout card', [
        'user_id' => $user_id,
        'email' => $user['email']
    ]);

    // Add card via Stripe
    $card_details = $stripeService->addPayoutCard(
        $card_token, 
        $user['email'],
        [
            'user_id' => $user_id,
            'cardholder_name' => $card_holder_name
        ]
    );

    $conn->autocommit(FALSE);

    try {
        // Check if user already has cards, set others to non-default
        $stmt_check = $conn->prepare("SELECT COUNT(*) as count FROM payout_cards WHERE user_id = ?");
        $stmt_check->bind_param("i", $user_id);
        $stmt_check->execute();
        $has_existing = $stmt_check->get_result()->fetch_assoc()['count'] > 0;
        $stmt_check->close();

        if ($has_existing) {
            // Set all existing cards to non-default
            $stmt_update = $conn->prepare("UPDATE payout_cards SET is_default = FALSE WHERE user_id = ?");
            $stmt_update->bind_param("i", $user_id);
            $stmt_update->execute();
            $stmt_update->close();
        }

        // Store card in database
        $stmt_insert = $conn->prepare(
            "INSERT INTO payout_cards 
            (user_id, stripe_card_id, card_brand, card_last4, card_holder_name, card_country, is_default) 
            VALUES (?, ?, ?, ?, ?, ?, TRUE)"
        );
        
        $stripe_card_id = $card_details['customer_id'] . ':' . $card_details['card_id']; // Store both for retrieval
        
        $stmt_insert->bind_param(
            "isssss",
            $user_id,
            $stripe_card_id,
            $card_details['brand'],
            $card_details['last4'],
            $card_holder_name,
            $card_details['country']
        );

        if (!$stmt_insert->execute()) {
            throw new Exception("Failed to save card details.");
        }

        $card_id = $conn->insert_id;
        $stmt_insert->close();

        $conn->commit();

        $log->info('Payout card added successfully', [
            'user_id' => $user_id,
            'card_id' => $card_id,
            'brand' => $card_details['brand'],
            'last4' => $card_details['last4']
        ]);

        http_response_code(201);
        echo json_encode([
            "message" => "Payout card added successfully.",
            "card" => [
                "id" => $card_id,
                "brand" => $card_details['brand'],
                "last4" => $card_details['last4'],
                "country" => $card_details['country'],
                "holder_name" => $card_holder_name,
                "is_default" => true
            ]
        ]);

    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }

} catch (Exception $e) {
    $log->error('Add payout card failed', [
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