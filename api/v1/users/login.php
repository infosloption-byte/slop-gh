<?php
// /api/v1/users/login.php

// --- STEP 1: START THE SESSION ---
// This is required to temporarily store the user's ID during the 2FA process.
session_start();


// Load dependencies
require_once __DIR__ . '/../../../config/api_bootstrap.php';

// Import the JWT class
use Firebase\JWT\JWT;

// --- RATE LIMITING (FIX: Corrected path from ../../ to ../../../) ---
$ip_address = $_SERVER['REMOTE_ADDR'];
require_once __DIR__ . '/../../../config/rate_limiter.php';
if (!check_rate_limit($conn, $ip_address, 'login', 10, 60)) {
    http_response_code(429);
    die(json_encode(["message" => "Too many login attempts. Please try again later."]));
}
// --- END FIX ---

$data = json_decode(file_get_contents("php://input"));
if (empty($data->email) || empty($data->password)) { 
    http_response_code(400);
    die(json_encode(["message" => "Email and password are required."]));
}

// Find the user by email
$email = $data->email;

// --- STEP 2: UPDATE THE SQL QUERY ---
// We need to fetch `google2fa_enabled` and `is_suspended` along with the other data.
$query = "SELECT id, password_hash, google2fa_enabled, is_suspended FROM users WHERE email = ? LIMIT 1";

$stmt = $conn->prepare($query);
$stmt->bind_param("s", $email);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows == 1) {
    $user = $result->fetch_assoc();

    // Check if the account is suspended (Your existing code, which is correct)
    if ($user['is_suspended']) {
        http_response_code(403); // Forbidden
        echo json_encode(["message" => "Your account has been suspended."]);
        exit();
    }
    
    // Verify password
    if (password_verify($data->password, $user['password_hash'])) {
        $user_id = $user['id'];

        // --- STEP 3: ADD THE 2FA CHECK ---
        // After verifying the password, check if the user has 2FA enabled.
        if ($user['google2fa_enabled'] == 1) {
            // --- 2FA IS ENABLED ---
            // 1. Store the user's ID in the session to verify on the next step.
            $_SESSION['2fa_user_id'] = $user_id;

            // 2. Send a specific response to the front-end.
            http_response_code(200);
            echo json_encode(["message" => "2FA required"]);
            exit();

        } else {
            // --- 2FA IS NOT ENABLED (Normal Login) ---
            // Create the JWT and set the cookie as before.
            $secret_key = JWT_SECRET_KEY;
            $issued_at = time();
            $expiration_time = $issued_at + (60 * 60); // 1 hour

            $payload = [
                "iss" => "https://www.sloption.com",
                "aud" => "https://www.sloption.com",
                "iat" => $issued_at, 
                "exp" => $expiration_time,
                "data" => ["id" => $user_id]
            ];

            $jwt = JWT::encode($payload, $secret_key, 'HS256');

            // Set the cookie
            setcookie('jwt_token', $jwt, [
                'expires' => $expiration_time,
                'path' => '/',
                'domain' => '.sloption.com',
                'secure' => true,
                'httponly' => true,
                'samesite' => 'Lax' // 'Lax' is generally best for login cookies
            ]);

            // --- STEP 4: CLEAN UP THE SUCCESS RESPONSE ---
            // The token should NOT be sent in the body.
            http_response_code(200);
            echo json_encode(["message" => "Login successful."]);
        }

    } else {
        // Password is not correct
        $log->warning('Failed login attempt (incorrect password).', ['email' => $email, 'ip' => $ip_address]);
        http_response_code(401);
        echo json_encode(["message" => "Login failed. Incorrect password."]);
    }
} else {
    // User not found
    $log->warning('Failed login attempt (user not found).', ['email' => $email, 'ip' => $ip_address]);
    http_response_code(401);
    echo json_encode(["message" => "Login failed. User not found."]);
}

$stmt->close();
$conn->close();
?>