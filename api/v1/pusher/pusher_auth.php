<?php
// /api/pusher/pusher_auth.php

// Use the secure bootstrap to get the logged-in user's ID
require_once __DIR__ . '/../../../config/api_secure_bootstrap.php';

// --- ADD THIS BLOCK TO CHECK THE RESULT ---
if (!isset($user_id)) {
    http_response_code(500);
    echo "Error: The user ID was not set by the bootstrap file.";
    exit();
}
// --- END BLOCK ---


try {
    // $user_id is provided by api_secure_bootstrap.php

    $socket_id = $_POST['socket_id'];
    $channel_name = $_POST['channel_name'];

    // Make sure the user is only trying to access their own channel
    $expected_channel = 'private-user-' . $user_id;

    if ($channel_name === $expected_channel) {
        // Use the service to authorize the channel
        $auth = $pusherService->authorizeChannel($channel_name, $socket_id);
        echo $auth;
    } else {
        http_response_code(403);
        echo "Forbidden";
    }
} catch (Exception $e) {
    $log->error('Pusher failed.', ['error' => $e->getMessage()]);
    http_response_code(500);
    error_log("Pusher auth failed: " . $e->getMessage());
}
?>