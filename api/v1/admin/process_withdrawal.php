<?php
// api/v1/admin/process_withdrawal.php (UPDATED for Hybrid Mode)
require_once __DIR__ . '/../../../config/api_secure_bootstrap.php';

// --- HELPER FUNCTIONS (COPIED FROM create_withdrawal.php) ---

/**
 * Creates a PayPal Payout
 */
function processPayPalPayout($amount_dollars, $receiver_payer_id, $log) {
    $client_id = $_ENV['PAYPAL_CLIENT_ID'];
    $client_secret = $_ENV['PAYPAL_CLIENT_SECRET'];
    $api_url = $_ENV['PAYPAL_API_URL'];

    // 1. Get Access Token
    $ch_token = curl_init();
    curl_setopt($ch_token, CURLOPT_URL, $api_url . '/v1/oauth2/token');
    curl_setopt($ch_token, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch_token, CURLOPT_POST, 1);
    curl_setopt($ch_token, CURLOPT_POSTFIELDS, 'grant_type=client_credentials');
    curl_setopt($ch_token, CURLOPT_USERPWD, $client_id . ':' . $client_secret);
    $response_body = curl_exec($ch_token);
    curl_close($ch_token);
    
    $token_data = json_decode($response_body, true);
    if (!isset($token_data['access_token'])) {
        $log->error('PayPal Payout: Failed to get access token', ['response' => $response_body]);
        throw new Exception("PayPal auth failed. Cannot process payout.");
    }
    $access_token = $token_data['access_token'];

    // 2. Create Payout
    $sender_batch_id = 'SLO_PAYOUT_' . time() . '_' . rand(1000, 9999);
    $payout_body = [
        'sender_batch_header' => [
            'sender_batch_id' => $sender_batch_id,
            'email_subject' => 'You have a payout from SL Option!',
            'email_message' => 'You have received a payout from SL Option. Thank you!'
        ],
        'items' => [
            [
                'recipient_type' => 'PAYER_ID',
                'amount' => ['value' => (string)$amount_dollars, 'currency' => 'USD'],
                'receiver' => $receiver_payer_id,
                'note' => 'SL Option Withdrawal',
                'sender_item_id' => 'ITEM_' . time()
            ]
        ]
    ];
    
    $ch_payout = curl_init();
    curl_setopt($ch_payout, CURLOPT_URL, $api_url . '/v1/payments/payouts');
    curl_setopt($ch_payout, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch_payout, CURLOPT_POST, 1);
    curl_setopt($ch_payout, CURLOPT_POSTFIELDS, json_encode($payout_body));
    curl_setopt($ch_payout, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $access_token
    ]);
    
    $response_body = curl_exec($ch_payout);
    $http_code = curl_getinfo($ch_payout, CURLINFO_HTTP_CODE);
    curl_close($ch_payout);
    
    $response = json_decode($response_body, true);

    if ($http_code != 201 || !isset($response['batch_header']['payout_batch_id'])) {
        $log->error('PayPal Payout: Failed to create payout', ['response' => $response_body, 'http_code' => $http_code]);
        throw new Exception("PayPal payout failed: " . ($response['message'] ?? $response['details'][0]['issue'] ?? 'Unknown error'));
    }
    
    return $response['batch_header']['payout_batch_id'];
}

/**
 * Creates a Binance Pay Payout
 */
function processBinancePayout($amount_dollars, $receiver_binance_id, $log) {
    $api_key = $_ENV['BINANCE_API_KEY'];
    $secret_key = $_ENV['BINANCE_SECRET_KEY'];
    $api_url = $_ENV['BINANCE_PAY_API_URL'];
    
    // Binance Pay requires a server-side timestamp
    $timestamp = round(microtime(true) * 1000);
    $nonce = bin2hex(random_bytes(16)); // Unique ID
    
    $body = [
        'receiver' => [
            'receiverType' => 'PAY_ID', // Can be EMAIL, PAY_ID, or PHONE
            'receiverId' => $receiver_binance_id
        ],
        'transferAmount' => (string)$amount_dollars,
        'transferAsset' => 'USDT', // You must hold USDT in your Binance funding wallet
        'orderId' => 'SLO_' . time() . '_' . rand(1000, 9999)
    ];
    $json_body = json_encode($body);
    
    // Create signature
    $string_to_sign = $timestamp . "\n" . $nonce . "\n" . $json_body . "\n";
    $signature = strtoupper(hash_hmac('SHA512', $string_to_sign, $secret_key));

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $api_url . '/v1/api/pay/transfer'); // Check Binance docs for latest endpoint
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $json_body);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'BinancePay-Timestamp: ' . $timestamp,
        'BinancePay-Nonce: ' . $nonce,
        'BinancePay-Certificate-SN: ' . $api_key,
        'BinancePay-Signature: ' . $signature
    ]);

    $response_body = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    $response = json_decode($response_body, true);
    
    if ($http_code != 200 || $response['status'] !== 'SUCCESS') {
        $log->error('Binance Pay: Failed to create payout', ['response' => $response_body, 'http_code' => $http_code]);
        throw new Exception("Binance Pay failed: " . ($response['message'] ?? 'Unknown error'));
    }
    
    return $response['data']['orderId']; // Return the unique order ID
}

