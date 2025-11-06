<?php
// /api/users/disable_2fa.php

require_once __DIR__ . '/../../../config/api_secure_bootstrap.php';

try {
    // 1. Authenticate the user to ensure they are logged in
    // using the $conn and $user_id variables provided by the bootstrap.

    // 2. Get the password from the request
    $data = json_decode(file_get_contents("php://input"));
    $password = $data->password ?? '';

    if (empty($password)) {
        throw new Exception("Password is required to disable 2FA.", 400);
    }

    // 3. Fetch the user's current hashed password from the database
    $stmt = $conn->prepare("SELECT password_hash FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // 4. Verify the provided password is correct
    if (password_verify($password, $user['password_hash'])) {
        // 5. Password is correct. Disable 2FA.
        $update_stmt = $conn->prepare("UPDATE users SET google2fa_enabled = 0, google2fa_secret = NULL WHERE id = ?");
        $update_stmt->bind_param("i", $user_id);
        $update_stmt->execute();
        $update_stmt->close();

        http_response_code(200);
        echo json_encode(["message" => "2FA has been disabled successfully."]);
    } else {
        // Password was incorrect
        throw new Exception("Incorrect password.", 401);
    }

} catch (Exception $e) {
    http_response_code($e->getCode() > 0 ? $e->getCode() : 500);
    echo json_encode(["message" => $e->getMessage()]);
}
?>