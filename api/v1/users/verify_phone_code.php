<?php
// /api/users/verify_phone_code.php

require_once __DIR__ . '/../../../config/api_secure_bootstrap.php';

try {
    // using the $conn and $user_id variables provided by the bootstrap.

    $data = json_decode(file_get_contents("php://input"));
    $code = $data->code ?? '';

    $stmt = $conn->prepare("SELECT phone_verification_code, phone_verification_expires_at FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();

    if (!$user || !$user['phone_verification_code']) {
        throw new Exception("No verification process started.", 400);
    }
    if (new DateTime() > new DateTime($user['phone_verification_expires_at'])) {
        throw new Exception("Verification code has expired.", 400);
    }
    if ($user['phone_verification_code'] !== $code) {
        throw new Exception("The verification code is incorrect.", 400);
    }

    $update_stmt = $conn->prepare("UPDATE users SET phone_verified = 1, phone_verification_code = NULL, phone_verification_expires_at = NULL WHERE id = ?");
    $update_stmt->bind_param("i", $user_id);
    $update_stmt->execute();

    http_response_code(200);
    echo json_encode(["message" => "Phone number verified successfully."]);

} catch (Exception $e) {
    http_response_code($e->getCode() > 0 ? $e->getCode() : 500);
    echo json_encode(["message" => $e->getMessage()]);
}
?>