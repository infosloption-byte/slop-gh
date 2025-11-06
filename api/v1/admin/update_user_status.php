<?php
// Load dependencies
require_once __DIR__ . '/../../../config/api_secure_bootstrap.php';

try {

    // 1. Authenticate and Authorize Admin
    // using the $conn and $user_id variables provided by the bootstrap.

    if (!is_admin($conn, $user_id)) {
        throw new Exception("Forbidden. Admin privileges required.", 403);
    }

    // 2. Get data from the request
    $data = json_decode(file_get_contents("php://input"));
    $target_user_id = $data->user_id ?? 0;
    $is_suspended = (bool)($data->is_suspended ?? true);

    if (!$target_user_id) {
        throw new Exception("Target user ID is required.", 400);
    }

    // 3. Update the user's status in the database
    $query = "UPDATE users SET is_suspended = ? WHERE id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("ii", $is_suspended, $target_user_id);

    if ($stmt->execute()) {
        http_response_code(200);
        echo json_encode(["message" => "User status updated successfully."]);
    } else {
        throw new Exception("Failed to update user status.", 500);
    }
    $stmt->close();

} catch (Exception $e) {
    $log->error('Admin update user status failed.', ['admin_id' => $user_id, 'error' => $e->getMessage()]);
    http_response_code($e->getCode() > 0 ? $e->getCode() : 500);
    echo json_encode(["message" => $e->getMessage()]);
} finally {
    if ($conn) $conn->close();
}
?>