<?php
// api/v1/payments/withdrawals/withdrawal_analytics.php
require_once __DIR__ . '/../../../config/api_secure_bootstrap.php';
require_once __DIR__ . '/../../../config/WithdrawalConfig.php';

try {
    // Get time range (default: last 30 days)
    $days = min(365, max(1, (int)($_GET['days'] ?? 30))); // Between 1-365 days

    $start_date = date('Y-m-d', strtotime("-$days days"));
    $end_date = date('Y-m-d');

    // --- 1. WITHDRAWAL STATISTICS ---
    $stats_query = "SELECT
                        COUNT(*) as total_requests,
                        SUM(CASE WHEN status = 'PENDING' THEN 1 ELSE 0 END) as pending_count,
                        SUM(CASE WHEN status = 'APPROVED' THEN 1 ELSE 0 END) as approved_count,
                        SUM(CASE WHEN status = 'REJECTED' THEN 1 ELSE 0 END) as rejected_count,
                        SUM(CASE WHEN status = 'FAILED' THEN 1 ELSE 0 END) as failed_count,
                        SUM(CASE WHEN status = 'APPROVED' THEN amount ELSE 0 END) as total_withdrawn_cents,
                        AVG(CASE WHEN status = 'APPROVED' THEN amount ELSE NULL END) as avg_withdrawal_cents,
                        MIN(CASE WHEN status = 'APPROVED' THEN amount ELSE NULL END) as min_withdrawal_cents,
                        MAX(CASE WHEN status = 'APPROVED' THEN amount ELSE NULL END) as max_withdrawal_cents
                    FROM withdrawal_requests
                    WHERE user_id = ?
                    AND DATE(created_at) BETWEEN ? AND ?";

    $stmt_stats = $conn->prepare($stats_query);
    $stmt_stats->bind_param("iss", $user_id, $start_date, $end_date);
    $stmt_stats->execute();
    $stats = $stmt_stats->get_result()->fetch_assoc();
    $stmt_stats->close();

    // --- 2. DAILY WITHDRAWAL TREND ---
    $trend_query = "SELECT
                        DATE(created_at) as date,
                        COUNT(*) as count,
                        SUM(CASE WHEN status = 'APPROVED' THEN amount ELSE 0 END) as total_cents,
                        SUM(CASE WHEN status = 'APPROVED' THEN 1 ELSE 0 END) as approved_count
                    FROM withdrawal_requests
                    WHERE user_id = ?
                    AND DATE(created_at) BETWEEN ? AND ?
                    GROUP BY DATE(created_at)
                    ORDER BY DATE(created_at) DESC
                    LIMIT 30";

    $stmt_trend = $conn->prepare($trend_query);
    $stmt_trend->bind_param("iss", $user_id, $start_date, $end_date);
    $stmt_trend->execute();
    $trend_result = $stmt_trend->get_result();

    $daily_trend = [];
    while ($row = $trend_result->fetch_assoc()) {
        $daily_trend[] = [
            'date' => $row['date'],
            'date_formatted' => date('M d', strtotime($row['date'])),
            'total_requests' => (int)$row['count'],
            'approved_count' => (int)$row['approved_count'],
            'total_amount' => $row['total_cents'] / 100,
            'total_amount_formatted' => '$' . number_format($row['total_cents'] / 100, 2)
        ];
    }
    $stmt_trend->close();

    // --- 3. BY PAYMENT METHOD ---
    $method_query = "SELECT
                        pm.method_type,
                        pm.display_name,
                        COUNT(*) as count,
                        SUM(CASE WHEN wr.status = 'APPROVED' THEN wr.amount ELSE 0 END) as total_cents,
                        SUM(CASE WHEN wr.status = 'APPROVED' THEN 1 ELSE 0 END) as approved_count,
                        SUM(CASE WHEN wr.status = 'FAILED' THEN 1 ELSE 0 END) as failed_count
                    FROM withdrawal_requests wr
                    LEFT JOIN user_payout_methods pm ON wr.user_payout_method_id = pm.id
                    WHERE wr.user_id = ?
                    AND DATE(wr.created_at) BETWEEN ? AND ?
                    GROUP BY pm.method_type, pm.display_name
                    ORDER BY count DESC";

    $stmt_method = $conn->prepare($method_query);
    $stmt_method->bind_param("iss", $user_id, $start_date, $end_date);
    $stmt_method->execute();
    $method_result = $stmt_method->get_result();

    $by_method = [];
    while ($row = $method_result->fetch_assoc()) {
        $success_rate = $row['count'] > 0 ? round(($row['approved_count'] / $row['count']) * 100, 1) : 0;

        $by_method[] = [
            'method_type' => $row['method_type'] ?: 'manual',
            'display_name' => $row['display_name'] ?: 'Manual',
            'total_requests' => (int)$row['count'],
            'approved_count' => (int)$row['approved_count'],
            'failed_count' => (int)$row['failed_count'],
            'total_amount' => $row['total_cents'] / 100,
            'total_amount_formatted' => '$' . number_format($row['total_cents'] / 100, 2),
            'success_rate' => $success_rate,
            'success_rate_formatted' => $success_rate . '%'
        ];
    }
    $stmt_method->close();

    // --- 4. RECENT ACTIVITY ---
    $recent_query = "SELECT
                        wr.id,
                        wr.amount,
                        wr.status,
                        wr.created_at,
                        pm.display_name as payout_method_name
                    FROM withdrawal_requests wr
                    LEFT JOIN user_payout_methods pm ON wr.user_payout_method_id = pm.id
                    WHERE wr.user_id = ?
                    ORDER BY wr.created_at DESC
                    LIMIT 5";

    $stmt_recent = $conn->prepare($recent_query);
    $stmt_recent->bind_param("i", $user_id);
    $stmt_recent->execute();
    $recent_result = $stmt_recent->get_result();

    $recent_activity = [];
    while ($row = $recent_result->fetch_assoc()) {
        $recent_activity[] = [
            'id' => $row['id'],
            'amount' => $row['amount'] / 100,
            'amount_formatted' => '$' . number_format($row['amount'] / 100, 2),
            'status' => $row['status'],
            'payout_method' => $row['payout_method_name'],
            'created_at' => $row['created_at'],
            'created_at_formatted' => date('M d, Y h:i A', strtotime($row['created_at']))
        ];
    }
    $stmt_recent->close();

    // --- 5. CURRENT LIMITS ---
    $daily_check = WithdrawalConfig::checkDailyLimit($conn, $user_id, 0);

    $limits = [
        'daily' => [
            'limit' => WithdrawalConfig::MAX_DAILY_WITHDRAWAL_PER_USER,
            'used' => $daily_check['current_total'],
            'remaining' => $daily_check['remaining'],
            'percentage_used' => round(($daily_check['current_total'] / WithdrawalConfig::MAX_DAILY_WITHDRAWAL_PER_USER) * 100, 1)
        ],
        'monthly' => [
            'limit' => WithdrawalConfig::MAX_MONTHLY_WITHDRAWAL_PER_USER
        ],
        'per_withdrawal' => [
            'minimum' => WithdrawalConfig::MIN_WITHDRAWAL,
            'maximum' => WithdrawalConfig::MAX_SINGLE_WITHDRAWAL,
            'auto_approve_limit' => WithdrawalConfig::AUTO_APPROVE_LIMIT
        ]
    ];

    // --- 6. PROCESSING TIME ANALYSIS ---
    $processing_time_query = "SELECT
                                AVG(TIMESTAMPDIFF(SECOND, created_at, processed_at)) as avg_processing_seconds,
                                MIN(TIMESTAMPDIFF(SECOND, created_at, processed_at)) as min_processing_seconds,
                                MAX(TIMESTAMPDIFF(SECOND, created_at, processed_at)) as max_processing_seconds
                              FROM withdrawal_requests
                              WHERE user_id = ?
                              AND status IN ('APPROVED', 'REJECTED')
                              AND processed_at IS NOT NULL
                              AND DATE(created_at) BETWEEN ? AND ?";

    $stmt_processing = $conn->prepare($processing_time_query);
    $stmt_processing->bind_param("iss", $user_id, $start_date, $end_date);
    $stmt_processing->execute();
    $processing = $stmt_processing->get_result()->fetch_assoc();
    $stmt_processing->close();

    $processing_times = null;
    if ($processing['avg_processing_seconds']) {
        $processing_times = [
            'average' => round($processing['avg_processing_seconds'] / 3600, 1) . ' hours',
            'fastest' => round($processing['min_processing_seconds'] / 60, 1) . ' minutes',
            'slowest' => round($processing['max_processing_seconds'] / 3600, 1) . ' hours'
        ];
    }

    // --- 7. FORMAT MAIN STATISTICS ---
    $statistics = [
        'total_requests' => (int)$stats['total_requests'],
        'pending_count' => (int)$stats['pending_count'],
        'approved_count' => (int)$stats['approved_count'],
        'rejected_count' => (int)$stats['rejected_count'],
        'failed_count' => (int)$stats['failed_count'],
        'success_rate' => $stats['total_requests'] > 0 ? round(($stats['approved_count'] / $stats['total_requests']) * 100, 1) : 0,
        'total_withdrawn' => $stats['total_withdrawn_cents'] / 100,
        'total_withdrawn_formatted' => '$' . number_format($stats['total_withdrawn_cents'] / 100, 2),
        'average_withdrawal' => $stats['avg_withdrawal_cents'] ? $stats['avg_withdrawal_cents'] / 100 : 0,
        'average_withdrawal_formatted' => $stats['avg_withdrawal_cents'] ? '$' . number_format($stats['avg_withdrawal_cents'] / 100, 2) : '$0.00',
        'largest_withdrawal' => $stats['max_withdrawal_cents'] ? $stats['max_withdrawal_cents'] / 100 : 0,
        'largest_withdrawal_formatted' => $stats['max_withdrawal_cents'] ? '$' . number_format($stats['max_withdrawal_cents'] / 100, 2) : '$0.00',
        'smallest_withdrawal' => $stats['min_withdrawal_cents'] ? $stats['min_withdrawal_cents'] / 100 : 0,
        'smallest_withdrawal_formatted' => $stats['min_withdrawal_cents'] ? '$' . number_format($stats['min_withdrawal_cents'] / 100, 2) : '$0.00'
    ];

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'period' => [
            'days' => $days,
            'start_date' => $start_date,
            'end_date' => $end_date,
            'start_date_formatted' => date('M d, Y', strtotime($start_date)),
            'end_date_formatted' => date('M d, Y', strtotime($end_date))
        ],
        'statistics' => $statistics,
        'daily_trend' => array_reverse($daily_trend), // Oldest to newest
        'by_payment_method' => $by_method,
        'recent_activity' => $recent_activity,
        'limits' => $limits,
        'processing_times' => $processing_times
    ]);

} catch (Exception $e) {
    $log->error('Withdrawal analytics failed', [
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
