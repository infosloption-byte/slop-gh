<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

// api/v1/payments/stripe/capabilities/admin_fix_all.php - IMPROVED VERSION
require_once __DIR__ . '/../../../config/api_secure_bootstrap.php';

// ⭐ OPTIONAL: Add admin check for security
// Uncomment if you want to restrict this to admins only
/*
if (!is_admin($conn, $user_id)) {
    http_response_code(403);
    echo json_encode(["message" => "Forbidden. Admin privileges required."]);
    exit;
}
*/

try {
    $log->info('Starting Connect capabilities fix');
    
    // Get all users with Connect accounts
    $stmt = $conn->prepare("
        SELECT id, email, stripe_connect_id 
        FROM users 
        WHERE stripe_connect_id IS NOT NULL 
        AND stripe_connect_id != ''
    ");
    $stmt->execute();
    $result = $stmt->get_result();
    
    $users = [];
    while ($row = $result->fetch_assoc()) {
        $users[] = $row;
    }
    $stmt->close();
    
    $total = count($users);
    $updated = 0;
    $errors = 0;
    $already_ok = 0;
    $pending_verification = 0;
    $results = [];
    
    $log->info("Found $total users with Connect accounts");
    
    foreach ($users as $user) {
        $user_result = [
            'user_id' => $user['id'],
            'email' => $user['email'],
            'connect_account' => $user['stripe_connect_id'],
            'status' => 'unknown'
        ];
        
        try {
            // Get detailed capability status
            $detailedStatus = $stripeService->getDetailedCapabilityStatus($user['stripe_connect_id']);
            
            $user_result['current_capabilities'] = $detailedStatus['capabilities'];
            $user_result['account_status'] = $detailedStatus['account_status'];
            
            // Check if capabilities need fixing
            $card_payments = $detailedStatus['capabilities']['card_payments'];
            $transfers = $detailedStatus['capabilities']['transfers'];
            
            if ($card_payments['is_active'] && $transfers['is_active']) {
                // Already active - no action needed
                $user_result['status'] = 'already_active';
                $user_result['message'] = 'Capabilities already active';
                $already_ok++;
                
            } else if ($card_payments['is_pending'] || $transfers['is_pending']) {
                // Pending verification - no action needed
                $user_result['status'] = 'pending_verification';
                $user_result['message'] = 'Capabilities pending verification';
                $pending_verification++;
                
            } else if ($card_payments['needs_fix'] || $transfers['needs_fix']) {
                // Needs fix - update capabilities
                $log->info('Updating capabilities for user', [
                    'user_id' => $user['id'],
                    'connect_account' => $user['stripe_connect_id']
                ]);
                
                $updated_account = $stripeService->updateConnectAccountCapabilities($user['stripe_connect_id']);
                
                $user_result['updated_capabilities'] = $updated_account['capabilities'];
                $user_result['status'] = 'updated';
                $user_result['message'] = 'Capabilities updated successfully';
                $updated++;
                
                // Update database
                $stmt_update = $conn->prepare("
                    UPDATE users 
                    SET connect_charges_enabled = ?,
                        connect_payouts_enabled = ?,
                        updated_at = NOW()
                    WHERE id = ?
                ");
                
                $charges = ($updated_account['capabilities']['card_payments'] === 'active') ? 1 : 0;
                $payouts = ($updated_account['capabilities']['transfers'] === 'active') ? 1 : 0;
                
                $stmt_update->bind_param("iii", $charges, $payouts, $user['id']);
                $stmt_update->execute();
                $stmt_update->close();
                
                $log->info('Capabilities updated successfully', [
                    'user_id' => $user['id']
                ]);
            }
            
        } catch (Exception $e) {
            $user_result['status'] = 'error';
            $user_result['error'] = $e->getMessage();
            $errors++;
            
            $log->error('Failed to update capabilities', [
                'user_id' => $user['id'],
                'error' => $e->getMessage()
            ]);
        }
        
        $results[] = $user_result;
    }
    
    // Generate next steps recommendations
    $next_steps = [];
    
    if ($updated > 0) {
        $next_steps[] = "$updated account(s) had capabilities updated and may need user verification";
    }
    
    if ($pending_verification > 0) {
        $next_steps[] = "$pending_verification account(s) are pending verification - users need to complete onboarding";
    }
    
    if ($errors > 0) {
        $next_steps[] = "$errors account(s) had errors - check logs and Stripe Dashboard for details";
    }
    
    if ($already_ok === $total) {
        $next_steps[] = "All accounts are already active - no action needed!";
    } else {
        $next_steps[] = "Check Stripe Dashboard for accounts requiring additional verification";
        $next_steps[] = "Users may need to complete identity verification via onboarding link";
    }
    
    $log->info('Connect capabilities fix completed', [
        'total' => $total,
        'updated' => $updated,
        'already_ok' => $already_ok,
        'pending_verification' => $pending_verification,
        'errors' => $errors
    ]);
    
    http_response_code(200);
    echo json_encode([
        "message" => "Connect capabilities fix completed",
        "summary" => [
            "total_accounts" => $total,
            "updated" => $updated,
            "already_active" => $already_ok,
            "pending_verification" => $pending_verification,
            "errors" => $errors
        ],
        "results" => $results,
        "next_steps" => $next_steps
    ]);

} catch (Exception $e) {
    $log->error('Fix capabilities script failed', [
        'error' => $e->getMessage()
    ]);

    http_response_code(500);
    echo json_encode([
        "message" => "Failed to fix capabilities: " . $e->getMessage()
    ]);
} finally {
    if ($conn) {
        $conn->close();
    }
}
?>