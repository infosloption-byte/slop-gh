<?php
// /api/users/request_phone_verification.php

require_once __DIR__ . '/../../../config/api_secure_bootstrap.php';

try {
    // using the $conn and $user_id variables provided by the bootstrap.

    $data = json_decode(file_get_contents("php://input"));
    $phone_number = $data->phone_number ?? '';

    if (!preg_match('/^\+[1-9]\d{1,14}$/', $phone_number)) {
        throw new Exception("Please use international format (e.g., +94771234567).", 400);
    }

    $verification_code = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);
    $expires_at = date('Y-m-d H:i:s', time() + 600); // Code valid for 10 minutes

    $stmt = $conn->prepare("UPDATE users SET phone_number = ?, phone_verification_code = ?, phone_verification_expires_at = ?, phone_verified = 0 WHERE id = ?");
    $stmt->bind_param("sssi", $phone_number, $verification_code, $expires_at, $user_id);
    $stmt->execute();

    // --- NEW: SEND SMS USING NOTIFY.LK ---

    // 1. Prepare the data for the Notify.lk API
    $notify_user_id  = $_ENV['NOTIFY_USER_ID'];
    $notify_api_key  = $_ENV['NOTIFY_API_KEY'];
    $notify_sender_id = $_ENV['NOTIFY_SENDER_ID'];
    // Notify.lk expects the phone number without the leading '+', e.g., 94771234567
    $notify_phone_number = ltrim($phone_number, '+'); 
    $message = "Your SL Option verification code is: " . $verification_code;

    // 2. Create the payload for the POST request
    $payload = [
        'user_id' => $notify_user_id,
        'api_key' => $notify_api_key,
        'sender_id' => $notify_sender_id,
        'to' => $notify_phone_number,
        'message' => $message
    ];

    // 3. Use cURL to send the request
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://app.notify.lk/api/v1/send");
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $response_data = json_decode($response);

    // 4. Check if the SMS was sent successfully
    if ($http_code !== 200 || $response_data->status !== 'success') {
        // If it failed, log the error but don't show technical details to the user
        error_log("Notify.lk SMS failed: " . $response);
        // throw new Exception("Failed to send verification code. Please try again later.", 500);
        throw new Exception($response, 500);
    }

    // --- END NOTIFY.LK LOGIC ---

    http_response_code(200);
    echo json_encode(["message" => "A verification code has been sent."]);

} catch (Exception $e) {
    http_response_code($e->getCode() > 0 ? $e->getCode() : 500);
    echo json_encode(["message" => $e->getMessage()]);
}
?>