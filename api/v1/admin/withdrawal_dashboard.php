<?php
// api/v1/admin/withdrawal_dashboard.php
require_once __DIR__ . '/../../../config/api_secure_bootstrap.php';
require_once __DIR__ . '/../../../config/WithdrawalConfig.php';

try {
    if (!is_admin($conn, $user_id)) {
        throw new Exception("Forbidden. Admin privileges required.", 403);
    }

    // Get time range (default: last 7 days)
    $days = min(90, max(1, (int)($_GET['days'] ?? 7)));
    $start_date = date('Y-m-d', strtotime("-$days days"));
    $end_date = date('Y-m-d');

    // --- 1. OVERVIEW STATISTICS ---
    $overview_query = "SELECT
                        COUNT(*) as total_requests,
                        SUM(CASE WHEN status = 'PENDING' THEN 1 ELSE 0 END) as pending_count,
                        SUM(CASE WHEN status = 'APPROVED' THEN 1 ELSE 0 END) as approved_count,
                        SUM(CASE WHEN status = 'REJECTED' THEN 1 ELSE 0 END) as rejected_count,
                        SUM(CASE WHEN status = 'FAILED' THEN 1 ELSE 0 END) as failed_count,
                        SUM(CASE WHEN status = 'APPROVED' THEN amount ELSE 0 END) as total_withdrawn_cents,
                        SUM(CASE WHEN status = 'PENDING' THEN amount ELSE 0 END) as pending_amount_cents,
                        COUNT(DISTINCT user_id) as unique_users
                    FROM withdrawal_requests
                    WHERE DATE(created_at) BETWEEN ? AND ?";

    $stmt_overview = $conn->prepare($overview_query);
    $stmt_overview->bind_param("ss", $start_date, $end_date);
    $stmt_overview->execute();
    $overview = $stmt_overview->get_result()->fetch_assoc();
    $stmt_overview->close();

    // --- 2. PENDING WITHDRAWALS (REQUIRE IMMEDIATE ATTENTION) ---
    $pending_query = "SELECT
                        wr.id,
                        wr.user_id,
                        wr.amount,
                        wr.status,
                        wr.admin_notes,
                        wr.created_at,
                        wr.requested_amount_available,
                        u.email as user_email,
                        u.name as user_name,
                        pm.method_type,
                        pm.display_name as payout_method_name,
                        pm.account_identifier,
                        w.balance as current_balance
                    FROM withdrawal_requests wr
                    JOIN users u ON wr.user_id = u.id
                    JOIN wallets w ON wr.wallet_id = w.id
                    LEFT JOIN user_payout_methods pm ON wr.user_payout_method_id = pm.id
                    WHERE wr.status = 'PENDING'
                    ORDER BY wr.created_at ASC
                    LIMIT 50";

    $stmt_pending = $conn->prepare($pending_query);
    $stmt_pending->execute();
    $pending_result = $stmt_pending->get_result();

    $pending_withdrawals = [];
    while ($row = $pending_result->fetch_assoc()) {
        $amount_dollars = $row['amount'] / 100;
        $current_balance = $row['current_balance'] / 100;
        $requested_balance = $row['requested_amount_available'] / 100;
        $hours_waiting = round((time() - strtotime($row['created_at'])) / 3600, 1);

        $pending_withdrawals[] = [
            'id' => $row['id'],
            'user' => [
                'id' => $row['user_id'],
                'email' => $row['user_email'],
                'name' => $row['user_name']
            ],
            'amount' => $amount_dollars,
            'amount_formatted' => '$' . number_format($amount_dollars, 2),
            'payout_method' => [
                'type' => $row['method_type'],
                'name' => $row['payout_method_name'],
                'account' => $row['account_identifier']
            ],
            'balance_check' => [
                'current_balance' => $current_balance,
                'requested_balance' => $requested_balance,
                'sufficient' => $current_balance >= $amount_dollars,
                'balance_changed' => abs($current_balance - $requested_balance) > 0.01
            ],
            'created_at' => $row['created_at'],
            'created_at_formatted' => date('M d, Y h:i A', strtotime($row['created_at'])),
            'hours_waiting' => $hours_waiting,
            'priority' => $hours_waiting > 20 ? 'high' : ($hours_waiting > 12 ? 'medium' : 'normal'),
            'admin_notes' => $row['admin_notes']
        ];
    }
    $stmt_pending->close();

    // --- 3. RECENT PROCESSED WITHDRAWALS ---
    $recent_query = "SELECT
                        wr.id,
                        wr.user_id,
                        wr.amount,
                        wr.status,
                        wr.gateway_transaction_id,
                        wr.processed_at,
                        wr.processed_by,
                        u.email as user_email,
                        pm.display_name as payout_method_name,
                        admin.name as processed_by_name
                    FROM withdrawal_requests wr
                    JOIN users u ON wr.user_id = u.id
                    LEFT JOIN user_payout_methods pm ON wr.user_payout_method_id = pm.id
                    LEFT JOIN users admin ON wr.processed_by = admin.id
                    WHERE wr.status IN ('APPROVED', 'REJECTED')
                    AND DATE(wr.processed_at) BETWEEN ? AND ?
                    ORDER BY wr.processed_at DESC
                    LIMIT 20";

    $stmt_recent = $conn->prepare($recent_query);
    $stmt_recent->bind_param("ss", $start_date, $end_date);
    $stmt_recent->execute();
    $recent_result = $stmt_recent->get_result();

    $recent_processed = [];
    while ($row = $recent_result->fetch_assoc()) {
        $recent_processed[] = [
            'id' => $row['id'],
            'user_email' => $row['user_email'],
            'amount' => $row['amount'] / 100,
            'amount_formatted' => '$' . number_format($row['amount'] / 100, 2),
            'status' => $row['status'],
            'payout_method' => $row['payout_method_name'],
            'gateway_transaction_id' => $row['gateway_transaction_id'],
            'processed_at' => $row['processed_at'],
            'processed_at_formatted' => date('M d, Y h:i A', strtotime($row['processed_at'])),
            'processed_by' => $row['processed_by_name'] ?? 'System'
        ];
    }
    $stmt_recent->close();

    // --- 4. DAILY TREND ---
    $trend_query = "SELECT
                        DATE(created_at) as date,
                        COUNT(*) as total_count,
                        SUM(CASE WHEN status = 'APPROVED' THEN amount ELSE 0 END) as approved_amount_cents,
                        SUM(CASE WHEN status = 'APPROVED' THEN 1 ELSE 0 END) as approved_count,
                        SUM(CASE WHEN status = 'PENDING' THEN 1 ELSE 0 END) as pending_count
                    FROM withdrawal_requests
                    WHERE DATE(created_at) BETWEEN ? AND ?
                    GROUP BY DATE(created_at)
                    ORDER BY DATE(created_at) DESC
                    LIMIT 30";

    $stmt_trend = $conn->prepare($trend_query);
    $stmt_trend->bind_param("ss", $start_date, $end_date);
    $stmt_trend->execute();
    $trend_result = $stmt_trend->get_result();

    $daily_trend = [];
    while ($row = $trend_result->fetch_assoc()) {
        $daily_trend[] = [
            'date' => $row['date'],
            'date_formatted' => date('M d', strtotime($row['date'])),
            'total_requests' => (int)$row['total_count'],
            'approved_count' => (int)$row['approved_count'],
            'pending_count' => (int)$row['pending_count'],
            'total_amount' => $row['approved_amount_cents'] / 100,
            'total_amount_formatted' => '$' . number_format($row['approved_amount_cents'] / 100, 2)
        ];
    }
    $stmt_trend->close();

    // --- 5. TOP USERS BY WITHDRAWAL VOLUME ---
    $top_users_query = "SELECT
                            u.id,
                            u.email,
                            u.name,
                            COUNT(*) as withdrawal_count,
                            SUM(CASE WHEN wr.status = 'APPROVED' THEN wr.amount ELSE 0 END) as total_withdrawn_cents,
                            MAX(wr.created_at) as last_withdrawal
                        FROM withdrawal_requests wr
                        JOIN users u ON wr.user_id = u.id
                        WHERE DATE(wr.created_at) BETWEEN ? AND ?
                        GROUP BY u.id, u.email, u.name
                        ORDER BY total_withdrawn_cents DESC
                        LIMIT 10";

    $stmt_top_users = $conn->prepare($top_users_query);
    $stmt_top_users->bind_param("ss", $start_date, $end_date);
    $stmt_top_users->execute();
    $top_users_result = $stmt_top_users->get_result();

    $top_users = [];
    while ($row = $top_users_result->fetch_assoc()) {
        $top_users[] = [
            'user_id' => $row['id'],
            'email' => $row['email'],
            'name' => $row['name'],
            'withdrawal_count' => (int)$row['withdrawal_count'],
            'total_withdrawn' => $row['total_withdrawn_cents'] / 100,
            'total_withdrawn_formatted' => '$' . number_format($row['total_withdrawn_cents'] / 100, 2),
            'last_withdrawal' => $row['last_withdrawal'],
            'last_withdrawal_formatted' => date('M d, Y', strtotime($row['last_withdrawal']))
        ];
    }
    $stmt_top_users->close();

    // --- 6. BY PAYMENT METHOD ---
    $method_stats_query = "SELECT
                            pm.method_type,
                            COUNT(*) as count,
                            SUM(CASE WHEN wr.status = 'APPROVED' THEN wr.amount ELSE 0 END) as total_cents,
                            SUM(CASE WHEN wr.status = 'FAILED' THEN 1 ELSE 0 END) as failed_count
                        FROM withdrawal_requests wr
                        LEFT JOIN user_payout_methods pm ON wr.user_payout_method_id = pm.id
                        WHERE DATE(wr.created_at) BETWEEN ? AND ?
                        GROUP BY pm.method_type
                        ORDER BY count DESC";

    $stmt_method = $conn->prepare($method_stats_query);
    $stmt_method->bind_param("ss", $start_date, $end_date);
    $stmt_method->execute();
    $method_result = $stmt_method->get_result();

    $by_payment_method = [];
    while ($row = $method_result->fetch_assoc()) {
        $success_rate = $row['count'] > 0 ? round((($row['count'] - $row['failed_count']) / $row['count']) * 100, 1) : 0;

        $by_payment_method[] = [
            'method_type' => $row['method_type'] ?: 'manual',
            'display_name' => ucfirst($row['method_type'] ?: 'manual'),
            'count' => (int)$row['count'],
            'total_amount' => $row['total_cents'] / 100,
            'total_amount_formatted' => '$' . number_format($row['total_cents'] / 100, 2),
            'failed_count' => (int)$row['failed_count'],
            'success_rate' => $success_rate,
            'success_rate_formatted' => $success_rate . '%'
        ];
    }
    $stmt_method->close();

    // --- 7. FORMAT OVERVIEW ---
    $statistics = [
        'total_requests' => (int)$overview['total_requests'],
        'pending_count' => (int)$overview['pending_count'],
        'approved_count' => (int)$overview['approved_count'],
        'rejected_count' => (int)$overview['rejected_count'],
        'failed_count' => (int)$overview['failed_count'],
        'unique_users' => (int)$overview['unique_users'],
        'total_withdrawn' => $overview['total_withdrawn_cents'] / 100,
        'total_withdrawn_formatted' => '$' . number_format($overview['total_withdrawn_cents'] / 100, 2),
        'pending_amount' => $overview['pending_amount_cents'] / 100,
        'pending_amount_formatted' => '$' . number_format($overview['pending_amount_cents'] / 100, 2),
        'approval_rate' => $overview['total_requests'] > 0 ? round(($overview['approved_count'] / $overview['total_requests']) * 100, 1) : 0
    ];

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'period' => [
            'days' => $days,
            'start_date' => $start_date,
            'end_date' => $end_date
        ],
        'statistics' => $statistics,
        'pending_withdrawals' => [
            'count' => count($pending_withdrawals),
            'items' => $pending_withdrawals
        ],
        'recent_processed' => $recent_processed,
        'daily_trend' => array_reverse($daily_trend),
        'top_users' => $top_users,
        'by_payment_method' => $by_payment_method,
        'alerts' => [
            'high_priority_count' => count(array_filter($pending_withdrawals, function($w) {
                return $w['priority'] === 'high';
            })),
            'balance_issues_count' => count(array_filter($pending_withdrawals, function($w) {
                return !$w['balance_check']['sufficient'];
            }))
        ]
    ]);

} catch (Exception $e) {
    $log->error('Admin dashboard failed', [
        'admin_id' => $user_id ?? 0,
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
