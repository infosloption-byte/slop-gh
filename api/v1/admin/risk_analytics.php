<?php
// Load dependencies and services
require_once __DIR__ . '/../../../config/api_secure_bootstrap.php';

try {
    // Bootstrap provides: $conn, $log, $user_id.
    if (!is_admin($conn, $user_id)) {
        throw new Exception("Forbidden. Admin privileges required.", 403);
    }

    $wallet_filter = $_GET['wallet_type'] ?? 'all';

    // Build dynamic JOIN and WHERE clauses (no change)
    $join_clause = "";
    $where_conditions = []; // Base conditions array
    $params = [];
    $types = '';
    if (in_array($wallet_filter, ['real', 'demo'])) {
        $join_clause = "JOIN wallets w ON t.wallet_id = w.id";
        $where_conditions[] = "w.type = ?";
        $params[] = $wallet_filter;
        $types .= 's';
    }
    // Base WHERE clause string for queries that don't need status filter
    $where_string_base = count($where_conditions) > 0 ? "WHERE " . implode(' AND ', $where_conditions) : "";

    // --- START: MODIFIED BIGGEST WINS QUERY ---
    // 1. Fetch Largest Single Winning Trades (profit_loss is in CENTS)
    // Add t.status = 'WIN' condition dynamically
    $wins_where_conditions = $where_conditions; // Copy base conditions
    $wins_where_conditions[] = "t.status = 'WIN'";
    $wins_where_string = "WHERE " . implode(' AND ', $wins_where_conditions);
    $wins_params = $params; // Copy base params
    $wins_types = $types;

    // Alias profit_loss as profit_loss_cents for clarity
    $biggest_wins_query = "SELECT u.email, t.pair, t.profit_loss as profit_loss_cents, t.created_at 
                           FROM trades t 
                           JOIN users u ON t.user_id = u.id
                           $join_clause
                           $wins_where_string 
                           ORDER BY t.profit_loss DESC 
                           LIMIT 5";
                           
    $stmt_wins = $conn->prepare($biggest_wins_query);
    if (!empty($wins_params)) { // Check if params exist before binding
         $stmt_wins->bind_param($wins_types, ...$wins_params);
    }
    $stmt_wins->execute();
    $wins_result = $stmt_wins->get_result();

    $biggest_winning_trades_frontend = []; // New array for converted data
    while ($row_db = $wins_result->fetch_assoc()) {
        // Convert profit_loss_cents to dollars
        $row_frontend = $row_db; // Copy other fields
        $row_frontend['profit_loss'] = (int)$row_db['profit_loss_cents'] / 100.0;
        unset($row_frontend['profit_loss_cents']); // Remove the cents field
        $biggest_winning_trades_frontend[] = $row_frontend;
    }
    $stmt_wins->close();
    // --- END: MODIFIED BIGGEST WINS QUERY ---

    // 2. Fetch Users with High Win Rates (no money involved, no change)
    // Add t.status IN ('WIN', 'LOSE') condition dynamically
    $win_rate_where_conditions = $where_conditions; // Copy base conditions
    $win_rate_where_conditions[] = "t.status IN ('WIN', 'LOSE')";
    $win_rate_where_string = "WHERE " . implode(' AND ', $win_rate_where_conditions);
    $win_rate_params = $params; // Copy base params
    $win_rate_types = $types;

    $high_win_rate_query = "SELECT u.email, 
                                   (SUM(CASE WHEN t.status = 'WIN' THEN 1 ELSE 0 END) / COUNT(t.id)) * 100 as win_rate, 
                                   COUNT(t.id) as total_trades 
                            FROM trades t 
                            JOIN users u ON t.user_id = u.id 
                            $join_clause 
                            $win_rate_where_string 
                            GROUP BY t.user_id, u.email
                            HAVING total_trades >= 10
                            ORDER BY win_rate DESC 
                            LIMIT 5";
                            
    $stmt_win_rate = $conn->prepare($high_win_rate_query);
    if (!empty($win_rate_params)) { // Check if params exist before binding
        $stmt_win_rate->bind_param($win_rate_types, ...$win_rate_params);
    }
    $stmt_win_rate->execute();
    $win_rate_result = $stmt_win_rate->get_result();
    $users_high_win_rate = $win_rate_result->fetch_all(MYSQLI_ASSOC);
    $stmt_win_rate->close();

    // 3. Assemble all data into a single response
    $risk_data = [
        "biggest_winning_trades" => $biggest_winning_trades_frontend, // Use the converted data
        "users_high_win_rate" => $users_high_win_rate
    ];

    http_response_code(200);
    echo json_encode($risk_data);

} catch (Exception $e) {
    $log->error('Admin risk analytics fetch failed.', ['user_id' => $user_id ?? 0, 'error' => $e->getMessage()]);
    $code = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 500;
    http_response_code($code);
    echo json_encode(["message" => $e->getMessage()]);
} finally {
    if ($conn) $conn->close();
}
?>
