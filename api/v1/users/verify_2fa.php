<?php
// /api/users/verify_2fa.php

session_start();

require_once __DIR__ . '/../../../config/api_bootstrap.php';

use PragmaRX\Google2FA\Google2FA;
use Firebase\JWT\JWT;

try {
    // 1. Check if user has passed the password step first
    if (!isset($_SESSION['2fa_user_id'])) {
        throw new Exception("Authentication process not initiated.", 401);
    }
    
    $user_id = $_SESSION['2fa_user_id'];

    // 2. Get the user's ENCRYPTED secret key from the database
    $stmt = $conn->prepare("SELECT google2fa_secret FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if (!$user || !$user['google2fa_secret']) {
        throw new Exception("2FA is not properly configured for this account.", 400);
    }

    // 3. Get the submitted code from the user
    $data = json_decode(file_get_contents("php://input"));
    $verification_code = $data->verification_code ?? '';
    
    // --- START: MODIFIED VERIFICATION LOGIC ---

    // 4. Decrypt the secret from the database using our helper function
    $encryption_key = $_ENV['ENCRYPTION_KEY'];
    $decrypted_secret = decrypt_data($user['google2fa_secret'], $encryption_key);

    if ($decrypted_secret === false) {
        // Log the error for admin review, but show a generic message to the user.
        $log->error('2FA secret decryption failed.', ['user_id' => $user_id]);
        throw new Exception("Verification failed due to a system error. Please try again.", 500);
    }

    // 5. Verify the user's code against the DECRYPTED secret
    $google2fa = new Google2FA();
    $is_valid = $google2fa->verifyKey($decrypted_secret, $verification_code);

    // --- END: MODIFIED VERIFICATION LOGIC ---

    if ($is_valid) {
        // SUCCESS: Code is correct. Complete the login process.
        unset($_SESSION['2fa_user_id']); // Clean up the session
        
        // Create and set the JWT cookie
        $expiration_time = time() + (60 * 60); // 1 hour
        $payload = [
            "iss" => "https://www.sloption.com", 
            "iat" => time(), 
            "exp" => $expiration_time, 
            "data" => ["id" => $user_id]
        ];
        $jwt = JWT::encode($payload, JWT_SECRET_KEY, 'HS256');
        
        setcookie('jwt_token', $jwt, [
            'expires' => $expiration_time,
            'path' => '/',
            'domain' => '.sloption.com',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Lax'
        ]);

        http_response_code(200);
        echo json_encode(["message" => "Login successful."]);
    } else {
        // If the code is simply incorrect
        throw new Exception("Invalid 2FA code.", 401);
    }
} catch (Exception $e) {
    http_response_code($e->getCode() > 0 ? $e->getCode() : 500);
    echo json_encode(["message" => $e->getMessage()]);
}
?>