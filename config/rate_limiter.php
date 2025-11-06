<?php
date_default_timezone_set('Asia/Colombo');
/**
 * Checks if an IP address has exceeded the request limit for a specific endpoint.
 *
 * @param mysqli $conn The database connection object.
 * @param string $ip The user's IP address.
 * @param string $endpoint A name for the endpoint (e.g., 'login').
 * @param int $limit The maximum number of requests allowed.
 * @param int $period The time period in seconds.
 * @return bool True if the request is allowed, false if it's blocked.
 */
function check_rate_limit($conn, $ip, $endpoint, $limit, $period, $user_id = null) {
    // 1. Cleanup old records (no change)
    $cleanup_query = "DELETE FROM api_requests WHERE timestamp < (NOW() - INTERVAL ? SECOND)";
    $stmt_cleanup = $conn->prepare($cleanup_query);
    $stmt_cleanup->bind_param("i", $period);
    $stmt_cleanup->execute();
    $stmt_cleanup->close();

    // 2. Count recent requests
    if ($user_id !== null) {
        // --- If a user is logged in, limit by their ID ---
        $query = "SELECT COUNT(*) as request_count FROM api_requests WHERE user_id = ? AND endpoint = ? AND timestamp > (NOW() - INTERVAL ? SECOND)";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("isi", $user_id, $endpoint, $period);
    } else {
        // --- If no user, limit by IP address (the original logic) ---
        $query = "SELECT COUNT(*) as request_count FROM api_requests WHERE ip_address = ? AND endpoint = ? AND timestamp > (NOW() - INTERVAL ? SECOND)";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("ssi", $ip, $endpoint, $period);
    }
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $request_count = $result['request_count'];

    // 3. Check if the limit is exceeded (no change)
    if ($request_count >= $limit) {
        return false;
    }

    // 4. Log the current request
    $log_query = "INSERT INTO api_requests (user_id, ip_address, endpoint) VALUES (?, ?, ?)";
    $stmt_log = $conn->prepare($log_query);
    $stmt_log->bind_param("iss", $user_id, $ip, $endpoint);
    $stmt_log->execute();
    $stmt_log->close();
    
    // 5. Allow the request (no change)
    return true;
}
?>