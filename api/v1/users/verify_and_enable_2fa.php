<?php
// /api/users/verify_and_enable_2fa.php

session_start();

require_once __DIR__ . '/../../../config/api_secure_bootstrap.php';

use PragmaRX\Google2FA\Google2FA;

try {
    // 1. Authenticate the user
    // using the $conn and $user_id variables provided by the bootstrap.

    // 2. Check if the temporary secret exists in the session
    if (!isset($_SESSION['2fa_temp_secret'])) {
        throw new Exception("2FA setup process not started or has expired. Please try again.", 400);
    }

    // 3. Get the submitted data
    $data = json_decode(file_get_contents("php://input"));
    $verification_code = $data->verification_code ?? '';
    $temp_secret = $_SESSION['2fa_temp_secret'];

    // 4. Verify the 6-digit code against the temporary secret
    $google2fa = new Google2FA();
    $is_valid = $google2fa->verifyKey($temp_secret, $verification_code);

    if ($is_valid) {
        // 5. If valid, encrypt and save the secret to the database, then enable 2FA
        $encryption_key = $_ENV['ENCRYPTION_KEY'];
        $encrypted_secret = encrypt_data($temp_secret, $encryption_key);

        if ($encrypted_secret === false) {
            throw new Exception("Could not securely save 2FA secret. Please try again.", 500);
        }

        $stmt = $conn->prepare("UPDATE users SET google2fa_secret = ?, google2fa_enabled = 1 WHERE id = ?");
        $stmt->bind_param("si", $encrypted_secret, $user_id);
        $stmt->execute();
        $stmt->close();

        // 6. Clear the temporary secret from the session
        unset($_SESSION['2fa_temp_secret']);

        http_response_code(200);
        echo json_encode(["message" => "2FA has been enabled successfully!"]);
    } else {
        // 7. If the code is invalid, send an error
        throw new Exception("The verification code is incorrect. Please try again.", 400);
    }

} catch (Exception $e) {
    http_response_code($e->getCode() > 0 ? $e->getCode() : 500);
    echo json_encode(["message" => $e->getMessage()]);
}
?>