<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

// api/v1/payments/stripe/cards/add_payout_card.php - UPDATED VERSION
require_once __DIR__ . '/../../../config/api_secure_bootstrap.php';

try {
    $data = json_decode(file_get_contents("php://input"));
    
    $card_token = $data->card_token ?? '';
    $card_holder_name = $data->card_holder_name ?? '';

    if (empty($card_token)) {
        throw new Exception("Card token is required.", 400);
    }

    if (empty($card_holder_name)) {
        throw new Exception("Cardholder name is required.", 400);
    }

    $stmt_user = $conn->prepare("SELECT email, stripe_connect_id FROM users WHERE id = ?");
    $stmt_user->bind_param("i", $user_id);
    $stmt_user->execute();
    $user = $stmt_user->get_result()->fetch_assoc();
    $stmt_user->close();

    if (!$user) {
        throw new Exception("User not found.", 404);
    }

    if (empty($user['stripe_connect_id'])) {
        throw new Exception("Please complete identity verification first. You need a Stripe Connect account to add payout cards.", 400);
    }

    try {
        $account = \Stripe\Account::retrieve($user['stripe_connect_id']);
        $card_payments_status = $account->capabilities->card_payments ?? 'inactive';
        $transfers_status = $account->capabilities->transfers ?? 'inactive';
        
        if ($transfers_status === 'inactive') {
            $log->info('Transfers capability inactive, attempting to update', ['user_id' => $user_id]);
            $account = \Stripe\Account::update($user['stripe_connect_id'], [
                'capabilities' => ['transfers' => ['requested' => true]],
            ]);
            $transfers_status = $account->capabilities->transfers ?? 'inactive';
        }
        
        $transfers_ok = in_array($transfers_status, ['active', 'pending']);
        
        if (!$transfers_ok) {
            if ($account->requirements && !empty($account->requirements->currently_due)) {
                throw new Exception("Your account needs additional verification. Required fields: " . implode(', ', $account->requirements->currently_due) . ". Please complete onboarding first.", 400);
            }
            throw new Exception("Your account verification is incomplete. The 'transfers' capability is: " . $transfers_status, 400);
        }
        
    } catch (\Stripe\Exception\ApiErrorException $stripeError) {
        $log->error('Stripe API error checking capabilities', ['user_id' => $user_id, 'error' => $stripeError->getMessage()]);
        throw new Exception("Unable to verify your Stripe account: " . $stripeError->getMessage(), 500);
    }

    $card_details = $stripeService->addPayoutCard(
        $card_token, 
        $user['stripe_connect_id'],
        $card_holder_name,
        ['user_id' => $user_id, 'email' => $user['email']]
    );

    $conn->autocommit(FALSE);

    try {
        // Check if user already has cards
        $stmt_check = $conn->prepare("SELECT COUNT(*) as count FROM payout_cards WHERE user_id = ? AND is_active = TRUE");
        $stmt_check->bind_param("i", $user_id);
        $stmt_check->execute();
        $has_existing = $stmt_check->get_result()->fetch_assoc()['count'] > 0;
        $stmt_check->close();
        
        $is_first_method = !$has_existing;

        // Store card in original table
        $stmt_insert = $conn->prepare(
            "INSERT INTO payout_cards 
            (user_id, stripe_connect_account_id, stripe_card_id, external_account_id, 
             card_brand, card_last4, card_holder_name, card_country, is_default) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)" // Set default based on if it's the first
        );
        
        $stripe_card_id_legacy = $card_details['connect_account_id'] . ':' . $card_details['external_account_id'];
        
        $stmt_insert->bind_param(
            "isssssssi",
            $user_id,
            $card_details['connect_account_id'],
            $stripe_card_id_legacy,
            $card_details['external_account_id'],
            $card_details['brand'],
            $card_details['last4'],
            $card_holder_name,
            $card_details['country'],
            $is_first_method // Set as default only if it's the first card
        );

        if (!$stmt_insert->execute()) {
            throw new Exception("Failed to save card details.");
        }
        $card_id = $conn->insert_id; // This is the payout_card_id
        $stmt_insert->close();

        // --- NEW LOGIC: ADD TO HUB TABLE ---
        $display_name = $card_details['brand'] . ' **** ' . $card_details['last4'];
        
        if ($is_first_method) {
            // This is the first method, so no need to reset others.
        } else {
            // Not the first method, so make sure no other methods are default.
            $stmt_reset_default = $conn->prepare("UPDATE user_payout_methods SET is_default = FALSE WHERE user_id = ?");
            $stmt_reset_default->bind_param("i", $user_id);
            $stmt_reset_default->execute();
            $stmt_reset_default->close();
        }
        
        // Add to the connection hub
        $stmt_hub = $conn->prepare(
            "INSERT INTO user_payout_methods 
             (user_id, method_type, account_identifier, display_name, payout_card_id, is_default) 
             VALUES (?, 'stripe_card', ?, ?, ?, ?)"
        );
        // Set as default if first, otherwise don't (new cards are not default)
        $stmt_hub->bind_param("issii", $user_id, $card_details['external_account_id'], $display_name, $card_id, $is_first_method);
        $stmt_hub->execute();
        $stmt_hub->close();
        // --- END NEW LOGIC ---

        $conn->commit();

        $log->info('Payout card added successfully to both tables', [
            'user_id' => $user_id,
            'card_id' => $card_id,
            'connect_account' => $card_details['connect_account_id'],
            'external_account' => $card_details['external_account_id']
        ]);

        http_response_code(201);
        echo json_encode([
            "message" => "Payout card added successfully.",
            "card" => [
                "id" => $card_id,
                "brand" => $card_details['brand'],
                "last4" => $card_details['last4'],
                "holder_name" => $card_holder_name,
                "is_default" => (bool)$is_first_method
            ]
        ]);

    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }

} catch (Exception $e) {
    $log->error('Add payout card failed', [
        'user_id' => $user_id ?? 0,
        'error' => $e->getMessage(),
        'code' => $e->getCode()
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