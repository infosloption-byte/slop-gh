<?php
// --- NEW: START THE SESSION ---
// This is required to handle the 2FA verification state.
session_start();

// Load our configuration, helpers, and the Composer autoloader
require_once __DIR__ . '/../../../config/api_bootstrap.php';

// Import the required classes
use Firebase\JWT\JWT;
use Google\Client as Google_Client;

// The base URL of your front-end application
$frontend_url = 'https://www.sloption.com';

// 1. Setup the Google Client (No changes here)
$client = new Google_Client();
$client->setClientId($_ENV['GOOGLE_CLIENT_ID']);
$client->setClientSecret($_ENV['GOOGLE_CLIENT_SECRET']);
$client->setRedirectUri($_ENV['GOOGLE_REDIRECT_URI']);
$client->addScope("email");
$client->addScope("profile");

// 2. Handle the response from Google (No changes here)
if (isset($_GET['code'])) {
    try {
        $token = $client->fetchAccessTokenWithAuthCode($_GET['code']);
        $client->setAccessToken($token['access_token']);

        $google_oauth = new Google_Service_Oauth2($client);
        $google_account_info = $google_oauth->userinfo->get();
        
        $email = $google_account_info->email;
        $google_id = $google_account_info->id;
        $first_name = $google_account_info->givenName;
        $last_name = $google_account_info->familyName;

        // --- MODIFIED: Update the query to get all necessary user flags ---
        $query = "SELECT id, google2fa_enabled, is_suspended FROM users WHERE email = ? LIMIT 1";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();

        $user_id = null;
        if ($result->num_rows == 1) {
            // --- USER EXISTS ---
            $user = $result->fetch_assoc();
            $user_id = $user['id'];

            // Check if suspended
            if ($user['is_suspended']) {
                header('Location: ' . $frontend_url . '?error=suspended');
                exit();
            }

            // --- NEW: THE CRITICAL 2FA CHECK ---
            if ($user['google2fa_enabled'] == 1) {
                // 2FA is enabled. Store their ID in the session and redirect to the verification page.
                $_SESSION['2fa_user_id'] = $user_id;
                header('Location: /2fa_verify.html');
                exit();
            }
            // If 2FA is not enabled, the script will continue to the bottom to log them in.

        } else {
            // --- NEW USER: Register them (Your existing logic is preserved) ---
            $conn->begin_transaction();
            $country = getCountryFromIP();
            $insert_user_query = "INSERT INTO users (email, first_name, last_name, country, provider, provider_id) VALUES (?, ?, ?, ?, 'google', ?)";
            $stmt_insert = $conn->prepare($insert_user_query);
            $stmt_insert->bind_param("sssss", $email, $first_name, $last_name, $country, $google_id);
            $stmt_insert->execute();
            $user_id = $stmt_insert->insert_id;
            $stmt_insert->close();
            $conn->query("INSERT INTO wallets (user_id, type, balance) VALUES ($user_id, 'demo', 10000.00)");
            $conn->query("INSERT INTO wallets (user_id, type, balance) VALUES ($user_id, 'real', 0.00)");
            $conn->commit();
        }
        $stmt->close();
        
        // --- FINAL LOGIN STEP (For non-2FA users or new users) ---
        $expiration_time = time() + (60 * 60); // 1 hour
        $payload = [
            "iss" => "https://www.sloption.com",
            "aud" => "https://www.sloption.com",
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

        header('Location: /app.html');
        exit();

    } catch (Exception $e) {
        $log->error('Google callback failed.', ['error' => $e->getMessage()]);
        header('Location: ' . $frontend_url . '?error=google_failed');
        exit();
    }
} else {
    $log->error('Invalid Google login request.');
    header('Location: ' . $frontend_url . '?error=invalid_request');
    exit();
}
?>