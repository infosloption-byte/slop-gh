<?php
// api/v1/payments/withdrawals/withdrawal_history.php
require_once __DIR__ . '/../../../config/api_secure_bootstrap.php';
require_once __DIR__ . '/../../../config/WithdrawalConfig.php';

try {
    // Get query parameters
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = min(100, max(10, (int)($_GET['limit'] ?? 20))); // Between 10-100
    $status_filter = $_GET['status'] ?? null; // PENDING, APPROVED, REJECTED, FAILED
    $offset = ($page - 1) * $limit;

    // Validate status filter
    $valid_statuses = ['PENDING', 'APPROVED', 'REJECTED', 'FAILED'];
    if ($status_filter && !in_array(strtoupper($status_filter), $valid_statuses)) {
        throw new Exception("Invalid status filter. Must be one of: " . implode(', ', $valid_statuses), 400);
    }

    // Build query
    $where_clause = "wr.user_id = ?";
    $params = [$user_id];
    $param_types = "i";

    if ($status_filter) {
        $where_clause .= " AND wr.status = ?";
        $params[] = strtoupper($status_filter);
        $param_types .= "s";
    }

    // Get total count
    $count_query = "SELECT COUNT(*) as total
                    FROM withdrawal_requests wr
                    WHERE $where_clause";

    $stmt_count = $conn->prepare($count_query);
    $stmt_count->bind_param($param_types, ...$params);
    $stmt_count->execute();
    $count_result = $stmt_count->get_result()->fetch_assoc();
    $total_records = $count_result['total'];
    $stmt_count->close();

    // Get withdrawal history
    $query = "SELECT
                wr.id,
                wr.amount,
                wr.status,
                wr.payout_method,
                wr.withdrawal_method,
                wr.gateway_transaction_id,
                wr.gateway_status,
                wr.failure_reason,
                wr.admin_notes,
                wr.requested_amount_available,
                wr.created_at,
                wr.processed_at,
                pm.method_type,
                pm.display_name as payout_method_name,
                pm.account_identifier
              FROM withdrawal_requests wr
              LEFT JOIN user_payout_methods pm ON wr.user_payout_method_id = pm.id
              WHERE $where_clause
              ORDER BY wr.created_at DESC
              LIMIT ? OFFSET ?";

    $params[] = $limit;
    $params[] = $offset;
    $param_types .= "ii";

    $stmt = $conn->prepare($query);
    $stmt->bind_param($param_types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();

    $withdrawals = [];
    while ($row = $result->fetch_assoc()) {
        // Convert amount from cents to dollars
        $amount_dollars = $row['amount'] / 100;

        // Calculate days since request
        $created_timestamp = strtotime($row['created_at']);
        $days_ago = floor((time() - $created_timestamp) / 86400);

        // Processing time (if processed)
        $processing_time = null;
        if ($row['processed_at']) {
            $processed_timestamp = strtotime($row['processed_at']);
            $processing_seconds = $processed_timestamp - $created_timestamp;
            $processing_hours = round($processing_seconds / 3600, 1);
            $processing_time = $processing_hours . ' hours';
        }

        // Status display
        $status_display = [
            'PENDING' => ['text' => 'Pending Review', 'color' => 'orange'],
            'APPROVED' => ['text' => 'Completed', 'color' => 'green'],
            'REJECTED' => ['text' => 'Rejected', 'color' => 'red'],
            'FAILED' => ['text' => 'Failed', 'color' => 'red']
        ];

        $withdrawals[] = [
            'id' => $row['id'],
            'amount' => $amount_dollars,
            'amount_formatted' => '$' . number_format($amount_dollars, 2),
            'status' => $row['status'],
            'status_display' => $status_display[$row['status']] ?? ['text' => $row['status'], 'color' => 'gray'],
            'payout_method' => [
                'type' => $row['method_type'] ?? $row['withdrawal_method'],
                'name' => $row['payout_method_name'] ?? ucfirst($row['payout_method']),
                'account' => $row['account_identifier'] ? substr($row['account_identifier'], 0, 8) . '***' : null
            ],
            'gateway_transaction_id' => $row['gateway_transaction_id'],
            'gateway_status' => $row['gateway_status'],
            'failure_reason' => $row['failure_reason'],
            'admin_notes' => $row['admin_notes'],
            'processing_time' => $processing_time,
            'created_at' => $row['created_at'],
            'created_at_formatted' => date('M d, Y h:i A', $created_timestamp),
            'days_ago' => $days_ago,
            'processed_at' => $row['processed_at'],
            'processed_at_formatted' => $row['processed_at'] ? date('M d, Y h:i A', strtotime($row['processed_at'])) : null
        ];
    }
    $stmt->close();

    // Calculate pagination
    $total_pages = ceil($total_records / $limit);
    $has_next_page = $page < $total_pages;
    $has_prev_page = $page > 1;

    // Get summary statistics
    $summary_query = "SELECT
                        COUNT(*) as total_withdrawals,
                        SUM(CASE WHEN status = 'PENDING' THEN 1 ELSE 0 END) as pending_count,
                        SUM(CASE WHEN status = 'APPROVED' THEN 1 ELSE 0 END) as approved_count,
                        SUM(CASE WHEN status = 'REJECTED' THEN 1 ELSE 0 END) as rejected_count,
                        SUM(CASE WHEN status = 'FAILED' THEN 1 ELSE 0 END) as failed_count,
                        SUM(CASE WHEN status = 'APPROVED' THEN amount ELSE 0 END) as total_withdrawn_cents,
                        SUM(CASE WHEN status = 'PENDING' THEN amount ELSE 0 END) as pending_amount_cents
                      FROM withdrawal_requests
                      WHERE user_id = ?";

    $stmt_summary = $conn->prepare($summary_query);
    $stmt_summary->bind_param("i", $user_id);
    $stmt_summary->execute();
    $summary = $stmt_summary->get_result()->fetch_assoc();
    $stmt_summary->close();

    $total_withdrawn = $summary['total_withdrawn_cents'] / 100;
    $pending_amount = $summary['pending_amount_cents'] / 100;

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'data' => $withdrawals,
        'pagination' => [
            'current_page' => $page,
            'total_pages' => $total_pages,
            'total_records' => $total_records,
            'per_page' => $limit,
            'has_next_page' => $has_next_page,
            'has_prev_page' => $has_prev_page,
            'next_page' => $has_next_page ? $page + 1 : null,
            'prev_page' => $has_prev_page ? $page - 1 : null
        ],
        'summary' => [
            'total_withdrawals' => (int)$summary['total_withdrawals'],
            'pending_count' => (int)$summary['pending_count'],
            'approved_count' => (int)$summary['approved_count'],
            'rejected_count' => (int)$summary['rejected_count'],
            'failed_count' => (int)$summary['failed_count'],
            'total_withdrawn' => $total_withdrawn,
            'total_withdrawn_formatted' => '$' . number_format($total_withdrawn, 2),
            'pending_amount' => $pending_amount,
            'pending_amount_formatted' => '$' . number_format($pending_amount, 2)
        ],
        'filters' => [
            'status' => $status_filter,
            'available_statuses' => $valid_statuses
        ]
    ]);

} catch (Exception $e) {
    $log->error('Withdrawal history failed', [
        'user_id' => $user_id ?? 0,
        'error' => $e->getMessage()
    ]);

    $code = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 500;
    http_response_code($code);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
} finally {
    if ($conn) {
        $conn->close();
    }
}
?>
