<?php
// api/v1/payments/payout_methods/list_methods.php
require_once __DIR__ . '/../../../config/api_secure_bootstrap.php';

try {
    // Check if table exists
    $table_check = $conn->query(
        "SELECT 1 FROM information_schema.tables
         WHERE table_schema = DATABASE()
         AND table_name = 'user_payout_methods'
         LIMIT 1"
    );

    if (!$table_check || $table_check->num_rows === 0) {
        // Table doesn't exist yet, return empty array
        $log->warning('user_payout_methods table does not exist', ['user_id' => $user_id]);
        http_response_code(200);
        echo json_encode([]);
        exit;
    }

    // Fetch all active payout methods for the user
    $query = "SELECT id, user_id, method_type, display_name, account_identifier, is_default, is_active, created_at
              FROM user_payout_methods
              WHERE user_id = ? AND is_active = 1
              ORDER BY is_default DESC, created_at DESC";

    $stmt = $conn->prepare($query);

    if (!$stmt) {
        throw new Exception("Database error: " . $conn->error);
    }

    $stmt->bind_param("i", $user_id);

    if (!$stmt->execute()) {
        throw new Exception("Query execution failed: " . $stmt->error);
    }

    $result = $stmt->get_result();

    $methods = [];
    while ($row = $result->fetch_assoc()) {
        // Mask account identifier for security
        $masked_account = $row['account_identifier'];
        if (strlen($masked_account) > 8) {
            $masked_account = substr($masked_account, 0, 8) . '***';
        }

        $methods[] = [
            'id' => (int)$row['id'],
            'method_type' => $row['method_type'],
            'display_name' => $row['display_name'],
            'account_identifier' => $masked_account,
            'is_default' => (bool)$row['is_default'],
            'created_at' => $row['created_at']
        ];
    }

    $stmt->close();

    $log->info('Payout methods retrieved', [
        'user_id' => $user_id,
        'count' => count($methods)
    ]);

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'methods' => $methods,
        'count' => count($methods)
    ]);

} catch (mysqli_sql_exception $e) {
    $log->error('List payout methods failed - SQL error', [
        'user_id' => $user_id ?? 0,
        'error' => $e->getMessage(),
        'code' => $e->getCode()
    ]);

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error occurred. Please contact support.',
        'error_code' => 'DB_ERROR'
    ]);
} catch (Exception $e) {
    $log->error('List payout methods failed', [
        'user_id' => $user_id ?? 0,
        'error' => $e->getMessage()
    ]);

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to retrieve payout methods. Please try again.',
        'error_code' => 'UNKNOWN_ERROR'
    ]);
} finally {
    if ($conn) $conn->close();
}
?>