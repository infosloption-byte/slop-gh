<?php
// Load dependencies and helpers
require_once __DIR__ . '/../../../config/api_bootstrap.php';

// Rate limiting block
$ip_address = $_SERVER['REMOTE_ADDR'];
require_once '../../config/rate_limiter.php';
if (!check_rate_limit($conn, $ip_address, 'request_reset', 5, 60)) {
    http_response_code(429); // Too Many Requests
    die(json_encode(["message" => "Too many requests. Please try again later."]));
}

$data = json_decode(file_get_contents("php://input"));

if (empty($data->email)) {
    http_response_code(400);
    die(json_encode(["message" => "Email is required."]));
}

$email = $conn->real_escape_string($data->email);

// 1. Check if a user with that email exists
$query = "SELECT id FROM users WHERE email = ? LIMIT 1";
$stmt = $conn->prepare($query);
$stmt->bind_param("s", $email);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows == 1) {
    // Generate a secure token and store it
    $token = bin2hex(random_bytes(50));
    $token_hash = hash('sha256', $token);
    $expires_at = date('Y-m-d H:i:s', time() + 3600); // 1 hour
    
    $query_insert = "INSERT INTO password_resets (email, token, expires_at) VALUES (?, ?, ?)";
    $stmt_insert = $conn->prepare($query_insert);
    $stmt_insert->bind_param("sss", $email, $token_hash, $expires_at);
    $stmt_insert->execute();
    
    // Prepare the content for the email
    $reset_link = "https://www.sloption.com/reset-password.html?token=" . $token;
    $email_subject = 'Your Password Reset Link';
    $email_body = '<h1>Password Reset Request</h1><p>Please click the link below to reset your password:</p><a href="' . $reset_link . '">Reset Password</a>';

    // Call the centralized helper function to send the email
    // No complex try/catch is needed here because it's handled inside the helper
    send_email($email, $email_subject, $email_body, $log);
}

// Always send a generic success message to prevent user enumeration attacks
http_response_code(200);
echo json_encode(["message" => "If an account with that email exists, a password reset link has been sent."]);

$stmt->close();
$conn->close();
?>