<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

/**
 * IMPROVED USER SELF-FIX ENDPOINT
 * api/v1/payments/stripe/capabilities/check_and_fix.php
 * 
 * Allows users to check and auto-fix their own Connect account capabilities
 */
require_once __DIR__ . '/../../../config/api_secure_bootstrap.php';

try {
    // Get user's Connect account
    $stmt = $conn->prepare("SELECT stripe_connect_id, email FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$user || empty($user['stripe_connect_id'])) {
        throw new Exception("No Connect account found. Please complete identity verification first.", 404);
    }

    $connect_account_id = $user['stripe_connect_id'];

    $log->info('User checking/fixing capabilities', [
        'user_id' => $user_id,
        'connect_account' => $connect_account_id
    ]);

    // Get account from Stripe
    try {
        $account = \Stripe\Account::retrieve($connect_account_id);
    } catch (\Stripe\Exception\ApiErrorException $e) {
        $log->error('Failed to retrieve Connect account', [
            'user_id' => $user_id,
            'error' => $e->getMessage()
        ]);
        throw new Exception("Unable to retrieve your Stripe account. Please contact support.", 500);
    }

    $response = [
        'connect_account_id' => $connect_account_id,
        'before' => [
            'card_payments' => $account->capabilities->card_payments ?? 'not_requested',
            'transfers' => $account->capabilities->transfers ?? 'not_requested',
            'payouts_enabled' => $account->payouts_enabled ?? false,
            'charges_enabled' => $account->charges_enabled ?? false,
            'details_submitted' => $account->details_submitted ?? false
        ],
        'action_taken' => 'none',
        'after' => null,
        'is_ready' => false,
        'message' => '',
        'requirements' => []
    ];

    // Check if there are outstanding requirements
    if ($account->requirements && !empty($account->requirements->currently_due)) {
        $response['requirements'] = $account->requirements->currently_due;
        $response['message'] = "Your account needs additional information: " . 
                               implode(', ', $account->requirements->currently_due) . 
                               ". Please complete the onboarding process.";
        $response['is_ready'] = false;
        
        http_response_code(200);
        echo json_encode($response);
        exit;
    }

    // Get capability statuses
    $card_payments_status = $account->capabilities->card_payments ?? 'inactive';
    $transfers_status = $account->capabilities->transfers ?? 'inactive';

    // Check if capabilities need fixing
    $needs_card_payments = !in_array($card_payments_status, ['active', 'pending']);
    $needs_transfers = !in_array($transfers_status, ['active', 'pending']);
    $needs_fix = $needs_card_payments || $needs_transfers;

    if ($needs_fix) {
        $log->info('Attempting to fix capabilities', [
            'user_id' => $user_id,
            'connect_account' => $connect_account_id,
            'card_payments' => $card_payments_status,
            'transfers' => $transfers_status
        ]);

        try {
            // Update capabilities
            $updated_account = \Stripe\Account::update($connect_account_id, [
                'capabilities' => [
                    'card_payments' => ['requested' => true],
                    'transfers' => ['requested' => true],
                ]
            ]);
            
            $response['action_taken'] = 'updated';
            $response['after'] = [
                'card_payments' => $updated_account->capabilities->card_payments ?? 'inactive',
                'transfers' => $updated_account->capabilities->transfers ?? 'inactive',
                'payouts_enabled' => $updated_account->payouts_enabled ?? false,
                'charges_enabled' => $updated_account->charges_enabled ?? false,
                'details_submitted' => $updated_account->details_submitted ?? false
            ];
            
            // Check if now ready
            $card_payments_ok = in_array($response['after']['card_payments'], ['active', 'pending']);
            $transfers_ok = in_array($response['after']['transfers'], ['active', 'pending']);
            $response['is_ready'] = $card_payments_ok && $transfers_ok;
            
            if ($response['after']['card_payments'] === 'active' && 
                $response['after']['transfers'] === 'active') {
                $response['message'] = '✅ Capabilities fixed successfully! Your account is ready for payouts.';
            } else if ($response['after']['card_payments'] === 'pending' || 
                      $response['after']['transfers'] === 'pending') {
                $response['message'] = '⏳ Capabilities requested. Your account is pending verification - this usually completes within a few minutes. You can now add payout cards.';
                $response['is_ready'] = true; // Allow adding cards even when pending
            } else {
                // Check for new requirements
                if ($updated_account->requirements && !empty($updated_account->requirements->currently_due)) {
                    $response['requirements'] = $updated_account->requirements->currently_due;
                    $response['message'] = "Additional information required: " . 
                                          implode(', ', $updated_account->requirements->currently_due) . 
                                          ". Please complete the onboarding process.";
                } else {
                    $response['message'] = 'Capabilities updated, but additional verification may be required. Please complete the onboarding process.';
                }
            }
            
            // Update database
            $stmt_update = $conn->prepare("
                UPDATE users 
                SET connect_payouts_enabled = ?,
                    connect_charges_enabled = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");
            
            $payouts_enabled = ($updated_account->payouts_enabled ?? false) ? 1 : 0;
            $charges_enabled = ($updated_account->charges_enabled ?? false) ? 1 : 0;
            
            $stmt_update->bind_param("iii", $payouts_enabled, $charges_enabled, $user_id);
            $stmt_update->execute();
            $stmt_update->close();
            
            $log->info('Capabilities fixed for user', [
                'user_id' => $user_id,
                'is_ready' => $response['is_ready'],
                'after' => $response['after']
            ]);
            
        } catch (\Stripe\Exception\ApiErrorException $fixError) {
            $log->error('Failed to fix capabilities', [
                'user_id' => $user_id,
                'error' => $fixError->getMessage()
            ]);
            throw new Exception("Failed to update capabilities: " . $fixError->getMessage(), 500);
        }
        
    } else {
        // Already active or pending
        $card_payments_ok = in_array($card_payments_status, ['active', 'pending']);
        $transfers_ok = in_array($transfers_status, ['active', 'pending']);
        $response['is_ready'] = $card_payments_ok && $transfers_ok;
        
        if ($card_payments_status === 'active' && $transfers_status === 'active') {
            $response['message'] = '✅ Your account capabilities are already active and ready!';
            $response['action_taken'] = 'already_active';
        } else if ($card_payments_status === 'pending' || $transfers_status === 'pending') {
            $response['message'] = '⏳ Your account is pending verification. This usually completes within a few minutes. You can add payout cards now.';
            $response['action_taken'] = 'already_pending';
            $response['is_ready'] = true; // Allow adding cards when pending
        } else {
            $response['message'] = '⚠️ Your account capabilities are in an unexpected state. Please complete onboarding or contact support.';
            $response['is_ready'] = false;
        }
    }

    http_response_code(200);
    echo json_encode($response);

} catch (Exception $e) {
    $log->error('Check/fix capabilities failed', [
        'user_id' => $user_id ?? 0,
        'error' => $e->getMessage()
    ]);

    $code = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 500;
    http_response_code($code);
    echo json_encode([
        "message" => $e->getMessage(),
        "error" => true
    ]);
} finally {
    if ($conn) {
        $conn->close();
    }
}
?>