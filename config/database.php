<?php
// Load Composer's autoloader to access installed libraries
require_once __DIR__ . '/../vendor/autoload.php';

// Load the .env file from the project root
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

// --- Set a strict CORS Policy ---
$allowed_origins = [
    'https://www.sloption.com'
    // Add 'https://www.your-live-domain.com' when you have one
];

if (isset($_SERVER['HTTP_ORIGIN']) && in_array($_SERVER['HTTP_ORIGIN'], $allowed_origins)) {
    header("Access-Control-Allow-Origin: " . $_SERVER['HTTP_ORIGIN']);
}

// --- Set Global Headers ---
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-control-allow-headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

// --- NEW: Add Security Headers ---
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: DENY");

// IMPORTANT: Only uncomment the line below when your LIVE site is fully working on HTTPS
// header("Strict-Transport-Security: max-age=31536000; includeSubDomains");

// A strong Content Security Policy to prevent XSS attacks
header("Content-Security-Policy: default-src 'self'; script-src 'self' https://js.stripe.com https://cdn.jsdelivr.net https://s3.tradingview.com; style-src 'self' https://cdn.jsdelivr.net 'unsafe-inline'; connect-src 'self' https://stream.binance.com https://api.binance.com; frame-src https://js.stripe.com; img-src 'self' data:;");

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

// Tell mysqli to throw exceptions on error
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// Use Environment Variables for Secrets
$db_host = $_ENV['DB_HOST'];
$db_user = $_ENV['DB_USER'];
$db_pass = $_ENV['DB_PASS'];
$db_name = $_ENV['DB_NAME'];

// Make the JWT secret key available as a constant
define('JWT_SECRET_KEY', $_ENV['JWT_SECRET_KEY']);

// Create database connection
$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
$conn->set_charset("utf8mb4");


/**
 * A robust function to get the bearer token from the Authorization header.
 * @return string|null The token, or null if not found.
 */
function get_bearer_token() {
    $headers = null;
    if (isset($_SERVER['Authorization'])) {
        $headers = trim($_SERVER["Authorization"]);
    } else if (isset($_SERVER['HTTP_AUTHORIZATION'])) { // Nginx or fast CGI
        $headers = trim($_SERVER["HTTP_AUTHORIZATION"]);
    } elseif (function_exists('getallheaders')) {
        $requestHeaders = getallheaders();
        $requestHeaders = array_combine(array_map('ucwords', array_keys($requestHeaders)), array_values($requestHeaders));
        if (isset($requestHeaders['Authorization'])) {
            $headers = trim($requestHeaders['Authorization']);
        }
    }
    
    if (!empty($headers)) {
        if (preg_match('/Bearer\s(\S+)/', $headers, $matches)) {
            return $matches[1];
        }
    }

    // 2. NEW: If not in the header, check for the cookie (for web browsers)
    if (isset($_COOKIE['jwt_token'])) {
        return $_COOKIE['jwt_token'];
    }

    return null;
}

/**
 * Checks if a user has the 'admin' role.
 * @param mysqli $conn The database connection.
 * @param int $user_id The ID of the user to check.
 * @return bool True if the user is an admin, false otherwise.
 */
function is_admin($conn, $user_id) {
    $query = "SELECT role FROM users WHERE id = ? LIMIT 1";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return ($result && $result['role'] === 'admin');
}

/**
 * Updates the last_seen_at timestamp for a given user.
 * @param mysqli $conn The database connection.
 * @param int $user_id The ID of the user to update.
 */
function update_last_seen($conn, $user_id) {
    $query = "UPDATE users SET last_seen_at = NOW() WHERE id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $stmt->close();
}

/**
 * A central function to authenticate a user via their JWT cookie and track their activity.
 * @param mysqli $conn The database connection.
 * @return int The validated user ID.
 * @throws Exception If authentication fails.
 */
function authenticate_and_track_user($conn) {
    // 1. Check directly for the JWT cookie instead of using the get_bearer_token() helper.
    if (!isset($_COOKIE['jwt_token'])) {
        throw new Exception("Access denied. No token provided.", 401);
    }
    
    $jwt = $_COOKIE['jwt_token'];
    
    try {
        // 2. Decode the token. This will throw an exception if invalid (e.g., expired, wrong signature).
        $decoded = \Firebase\JWT\JWT::decode($jwt, new \Firebase\JWT\Key(JWT_SECRET_KEY, 'HS256'));
        $user_id = $decoded->data->id;

        // 3. If decoding was successful, update the last_seen_at timestamp.
        update_last_seen($conn, $user_id);

        // 4. Return the validated user ID.
        return $user_id;
        
    } catch (Exception $e) {
        // Catch any JWT-related errors and re-throw a standard authentication error.
        throw new Exception("Access denied. Invalid or expired token.", 401);
    }
}

?>