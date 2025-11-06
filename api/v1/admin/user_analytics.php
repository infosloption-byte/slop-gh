<?php
// Load dependencies and services
require_once __DIR__ . '/../../../config/api_secure_bootstrap.php';

try {
    // Bootstrap provides: $conn, $log, $user_id.
    if (!is_admin($conn, $user_id)) {
        throw new Exception("Forbidden. Admin privileges required.", 403);
    }

    $wallet_filter = $_GET['wallet_type'] ?? 'all';

    // Build dynamic JOIN and WHERE clauses
    $join_clause = "";
    $where_conditions = []; // Use array for conditions
    $params = [];
    $types = '';
    if (in_array($wallet_filter, ['real', 'demo'])) {
        $join_clause = "JOIN wallets w ON t.wallet_id = w.id";
        $where_conditions[] = "w.type = ?"; // Add condition to array
        $params[] = $wallet_filter;
        $types .= 's';
    }
    // Build WHERE string only if conditions exist
    $where_string = count($where_conditions) > 0 ? "WHERE " . implode(' AND ', $where_conditions) : "";


    // --- START: MODIFIED TOP TRADERS BY VOLUME ---
    // 1. Fetch Top 5 Traders by Volume (SUM is in CENTS)
    // Alias sum as total_volume_cents
    $volume_query = "SELECT u.email, SUM(t.bid_amount) as total_volume_cents
                     FROM trades t
                     JOIN users u ON t.user_id = u.id
                     $join_clause
                     $where_string
                     GROUP BY t.user_id, u.email
                     ORDER BY total_volume_cents DESC
                     LIMIT 5";

    $stmt_volume = $conn->prepare($volume_query);
    // Bind parameters only if they exist
    if (!empty($params)) {
        $stmt_volume->bind_param($types, ...$params);
    }
    $stmt_volume->execute();
    $volume_result = $stmt_volume->get_result();

    $top_traders_by_volume_frontend = []; // New array for converted data
    while ($row_db = $volume_result->fetch_assoc()) {
        // Convert total_volume_cents to dollars
        $row_frontend = [
            'email' => $row_db['email'],
            'total_volume' => ((int)$row_db['total_volume_cents'] ?? 0) / 100.0 // Ensure integer before division
        ];
        $top_traders_by_volume_frontend[] = $row_frontend;
    }
    $stmt_volume->close();
    // --- END: MODIFIED TOP TRADERS BY VOLUME ---

    // --- START: MODIFIED TOP WINNERS BY P&L ---
    // 2. Fetch Top 5 Winners by Net P&L (SUM is in CENTS)
    // Add condition to only sum WIN/LOSE trades for P/L
    $where_string_pl = $where_string . (count($where_conditions) > 0 ? " AND " : "WHERE ") . "t.status IN ('WIN', 'LOSE')";
    // Alias sum as net_pl_cents
    $winners_query = "SELECT u.email, SUM(t.profit_loss) as net_pl_cents
                      FROM trades t
                      JOIN users u ON t.user_id = u.id
                      $join_clause
                      $where_string_pl
                      GROUP BY t.user_id, u.email
                      ORDER BY net_pl_cents DESC
                      LIMIT 5";

    $stmt_winners = $conn->prepare($winners_query);
     // Bind parameters only if they exist
    if (!empty($params)) {
        $stmt_winners->bind_param($types, ...$params); // Same params as volume query
    }
    $stmt_winners->execute();
    $winners_result = $stmt_winners->get_result();

    $top_winners_frontend = []; // New array for converted data
    while ($row_db = $winners_result->fetch_assoc()) {
        // Convert net_pl_cents to dollars
        $row_frontend = [
            'email' => $row_db['email'],
            'net_pl' => ((int)$row_db['net_pl_cents'] ?? 0) / 100.0 // Ensure integer before division
        ];
        $top_winners_frontend[] = $row_frontend;
    }
    $stmt_winners->close();
    // --- END: MODIFIED TOP WINNERS BY P&L ---

    // 3. Fetch Top 5 Countries by User Count (no money involved, no change)
    $countries_query = "SELECT country, COUNT(id) as user_count
                        FROM users
                        WHERE country IS NOT NULL AND country != ''
                        GROUP BY country
                        ORDER BY user_count DESC
                        LIMIT 5";
    $countries_result = $conn->query($countries_query);
    $top_countries = $countries_result->fetch_all(MYSQLI_ASSOC);

    // 4. Assemble all data into a single response
    $analytics_data = [
        "top_traders_by_volume" => $top_traders_by_volume_frontend, // Use converted data
        "top_winners_by_pl" => $top_winners_frontend,             // Use converted data
        "top_countries_by_user" => $top_countries
    ];

    http_response_code(200);
    echo json_encode($analytics_data);

} catch (Exception $e) {
    $log->error('Admin user analytics fetch failed.', ['user_id' => $user_id ?? 0, 'error' => $e->getMessage()]);
    $code = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 500;
    http_response_code($code);
    echo json_encode(["message" => $e->getMessage()]);
} finally {
    if ($conn) $conn->close();
}
?>