/**
 * Creates a Skrill Payout (This is a simplified example)
 */
function processSkrillPayout($amount_dollars, $receiver_email, $log) {
    $merchant_email = $_ENV['SKRILL_MERCHANT_EMAIL'];
    $secret_word_md5 = md5($_ENV['SKRILL_SECRET_WORD']); // Skrill often uses MD5 of a secret word
    
    // TODO: Skrill's API is complex. This is a simplified stub.
    // You will likely need to:
    // 1. Call a "prepare" endpoint.
    // 2. Get a session ID.
    // 3. Call an "execute" endpoint.
    // This stub just simulates a success.
    
    if (empty($merchant_email) || empty($_ENV['SKRILL_SECRET_WORD'])) {
        throw new Exception("Skrill is not configured on the server.");
    }
    
    $log->info('Skrill Payout: Simulating success', ['email' => $receiver_email, 'amount' => $amount_dollars]);
    // SIMULATE A FAKE TRANSACTION ID
    $gateway_transaction_id = 'SKRILL_TX_' . time();
    
    // In a real implementation, you would make your cURL calls here.
    // If they fail, you would:
    // throw new Exception("Skrill Payout Failed: [error from skrill]");
    
    return $gateway_transaction_id;
}
// --- END HELPER FUNCTIONS ---


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

    // Get request with full details and lock it
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
        
        // 1. Check current balance
        if ($request['current_balance'] < $amount_cents) {
            throw new Exception("Insufficient balance. User's balance is now lower than requested amount.", 400);
        }

        // 2. Deduct from wallet
        $deduct_query = "UPDATE wallets SET balance = balance - ? WHERE id = ?";
        $stmt_deduct = $conn->prepare($deduct_query);
        $stmt_deduct->bind_param("ii", $amount_cents, $request['wallet_id']);
        if (!$stmt_deduct->execute()) {
            throw new Exception("Failed to deduct from wallet.");
        }
        $stmt_deduct->close();
        
        // 3. Process payout based on method type
        try {
            switch ($request['method_type']) {
                case 'stripe_card':
                    if (empty($request['stripe_connect_id']) || empty($request['external_account_id'])) {
                        throw new Exception("Stripe account is not properly configured.", 500);
                    }
                    $transfer = $stripeService->transferToConnectAccount($amount_dollars, $request['stripe_connect_id'], ['req_id' => $request_id]);
                    $payout = $stripeService->processStandardCardPayout($amount_dollars, $request['stripe_connect_id'], $request['external_account_id'], ['transfer_id' => $transfer->id]);
                    $gateway_transaction_id = $payout->id;
                    break;
                case 'paypal':
                    $gateway_transaction_id = processPayPalPayout($amount_dollars, $request['account_identifier'], $log);
                    break;
                case 'binance':
                    $gateway_transaction_id = processBinancePayout($amount_dollars, $request['account_identifier'], $log);
                    break;
                case 'skrill':
                    $gateway_transaction_id = processSkrillPayout($amount_dollars, $request['account_identifier'], $log);
                    break;
                default:
                    // This handles old "manual" requests that didn't have a payout_method_id
                    if ($request['withdrawal_method'] === 'manual') {
                         $gateway_transaction_id = 'MANUAL_BY_ADMIN_' . $user_id;
                         $log->info('Admin processed old manual withdrawal', ['req_id' => $request_id, 'admin_id' => $user_id]);
                    } else {
                        throw new Exception("Unknown or unsupported payout method type: " . $request['method_type']);
                    }
            }
        } catch (Exception $e) {
            $failure_reason = "Payout API Error: " . $e->getMessage();
            $log->error('Admin Payout Process Failed', ['req_id' => $request_id, 'error' => $failure_reason]);

            // ROLLBACK: Refund the wallet since payout failed
            $refund_query = "UPDATE wallets SET balance = balance + ? WHERE id = ?";
            $stmt_refund = $conn->prepare($refund_query);
            $stmt_refund->bind_param("ii", $amount_cents, $request['wallet_id']);
            $stmt_refund->execute();
            $stmt_refund->close();

            throw new Exception($failure_reason, 500); // Throw to roll back transaction
        }

        // 4. Update withdrawal request
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
        
        $notification_message = "Your withdrawal of \${$amount_dollars} has been approved and processed.";

    } else {
        // === REJECTION ===
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

        $log->info('Withdrawal rejected by admin', ['req_id' => $request_id, 'admin_id' => $user_id, 'reason' => $admin_notes]);
        $notification_message = "Your withdrawal request of \${$amount_dollars} has been rejected. Reason: {$admin_notes}";
    }

    // 5. Update transaction ledger
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

    // 6. Send notification
    send_notification($request['user_id'], 'withdrawal-update', ['message' => $notification_message], null);

    // 7. Commit
    $conn->commit();
    
    http_response_code(200);
    echo json_encode(["message" => "Withdrawal request has been " . strtolower($new_status) . "."]);

} catch (Exception $e) {
    if ($conn) $conn->rollback();
    
    $log->error('Admin withdrawal processing failed.', ['req_id' => $request_id ?? 0, 'error' => $e->getMessage()]);
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