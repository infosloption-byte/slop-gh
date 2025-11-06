<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// api/v1/payments/create_deposit.php
require_once __DIR__ . '/../../../config/api_secure_bootstrap.php';

use Stripe\Exception\CardException;

try {
    // 1. Get data from the front-end
    $data = json_decode(file_get_contents("php://input"));
    $amount = $data->amount ?? 0;
    $token = $data->token ?? '';

    if ($amount <= 0 || empty($token)) {
        throw new Exception("Invalid data provided.", 400);
    }
    
    // --- BEGIN TRANSACTION ---
    $conn->autocommit(FALSE);

    // 2. Fetch the user's email
    $stmt_user = $conn->prepare("SELECT email FROM users WHERE id = ?");
    $stmt_user->bind_param("i", $user_id);
    $stmt_user->execute();
    $user = $stmt_user->get_result()->fetch_assoc();
    if (!$user) {
        throw new Exception("User not found.", 404);
    }
    $stmt_user->close();

    // 3. Create the charge using StripeService
    try {
        $charge = $stripeService->createDepositCharge($amount, $token, $user['email']);
    } catch (Exception $e) {
        throw new Exception("Payment failed: " . $e->getMessage(), 400);
    }

    // 4. If charge is successful, update wallet
    if ($charge->status == 'succeeded') {
        // Get the real wallet ID
        $query_wallet = "SELECT id FROM wallets WHERE user_id = ? AND type = 'real' LIMIT 1";
        $stmt_wallet = $conn->prepare($query_wallet);
        $stmt_wallet->bind_param("i", $user_id);
        $stmt_wallet->execute();
        $wallet = $stmt_wallet->get_result()->fetch_assoc();
        if (!$wallet) { 
            throw new Exception("Real wallet not found."); 
        }
        $wallet_id = $wallet['id'];
        $stmt_wallet->close();

        // *** FIX: Convert dollars to cents for database storage ***
        $amount_in_cents = (int)($amount * 100);

        // Update wallet balance (stored in cents)
        $update_query = "UPDATE wallets SET balance = balance + ? WHERE id = ?";
        $stmt_update = $conn->prepare($update_query);
        $stmt_update->bind_param("ii", $amount_in_cents, $wallet_id);
        if (!$stmt_update->execute()) { 
            throw new Exception("Failed to update wallet balance."); 
        }
        $stmt_update->close();

        // Log the transaction (stored in cents)
        $log_query = "INSERT INTO transactions (user_id, wallet_id, type, amount, status, gateway, gateway_transaction_id) VALUES (?, ?, 'DEPOSIT', ?, 'COMPLETED', 'Stripe', ?)";
        $stmt_log = $conn->prepare($log_query);
        $stmt_log->bind_param("iiis", $user_id, $wallet_id, $amount_in_cents, $charge->id);
        if (!$stmt_log->execute()) { 
            throw new Exception("Failed to log deposit transaction."); 
        }
        $stmt_log->close();
        
        // Commit transaction
        $conn->commit();
        http_response_code(200);
        echo json_encode(["message" => "Deposit successful."]);

    } else {
        throw new Exception("Stripe charge was not successful.");
    }
} catch (Exception $e) {
    if ($conn) $conn->rollback();
    $log->error('Deposit failed.', ['user_id' => $user_id ?? 0, 'error' => $e->getMessage()]);
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