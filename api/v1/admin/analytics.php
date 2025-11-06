<?php
// Load dependencies and services
require_once __DIR__ . '/../../../config/api_secure_bootstrap.php';

try {
    // Bootstrap provides: $conn, $log, $user_id.
    if (!is_admin($conn, $user_id)) {
        throw new Exception("Forbidden. Admin privileges required.", 403);
    }

    $wallet_filter = $_GET['wallet_type'] ?? 'all';

    // 1. Fetch New User Registrations (no change)
    $registrations_query = "SELECT DATE(created_at) as registration_date, COUNT(id) as count 
                            FROM users 
                            WHERE created_at >= NOW() - INTERVAL 30 DAY
                            GROUP BY DATE(created_at)
                            ORDER BY registration_date ASC";
    $registrations_result = $conn->query($registrations_query);
    $user_registrations = [];
    while ($row = $registrations_result->fetch_assoc()) {
        $user_registrations[] = $row;
    }

    // --- Prepare filtering clauses ---
    $join_clause = "";
    $where_conditions = []; // Initialize as empty array
    $params = [];
    $types = '';

    if (in_array($wallet_filter, ['real', 'demo'])) {
        $join_clause = "JOIN wallets w ON t.wallet_id = w.id";
        $where_conditions[] = "w.type = ?";
        $params[] = $wallet_filter;
        $types .= 's';
    }
    // Always add status filter if needed for specific queries later
    $where_string_base = count($where_conditions) > 0 ? "WHERE " . implode(' AND ', $where_conditions) : "";


    // 2. Fetch Overall Trade Outcomes (WIN vs LOSE) (no change)
    $outcomes_where_conditions = $where_conditions; // Copy base conditions
    $outcomes_where_conditions[] = "t.status IN ('WIN', 'LOSE')"; // Add status condition
    $outcomes_where_string = "WHERE " . implode(' AND ', $outcomes_where_conditions);
    $outcomes_params = $params; // Copy base params
    $outcomes_types = $types;

    $outcomes_query = "SELECT t.status, COUNT(t.id) as count 
                       FROM trades t 
                       $join_clause
                       $outcomes_where_string
                       GROUP BY t.status";
                       
    $stmt_outcomes = $conn->prepare($outcomes_query);
    if (!empty($outcomes_params)) { // Check if params exist before binding
        $stmt_outcomes->bind_param($outcomes_types, ...$outcomes_params);
    }
    $stmt_outcomes->execute();
    $outcomes_result = $stmt_outcomes->get_result();
    $trade_outcomes = [];
    while ($row = $outcomes_result->fetch_assoc()) {
        $trade_outcomes[$row['status']] = (int)$row['count'];
    }
    $stmt_outcomes->close();
    
    // --- START: MODIFIED VOLUME QUERY ---
    // 3. Fetch Most Traded Pairs by Volume (SUM is in CENTS)
    // Use the base WHERE clause (no status filter needed here)
    $volume_query = "SELECT t.pair, SUM(t.bid_amount) as total_volume_cents 
                     FROM trades t 
                     $join_clause 
                     $where_string_base 
                     GROUP BY t.pair 
                     ORDER BY total_volume_cents DESC";
    $stmt_volume = $conn->prepare($volume_query);
    if (!empty($params)) { // Use original params
         $stmt_volume->bind_param($types, ...$params);
    }
    $stmt_volume->execute();
    $volume_result = $stmt_volume->get_result();
    $most_traded_pairs_frontend = []; // New array for converted data
    while ($row_db = $volume_result->fetch_assoc()) {
        // Convert total_volume_cents to dollars
        $row_frontend = [
            'pair' => $row_db['pair'],
            'total_volume' => (int)$row_db['total_volume_cents'] / 100.0
        ];
        $most_traded_pairs_frontend[] = $row_frontend;
    }
    $stmt_volume->close();
    // --- END: MODIFIED VOLUME QUERY ---

    // 4. Fetch Platform Win Rate by Pair (no money involved, no change)
    // Use the outcomes WHERE clause again as we only care about WIN/LOSE
    $win_rate_query = "SELECT 
                           t.pair, 
                           SUM(CASE WHEN t.status = 'LOSE' THEN 1 ELSE 0 END) as platform_wins, 
                           COUNT(t.id) as total_trades
                         FROM trades t
                         $join_clause
                         $outcomes_where_string 
                         GROUP BY t.pair";
    $stmt_win_rate = $conn->prepare($win_rate_query);
     if (!empty($outcomes_params)) {
        $stmt_win_rate->bind_param($outcomes_types, ...$outcomes_params);
    }
    $stmt_win_rate->execute();
    $win_rate_result = $stmt_win_rate->get_result();
    $win_rate_by_pair = [];
    while ($row = $win_rate_result->fetch_assoc()) {
        $win_rate_by_pair[] = $row;
    }
    $stmt_win_rate->close();

    // 5. Assemble all data into a single response
    $analytics_data = [
        "user_registrations_daily" => $user_registrations,
        "trade_outcomes" => [ "win" => $trade_outcomes['WIN'] ?? 0, "lose" => $trade_outcomes['LOSE'] ?? 0 ],
        "most_traded_pairs" => $most_traded_pairs_frontend, // Use the converted data
        "win_rate_by_pair" => $win_rate_by_pair
    ];

    http_response_code(200);
    echo json_encode($analytics_data);

} catch (Exception $e) {
    $log->error('Admin analytics fetch failed.', ['error' => $e->getMessage()]);
    $code = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 500;
    http_response_code($code);
    echo json_encode(["message" => $e->getMessage()]);
} finally {
    if ($conn) $conn->close();
}
?>
