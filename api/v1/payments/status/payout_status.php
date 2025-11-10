<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

// api/v1/payments/status/payout_status.php
require_once __DIR__ . '/../../../config/api_secure_bootstrap.php';

try {
    // Get user details including Connect account
    $stmt_user = $conn->prepare("
        SELECT email, stripe_connect_id, connect_payouts_enabled, connect_charges_enabled 
        FROM users 
        WHERE id = ?
    ");
    $stmt_user->bind_param("i", $user_id);
    $stmt_user->execute();
    $user = $stmt_user->get_result()->fetch_assoc();
    $stmt_user->close();

    if (!$user) {
        throw new Exception("User not found.", 404);
    }

    $has_connect_account = !empty($user['stripe_connect_id']);
    $connect_verified = false;
    $default_card = null;

    // If Connect account exists, check its status via Stripe
    if ($has_connect_account) {
        try {
            // Get account status from Stripe
            $account_status = $stripeService->getConnectAccountStatus($user['stripe_connect_id']);
            
            // Check if account is verified for payouts
            $connect_verified = $account_status['payouts_enabled'] && $account_status['charges_enabled'];
            
            // Try to get default external account if exists
            if ($connect_verified) {
                try {
                    $account = \Stripe\Account::retrieve($user['stripe_connect_id']);
                    
                    if ($account->external_accounts && $account->external_accounts->total_count > 0) {
                        $default_external = $account->external_accounts->data[0];
                        $default_card = [
                            'id' => $default_external->id,
                            'last4' => $default_external->last4,
                            'brand' => $default_external->brand ?? 'Card',
                            'holder_name' => $default_external->account_holder_name ?? 'Cardholder'
                        ];
                    }
                } catch (Exception $e) {
                    $log->debug('Could not retrieve external accounts', [
                        'user_id' => $user_id,
                        'error' => $e->getMessage()
                    ]);
                }
            }
            
        } catch (Exception $e) {
            $log->warning('Connect account verification failed', [
                'user_id' => $user_id,
                'connect_account' => $user['stripe_connect_id'],
                'error' => $e->getMessage()
            ]);
            // If account doesn't exist in Stripe, treat as no account
            $has_connect_account = false;
        }
    }

    // Check if user has cards in database
    $stmt_check = $conn->prepare("
        SELECT COUNT(*) as count 
        FROM payout_cards 
        WHERE user_id = ? AND is_active = TRUE
    ");
    $stmt_check->bind_param("i", $user_id);
    $stmt_check->execute();
    $has_payout_card = $stmt_check->get_result()->fetch_assoc()['count'] > 0;
    $stmt_check->close();

    // Get default card from database if not found in Stripe
    if (!$default_card && $has_payout_card) {
        $stmt_card = $conn->prepare("
            SELECT id, card_last4, card_brand, card_holder_name 
            FROM payout_cards 
            WHERE user_id = ? AND is_default = TRUE AND is_active = TRUE
            LIMIT 1
        ");
        $stmt_card->bind_param("i", $user_id);
        $stmt_card->execute();
        $card = $stmt_card->get_result()->fetch_assoc();
        $stmt_card->close();
        
        if ($card) {
            $default_card = [
                'id' => $card['id'],
                'last4' => $card['card_last4'],
                'brand' => $card['card_brand'],
                'holder_name' => $card['card_holder_name']
            ];
        }
    }

    // Determine if payouts are fully enabled
    $payout_enabled = $has_connect_account && $connect_verified && $has_payout_card;

    $log->info('Payout status checked', [
        'user_id' => $user_id,
        'payout_enabled' => $payout_enabled,
        'has_connect_account' => $has_connect_account,
        'connect_verified' => $connect_verified,
        'has_payout_card' => $has_payout_card
    ]);

    http_response_code(200);
    echo json_encode([
        "payout_enabled" => $payout_enabled,
        "requirements" => [
            "has_connect_account" => $has_connect_account,
            "connect_verified" => $connect_verified,
            "has_payout_card" => $has_payout_card
        ],
        "default_card" => $default_card,
        "stripe_connect_id" => $user['stripe_connect_id']
    ]);

} catch (Exception $e) {
    $log->error('Check payout status failed', [
        'user_id' => $user_id ?? 0,
        'error' => $e->getMessage()
    ]);

    $code = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 500;
    http_response_code($code);
    echo json_encode([
        "payout_enabled" => false,
        "requirements" => [
            "has_connect_account" => false,
            "connect_verified" => false,
            "has_payout_card" => false
        ],
        "message" => $e->getMessage()
    ]);
} finally {
    if ($conn) {
        $conn->close();
    }
}
?>