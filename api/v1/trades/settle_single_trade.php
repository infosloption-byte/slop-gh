<?php
// Load dependencies and services
require_once __DIR__ . '/../../../config/api_secure_bootstrap.php';

try {
    $trade_id = $_GET['id'] ?? 0;
    if (!$trade_id) {
        throw new Exception("No trade ID provided.", 400);
    }

    $conn->begin_transaction();

    // Lock the trade row to prevent interference. Values are now integers.
    $query_trade = "SELECT t.*, w.type as wallet_type 
                    FROM trades t
                    JOIN wallets w ON t.wallet_id = w.id
                    WHERE t.id = ? AND t.status = 'PENDING' FOR UPDATE";
    $stmt_trade = $conn->prepare($query_trade);
    $stmt_trade->bind_param("i", $trade_id);
    $stmt_trade->execute();
    $trade_db = $stmt_trade->get_result()->fetch_assoc();
    $stmt_trade->close();

    // If trade is not pending, fetch its final state and return it
    if (!$trade_db) {
        $conn->commit(); // Commit to release the lock
        $query_final = "SELECT t.*, w.type as wallet_type 
                        FROM trades t
                        JOIN wallets w ON t.wallet_id = w.id
                        WHERE t.id = ?";
        $stmt_final = $conn->prepare($query_final);
        $stmt_final->bind_param("i", $trade_id);
        $stmt_final->execute();
        $final_trade_db = $stmt_final->get_result()->fetch_assoc();
        $stmt_final->close();

        if ($final_trade_db) {
            // Convert final trade data for front-end before sending
            $final_trade_frontend = $final_trade_db;
            $final_trade_frontend['bid_amount'] = $final_trade_db['bid_amount'] / 100.0;
            if ($final_trade_db['profit_loss'] !== null) {
                $final_trade_frontend['profit_loss'] = $final_trade_db['profit_loss'] / 100.0;
            }
            $final_trade_frontend['entry_price'] = $final_trade_db['entry_price'] / 1000000.0;
            if ($final_trade_db['close_price'] !== null) {
                $final_trade_frontend['close_price'] = $final_trade_db['close_price'] / 1000000.0;
            }
            http_response_code(200);
            echo json_encode($final_trade_frontend);
        } else {
            http_response_code(404);
            echo json_encode(["message" => "Trade not found."]);
        }
        exit();
    }

    // --- Settle the Trade using Integer Math ---
    $close_price_float = getClosingPrice($trade_db['pair'], $trade_db['expires_at'], $log);
    if ($close_price_float == 0) { throw new Exception("Failed to fetch historical price for " . $trade_db['pair']); }

    $close_price_int = (int)($close_price_float * 1000000);
    $entry_price_int = (int)$trade_db['entry_price'];
    $bid_amount_cents = (int)$trade_db['bid_amount'];

    $is_win = false;
    if ($trade_db['direction'] == 'HIGH' && $close_price_int > $entry_price_int) $is_win = true;
    if ($trade_db['direction'] == 'LOW' && $close_price_int < $entry_price_int) $is_win = true;
    
    $status = $is_win ? 'WIN' : 'LOSE';
    $payout_rate = (float)$trade_db['payout_rate'];
    $profit_loss_cents = $is_win ? (int)($bid_amount_cents * $payout_rate) : -$bid_amount_cents;

    // Update the trade status with integer values
    $update_trade_query = "UPDATE trades SET status = ?, close_price = ?, profit_loss = ? WHERE id = ?";
    $stmt_update = $conn->prepare($update_trade_query);
    $stmt_update->bind_param("siii", $status, $close_price_int, $profit_loss_cents, $trade_db['id']);
    if (!$stmt_update->execute()) { throw new Exception("Failed to update trade status."); }
    $stmt_update->close();

    // If it was a win, update wallet and log transaction in cents
    if ($is_win) {
        $payout_amount_cents = $bid_amount_cents + $profit_loss_cents;
        
        $update_wallet_query = "UPDATE wallets SET balance = balance + ? WHERE id = ?";
        $stmt_wallet = $conn->prepare($update_wallet_query);
        $stmt_wallet->bind_param("ii", $payout_amount_cents, $trade_db['wallet_id']);
        if (!$stmt_wallet->execute()) { throw new Exception("Failed to update wallet balance."); }
        $stmt_wallet->close();

        $log_trade_credit_query = "INSERT INTO transactions (user_id, wallet_id, type, amount, status) VALUES (?, ?, 'TRADE_CREDIT', ?, 'COMPLETED')";
        $stmt_log_credit = $conn->prepare($log_trade_credit_query);
        $stmt_log_credit->bind_param("iii", $trade_db['user_id'], $trade_db['wallet_id'], $payout_amount_cents);
        if (!$stmt_log_credit->execute()) { throw new Exception("Failed to log trade credit transaction."); }
        $stmt_log_credit->close();
    }
    
    // Fetch the final, updated trade data to return
    $query_final = "SELECT t.*, w.type as wallet_type FROM trades t JOIN wallets w ON t.wallet_id = w.id WHERE t.id = ?";
    $stmt_final = $conn->prepare($query_final);
    $stmt_final->bind_param("i", $trade_id);
    $stmt_final->execute();
    $final_trade_db = $stmt_final->get_result()->fetch_assoc();
    $stmt_final->close();

    // --- CONVERT FOR FRONT-END ---
    $final_trade_frontend = $final_trade_db;
    $final_trade_frontend['bid_amount'] = $final_trade_db['bid_amount'] / 100.0;
    if ($final_trade_db['profit_loss'] !== null) {
        $final_trade_frontend['profit_loss'] = $final_trade_db['profit_loss'] / 100.0;
    }
    $final_trade_frontend['entry_price'] = $final_trade_db['entry_price'] / 1000000.0;
    if ($final_trade_db['close_price'] !== null) {
        $final_trade_frontend['close_price'] = $final_trade_db['close_price'] / 1000000.0;
    }

    // Send Pusher notification (no change needed here)
    $notificationData = ['message' => "Your {$final_trade_frontend['pair']} trade was a {$final_trade_frontend['status']}!", 'status' => $final_trade_frontend['status']];
    send_notification($final_trade_frontend['user_id'], 'trade-settled', $notificationData);

    $conn->commit();
    http_response_code(200);
    echo json_encode($final_trade_frontend);

} catch (Exception $e) {
    if ($conn) $conn->rollback();
    $log->error('On-demand settlement failed.', ['trade_id' => $trade_id ?? 0, 'error' => $e->getMessage()]);
    $code = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 500;
    http_response_code($code);
    echo json_encode(["message" => $e->getMessage()]);
} finally {
    if ($conn) {
        $conn->close();
    }
}
?>