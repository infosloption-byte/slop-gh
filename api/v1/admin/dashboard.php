<?php
// Load dependencies and services
require_once __DIR__ . '/../../../config/api_secure_bootstrap.php';

try {
    // Bootstrap provides: $conn, $log, $user_id.
    if (!is_admin($conn, $user_id)) {
        throw new Exception("Forbidden. Admin privileges required.", 403);
    }

    $wallet_filter = $_GET['wallet_type'] ?? 'all'; // 'all', 'real', 'demo'
    
    // Fetch admin's email (no change)
    $query_admin = "SELECT email FROM users WHERE id = ?";
    $stmt_admin = $conn->prepare($query_admin);
    $stmt_admin->bind_param("i", $user_id);
    $stmt_admin->execute();
    $admin = $stmt_admin->get_result()->fetch_assoc();
    $stmt_admin->close();

    // Build WHERE clause for trade filtering (no change)
    $where_clause = "";
    $params = [];
    $types = '';
    if (in_array($wallet_filter, ['real', 'demo'])) {
        $where_clause = "WHERE w.type = ?";
        $params[] = $wallet_filter;
        $types .= 's';
    }

    // --- Fetch KPI data (Sums are now in CENTS) ---

    // Total Users & Active Users (no change)
    $total_users = $conn->query("SELECT COUNT(*) as count FROM users")->fetch_assoc()['count'];
    $active_users_query = "SELECT COUNT(*) as count FROM users WHERE last_seen_at >= NOW() - INTERVAL 5 MINUTE";
    $active_users = $conn->query($active_users_query)->fetch_assoc()['count'];
    
    // Total Volume (SUM is in CENTS)
    $query_volume = "SELECT SUM(t.bid_amount) as sum_cents FROM trades t JOIN wallets w ON t.wallet_id = w.id $where_clause";
    $stmt_volume = $conn->prepare($query_volume);
    if ($wallet_filter !== 'all') $stmt_volume->bind_param($types, ...$params);
    $stmt_volume->execute();
    $total_volume_cents = $stmt_volume->get_result()->fetch_assoc()['sum_cents'] ?? 0;
    $stmt_volume->close();

    // Platform Profit (SUMs are in CENTS)
    $ggr_query = "SELECT 
                    SUM(CASE WHEN t.status = 'LOSE' THEN t.bid_amount ELSE 0 END) as total_losses_cents,
                    SUM(CASE WHEN t.status = 'WIN' THEN t.profit_loss ELSE 0 END) as total_profits_paid_cents
                  FROM trades t JOIN wallets w ON t.wallet_id = w.id $where_clause";
    $stmt_ggr = $conn->prepare($ggr_query);
    if ($wallet_filter !== 'all') $stmt_ggr->bind_param($types, ...$params);
    $stmt_ggr->execute();
    $ggr_data = $stmt_ggr->get_result()->fetch_assoc();
    $platform_profit_cents = ((int)($ggr_data['total_losses_cents'] ?? 0)) - ((int)($ggr_data['total_profits_paid_cents'] ?? 0));
    $stmt_ggr->close();

    // Deposit and Withdrawal Stats (SUMs are in CENTS)
    $total_deposits_cents = $conn->query("SELECT SUM(amount) as sum_cents FROM transactions WHERE type = 'DEPOSIT' AND status = 'COMPLETED'")->fetch_assoc()['sum_cents'] ?? 0;
    $pending_withdrawals_cents = $conn->query("SELECT SUM(amount) as sum_cents FROM withdrawal_requests WHERE status = 'PENDING'")->fetch_assoc()['sum_cents'] ?? 0;
    $approved_withdrawals_cents = $conn->query("SELECT SUM(amount) as sum_cents FROM withdrawal_requests WHERE status = 'APPROVED'")->fetch_assoc()['sum_cents'] ?? 0;

    // --- Assemble and Convert data for Frontend ---
    $dashboard_data = [
        "admin_email" => $admin['email'],
        "kpis" => [
            "total_users" => (int)$total_users,
            "active_users" => (int)$active_users,
            // Convert CENTS to DOLLARS before sending
            "total_volume" => (int)$total_volume_cents / 100.0,
            "platform_profit" => (int)$platform_profit_cents / 100.0,
            "total_deposits" => (int)$total_deposits_cents / 100.0,
            "pending_withdrawals" => (int)$pending_withdrawals_cents / 100.0,
            "approved_withdrawals" => (int)$approved_withdrawals_cents / 100.0
        ]
    ];

    http_response_code(200);
    echo json_encode($dashboard_data);

} catch (Exception $e) {
    $log->error('Admin dashboard fetch failed.', ['user_id' => $user_id ?? 0, 'error' => $e->getMessage()]);
    $code = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 500;
    http_response_code($code);
    echo json_encode(["message" => $e->getMessage()]);
} finally {
    if ($conn) $conn->close();
}
?>

