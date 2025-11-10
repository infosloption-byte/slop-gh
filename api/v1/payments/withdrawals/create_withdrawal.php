<?php
// api/v1/payments/withdrawals/create_withdrawal.php (REWRITTEN FOR HYBRID MODE - MERGED)
require_once __DIR__ . '/../../../config/api_secure_bootstrap.php';

// --- HELPER FUNCTIONS FOR NEW PAYOUT METHODS ---

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
    
    $timestamp = round(microtime(true) * 1000);
    $nonce = bin2hex(random_bytes(16));
    
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
    
    return $response['data']['orderId'];
}

/**
 * Creates a Skrill Payout (This is a simplified example)
 */
function processSkrillPayout($amount_dollars, $receiver_email, $log) {
    $merchant_email = $_ENV['SKRILL_MERCHANT_EMAIL'];
    $secret_word_md5 = md5($_ENV['SKRILL_SECRET_WORD']);
    
    if (empty($merchant_email) || empty($_ENV['SKRILL_SECRET_WORD'])) {
        throw new Exception("Skrill is not configured on the server.");
    }
    
    $log->info('Skrill Payout: Simulating success', ['email' => $receiver_email, 'amount' => $amount_dollars]);
    // SIMULATE A FAKE TRANSACTION ID
    $gateway_transaction_id = 'SKRILL_TX_' . time();
    
    // TODO: In a real implementation, you would make your cURL calls here.
    // If they fail, you would:
    // throw new Exception("Skrill Payout Failed: [error from skrill]");
    
    return $gateway_transaction_id;
}


