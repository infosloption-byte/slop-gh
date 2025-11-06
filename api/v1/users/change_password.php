<?php
// /api/users/change_password.php

require_once __DIR__ . '/../../../config/api_secure_bootstrap.php';

try {
    // 1. Authenticate the user via their cookie to get their ID
    // using the $conn and $user_id variables provided by the bootstrap.

    // 2. Get the submitted passwords
    $data = json_decode(file_get_contents("php://input"));
    $current_password = $data->current_password ?? '';
    $new_password = $data->new_password ?? '';

    // 3. Validate the input
    if (empty($current_password) || empty($new_password)) {
        throw new Exception("All password fields are required.", 400);
    }
    if (strlen($new_password) < 8) {
        throw new Exception("New password must be at least 8 characters long.", 400);
    }

    // 4. Fetch the user's current hashed password from the database
    $stmt = $conn->prepare("SELECT password_hash FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // 5. Verify the CURRENT password is correct
    if (password_verify($current_password, $user['password_hash'])) {
        // 6. If correct, hash the NEW password and update the database
        $new_password_hash = password_hash($new_password, PASSWORD_BCRYPT);
        
        $update_stmt = $conn->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
        $update_stmt->bind_param("si", $new_password_hash, $user_id);
        $update_stmt->execute();
        $update_stmt->close();

        http_response_code(200);
        echo json_encode(["message" => "Password changed successfully."]);
    } else {
        // If the current password was incorrect
        throw new Exception("Incorrect current password.", 401);
    }

} catch (Exception $e) {
    http_response_code($e->getCode() > 0 ? $e->getCode() : 500);
    echo json_encode(["message" => $e->getMessage()]);
}
?>