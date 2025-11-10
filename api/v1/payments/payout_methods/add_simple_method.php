<?php
// api/v1/payments/payout_methods/add_simple_method.php
require_once __DIR__ . '/../../../config/api_secure_bootstrap.php';

try {
    $data = json_decode(file_get_contents("php://input"));
    $method_type = $data->method_type ?? ''; // 'binance' or 'skrill'
    $identifier = trim($data->identifier ?? '');

    if (!in_array($method_type, ['binance', 'skrill'])) {
        throw new Exception("Invalid method type.", 400);
    }
    if (empty($identifier)) {
        throw new Exception("Account identifier (email or ID) is required.", 400);
    }
    
    // --- START COMPLETED VALIDATION ---
    if ($method_type == 'skrill') {
        if (!filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
            throw new Exception("Invalid Skrill account. Please enter a valid email address.", 400);
        }
    } else if ($method_type == 'binance') {
        // Binance Pay ID can be an email, a phone number, or a numeric User ID.
        $is_email = filter_var($identifier, FILTER_VALIDATE_EMAIL);
        $is_phone = preg_match('/^\+[1-9]\d{1,14}$/', $identifier);
        $is_user_id = ctype_digit($identifier) && strlen($identifier) > 5; // Guessing a user ID is numeric and > 5 chars

        if (!$is_email && !$is_phone && !$is_user_id) {
             throw new Exception("Invalid Binance Pay ID. Please enter a valid email, phone number (e.g., +9477... or +1...), or Binance User ID.", 400);
        }
    }
    // --- END COMPLETED VALIDATION ---

    $conn->autocommit(FALSE);

    // Set all other methods to non-default
    $stmt_reset = $conn->prepare("UPDATE user_payout_methods SET is_default = FALSE WHERE user_id = ?");
    $stmt_reset->bind_param("i", $user_id);
    $stmt_reset->execute();
    $stmt_reset->close();
    
    // Set corresponding payout_cards to non-default
    $stmt_reset_cards = $conn->prepare("UPDATE payout_cards SET is_default = FALSE WHERE user_id = ?");
    $stmt_reset_cards->bind_param("i", $user_id);
    $stmt_reset_cards->execute();
    $stmt_reset_cards->close();

    // Insert the new simple method
    $stmt_insert = $conn->prepare(
        "INSERT INTO user_payout_methods 
         (user_id, method_type, account_identifier, display_name, is_default) 
         VALUES (?, ?, ?, ?, TRUE)"
    );
    $stmt_insert->bind_param("isss", $user_id, $method_type, $identifier, $identifier);
    
    if (!$stmt_insert->execute()) {
        throw new Exception("Failed to save new payout method.");
    }
    $new_method_id = $conn->insert_id;
    $stmt_insert->close();

    $conn->commit();
    
    $log->info('Simple payout method added', ['user_id' => $user_id, 'method_type' => $method_type, 'id' => $new_method_id]);

    http_response_code(201);
    echo json_encode([
        "message" => ucfirst($method_type) . " account added successfully.",
        "method" => [
            "id" => $new_method_id,
            "method_type" => $method_type,
            "display_name" => $identifier,
            "is_default" => true
        ]
    ]);

} catch (Exception $e) {
    if ($conn) $conn->rollback();
    $log->error('Add simple payout method failed', ['user_id' => $user_id ?? 0, 'error' => $e->getMessage()]);
    $code = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 500;
    http_response_code($code);
    echo json_encode(["message" => $e->getMessage()]);
} finally {
    if ($conn) {
        $conn->autocommit(TRUE);
        $conn->close();
    }
}
?>