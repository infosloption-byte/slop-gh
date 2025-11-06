<?php
// Load dependencies and services
require_once __DIR__ . '/../../../config/api_secure_bootstrap.php';

try {
    // Authenticate the user (handled by the bootstrap)
    
    // Rate Limiting (no change)
    $ip_address = $_SERVER['REMOTE_ADDR'];
    require_once __DIR__ . '/../../../config/rate_limiter.php';
    if (!check_rate_limit($conn, $ip_address, 'place_trade', 30, 60, $user_id)) {
        http_response_code(429);
        die(json_encode(["message" => "You are placing trades too quickly. Please wait a moment."]));
    }
    
    // Get the posted trade data
    $data = json_decode(file_get_contents("php://input"));

    // Basic Input Validation (no change)
    if (empty($data->pair) || empty($data->direction) || !isset($data->bid_amount) || !is_numeric($data->bid_amount) || $data->bid_amount <= 0 || empty($data->wallet_type) || empty($data->duration)) {
        throw new Exception("Invalid trade data provided.", 400);
    }

    // Fetch payout rate (no change)
    $pair = $data->pair;
    $stmt_asset = $conn->prepare("SELECT payout_rate FROM trading_assets WHERE symbol = ? AND is_active = TRUE");
    $stmt_asset->bind_param("s", $pair);
    $stmt_asset->execute();
    $asset_details = $stmt_asset->get_result()->fetch_assoc();
    $stmt_asset->close();
    if (!$asset_details) { throw new Exception("The selected trading pair is not available.", 404); }
    $payout_rate_for_trade = (float)$asset_details['payout_rate'];
    
    // Get price from Redis (no change)
    $redis = new Predis\Client(['scheme' => $_ENV['REDIS_SCHEME'] ?? 'tcp', 'host' => $_ENV['REDIS_HOST'], 'port' => $_ENV['REDIS_PORT'], 'password' => $_ENV['REDIS_PASSWORD'] ?? null]);
    $redis_key = "price:" . str_replace('/', '', $data->pair);
    $entry_price_float = $redis->get($redis_key);
    if ($entry_price_float === null) { throw new Exception("Market price is currently unavailable. Please try again.", 503); }

    // --- START: INTEGER CONVERSION ---
    $bid_amount_cents = (int)($data->bid_amount * 100);
    $entry_price_int = (int)((float)$entry_price_float * 1000000);
    // --- END: INTEGER CONVERSION ---

    // Begin database transaction
    $conn->autocommit(FALSE);

    // 1. Get wallet and lock row. Balance is now in cents.
    $wallet_type = $conn->real_escape_string($data->wallet_type);
    $query_wallet = "SELECT id, balance FROM wallets WHERE user_id = ? AND type = ? FOR UPDATE";
    $stmt_wallet = $conn->prepare($query_wallet);
    $stmt_wallet->bind_param("is", $user_id, $wallet_type);
    $stmt_wallet->execute();
    $wallet = $stmt_wallet->get_result()->fetch_assoc();
    if (!$wallet) { throw new Exception("Wallet not found."); }
    $wallet_id = $wallet['id'];
    $current_balance_cents = (int)$wallet['balance'];
    $stmt_wallet->close();

    // 2. Check balance (comparing cents with cents)
    if ($current_balance_cents < $bid_amount_cents) { throw new Exception("Insufficient funds."); }

    // 3. Deduct bid amount (integer math)
    $new_balance_cents = $current_balance_cents - $bid_amount_cents;
    $query_update_wallet = "UPDATE wallets SET balance = ? WHERE id = ?";
    $stmt_update_wallet = $conn->prepare($query_update_wallet);
    $stmt_update_wallet->bind_param("ii", $new_balance_cents, $wallet_id); // 'd' changed to 'i'
    if (!$stmt_update_wallet->execute()) { throw new Exception("Failed to update wallet balance."); }
    $stmt_update_wallet->close();
    
    // 4. Record the new trade (using integer values)
    $duration_seconds = (int)$data->duration;
    $query_insert_trade = "INSERT INTO trades (user_id, wallet_id, pair, direction, bid_amount, payout_rate, entry_price, status, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?, 'PENDING', UTC_TIMESTAMP() + INTERVAL ? SECOND)";
    $stmt_insert_trade = $conn->prepare($query_insert_trade);
    $stmt_insert_trade->bind_param("isssidis", $user_id, $wallet_id, $data->pair, $data->direction, $bid_amount_cents, $payout_rate_for_trade, $entry_price_int, $duration_seconds); // 'd's changed to 'i'
    if (!$stmt_insert_trade->execute()) { throw new Exception("Failed to record trade."); }
    $trade_id = $stmt_insert_trade->insert_id;
    $stmt_insert_trade->close();

    // 5. Log transaction (using cents)
    $log_trade_debit_query = "INSERT INTO transactions (user_id, wallet_id, type, amount, status) VALUES (?, ?, 'TRADE_DEBIT', ?, 'COMPLETED')";
    $stmt_log_debit = $conn->prepare($log_trade_debit_query);
    $stmt_log_debit->bind_param("iii", $user_id, $wallet_id, $bid_amount_cents); // 'd' changed to 'i'
    if (!$stmt_log_debit->execute()) { throw new Exception("Failed to log trade debit transaction."); }
    $stmt_log_debit->close();

    // Fetch the full trade details to return
    $query_get_trade = "SELECT t.*, w.type as wallet_type FROM trades t JOIN wallets w ON t.wallet_id = w.id WHERE t.id = ?";
    $stmt_get_trade = $conn->prepare($query_get_trade);
    $stmt_get_trade->bind_param("i", $trade_id);
    $stmt_get_trade->execute();
    $new_trade_db = $stmt_get_trade->get_result()->fetch_assoc();
    $stmt_get_trade->close();

    // --- CONVERT FOR FRONT-END ---
    $new_trade_frontend = $new_trade_db;
    $new_trade_frontend['bid_amount'] = $new_trade_db['bid_amount'] / 100.0;
    $new_trade_frontend['entry_price'] = $new_trade_db['entry_price'] / 1000000.0;
    // --- END CONVERSION ---

    $conn->commit();
    http_response_code(201);
    echo json_encode($new_trade_frontend);

} catch (Exception $e) {
    if (isset($conn)) $conn->rollback();
    $log->error('Trade placement failed.', ['user_id' => $user_id ?? 'unknown', 'error' => $e->getMessage()]);
    $code = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 500;
    http_response_code($code);
    echo json_encode(["message" => $e->getMessage()]);
} finally {
    if (isset($conn)) {
        $conn->autocommit(TRUE);
        $conn->close();
    }
}
?>
