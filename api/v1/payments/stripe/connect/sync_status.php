<?php
// api/v1/payments/stripe/connect/sync_status.php
require_once __DIR__ . '/../../../config/api_secure_bootstrap.php';

try {
    // Get user's Connect account
    $stmt = $conn->prepare("SELECT stripe_connect_id FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (empty($user['stripe_connect_id'])) {
        throw new Exception("No Connect account found", 404);
    }

    $log->info('Syncing Connect account status', [
        'user_id' => $user_id,
        'connect_account' => $user['stripe_connect_id']
    ]);

    // Get current status from Stripe
    $status = $stripeService->getConnectAccountStatus($user['stripe_connect_id']);

    // Update database
    $stmt_update = $conn->prepare(
        "UPDATE users 
         SET connect_charges_enabled = ?, 
             connect_payouts_enabled = ?,
             connect_onboarding_complete = ?,
             updated_at = NOW()
         WHERE id = ?"
    );
    
    $charges_enabled = $status['charges_enabled'] ? 1 : 0;
    $payouts_enabled = $status['payouts_enabled'] ? 1 : 0;
    $onboarding_complete = $status['details_submitted'] ? 1 : 0;
    
    $stmt_update->bind_param("iiii", $charges_enabled, $payouts_enabled, $onboarding_complete, $user_id);
    $stmt_update->execute();
    $stmt_update->close();

    $log->info('Connect status synced', [
        'user_id' => $user_id,
        'payouts_enabled' => $payouts_enabled,
        'onboarding_complete' => $onboarding_complete
    ]);

    http_response_code(200);
    echo json_encode([
        'message' => 'Connect status synced successfully',
        'status' => [
            'payouts_enabled' => (bool)$payouts_enabled,
            'onboarding_complete' => (bool)$onboarding_complete,
            'charges_enabled' => (bool)$charges_enabled
        ]
    ]);

} catch (Exception $e) {
    $log->error('Connect status sync failed', [
        'user_id' => $user_id,
        'error' => $e->getMessage()
    ]);
    
    $code = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 500;
    http_response_code($code);
    echo json_encode(['message' => $e->getMessage()]);
} finally {
    if ($conn) $conn->close();
}

?>