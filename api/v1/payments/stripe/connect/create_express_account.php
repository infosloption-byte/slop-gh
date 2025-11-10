<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

/**
 * GLOBAL EXPRESS ACCOUNT WITH COUNTRY SELECTION
 * api/v1/payments/stripe/connect/create_express_account.php
 * 
 * User selects country on frontend, we create account for that country
 */

require_once __DIR__ . '/../../../config/api_secure_bootstrap.php';

try {
    // Get country from request (sent from frontend)
    $data = json_decode(file_get_contents("php://input"), true);
    $country = $data['country'] ?? null;

    if (empty($country)) {
        throw new Exception("Country is required. Please select your country.", 400);
    }

    // Validate country code (2-letter ISO code)
    if (strlen($country) !== 2 || !ctype_alpha($country)) {
        throw new Exception("Invalid country code.", 400);
    }

    $country = strtoupper($country);

    // Check if user already has a Connect account
    $stmt = $conn->prepare("SELECT stripe_connect_id FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!empty($user['stripe_connect_id'])) {
        // Account exists, just create onboarding link
        $log->info('Connect account already exists, creating onboarding link', [
            'user_id' => $user_id,
            'connect_account' => $user['stripe_connect_id']
        ]);

        $accountLink = \Stripe\AccountLink::create([
            'account' => $user['stripe_connect_id'],
            'refresh_url' => 'https://www.sloption.com/onboarding-refresh.html',
            'return_url' => 'https://www.sloption.com/onboarding-complete.html',
            'type' => 'account_onboarding',
        ]);

        http_response_code(200);
        echo json_encode([
            'message' => 'Connect account already exists',
            'stripe_connect_id' => $user['stripe_connect_id'],
            'onboarding_url' => $accountLink->url,
            'country' => $country
        ]);
        exit;
    }

    // Get user email
    $stmt = $conn->prepare("SELECT email FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $user_data = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$user_data) {
        throw new Exception("User not found", 404);
    }

    $log->info('Creating Express account for user-selected country', [
        'user_id' => $user_id,
        'email' => $user_data['email'],
        'country' => $country
    ]);

    // ✅ CREATE EXPRESS ACCOUNT WITH USER'S COUNTRY
    $account = \Stripe\Account::create([
        'type' => 'express',
        'country' => $country, // ← User's selected country!
        'email' => $user_data['email'],
        'capabilities' => [
            'transfers' => ['requested' => true],
        ],
    ]);

    // Save to database
    $stmt = $conn->prepare("UPDATE users SET stripe_connect_id = ?, updated_at = NOW() WHERE id = ?");
    $stmt->bind_param("si", $account->id, $user_id);
    
    if (!$stmt->execute()) {
        throw new Exception("Failed to save Connect account ID");
    }
    $stmt->close();

    $log->info('Express Connect account created', [
        'user_id' => $user_id,
        'connect_account_id' => $account->id,
        'country' => $country
    ]);

    // Create onboarding link
    $accountLink = \Stripe\AccountLink::create([
        'account' => $account->id,
        'refresh_url' => 'https://www.sloption.com/onboarding-refresh.html',
        'return_url' => 'https://www.sloption.com/onboarding-complete.html',
        'type' => 'account_onboarding',
    ]);

    http_response_code(201);
    echo json_encode([
        'message' => 'Express account created successfully',
        'stripe_connect_id' => $account->id,
        'onboarding_url' => $accountLink->url,
        'country' => $country
    ]);

} catch (\Stripe\Exception\ApiErrorException $e) {
    $log->error('Stripe API error', [
        'user_id' => $user_id ?? 0,
        'error' => $e->getMessage(),
        'type' => get_class($e)
    ]);
    
    http_response_code(500);
    echo json_encode([
        'error' => true,
        'message' => 'Failed to create account: ' . $e->getMessage()
    ]);
    
} catch (Exception $e) {
    $log->error('Error creating Express account', [
        'user_id' => $user_id ?? 0,
        'error' => $e->getMessage(),
        'line' => $e->getLine()
    ]);
    
    $code = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 500;
    http_response_code($code);
    echo json_encode([
        'error' => true,
        'message' => $e->getMessage()
    ]);
} finally {
    if (isset($conn) && $conn) {
        $conn->close();
    }
}
?>