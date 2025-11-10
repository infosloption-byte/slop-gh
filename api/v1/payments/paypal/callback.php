<?php
// api/v1/payments/paypal/callback.php
require_once __DIR__ . '/../../../config/api_secure_bootstrap.php';

/**
 * Helper function to exchange auth code for tokens
 */
function getPayPalTokens($auth_code, $log) {
    $client_id = $_ENV['PAYPAL_CLIENT_ID'];
    $client_secret = $_ENV['PAYPAL_CLIENT_SECRET'];
    $api_url = $_ENV['PAYPAL_API_URL'] . '/v1/oauth2/token';
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $api_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, 'grant_type=authorization_code&code=' . $auth_code);
    curl_setopt($ch, CURLOPT_USERPWD, $client_id . ':' . $client_secret);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json', 'Content-Type: application/x-www-form-urlencoded']);
    
    $response_body = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    $response = json_decode($response_body, true);
    
    if ($http_code != 200 || !isset($response['access_token'])) {
        $log->error('PayPal token exchange failed', ['response' => $response_body, 'http_code' => $http_code]);
        throw new Exception("Failed to get PayPal token: " . ($response['error_description'] ?? 'Unknown error'));
    }
    
    return $response['access_token'];
}

/**
 * Helper function to get user info from PayPal
 */
function getPayPalUserInfo($access_token, $log) {
    $api_url = $_ENV['PAYPAL_API_URL'] . '/v1/identity/oauth2/userinfo?schema=openid';
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $api_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Accept: application/json',
        'Authorization: Bearer ' . $access_token
    ]);
    
    $response_body = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    $response = json_decode($response_body, true);
    
    if ($http_code != 200 || !isset($response['user_id'])) {
        $log->error('PayPal user info fetch failed', ['response' => $response_body, 'http_code' => $http_code]);
        throw new Exception("Failed to get PayPal user info: " . ($response['error_description'] ?? 'Unknown error'));
    }
    
    // Use payer_id if available, otherwise fall back to user_id
    $payer_id = $response['payer_id'] ?? $response['user_id'] ?? null;
    $email = $response['email'] ?? null;
    
    if (!$payer_id || !$email) {
         throw new Exception("PayPal response missing Payer ID or Email.");
    }
    
    return ['payer_id' => $payer_id, 'email' => $email];
}

try {
    $auth_code = $_GET['code'] ?? '';
    if (empty($auth_code)) {
        throw new Exception("PayPal authorization failed or was canceled.");
    }

    // 1. Exchange auth code for access token
    $access_token = getPayPalTokens($auth_code, $log);

    // 2. Get User Info (Payer ID and Email)
    $user_info = getPayPalUserInfo($access_token, $log);
    $payer_id = $user_info['payer_id'];
    $email = $user_info['email'];

    $conn->autocommit(FALSE);

    // 3. Set all other methods to non-default
    $stmt_reset = $conn->prepare("UPDATE user_payout_methods SET is_default = FALSE WHERE user_id = ?");
    $stmt_reset->bind_param("i", $user_id);
    $stmt_reset->execute();
    $stmt_reset->close();
    
    $stmt_reset_cards = $conn->prepare("UPDATE payout_cards SET is_default = FALSE WHERE user_id = ?");
    $stmt_reset_cards->bind_param("i", $user_id);
    $stmt_reset_cards->execute();
    $stmt_reset_cards->close();

    // 4. Insert the new PayPal method
    $stmt_insert = $conn->prepare(
        "INSERT INTO user_payout_methods 
         (user_id, method_type, account_identifier, display_name, is_default) 
         VALUES (?, 'paypal', ?, ?, TRUE)
         ON DUPLICATE KEY UPDATE account_identifier = ?, display_name = ?, is_default = TRUE" // Prevents duplicates
    );
    // For ON DUPLICATE KEY UPDATE part
    $stmt_insert->bind_param("issss", $user_id, $payer_id, $email, $payer_id, $email);
    
    if (!$stmt_insert->execute()) {
        throw new Exception("Failed to save new PayPal payout method.");
    }
    $stmt_insert->close();

    $conn->commit();
    
    $log->info('PayPal method added/updated', ['user_id' => $user_id, 'email' => $email, 'payer_id' => $payer_id]);

    // Redirect user back to the app's finance popup
    header('Location: /app.html?popup=finances&tab=withdrawal');
    exit();

} catch (Exception $e) {
    if ($conn) $conn->rollback();
    $log->error('PayPal callback failed', ['user_id' => $user_id ?? 0, 'error' => $e->getMessage()]);
    // Redirect to app with an error
    header('Location: /app.html?popup=finances&tab=withdrawal&error=' . urlencode($e->getMessage()));
    exit();
} finally {
    if ($conn) {
        $conn->autocommit(TRUE);
        $conn->close();
    }
}
?>