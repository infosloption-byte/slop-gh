<?php
// /api/v1/notifications/get_all.php

require_once __DIR__ . '/../../../config/api_secure_bootstrap.php';

try {
    // The $user_id is provided by the secure bootstrap file
    
    // 1. Fetch all notifications for the user, newest first.
    $stmt = $conn->prepare("SELECT id, message, is_read, link, created_at FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 50");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $notifications = [];
    while ($row = $result->fetch_assoc()) {
        $notifications[] = $row;
    }
    $stmt->close();

    // 2. After fetching, mark all of this user's unread notifications as read.
    // This happens when they open the notification panel.
    $update_stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0");
    $update_stmt->bind_param("i", $user_id);
    $update_stmt->execute();
    $update_stmt->close();
    
    // 3. Send the list of notifications to the front-end.
    http_response_code(200);
    echo json_encode($notifications);

} catch (Exception $e) {
    $log->error('Failed to fetch notifications.', ['user_id' => $user_id, 'error' => $e->getMessage()]);
    http_response_code(500);
    echo json_encode(["message" => "Could not retrieve notifications."]);
}
?>