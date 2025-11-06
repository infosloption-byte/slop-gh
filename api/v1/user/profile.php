<?php
// api/users/profile.php

// 1. Use the REGULAR bootstrap to prevent crashes for logged-out users.
require_once __DIR__ . '/../../../config/api_bootstrap.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

try {
    // --- HANDLE GET REQUEST (Fetch Profile) ---
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        
        $user_profile = null; // Default to null (logged-out state)

        // Manually check for the JWT token
        if (isset($_COOKIE['jwt_token'])) {
            try {
                $decoded = JWT::decode($_COOKIE['jwt_token'], new Key(JWT_SECRET_KEY, 'HS256'));
                $user_id = $decoded->data->id;

                // If token is valid, fetch the user's data (no change here)
                $query = "SELECT id, email, first_name, last_name, birthday, postal_code, address, city, country, role, google2fa_enabled, phone_number, phone_verified, stripe_connect_id FROM users WHERE id = ?";
                $stmt = $conn->prepare($query);
                $stmt->bind_param("i", $user_id);
                $stmt->execute();
                $user_profile = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if ($user_profile) {
                    // --- START: FETCH AND CONVERT WALLET BALANCES ---
                    $wallets_query = "SELECT type, balance FROM wallets WHERE user_id = ?";
                    $wallets_stmt = $conn->prepare($wallets_query);
                    $wallets_stmt->bind_param("i", $user_id);
                    $wallets_stmt->execute();
                    $wallets_result = $wallets_stmt->get_result();
                    
                    // Initialize with default float values
                    $user_profile['wallets'] = ['real' => 0.0, 'demo' => 0.0]; 
                    
                    while ($wallet = $wallets_result->fetch_assoc()) {
                        // Convert balance from cents (BIGINT) back to dollars (float)
                        $user_profile['wallets'][$wallet['type']] = (int)$wallet['balance'] / 100.0; 
                    }
                    $wallets_stmt->close();
                    // --- END: FETCH AND CONVERT WALLET BALANCES ---
                }

            } catch (Exception $e) {
                // If token is invalid/expired, we do nothing. $user_profile remains null.
                $log->warning('JWT decode failed during profile fetch.', ['error' => $e->getMessage()]);
            }
        }

        // Always return a 200 OK response with the data (or null if not logged in)
        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode($user_profile);
    }
    // --- HANDLE POST REQUEST (Update Profile) ---
    else if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        
        // This part MUST be secure. First, we authenticate the user.
        if (!isset($_COOKIE['jwt_token'])) {
            throw new Exception("Authentication required.", 401);
        }

        try {
            $decoded = JWT::decode($_COOKIE['jwt_token'], new Key(JWT_SECRET_KEY, 'HS256'));
            $user_id = $decoded->data->id;
        } catch (Exception $e) {
            throw new Exception("Authentication failed: " . $e->getMessage(), 401);
        }

        // Now, proceed with your existing update logic
        $data = json_decode(file_get_contents("php://input"));
        
        $fields = [];
        $params = [];
        $types = '';
        $allowed_fields = ['first_name', 'last_name', 'birthday', 'postal_code', 'address', 'city'];

        foreach ($allowed_fields as $field) {
            if (isset($data->$field)) {
                $fields[] = "`$field` = ?";
                $params[] = $data->$field;
                $types .= 's';
            }
        }

        if (count($fields) > 0) {
            $params[] = $user_id;
            $types .= 'i';
            
            $sql = "UPDATE users SET " . implode(', ', $fields) . " WHERE id = ?";
            
            $stmt = $conn->prepare($sql);
            $stmt->bind_param($types, ...$params);
            
            if ($stmt->execute()) {
                http_response_code(200);
                echo json_encode(["message" => "Profile updated successfully."]);
            } else {
                throw new Exception("Failed to update profile.", 500);
            }
        } else {
            throw new Exception("No valid fields provided for update.", 400);
        }
    }
    
    // --- Handle other methods ---
    else {
        http_response_code(405); // Method Not Allowed
        echo json_encode(["message" => "Method not allowed."]);
    }

} catch (Exception $e) {
    // A single, clean error handler
    $log->error('Profile API error.', ['error' => $e->getMessage()]);
    // Use the exception's code if it's a valid HTTP code, otherwise default to 500
    $code = in_array($e->getCode(), [400, 401, 403, 404, 405, 500]) ? $e->getCode() : 500;
    http_response_code($code);
    echo json_encode(["message" => $e->getMessage()]);
    
} finally {
    if ($conn) {
        $conn->close();
    }
}
?>