<?php
// api/v1/admin/process_withdrawal.php (DUAL METHOD SUPPORT)
require_once __DIR__ . '/../../../config/api_secure_bootstrap.php';

try {
    if (!is_admin($conn, $user_id)) {
        throw new Exception("Forbidden. Admin privileges required.", 403);
    }

    $data = json_decode(file_get_contents("php://input"));
    $request_id = $data->request_id ?? 0;
    $new_status = $data->new_status ?? ''; // 'APPROVED' or 'REJECTED'
    $admin_notes = $data->admin_notes ?? '';
    $proof_image_url = $data->proof_image_url ?? null; // For manual withdrawals

    if (!$request_id || !in_array($new_status, ['APPROVED', 'REJECTED'])) {
        throw new Exception("Invalid input data.", 400);
    }
    
    $conn->autocommit(FALSE);

    // Get request with full details and lock it
    $query_req = "SELECT wr.*, u.email, u.stripe_connect_id, w.balance as current_balance,
                         pc.stripe_card_id, pc.card_brand, pc.card_last4, pc.card_holder_name
                  FROM withdrawal_requests wr
                  JOIN users u ON wr.user_id = u.id
                  JOIN wallets w ON wr.wallet_id = w.id
                  LEFT JOIN payout_cards pc ON wr.payout_card_id = pc.id
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
    $withdrawal_method = $request['withdrawal_method'];
    $gateway_transaction_id = null;
    $failure_reason = null;

    // ===== PROCESS BASED ON METHOD =====
    
    if ($new_status === 'APPROVED') {
        
        if ($withdrawal_method === 'manual') {
            // === MANUAL WITHDRAWAL APPROVAL ===
            
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

            // Update withdrawal request - MANUAL (no Stripe transaction)
            $query_update_req = "UPDATE withdrawal_requests 
                                 SET status = 'APPROVED', 
                                     processed_at = NOW(),
                                     processed_by = ?,
                                     admin_notes = ?,
                                     admin_proof_image = ?
                                 WHERE id = ?";
            
            $stmt_update_req = $conn->prepare($query_update_req);
            $stmt_update_req->bind_param("issi", $user_id, $admin_notes, $proof_image_url, $request_id);
            
            if (!$stmt_update_req->execute()) {
                throw new Exception("Failed to update withdrawal request status.");
            }
            $stmt_update_req->close();

            // Log audit trail
            $audit_query = "INSERT INTO withdrawal_audit_log (withdrawal_request_id, action, performed_by, notes) 
                            VALUES (?, 'approved', ?, ?)";
            $stmt_audit = $conn->prepare($audit_query);
            $stmt_audit->bind_param("iis", $request_id, $user_id, $admin_notes);
            $stmt_audit->execute();
            $stmt_audit->close();

            $log->info('Manual withdrawal approved', [
                'request_id' => $request_id,
                'user_id' => $request['user_id'],
                'amount' => $amount_dollars,
                'card_last4' => $request['manual_card_number_last4'],
                'admin_id' => $user_id
            ]);

            $notification_message = "Your withdrawal of \${$amount_dollars} has been approved and sent to ****{$request['manual_card_number_last4']}. You should receive it within 1-3 business days.";
            
        } else {
            // === AUTOMATED WITHDRAWAL (Stripe) ===
            
            // Validate card exists
            if (empty($request['stripe_card_id'])) {
                throw new Exception("No payout card associated with this request.", 400);
            }

            // Check Connect account
            if (empty($request['stripe_connect_id'])) {
                throw new Exception("User has no Stripe Connect account.", 400);
            }

            // Check current balance
            if ($request['current_balance'] < $amount_cents) {
                throw new Exception("Insufficient balance. User's balance is now lower than requested amount.", 400);
            }

            // Deduct from wallet BEFORE Stripe call
            $deduct_query = "UPDATE wallets SET balance = balance - ? WHERE id = ?";
            $stmt_deduct = $conn->prepare($deduct_query);
            $stmt_deduct->bind_param("ii", $amount_cents, $request['wallet_id']);
            if (!$stmt_deduct->execute()) {
                throw new Exception("Failed to deduct from wallet.");
            }
            $stmt_deduct->close();

            // Process the payout via Stripe
            try {
                $external_account_id = $request['stripe_card_id'];
                
                $log->info('Processing automated card payout', [
                    'request_id' => $request_id,
                    'amount' => $amount_dollars,
                    'card_last4' => $request['card_last4'],
                    'connect_account' => $request['stripe_connect_id']
                ]);

                // Always use standard payout (you can make this configurable)
                $payout = $stripeService->processStandardCardPayout(
                    $amount_dollars,
                    $request['stripe_connect_id'],
                    $external_account_id,
                    [
                        'description' => "Withdrawal for " . $request['email'],
                        'request_id' => $request_id,
                        'user_id' => $request['user_id'],
                        'card_holder' => $request['card_holder_name']
                    ]
                );
                
                $gateway_transaction_id = $payout->id;
                
                $log->info('Automated withdrawal processed via Stripe', [
                    'request_id' => $request_id,
                    'payout_id' => $gateway_transaction_id,
                    'card' => $request['card_brand'] . ' ****' . $request['card_last4']
                ]);

            } catch (Exception $e) {
                $failure_reason = $e->getMessage();
                
                $log->error('Stripe payout failed', [
                    'request_id' => $request_id,
                    'error' => $failure_reason
                ]);

                // ROLLBACK: Refund the wallet since Stripe failed
                $refund_query = "UPDATE wallets SET balance = balance + ? WHERE id = ?";
                $stmt_refund = $conn->prepare($refund_query);
                $stmt_refund->bind_param("ii", $amount_cents, $request['wallet_id']);
                $stmt_refund->execute();
                $stmt_refund->close();

                throw new Exception("Stripe payout failed: " . $failure_reason, 500);
            }

            // Update withdrawal request - AUTOMATED
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
            
            if (!$stmt_update_req->execute()) {
                throw new Exception("Failed to update withdrawal request status.");
            }
            $stmt_update_req->close();

            // Log audit trail
            $audit_notes = "Automated payout via Stripe. Payout ID: " . $gateway_transaction_id;
            $audit_query = "INSERT INTO withdrawal_audit_log (withdrawal_request_id, action, performed_by, notes) 
                            VALUES (?, 'approved', ?, ?)";
            $stmt_audit = $conn->prepare($audit_query);
            $stmt_audit->bind_param("iis", $request_id, $user_id, $audit_notes);
            $stmt_audit->execute();
            $stmt_audit->close();

            $notification_message = "Your withdrawal of \${$amount_dollars} has been processed to {$request['card_brand']} ****{$request['card_last4']}. You should receive it within 1-3 business days.";
        }

    } else {
        // === REJECTION (Same for both methods) ===
        
        $query_update_req = "UPDATE withdrawal_requests 
                             SET status = 'REJECTED', 
                                 processed_at = NOW(),
                                 processed_by = ?,
                                 admin_notes = ?,
                                 failure_reason = ?
                             WHERE id = ?";
        
        $stmt_update_req = $conn->prepare($query_update_req);
        $stmt_update_req->bind_param("issi", $user_id, $admin_notes, $admin_notes, $request_id);
        
        if (!$stmt_update_req->execute()) {
            throw new Exception("Failed to update withdrawal request status.");
        }
        $stmt_update_req->close();

        // Log audit trail
        $audit_query = "INSERT INTO withdrawal_audit_log (withdrawal_request_id, action, performed_by, notes) 
                        VALUES (?, 'rejected', ?, ?)";
        $stmt_audit = $conn->prepare($audit_query);
        $stmt_audit->bind_param("iis", $request_id, $user_id, $admin_notes);
        $stmt_audit->execute();
        $stmt_audit->close();

        $log->info('Withdrawal rejected', [
            'request_id' => $request_id,
            'user_id' => $request['user_id'],
            'reason' => $admin_notes,
            'admin_id' => $user_id
        ]);

        $notification_message = "Your withdrawal request of \${$amount_dollars} has been rejected. Reason: {$admin_notes}";
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
    $notificationData = [
        'message' => $notification_message,
        'amount' => $amount_dollars,
        'status' => $new_status,
        'request_id' => $request_id,
        'method' => $withdrawal_method
    ];
    
    send_notification($request['user_id'], 'withdrawal-update', $notificationData);

    $conn->commit();
    
    http_response_code(200);
    echo json_encode([
        "message" => "Withdrawal request has been " . strtolower($new_status) . ".",
        "request_id" => $request_id,
        "amount" => $amount_dollars,
        "method" => $withdrawal_method,
        "gateway_transaction_id" => $gateway_transaction_id
    ]);

} catch (Exception $e) {
    if ($conn) $conn->rollback();
    
    $log->error('Withdrawal processing failed.', [
        'request_id' => $request_id ?? 0, 
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
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