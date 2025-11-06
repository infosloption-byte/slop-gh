<?php
// api/v1/payments/create_withdrawal.php (DUAL METHOD SUPPORT)
require_once __DIR__ . '/../../../config/api_secure_bootstrap.php';

try {
    $data = json_decode(file_get_contents("php://input"));
    $amount_dollars = (float)($data->amount ?? 0);
    $withdrawal_method = $data->withdrawal_method ?? 'manual'; // 'manual' or 'automated'

    // Validation
    $MIN_WITHDRAWAL = 10;
    $MAX_WITHDRAWAL = 10000;

    if ($amount_dollars < $MIN_WITHDRAWAL) { 
        throw new Exception("Minimum withdrawal is $$MIN_WITHDRAWAL.", 400); 
    }

    if ($amount_dollars > $MAX_WITHDRAWAL) { 
        throw new Exception("Maximum withdrawal is $$MAX_WITHDRAWAL.", 400); 
    }

    $amount_cents = (int)($amount_dollars * 100);

    $conn->autocommit(FALSE);

    // Get wallet and lock it
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

    // Check for pending withdrawals
    $query_pending = "SELECT COUNT(*) as count FROM withdrawal_requests 
                      WHERE user_id = ? AND status = 'PENDING'";
    $stmt_pending = $conn->prepare($query_pending);
    $stmt_pending->bind_param("i", $user_id);
    $stmt_pending->execute();
    $pending_result = $stmt_pending->get_result()->fetch_assoc();
    $stmt_pending->close();

    if ($pending_result['count'] > 0) {
        throw new Exception("You already have a pending withdrawal request. Please wait for it to be processed.", 400);
    }

    // PROCESS BASED ON METHOD
    if ($withdrawal_method === 'manual') {
        // === MANUAL WITHDRAWAL ===
        $card_number = $data->card_number ?? '';
        $card_holder_name = $data->card_holder_name ?? '';
        $bank_name = $data->bank_name ?? '';

        // Basic validation
        if (empty($card_number) || strlen($card_number) < 13) {
            throw new Exception("Please enter a valid card/account number.", 400);
        }
        if (empty($card_holder_name)) {
            throw new Exception("Cardholder name is required.", 400);
        }

        $card_last4 = substr($card_number, -4);

        // Create manual withdrawal request (NO wallet deduction yet - admin does it)
        $request_query = "INSERT INTO withdrawal_requests 
                          (user_id, wallet_id, amount, status, payout_method, withdrawal_method,
                           manual_card_number_last4, manual_card_holder_name, manual_bank_name,
                           requested_amount_available) 
                          VALUES (?, ?, ?, 'PENDING', 'manual_card', 'manual', ?, ?, ?, ?)";
        
        $stmt_request = $conn->prepare($request_query);
        $stmt_request->bind_param(
            "iiisssi", 
            $user_id, 
            $wallet_id, 
            $amount_cents, 
            $card_last4, 
            $card_holder_name,
            $bank_name,
            $wallet['balance']
        );
        
        if (!$stmt_request->execute()) { 
            throw new Exception("Failed to create withdrawal request."); 
        }
        $request_id = $conn->insert_id;
        $stmt_request->close();

        // Log as PENDING (no wallet deduction yet)
        $log_query = "INSERT INTO transactions 
                      (user_id, wallet_id, type, amount, status, reference_id) 
                      VALUES (?, ?, 'WITHDRAWAL', ?, 'PENDING', ?)";
        $stmt_log = $conn->prepare($log_query);
        $stmt_log->bind_param("iiii", $user_id, $wallet_id, $amount_cents, $request_id);
        
        if (!$stmt_log->execute()) { 
            throw new Exception("Failed to log withdrawal transaction."); 
        }
        $stmt_log->close();

        $conn->commit();
        
        $log->info('Manual withdrawal request created', [
            'user_id' => $user_id,
            'request_id' => $request_id,
            'amount' => $amount_dollars,
            'card_last4' => $card_last4
        ]);
        
        http_response_code(200);
        echo json_encode([
            "message" => "Withdrawal request submitted successfully! Our team will process it within 24-48 hours.",
            "request_id" => $request_id,
            "amount" => $amount_dollars,
            "method" => "manual",
            "card_last4" => $card_last4,
            "estimated_processing" => "24-48 hours",
            "note" => "You will receive a notification once processed."
        ]);

    } else {
        // === AUTOMATED WITHDRAWAL (Stripe Connect + Cards) ===
        $payout_card_id = $data->card_id ?? null;

        // Check if user has Stripe Connect account
        $stmt_user = $conn->prepare("SELECT stripe_connect_id FROM users WHERE id = ?");
        $stmt_user->bind_param("i", $user_id);
        $stmt_user->execute();
        $user = $stmt_user->get_result()->fetch_assoc();
        $stmt_user->close();

        if (empty($user['stripe_connect_id'])) {
            throw new Exception("Please complete identity verification first for automated withdrawals.", 400);
        }

        // Check if user has payout cards
        $query_card_count = "SELECT COUNT(*) as count FROM payout_cards WHERE user_id = ? AND is_active = TRUE";
        $stmt_card_count = $conn->prepare($query_card_count);
        $stmt_card_count->bind_param("i", $user_id);
        $stmt_card_count->execute();
        $card_count = $stmt_card_count->get_result()->fetch_assoc()['count'];
        $stmt_card_count->close();

        if ($card_count == 0) {
            throw new Exception("Please add a payout card first for automated withdrawals.", 400);
        }

        // Get the card to use (specified or default)
        if ($payout_card_id) {
            $query_selected_card = "SELECT id, card_brand, card_last4 
                                    FROM payout_cards 
                                    WHERE id = ? AND user_id = ? AND is_active = TRUE";
            $stmt_selected = $conn->prepare($query_selected_card);
            $stmt_selected->bind_param("ii", $payout_card_id, $user_id);
            $stmt_selected->execute();
            $selected_card = $stmt_selected->get_result()->fetch_assoc();
            $stmt_selected->close();

            if (!$selected_card) {
                throw new Exception("Selected card not found.", 400);
            }
        } else {
            // Use default card
            $query_default_card = "SELECT id, card_brand, card_last4 
                                   FROM payout_cards 
                                   WHERE user_id = ? AND is_default = TRUE AND is_active = TRUE 
                                   LIMIT 1";
            $stmt_default = $conn->prepare($query_default_card);
            $stmt_default->bind_param("i", $user_id);
            $stmt_default->execute();
            $selected_card = $stmt_default->get_result()->fetch_assoc();
            $stmt_default->close();

            if (!$selected_card) {
                throw new Exception("No default card found. Please set a default card.", 400);
            }
            
            $payout_card_id = $selected_card['id'];
        }

        // Create automated withdrawal request (admin still approves)
        $request_query = "INSERT INTO withdrawal_requests 
                          (user_id, wallet_id, payout_card_id, amount, status, payout_method, 
                           withdrawal_method, requested_amount_available) 
                          VALUES (?, ?, ?, ?, 'PENDING', 'automated_card', 'automated', ?)";
        $stmt_request = $conn->prepare($request_query);
        $stmt_request->bind_param("iiiii", $user_id, $wallet_id, $payout_card_id, $amount_cents, $wallet['balance']);
        
        if (!$stmt_request->execute()) { 
            throw new Exception("Failed to create withdrawal request."); 
        }
        $request_id = $conn->insert_id;
        $stmt_request->close();

        // Log as PENDING
        $log_query = "INSERT INTO transactions 
                      (user_id, wallet_id, type, amount, status, reference_id) 
                      VALUES (?, ?, 'WITHDRAWAL', ?, 'PENDING', ?)";
        $stmt_log = $conn->prepare($log_query);
        $stmt_log->bind_param("iiii", $user_id, $wallet_id, $amount_cents, $request_id);
        
        if (!$stmt_log->execute()) { 
            throw new Exception("Failed to log withdrawal transaction."); 
        }
        $stmt_log->close();

        $conn->commit();
        
        $log->info('Automated withdrawal request created', [
            'user_id' => $user_id,
            'request_id' => $request_id,
            'amount' => $amount_dollars,
            'card_id' => $payout_card_id,
            'card_info' => $selected_card['card_brand'] . ' ****' . $selected_card['card_last4']
        ]);
        
        http_response_code(200);
        echo json_encode([
            "message" => "Automated withdrawal request submitted! Pending admin approval.",
            "request_id" => $request_id,
            "amount" => $amount_dollars,
            "method" => "automated",
            "card" => [
                "brand" => $selected_card['card_brand'],
                "last4" => $selected_card['card_last4']
            ],
            "estimated_processing" => "Instant to 30 minutes after approval"
        ]);
    }

} catch (Exception $e) {
    if ($conn) $conn->rollback();
    
    $log->error('Withdrawal request failed.', [
        'user_id' => $user_id ?? 0,
        'amount' => $amount_dollars ?? 0,
        'method' => $withdrawal_method ?? 'unknown',
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