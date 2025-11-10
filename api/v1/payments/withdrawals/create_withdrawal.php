<?php
// api/v1/payments/withdrawals/create_withdrawal.php (REFACTORED - Service Layer Architecture)
require_once __DIR__ . '/../../../config/api_secure_bootstrap.php';
require_once __DIR__ . '/../../services/PaymentProviderFactory.php';
require_once __DIR__ . '/../../../config/WithdrawalConfig.php';

try {
    $data = json_decode(file_get_contents("php://input"));
    $amount_dollars = (float)($data->amount ?? 0);
    $payout_method_id = (int)($data->payout_method_id ?? 0);

    // --- 1. VALIDATE INPUT ---
    $validation = WithdrawalConfig::validateAmount($amount_dollars);
    if (!$validation['valid']) {
        throw new Exception(implode(' ', $validation['errors']), 400);
    }

    if (empty($payout_method_id)) {
        throw new Exception("A payout method must be selected.", 400);
    }

    // --- 2. CHECK DAILY LIMIT ---
    $daily_limit_check = WithdrawalConfig::checkDailyLimit($conn, $user_id, $amount_dollars);
    if (!$daily_limit_check['allowed']) {
        throw new Exception(
            "Daily withdrawal limit exceeded. You have withdrawn $" . number_format($daily_limit_check['current_total'], 2) .
            " today. Maximum daily limit is $" . number_format($daily_limit_check['limit'], 2) . ".",
            400
        );
    }

    $amount_cents = (int)($amount_dollars * 100);
    $auto_approve_limit_cents = (int)(WithdrawalConfig::getAutoApproveLimit() * 100);

    $conn->autocommit(FALSE);

    // --- 3. GET WALLET AND LOCK IT ---
    $query_wallet = "SELECT id, balance FROM wallets WHERE user_id = ? AND type = 'real' FOR UPDATE";
    $stmt_wallet = $conn->prepare($query_wallet);
    $stmt_wallet->bind_param("i", $user_id);
    $stmt_wallet->execute();
    $wallet = $stmt_wallet->get_result()->fetch_assoc();

    if (!$wallet || $wallet['balance'] < $amount_cents) {
        throw new Exception("Insufficient funds for withdrawal.", 400);
    }
    $wallet_id = $wallet['id'];
    $stmt_wallet->close();

    // --- 4. GET PAYOUT METHOD DETAILS ---
    $query_method = "SELECT
                        pm.id, pm.method_type, pm.account_identifier, pm.display_name, pm.payout_card_id,
                        u.stripe_connect_id, u.connect_payouts_enabled,
                        pc.external_account_id, pc.stripe_connect_account_id
                     FROM user_payout_methods pm
                     JOIN users u ON pm.user_id = u.id
                     LEFT JOIN payout_cards pc ON pm.payout_card_id = pc.id AND pc.user_id = u.id
                     WHERE pm.id = ? AND pm.user_id = ?";
    $stmt_method = $conn->prepare($query_method);
    $stmt_method->bind_param("ii", $payout_method_id, $user_id);
    $stmt_method->execute();
    $method = $stmt_method->get_result()->fetch_assoc();
    $stmt_method->close();

    if (!$method) {
        throw new Exception("Selected payout method not found.", 404);
    }

    $payout_card_id = $method['payout_card_id'] ?? null;
    $payout_method_db = $method['method_type'] === 'stripe_card' ? 'automated_card' : $method['method_type'];

    // --- 5. HYBRID LOGIC: MANUAL REVIEW vs AUTO-PROCESS ---
    if ($amount_cents > $auto_approve_limit_cents) {

        // ============ OVER LIMIT: PENDING FOR MANUAL REVIEW ============
        $admin_notes = "Pending manual review (amount over $" . WithdrawalConfig::getAutoApproveLimit() . " limit).";

        // Insert pending request
        $query_insert_req = "INSERT INTO withdrawal_requests
                             (user_id, wallet_id, user_payout_method_id, payout_card_id, amount, status, payout_method, withdrawal_method,
                              admin_notes, requested_amount_available)
                             VALUES (?, ?, ?, ?, ?, 'PENDING', ?, ?, ?, ?)";
        $stmt_req = $conn->prepare($query_insert_req);
        $stmt_req->bind_param("iiiissssi",
            $user_id, $wallet_id, $payout_method_id, $payout_card_id, $amount_cents,
            $payout_method_db, $method['method_type'], $admin_notes, $wallet['balance']
        );
        $stmt_req->execute();
        $request_id = $conn->insert_id;
        $stmt_req->close();

        // Log pending transaction
        $log_query = "INSERT INTO transactions
                      (user_id, wallet_id, type, amount, status, reference_id)
                      VALUES (?, ?, 'WITHDRAWAL', ?, 'PENDING', ?)";
        $stmt_log = $conn->prepare($log_query);
        $stmt_log->bind_param("iiii", $user_id, $wallet_id, $amount_cents, $request_id);
        $stmt_log->execute();
        $stmt_log->close();

        $conn->commit();

        // Send notification
        if (WithdrawalConfig::NOTIFY_USER_ON_PENDING) {
            $notification_message = "Your withdrawal request for $" . number_format($amount_dollars, 2) .
                                  " is pending review. This is standard for large amounts and will be processed within " .
                                  WithdrawalConfig::MANUAL_REVIEW_PROCESSING_SLA_HOURS . " hours.";
            send_notification($user_id, 'withdrawal-update', ['message' => $notification_message], null);
        }

        $log->info('Withdrawal request pending review', [
            'user' => $user_id,
            'req_id' => $request_id,
            'amount' => $amount_dollars
        ]);

        http_response_code(200);
        echo json_encode([
            "message" => "Withdrawal request submitted! As this is a large amount, it will be processed after a brief manual review (within " .
                        WithdrawalConfig::MANUAL_REVIEW_PROCESSING_SLA_HOURS . " hours).",
            "request_id" => $request_id,
            "status" => "PENDING"
        ]);

    } else {

        // ============ UNDER LIMIT: AUTO-PROCESS INSTANTLY ============
        $gateway_transaction_id = null;

        // Deduct from wallet
        $deduct_query = "UPDATE wallets SET balance = balance - ? WHERE id = ?";
        $stmt_deduct = $conn->prepare($deduct_query);
        $stmt_deduct->bind_param("ii", $amount_cents, $wallet_id);
        if (!$stmt_deduct->execute()) {
            throw new Exception("Failed to update wallet balance.");
        }
        $stmt_deduct->close();

        // Initialize Payment Provider Factory
        $paymentFactory = new PaymentProviderFactory($log);

        // Process payout using the factory
        try {
            if ($method['method_type'] === 'stripe_card') {
                // Stripe requires special handling
                $gateway_transaction_id = $paymentFactory->processPayout(
                    'stripe_card',
                    $amount_dollars,
                    null,
                    $stripeService,
                    [
                        'connect_account_id' => $method['stripe_connect_id'],
                        'external_account_id' => $method['external_account_id']
                    ],
                    ['user_id' => $user_id]
                );
            } else {
                // PayPal, Binance, Skrill
                $gateway_transaction_id = $paymentFactory->processPayout(
                    $method['method_type'],
                    $amount_dollars,
                    $method['account_identifier'],
                    null,
                    [],
                    ['user_id' => $user_id]
                );
            }

        } catch (Exception $api_error) {
            // API Payout Failed - log failure and rollback wallet
            $failure_reason = $api_error->getMessage();

            $query_fail_req = "INSERT INTO withdrawal_requests
                               (user_id, wallet_id, user_payout_method_id, payout_card_id, amount, status, payout_method, withdrawal_method,
                                failure_reason, processed_at, admin_notes, requested_amount_available)
                               VALUES (?, ?, ?, ?, ?, 'FAILED', ?, ?, ?, NOW(), ?, ?)";
            $stmt_fail = $conn->prepare($query_fail_req);
            $admin_notes = "Automated payout failed";
            $stmt_fail->bind_param("iiiisssssi",
                $user_id, $wallet_id, $payout_method_id, $payout_card_id, $amount_cents,
                $payout_method_db, $method['method_type'], $failure_reason, $admin_notes, $wallet['balance']
            );
            $stmt_fail->execute();
            $stmt_fail->close();

            throw $api_error; // Re-throw to trigger rollback
        }

        // Log successful withdrawal request
        $query_insert_req = "INSERT INTO withdrawal_requests
                             (user_id, wallet_id, user_payout_method_id, payout_card_id, amount, status, payout_method, withdrawal_method,
                              gateway_transaction_id, processed_at, admin_notes, requested_amount_available)
                             VALUES (?, ?, ?, ?, ?, 'APPROVED', ?, ?, ?, NOW(), ?, ?)";
        $stmt_req = $conn->prepare($query_insert_req);
        $admin_notes = "Instant payout processed automatically via " . $method['method_type'];
        $stmt_req->bind_param("iiiisssssi",
            $user_id, $wallet_id, $payout_method_id, $payout_card_id, $amount_cents,
            $payout_method_db, $method['method_type'], $gateway_transaction_id, $admin_notes, $wallet['balance']
        );
        $stmt_req->execute();
        $request_id = $conn->insert_id;
        $stmt_req->close();

        // Log successful transaction
        $log_query = "INSERT INTO transactions
                      (user_id, wallet_id, type, amount, status, reference_id, gateway_transaction_id)
                      VALUES (?, ?, 'WITHDRAWAL', ?, 'COMPLETED', ?, ?)";
        $stmt_log = $conn->prepare($log_query);
        $stmt_log->bind_param("iiiis", $user_id, $wallet_id, $amount_cents, $request_id, $gateway_transaction_id);
        $stmt_log->execute();
        $stmt_log->close();

        $conn->commit();

        $log->info('Instant withdrawal processed', [
            'user' => $user_id,
            'req_id' => $request_id,
            'amount' => $amount_dollars
        ]);

        // Send notification
        if (WithdrawalConfig::NOTIFY_USER_ON_APPROVED) {
            $notification_message = "Your withdrawal of $" . number_format($amount_dollars, 2) .
                                  " to " . $method['display_name'] . " has been successfully processed.";
            send_notification($user_id, 'withdrawal-update', ['message' => $notification_message], null);
        }

        http_response_code(200);
        echo json_encode([
            "message" => "Withdrawal processed successfully!",
            "request_id" => $request_id,
            "amount" => $amount_dollars,
            "method" => $method['display_name'],
            "gateway_transaction_id" => $gateway_transaction_id,
            "status" => "APPROVED"
        ]);
    }

} catch (Exception $e) {
    if ($conn) $conn->rollback();

    $log->error('Withdrawal request failed', [
        'user_id' => $user_id ?? 0,
        'amount' => $amount_dollars ?? 0,
        'method_id' => $payout_method_id ?? 0,
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
