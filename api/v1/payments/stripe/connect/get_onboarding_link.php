<?php
/**
 * GET ONBOARDING LINK
 * api/v1/payments/stripe/connect/get_onboarding_link.php
 * 
 * Returns a one-time URL for user to complete Stripe onboarding
 */

require_once __DIR__ . '/../../../config/api_secure_bootstrap.php';

try {
    // Get user's Connect account
    $stmt = $conn->prepare("SELECT stripe_connect_id FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (empty($user['stripe_connect_id'])) {
        throw new Exception("No Connect account found. Please create one first.", 404);
    }

    $log->info('Creating onboarding link', [
        'user_id' => $user_id,
        'connect_account' => $user['stripe_connect_id']
    ]);

    // Create Account Link for onboarding
    $accountLink = \Stripe\AccountLink::create([
        'account' => $user['stripe_connect_id'],
        'refresh_url' => 'https://www.sloption.com/onboarding-refresh.html', // If link expires
        'return_url' => 'https://www.sloption.com/onboarding-complete.html',  // After completion
        'type' => 'account_onboarding',
    ]);

    $log->info('Onboarding link created', [
        'user_id' => $user_id,
        'expires_at' => $accountLink->expires_at
    ]);

    http_response_code(200);
    echo json_encode([
        'onboarding_url' => $accountLink->url,
        'expires_at' => $accountLink->expires_at,
        'message' => 'Redirect user to this URL to complete onboarding'
    ]);

} catch (\Stripe\Exception\ApiErrorException $e) {
    $log->error('Stripe error creating onboarding link', [
        'user_id' => $user_id,
        'error' => $e->getMessage()
    ]);
    
    http_response_code(500);
    echo json_encode([
        'error' => true,
        'message' => 'Failed to create onboarding link: ' . $e->getMessage()
    ]);
    
} catch (Exception $e) {
    $log->error('Error creating onboarding link', [
        'user_id' => $user_id,
        'error' => $e->getMessage()
    ]);
    
    $code = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 500;
    http_response_code($code);
    echo json_encode([
        'error' => true,
        'message' => $e->getMessage()
    ]);
} finally {
    if ($conn) $conn->close();
}
?>