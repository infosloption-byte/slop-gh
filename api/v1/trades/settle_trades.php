<?php
// This script is intended to be run by a cron job every minute.
require_once __DIR__ . '/../../../config/api_bootstrap.php';

// Locking mechanism (no change)
$lock_file = sys_get_temp_dir() . '/settle_trades.lock';
if (file_exists($lock_file)) { exit; }
touch($lock_file);
register_shutdown_function(function() use ($lock_file) { if (file_exists($lock_file)) { unlink($lock_file); } });

// Find all pending trades that have expired. Values are now integers.
$query = "SELECT * FROM trades WHERE status = 'PENDING' AND expires_at <= UTC_TIMESTAMP()";
$result = $conn->query($query);

if (!$result) {
    $log->error('Cron settlement query failed.', ['error' => $conn->error]);
    die("Query failed: " . $conn->error);
}

if ($result->num_rows > 0) {
    echo "Found " . $result->num_rows . " trades to settle.\n";
    while ($trade = $result->fetch_assoc()) {
        $conn->begin_transaction();
        try {
            // Get closing price as a float
            $close_price_float = getClosingPrice($trade['pair'], $trade['expires_at'], $log);
            if ($close_price_float == 0) { throw new Exception("Failed to fetch historical price for " . $trade['pair']); }

            // --- START: INTEGER CONVERSION & CALCULATION ---
            $close_price_int = (int)($close_price_float * 1000000);
            $entry_price_int = (int)$trade['entry_price'];
            $bid_amount_cents = (int)$trade['bid_amount'];

            // Determine win/loss by comparing integers
            $is_win = false;
            if ($trade['direction'] == 'HIGH' && $close_price_int > $entry_price_int) $is_win = true;
            if ($trade['direction'] == 'LOW' && $close_price_int < $entry_price_int) $is_win = true;
            
            $status = $is_win ? 'WIN' : 'LOSE';

            // Calculate profit/loss in cents
            $payout_rate = (float)$trade['payout_rate'];
            $profit_loss_cents = $is_win ? (int)($bid_amount_cents * $payout_rate) : -$bid_amount_cents;
            // --- END: INTEGER CONVERSION & CALCULATION ---

            // Update the trade status with integer values
            $update_trade_query = "UPDATE trades SET status = ?, close_price = ?, profit_loss = ? WHERE id = ?";
            $stmt_trade = $conn->prepare($update_trade_query);
            $stmt_trade->bind_param("siii", $status, $close_price_int, $profit_loss_cents, $trade['id']); // 'd's changed to 'i'
            if (!$stmt_trade->execute()) { throw new Exception("Failed to update trade."); }
            $stmt_trade->close();

            if ($is_win) {
                $payout_amount_cents = $bid_amount_cents + $profit_loss_cents;
                // Update wallet balance with cents
                $update_wallet_query = "UPDATE wallets SET balance = balance + ? WHERE id = ?";
                $stmt_wallet = $conn->prepare($update_wallet_query);
                $stmt_wallet->bind_param("ii", $payout_amount_cents, $trade['wallet_id']); // 'd' changed to 'i'
                if (!$stmt_wallet->execute()) { throw new Exception("Failed to update wallet."); }
                $stmt_wallet->close();
                // Log the credit transaction in cents
                $log_trade_credit_query = "INSERT INTO transactions (user_id, wallet_id, type, amount, status) VALUES (?, ?, 'TRADE_CREDIT', ?, 'COMPLETED')";
                $stmt_log_credit = $conn->prepare($log_trade_credit_query);
                $stmt_log_credit->bind_param("iii", $trade['user_id'], $trade['wallet_id'], $payout_amount_cents); // 'd' changed to 'i'
                if (!$stmt_log_credit->execute()) { throw new Exception("Failed to log trade credit transaction."); }
                $stmt_log_credit->close();
            }

            $conn->commit();
            echo "Successfully settled trade ID #" . $trade['id'] . " as a " . $status . ".\n";

        } catch (Exception $e) {
            $conn->rollback();
            $log->error('Cron job settlement failed for a trade.', ['trade_id' => $trade['id'], 'error' => $e->getMessage()]);
            echo "Failed to settle trade ID #" . $trade['id'] . ". Error: " . $e->getMessage() . "\n";
        }
    }
} else {
    echo "No trades to settle.\n";
}

$conn->close();
?>
