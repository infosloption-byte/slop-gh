<?php
// Load the logger first
require_once __DIR__ . '/../../../config/api_bootstrap.php';

try {
    date_default_timezone_set('Asia/Colombo');
    $data = json_decode(file_get_contents("php://input"));

    if (empty($data->token) || empty($data->password)) {
        throw new Exception("Token and new password are required.", 400);
    }

    $token = $data->token;
    $new_password = $data->password;

    if (strlen($new_password) < 8) {
        throw new Exception("Password must be at least 8 characters long.", 400);
    }

    $token_hash = hash('sha256', $token);

    // Find the token in the database
    $query = "SELECT * FROM password_resets WHERE token = ? AND expires_at > NOW() LIMIT 1";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $token_hash);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows == 1) {
        $reset_request = $result->fetch_assoc();
        $email = $reset_request['email'];
        
        $new_password_hash = password_hash($new_password, PASSWORD_BCRYPT);
        
        $update_query = "UPDATE users SET password_hash = ? WHERE email = ?";
        $update_stmt = $conn->prepare($update_query);
        $update_stmt->bind_param("ss", $new_password_hash, $email);
        
        if ($update_stmt->execute()) {
            $delete_query = "DELETE FROM password_resets WHERE email = ?";
            $delete_stmt = $conn->prepare($delete_query);
            $delete_stmt->bind_param("s", $email);
            $delete_stmt->execute();
            
            http_response_code(200);
            echo json_encode(["message" => "Password has been reset successfully."]);
        } else {
            throw new Exception("Failed to update password.", 500);
        }
    } else {
        throw new Exception("Invalid or expired token.", 400);
    }
} catch (Exception $e) {
    // --- NEW: LOG THE ERROR ---
    $log->error('Password reset failed.', [
        'token_used' => $data->token ?? 'not_provided',
        'error' => $e->getMessage()
    ]);

    http_response_code($e->getCode() > 0 ? $e->getCode() : 500);
    echo json_encode(["message" => $e->getMessage()]);
} finally {
    if (isset($conn)) $conn->close();
}
?>