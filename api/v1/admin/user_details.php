<?php
// Load dependencies and services
require_once __DIR__ . '/../../../config/api_secure_bootstrap.php';

// Import JWT classes
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

try {
    // Bootstrap provides: $conn, $log, $user_id (admin).
    if (!is_admin($conn, $user_id)) {
        throw new Exception("Forbidden. Admin privileges required.", 403);
    }

    $target_user_id = $_GET['user_id'] ?? 0;
    if (!$target_user_id) {
        throw new Exception("User ID is required.", 400);
    }

    // --- Fetch User Profile (No money conversion needed) ---
    $profile_query = "SELECT id, email, first_name, last_name, birthday, postal_code, address, city, country, role, provider, created_at, last_seen_at, is_suspended, google2fa_enabled, phone_number, phone_verified, stripe_connect_id FROM users WHERE id = ?";
    $stmt_profile = $conn->prepare($profile_query);
    $stmt_profile->bind_param("i", $target_user_id);
    $stmt_profile->execute();
    $profile = $stmt_profile->get_result()->fetch_assoc();
    $stmt_profile->close();
    if (!$profile) { throw new Exception("User not found.", 404); }

    // --- Fetch Wallets (Convert balance to dollars) ---
    $wallets_query = "SELECT type, balance, currency FROM wallets WHERE user_id = ?";
    $stmt_wallets = $conn->prepare($wallets_query);
    $stmt_wallets->bind_param("i", $target_user_id);
    $stmt_wallets->execute();
    $result_wallets = $stmt_wallets->get_result();
    $wallets_frontend = []; // Array to hold converted data
    while ($w_db = $result_wallets->fetch_assoc()) {
        $w_frontend = $w_db; // Copy other fields
        $w_frontend['balance'] = (int)$w_db['balance'] / 100.0; // Convert cents to dollars
        $wallets_frontend[] = $w_frontend;
    }
    $stmt_wallets->close();

    // --- Fetch Trades (Convert amounts, prices, profit/loss to dollars) ---
    $trades_query = "SELECT * FROM trades WHERE user_id = ? ORDER BY created_at DESC LIMIT 100"; // Limit added for performance
    $stmt_trades = $conn->prepare($trades_query);
    $stmt_trades->bind_param("i", $target_user_id);
    $stmt_trades->execute();
    $result_trades = $stmt_trades->get_result();
    $trades_frontend = []; // Array to hold converted data
    while ($t_db = $result_trades->fetch_assoc()) {
        $t_frontend = $t_db; // Copy other fields
        $t_frontend['bid_amount'] = (int)$t_db['bid_amount'] / 100.0;
        $t_frontend['profit_loss'] = $t_db['profit_loss'] !== null ? (int)$t_db['profit_loss'] / 100.0 : null;
        $t_frontend['entry_price'] = (int)$t_db['entry_price'] / 1000000.0;
        $t_frontend['close_price'] = $t_db['close_price'] !== null ? (int)$t_db['close_price'] / 1000000.0 : null;
        $trades_frontend[] = $t_frontend;
    }
    $stmt_trades->close();

    // --- Fetch Transactions (Convert amount to dollars) ---
    $transactions_query = "SELECT * FROM transactions WHERE user_id = ? ORDER BY created_at DESC LIMIT 100"; // Limit added for performance
    $stmt_transactions = $conn->prepare($transactions_query);
    $stmt_transactions->bind_param("i", $target_user_id);
    $stmt_transactions->execute();
    $result_transactions = $stmt_transactions->get_result();
    $transactions_frontend = []; // Array to hold converted data
    while ($tx_db = $result_transactions->fetch_assoc()) {
        $tx_frontend = $tx_db; // Copy other fields
        $tx_frontend['amount'] = (int)$tx_db['amount'] / 100.0;
        $transactions_frontend[] = $tx_frontend;
    }
    $stmt_transactions->close();
    
    // --- Assemble all CONVERTED data into a single response object ---
    $user_details = [
        'profile' => $profile,
        'wallets' => $wallets_frontend,
        'trades' => $trades_frontend,
        'transactions' => $transactions_frontend
    ];

    http_response_code(200);
    echo json_encode($user_details);

} catch (Exception $e) {
    $log->error('Admin fetch user details failed.', ['admin_id' => $user_id ?? 0, 'error' => $e->getMessage()]);
    $code = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 500;
    http_response_code($code);
    echo json_encode(["message" => $e->getMessage()]);
    
} finally {
    if ($conn) $conn->close();
}
?>

