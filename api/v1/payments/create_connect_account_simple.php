<?php
/**
 * SIMPLIFIED STRIPE CONNECT ACCOUNT CREATION
 * api/v1/payments/create_connect_account_simple.php
 * 
 * Creates a Stripe Connect account with minimal requirements
 */

require_once __DIR__ . '/../../../config/api_secure_bootstrap.php';

try {
    // Check if user already has a Connect account
    $stmt = $conn->prepare("SELECT stripe_connect_id FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!empty($user['stripe_connect_id'])) {
        // Account already exists
        http_response_code(200);
        echo json_encode([
            'message' => 'Connect account already exists',
            'stripe_connect_id' => $user['stripe_connect_id']
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

    $log->info('Creating simplified Stripe Connect account', [
        'user_id' => $user_id,
        'email' => $user_data['email']
    ]);

    // Create a Stripe Connect account with MINIMAL requirements
    $account = \Stripe\Account::create([
        'type' => 'custom', // Use 'custom' for more control
        'country' => 'US', // Default to US (can be changed during onboarding)
        'email' => $user_data['email'],
        'capabilities' => [
            'card_payments' => ['requested' => false], // Don't need to accept payments
            'transfers' => ['requested' => true],      // Only need to receive payouts
        ],
        'business_type' => 'individual', // Simplest type (less info required)
        'tos_acceptance' => [
            'date' => time(),
            'ip' => $_SERVER['REMOTE_ADDR'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '0.0.0.0'
        ],
        'settings' => [
            'payouts' => [
                'debit_negative_balances' => false, // Don't auto-debit negatives
                'schedule' => [
                    'interval' => 'manual' // Manual payouts only
                ]
            ]
        ]
    ]);

    // Save to database
    $stmt = $conn->prepare("UPDATE users SET stripe_connect_id = ?, updated_at = NOW() WHERE id = ?");
    $stmt->bind_param("si", $account->id, $user_id);
    
    if (!$stmt->execute()) {
        throw new Exception("Failed to save Connect account ID");
    }
    $stmt->close();

    $log->info('Stripe Connect account created successfully', [
        'user_id' => $user_id,
        'connect_account_id' => $account->id
    ]);

    http_response_code(201);
    echo json_encode([
        'message' => 'Connect account created successfully',
        'stripe_connect_id' => $account->id,
        'next_step' => 'onboarding'
    ]);

} catch (\Stripe\Exception\ApiErrorException $e) {
    $log->error('Stripe API error creating Connect account', [
        'user_id' => $user_id,
        'error' => $e->getMessage()
    ]);
    
    http_response_code(500);
    echo json_encode([
        'error' => true,
        'message' => 'Failed to create payout account: ' . $e->getMessage()
    ]);
    
} catch (Exception $e) {
    $log->error('Error creating Connect account', [
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