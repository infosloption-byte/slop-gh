<?php
require_once __DIR__ . '/../../../config/api_secure_bootstrap.php';

// Get trade_id from the query string
$trade_id = filter_input(INPUT_GET, 'trade_id', FILTER_VALIDATE_INT);

if (!$trade_id) {
    http_response_code(400);
    echo json_encode(['error' => 'A valid trade_id is required.']);
    exit();
}

// SECURE: This query checks both the trade ID AND the owner's user ID
$query = "SELECT status FROM trades WHERE id = ? AND user_id = ?";
$stmt = $conn->prepare($query);
// Bind both the trade_id and the user_id from the authenticated session
$stmt->bind_param("ii", $trade_id, $user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($trade = $result->fetch_assoc()) {
    http_response_code(200);
    echo json_encode(['status' => $trade['status']]);
} else {
    // This message now correctly appears if the trade doesn't exist OR it doesn't belong to the user
    http_response_code(404);
    echo json_encode(['error' => 'Trade not found.']);
}

$stmt->close();
$conn->close();
?>