<?php
// api/v1/notifications/mark_read.php

require_once __DIR__ . '/../../../config/api_secure_bootstrap.php';

try {
    // Bootstrap provides $conn, $log, $user_id

    // Update all unread notifications for the current user
    $query = "UPDATE notifications SET is_read = TRUE WHERE user_id = ? AND is_read = FALSE";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $user_id);
    
    if ($stmt->execute()) {
        http_response_code(200);
        echo json_encode(["message" => "Notifications marked as read."]);
    } else {
        throw new Exception("Failed to update notifications.", 500);
    }
    $stmt->close();

} catch (Exception $e) {
    $log->error('Mark notifications read failed.', ['user_id' => $user_id ?? 0, 'error' => $e->getMessage()]);
    $code = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 500;
    http_response_code($code);
    echo json_encode(["message" => $e->getMessage()]);
} finally {
    if ($conn) { $conn->close(); }
}
?>