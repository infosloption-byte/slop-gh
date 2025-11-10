<?php
// api/v1/admin/process_withdrawal.php (REFACTORED - Service Layer Architecture)
require_once __DIR__ . '/../../../config/api_secure_bootstrap.php';
require_once __DIR__ . '/../services/PaymentProviderFactory.php';
require_once __DIR__ . '/../../../config/WithdrawalConfig.php';

try {
    if (!is_admin($conn, $user_id)) {
        throw new Exception("Forbidden. Admin privileges required.", 403);
    }

    $data = json_decode(file_get_contents("php://input"));
    $request_id = $data->request_id ?? 0;
    $new_status = $data->new_status ?? ''; // 'APPROVED' or 'REJECTED'
    $admin_notes = $data->admin_notes ?? 'Processed by admin';

    if (!$request_id || !in_array($new_status, ['APPROVED', 'REJECTED'])) {
        throw new Exception("Invalid input data.", 400);
    }

    $conn->autocommit(FALSE);

    // --- 1. GET REQUEST WITH FULL DETAILS AND LOCK IT ---
    $query_req = "SELECT
                    wr.*,
                    w.balance as current_balance,
                    pm.id as payout_method_id, pm.method_type, pm.account_identifier,
                    u.stripe_connect_id,
                    pc.external_account_id
                  FROM withdrawal_requests wr
                  JOIN wallets w ON wr.wallet_id = w.id
                  LEFT JOIN user_payout_methods pm ON wr.user_payout_method_id = pm.id
                  LEFT JOIN users u ON pm.user_id = u.id
                  LEFT JOIN payout_cards pc ON pm.payout_card_id = pc.id
                  WHERE wr.id = ? AND wr.status = 'PENDING' FOR UPDATE";

    $stmt_req = $conn->prepare($query_req);
    $stmt_req->bind_param("i", $request_id);
    $stmt_req->execute();
    $request = $stmt_req->get_result()->fetch_assoc();
    $stmt_req->close();

    if (!$request) {
        throw new Exception("Withdrawal request not found or already processed.", 404);
    }

    $amount_cents = (int)$request['amount'];
    $amount_dollars = $amount_cents / 100.0;
    $gateway_transaction_id = null;
    $failure_reason = null;

    if ($new_status === 'APPROVED') {

        // ============ APPROVAL FLOW ============

        // Check current balance
        if ($request['current_balance'] < $amount_cents) {
            throw new Exception("Insufficient balance. User's balance is now lower than requested amount.", 400);
        }

        // Deduct from wallet
        $deduct_query = "UPDATE wallets SET balance = balance - ? WHERE id = ?";
        $stmt_deduct = $conn->prepare($deduct_query);
        $stmt_deduct->bind_param("ii", $amount_cents, $request['wallet_id']);
        if (!$stmt_deduct->execute()) {
            throw new Exception("Failed to deduct from wallet.");
        }
        $stmt_deduct->close();

        // Initialize Payment Provider Factory
        $paymentFactory = new PaymentProviderFactory($log);

        // Process payout
        try {
            if ($request['method_type'] === 'stripe_card') {
                $gateway_transaction_id = $paymentFactory->processPayout(
                    'stripe_card',
                    $amount_dollars,
                    null,
                    $stripeService,
                    [
                        'connect_account_id' => $request['stripe_connect_id'],
                        'external_account_id' => $request['external_account_id']
                    ],
                    ['req_id' => $request_id, 'admin_id' => $user_id]
                );
            } else if (!empty($request['method_type'])) {
                // PayPal, Binance, Skrill
                $gateway_transaction_id = $paymentFactory->processPayout(
                    $request['method_type'],
                    $amount_dollars,
                    $request['account_identifier'],
                    null,
                    [],
                    ['req_id' => $request_id, 'admin_id' => $user_id]
                );
            } else {
                // Legacy manual method (no payout_method_id)
                if ($request['withdrawal_method'] === 'manual') {
                    $gateway_transaction_id = 'MANUAL_BY_ADMIN_' . $user_id;
                    $log->info('Admin processed old manual withdrawal', [
                        'req_id' => $request_id,
                        'admin_id' => $user_id
                    ]);
                } else {
                    throw new Exception("Unknown or unsupported payout method type.");
                }
            }

        } catch (Exception $e) {
            $failure_reason = "Payout API Error: " . $e->getMessage();
            $log->error('Admin Payout Process Failed', [
                'req_id' => $request_id,
                'error' => $failure_reason
            ]);

            // Rollback: Refund the wallet
            $refund_query = "UPDATE wallets SET balance = balance + ? WHERE id = ?";
            $stmt_refund = $conn->prepare($refund_query);
            $stmt_refund->bind_param("ii", $amount_cents, $request['wallet_id']);
            $stmt_refund->execute();
            $stmt_refund->close();

            throw new Exception($failure_reason, 500);
        }

        // Update withdrawal request
        $query_update_req = "UPDATE withdrawal_requests
                             SET status = 'APPROVED',
                                 gateway_transaction_id = ?,
                                 gateway_status = 'payout_sent',
                                 processed_at = NOW(),
                                 processed_by = ?,
                                 admin_notes = ?
                             WHERE id = ?";

        $stmt_update_req = $conn->prepare($query_update_req);
        $stmt_update_req->bind_param("sisi", $gateway_transaction_id, $user_id, $admin_notes, $request_id);
        $stmt_update_req->execute();
        $stmt_update_req->close();

        $notification_message = "Your withdrawal of $" . number_format($amount_dollars, 2) . " has been approved and processed.";

    } else {

        // ============ REJECTION FLOW ============

        $query_update_req = "UPDATE withdrawal_requests
                             SET status = 'REJECTED',
                                 processed_at = NOW(),
                                 processed_by = ?,
                                 admin_notes = ?,
                                 failure_reason = ?
                             WHERE id = ?";

        $stmt_update_req = $conn->prepare($query_update_req);
        $stmt_update_req->bind_param("issi", $user_id, $admin_notes, $admin_notes, $request_id);
        $stmt_update_req->execute();
        $stmt_update_req->close();

        $log->info('Withdrawal rejected by admin', [
            'req_id' => $request_id,
            'admin_id' => $user_id,
            'reason' => $admin_notes
        ]);

        $notification_message = "Your withdrawal request of $" . number_format($amount_dollars, 2) . " has been rejected. Reason: {$admin_notes}";
    }

    // Update transaction ledger
    $transaction_status = ($new_status === 'APPROVED') ? 'COMPLETED' : 'REJECTED';
    $query_update_trans = "UPDATE transactions
                           SET status = ?,
                               gateway_transaction_id = ?,
                               updated_at = NOW()
                           WHERE type = 'WITHDRAWAL'
                           AND reference_id = ?
                           AND status = 'PENDING'";
    $stmt_update_trans = $conn->prepare($query_update_trans);
    $stmt_update_trans->bind_param("ssi", $transaction_status, $gateway_transaction_id, $request_id);
    $stmt_update_trans->execute();
    $stmt_update_trans->close();

    // Send notification
    if (($new_status === 'APPROVED' && WithdrawalConfig::NOTIFY_USER_ON_APPROVED) ||
        ($new_status === 'REJECTED' && WithdrawalConfig::NOTIFY_USER_ON_REJECTED)) {
        send_notification($request['user_id'], 'withdrawal-update', ['message' => $notification_message], null);
    }

    // Commit
    $conn->commit();

    http_response_code(200);
    echo json_encode([
        "message" => "Withdrawal request has been " . strtolower($new_status) . ".",
        "request_id" => $request_id,
        "status" => $new_status
    ]);

} catch (Exception $e) {
    if ($conn) $conn->rollback();

    $log->error('Admin withdrawal processing failed', [
        'req_id' => $request_id ?? 0,
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
