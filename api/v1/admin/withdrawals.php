<?php
// api/v1/admin/withdrawals.php (UPDATED for Hybrid Mode)
require_once __DIR__ . '/../../../config/api_secure_bootstrap.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

try {
    if (!is_admin($conn, $user_id)) {
        throw new Exception("Forbidden. Admin privileges required.", 403);
    }

    $allowed_sort_columns = ['requested_at', 'amount', 'status', 'email', 'withdrawal_method'];
    
    $search_email = $_GET['search_email'] ?? '';
    $filter_status = in_array($_GET['filter_status'] ?? '', ['PENDING', 'APPROVED', 'REJECTED', 'FAILED']) ? $_GET['filter_status'] : '';
    $filter_method = in_array($_GET['filter_method'] ?? '', ['manual', 'automated', 'paypal', 'binance', 'skrill']) ? $_GET['filter_method'] : '';
    $start_date = $_GET['start_date'] ?? '';
    $end_date = $_GET['end_date'] ?? '';

    $requested_sort_by = $_GET['sort_by'] ?? 'requested_at';
    $sort_by = in_array($requested_sort_by, $allowed_sort_columns) ? $requested_sort_by : 'requested_at';
    $requested_sort_order = strtoupper($_GET['sort_order'] ?? 'DESC');
    $sort_order = in_array($requested_sort_order, ['ASC', 'DESC']) ? $requested_sort_order : 'DESC';

    // --- NEW SQL QUERY ---
    // We LEFT JOIN user_payout_methods to get the display name
    $sql = "SELECT 
                wr.id, wr.user_id, u.email, wr.amount, wr.status, wr.requested_at, wr.processed_at, 
                wr.gateway_transaction_id, wr.withdrawal_method, wr.payout_method,
                wr.manual_card_number_last4, wr.manual_card_holder_name, wr.manual_bank_name,
                wr.admin_notes, wr.failure_reason,
                wr.user_payout_method_id,
                pm.display_name as payout_method_name,
                pc.card_brand, pc.card_last4, pc.card_holder_name
            FROM withdrawal_requests wr
            JOIN users u ON wr.user_id = u.id
            LEFT JOIN payout_cards pc ON wr.payout_card_id = pc.id
            LEFT JOIN user_payout_methods pm ON wr.user_payout_method_id = pm.id
            ";
    // --- END NEW SQL QUERY ---
    
    $where_conditions = [];
    $params = [];
    $types = '';

    if (!empty($search_email)) {
        $where_conditions[] = "u.email LIKE ?";
        $params[] = "%{$search_email}%";
        $types .= 's';
    }
    
    if (!empty($filter_status)) {
        $where_conditions[] = "wr.status = ?";
        $params[] = $filter_status;
        $types .= 's';
    }

    if (!empty($filter_method)) {
        // Filter by the new generic `withdrawal_method` column
        $where_conditions[] = "wr.withdrawal_method = ?";
        $params[] = $filter_method;
        $types .= 's';
    }

    if (!empty($start_date)) {
        $where_conditions[] = "DATE(wr.requested_at) >= ?";
        $params[] = $start_date;
        $types .= 's';
    }
    if (!empty($end_date)) {
        $where_conditions[] = "DATE(wr.requested_at) <= ?";
        $params[] = $end_date;
        $types .= 's';
    }

    if (count($where_conditions) > 0) {
        $sql .= " WHERE " . implode(' AND ', $where_conditions);
    }
    
    $sort_column_prefix = ($sort_by === 'email') ? 'u' : 'wr';
    $sort_column = ($sort_by === 'amount') ? 'wr.amount' : $sort_column_prefix . "." . $sort_by;
    $sql .= " ORDER BY " . $sort_column . " " . $sort_order;

    $stmt = $conn->prepare($sql);
    if (count($params) > 0) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();

    $requests_frontend = [];
    while ($row_db = $result->fetch_assoc()) {
        $row_frontend = $row_db;
        $row_frontend['amount'] = (int)$row_db['amount'] / 100.0;
        
        // --- NEW LOGIC: Create a clean display name ---
        if (!empty($row_frontend['payout_method_name'])) {
            // New system: "Visa **** 4242" or "paypal@user.com"
            $row_frontend['payout_display'] = $row_frontend['payout_method_name'];
        } else if (!empty($row_frontend['manual_card_number_last4'])) {
            // Old manual system
            $row_frontend['payout_display'] = "Manual: **** " . $row_frontend['manual_card_number_last4'];
        } else if (!empty($row_frontend['card_last4'])) {
            // Old automated system
            $row_frontend['payout_display'] = $row_frontend['card_brand'] . " **** " . $row_frontend['card_last4'];
        } else {
            $row_frontend['payout_display'] = "Unknown";
        }
        // --- END NEW LOGIC ---
        
        $requests_frontend[] = $row_frontend;
    }

    http_response_code(200);
    echo json_encode($requests_frontend);

} catch (Exception $e) {
    $log->error('Admin fetch withdrawals failed.', ['user_id' => $user_id ?? 0, 'error' => $e->getMessage()]);
    $code = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 500;
    http_response_code($code);
    echo json_encode(["message" => $e->getMessage()]);
    
} finally {
    if ($conn) $conn->close();
}
?>