// --- MAIN WITHDRAWAL LOGIC ---
try {
    $data = json_decode(file_get_contents("php://input"));
    $amount_dollars = (float)($data->amount ?? 0);
    $payout_method_id = (int)($data->payout_method_id ?? 0);

    // --- NEW: RISK MANAGEMENT LIMIT ---
    $AUTO_APPROVE_LIMIT_USD = 500; // $500.00
    // --- END: RISK MANAGEMENT ---

    // Validation
    $MIN_WITHDRAWAL = 10;
    if ($amount_dollars < $MIN_WITHDRAWAL) { 
        throw new Exception("Minimum withdrawal is $$MIN_WITHDRAWAL.", 400); 
    }
    // We no longer check MAX_WITHDRAWAL here, as the risk limit handles large amounts

    if (empty($payout_method_id)) {
        throw new Exception("A payout method must be selected.", 400);
    }

    $amount_cents = (int)($amount_dollars * 100);
    $auto_approve_limit_cents = (int)($AUTO_APPROVE_LIMIT_USD * 100);
    
    $conn->autocommit(FALSE);

    // 1. Get wallet and lock it
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

    // 2. Get the selected payout method details
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
    
    // Define these vars now for use in both paths
    $payout_card_id = $method['payout_card_id'] ?? null;
    $payout_method_db = $method['method_type'] === 'stripe_card' ? 'automated_card' : $method['method_type'];


    // --- NEW: HYBRID LOGIC GATE ---
    if ($amount_cents > $auto_approve_limit_cents) {
        
        // --- OVER LIMIT: MARK AS PENDING FOR ADMIN REVIEW ---
        $final_status = 'PENDING';
        $admin_notes = "Pending manual review (amount over $$AUTO_APPROVE_LIMIT_USD limit).";

        // 1. Log the withdrawal request as PENDING
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
        
        // 2. Log the transaction as PENDING
        $log_query = "INSERT INTO transactions 
                      (user_id, wallet_id, type, amount, status, reference_id) 
                      VALUES (?, ?, 'WITHDRAWAL', ?, 'PENDING', ?)";
        $stmt_log = $conn->prepare($log_query);
        $stmt_log->bind_param("iiii", $user_id, $wallet_id, $amount_cents, $request_id);
        $stmt_log->execute();
        $stmt_log->close();
        
        // 3. Commit
        $conn->commit();
        
        // 4. Send Notification
        $notification_message = "Your withdrawal request for $" . $amount_dollars . " is pending review. This is standard for large amounts and will be processed within 24 hours.";
        send_notification($user_id, 'withdrawal-update', ['message' => $notification_message], null);

        $log->info('Withdrawal request pending review (over limit)', ['user' => $user_id, 'req_id' => $request_id, 'amount' => $amount_dollars]);
        
        http_response_code(200);
        echo json_encode(["message" => "Withdrawal request submitted! As this is a large amount, it will be processed after a brief manual review (within 24 hours)."]);

    } else {
        
        // --- UNDER LIMIT: PROCESS INSTANTLY ---
        $gateway_transaction_id = null;
        $final_status = 'FAILED'; // Default to FAILED, set to APPROVED on success
        
        // 3. Deduct from wallet
        $deduct_query = "UPDATE wallets SET balance = balance - ? WHERE id = ?";
        $stmt_deduct = $conn->prepare($deduct_query);
        $stmt_deduct->bind_param("ii", $amount_cents, $wallet_id);
        if (!$stmt_deduct->execute()) {
            throw new Exception("Failed to update wallet balance.");
        }
        $stmt_deduct->close();

        // 4. Process payout based on method type
        try {
            switch ($method['method_type']) {
                case 'stripe_card':
                    if (empty($method['stripe_connect_id']) || empty($method['external_account_id'])) {
                        throw new Exception("Stripe account is not properly configured.", 500);
                    }
                    $transfer = $stripeService->transferToConnectAccount($amount_dollars, $method['stripe_connect_id'], ['user_id' => $user_id]);
                    $payout = $stripeService->processStandardCardPayout($amount_dollars, $method['stripe_connect_id'], $method['external_account_id'], ['transfer_id' => $transfer->id]);
                    $gateway_transaction_id = $payout->id;
                    break;

                case 'paypal':
                    $gateway_transaction_id = processPayPalPayout($amount_dollars, $method['account_identifier'], $log);
                    break;
                    
                case 'binance':
                    $gateway_transaction_id = processBinancePayout($amount_dollars, $method['account_identifier'], $log);
                    break;

                case 'skrill':
                    $gateway_transaction_id = processSkrillPayout($amount_dollars, $method['account_identifier'], $log);
                    break;
            }
            
            // If we get here, the API call was successful
            $final_status = 'APPROVED';

        } catch (Exception $api_error) {
            // API Payout Failed - log this failure but re-throw to trigger wallet rollback
            $final_status = 'FAILED';
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

            throw $api_error; // IMPORTANT: Re-throw to trigger $conn->rollback()
        }

        // 5. Log the successful withdrawal request
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

        // 6. Log the successful transaction
        $log_query = "INSERT INTO transactions 
                      (user_id, wallet_id, type, amount, status, reference_id, gateway_transaction_id) 
                      VALUES (?, ?, 'WITHDRAWAL', ?, 'COMPLETED', ?, ?)";
        $stmt_log = $conn->prepare($log_query);
        $stmt_log->bind_param("iiiis", $user_id, $wallet_id, $amount_cents, $request_id, $gateway_transaction_id);
        $stmt_log->execute();
        $stmt_log->close();

        // 7. Commit the transaction
        $conn->commit();
        
        $log->info('Instant withdrawal processed successfully', ['user' => $user_id, 'req_id' => $request_id, 'amount' => $amount_dollars]);
        
        // 8. Send Notification
        if ($final_status == 'APPROVED') {
            $notification_message = "Your withdrawal of $" . $amount_dollars . " to " . $method['display_name'] . " has been successfully processed.";
        } else {
            // This case should not be hit here due to the try/catch, but good for safety
            $notification_message = "Your withdrawal of $" . $amount_dollars . " to " . $method['display_name'] . " failed.";
        }
        send_notification($user_id, 'withdrawal-update', ['message' => $notification_message], null);

        http_response_code(200);
        echo json_encode([
            "message" => "Withdrawal processed successfully!",
            "request_id" => $request_id,
            "amount" => $amount_dollars,
            "method" => $method['display_name'],
            "gateway_transaction_id" => $gateway_transaction_id,
            "status" => $final_status
        ]);
    }

} catch (Exception $e) {
    if ($conn) $conn->rollback(); // This is the most important part
    
    $log->error('Withdrawal request failed.', [
        'user_id' => $user_id ?? 0,
        'amount' => $amount_dollars ?? 0,
        'method_id' => $payout_method_id ?? 0,
        'error' => $e->getMessage()
    ]);
    
    $code = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 500;
    http_response_code($code);
    echo json_encode(["message" => $e->getMessage()]); // Send the Stripe/API error to the user
} finally {
    if ($conn) { 
        $conn->autocommit(TRUE); 
        $conn->close(); 
    }
}
?>