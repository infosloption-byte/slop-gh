<?php
// /api/v1/users/register.php

// Load the logger first
require_once __DIR__ . '/../../../config/api_bootstrap.php';

// --- START: FIX 1 (File Path) ---
// The path must go up THREE levels to reach the root config folder.
$ip_address = $_SERVER['REMOTE_ADDR'];
require_once __DIR__ . '/../../../config/rate_limiter.php';
// --- END: FIX 1 ---

// Allow 10 registration attempts per hour from one IP
if (!check_rate_limit($conn, $ip_address, 'register', 10, 3600)) {
    http_response_code(429);
    die(json_encode(["message" => "Too many registration attempts. Please try again later."]));
}

// Get the posted data from the front-end
$data = json_decode(file_get_contents("php://input"));

// Basic validation (no change)
if (empty($data->email) || empty($data->password)) {
    http_response_code(400);
    echo json_encode(array("message" => "Unable to register user. Email and password are required."));
    exit();
}

// Sanitize the input (no change)
$email = $conn->real_escape_string($data->email);
$password = $data->password;

// Check if the user already exists (no change)
$query = "SELECT id FROM users WHERE email = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("s", $email);
$stmt->execute();
$stmt->store_result();

if ($stmt->num_rows > 0) {
    http_response_code(409);
    echo json_encode(array("message" => "User with this email already exists."));
    $stmt->close();
    $conn->close();
    exit();
}
$stmt->close();

// --- START TRANSACTION ---
$conn->autocommit(FALSE);

try {
    // Hash the password (no change)
    $password_hash = password_hash($password, PASSWORD_BCRYPT);

    // Create the new user (no change)
    $query_user = "INSERT INTO users (email, password_hash) VALUES (?, ?)";
    $stmt_user = $conn->prepare($query_user);
    $stmt_user->bind_param("ss", $email, $password_hash);
    if (!$stmt_user->execute()) {
        throw new Exception("Failed to create user: " . $stmt_user->error);
    }
    
    // Get the ID of the newly created user (no change)
    $user_id = $conn->insert_id;
    $stmt_user->close();

    // --- START: FIX 2 (Currency Conversion) ---
    // Get starting balance from .env and convert to cents
    $demo_balance_dollars = (float)($_ENV['DEMO_WALLET_START_BALANCE'] ?? 10000.00);
    $demo_balance_cents = (int)($demo_balance_dollars * 100);
    $real_balance_cents = 0; // Real balance starts at 0 cents

    // Create demo wallet using prepared statement and CENTS
    $stmt_demo = $conn->prepare("INSERT INTO wallets (user_id, type, balance) VALUES (?, 'demo', ?)");
    $stmt_demo->bind_param("ii", $user_id, $demo_balance_cents); // Bind as integer (i)
    if (!$stmt_demo->execute()) {
        throw new Exception("Failed to create demo wallet: " . $stmt_demo->error);
    }
    $stmt_demo->close();

    // Create real wallet using prepared statement and CENTS
    $stmt_real = $conn->prepare("INSERT INTO wallets (user_id, type, balance) VALUES (?, 'real', ?)");
    $stmt_real->bind_param("ii", $user_id, $real_balance_cents); // Bind as integer (i)
    if (!$stmt_real->execute()) {
        throw new Exception("Failed to create real wallet: " . $stmt_real->error);
    }
    $stmt_real->close();
    // --- END: FIX 2 ---

    // If all queries were successful, commit the transaction
    $conn->commit();

    // Send success response
    http_response_code(201);
    echo json_encode(array("message" => "User was registered successfully."));

} catch (Exception $e) {
    // If anything fails, rollback the transaction
    if ($conn) $conn->rollback();

    $log->error('User registration failed.', [
        'email' => $data->email ?? 'not provided',
        'error' => $e->getMessage()
    ]);

    // Send a generic, user-friendly error
    http_response_code(503);
    echo json_encode(array("message" => "An internal error occurred. Please try again."));
} finally {
    if (isset($conn)) {
        $conn->autocommit(TRUE);
        $conn->close();
    }
}
?>