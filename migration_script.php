<?php
/**
 * Migration Script for Existing Withdrawal Requests
 * 
 * This script handles the transition from the old system to the new system.
 * It refunds any pending withdrawals so they can be re-requested under the new flow.
 * 
 * RUN THIS ONCE AFTER DEPLOYING THE NEW CODE
 * 
 * Usage: php migration_script.php
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/vendor/autoload.php';

echo "==================================================\n";
echo "Withdrawal System Migration Script\n";
echo "==================================================\n\n";

$conn->autocommit(FALSE);

try {
    // 1. Find all pending withdrawals in old format
    echo "Step 1: Finding pending withdrawals...\n";
    
    $query = "SELECT wr.*, u.email, w.balance as current_balance
              FROM withdrawal_requests wr
              JOIN users u ON wr.user_id = u.id
              JOIN wallets w ON wr.wallet_id = w.id
              WHERE wr.status = 'PENDING'
              AND wr.requested_amount_available = 0"; // Old format marker
    
    $result = $conn->query($query);
    $pending_requests = $result->fetch_all(MYSQLI_ASSOC);
    
    echo "Found " . count($pending_requests) . " pending requests to migrate.\n\n";
    
    if (count($pending_requests) === 0) {
        echo "No pending requests to migrate. Exiting.\n";
        exit(0);
    }
    
    // 2. Process each request
    echo "Step 2: Processing requests...\n\n";
    $refunded_count = 0;
    
    foreach ($pending_requests as $request) {
        $request_id = $request['id'];
        $user_id = $request['user_id'];
        $wallet_id = $request['wallet_id'];
        $amount = $request['amount'];
        $email = $request['email'];
        
        echo "Processing Request #$request_id for $email ($" . ($amount/100) . ")...\n";
        
        // Check if this request already had funds deducted
        $request_time = strtotime($request['requested_at']);
        $hours_ago = (time() - $request_time) / 3600;
        
        if ($hours_ago < 24) {
            echo "  ⚠️  Recent request (${hours_ago}h ago) - may need manual review\n";
        }
        
        // Refund the amount to the wallet
        $refund_stmt = $conn->prepare("UPDATE wallets SET balance = balance + ? WHERE id = ?");
        $refund_stmt->bind_param("ii", $amount, $wallet_id);
        $refund_stmt->execute();
        $refund_stmt->close();
        
        // Update the withdrawal request
        $update_stmt = $conn->prepare("UPDATE withdrawal_requests 
                                       SET status = 'REJECTED',
                                           admin_notes = 'Auto-rejected during system migration. Funds refunded. Please re-request withdrawal.',
                                           processed_at = NOW(),
                                           failure_reason = 'System migration - please re-request',
                                           requested_amount_available = ?
                                       WHERE id = ?");
        $update_stmt->bind_param("ii", $request['current_balance'], $request_id);
        $update_stmt->execute();
        $update_stmt->close();
        
        // Update transaction status
        $trans_stmt = $conn->prepare("UPDATE transactions 
                                      SET status = 'REJECTED'
                                      WHERE type = 'WITHDRAWAL' 
                                      AND user_id = ? 
                                      AND amount = ? 
                                      AND status = 'PENDING'
                                      ORDER BY id DESC LIMIT 1");
        $trans_stmt->bind_param("ii", $user_id, $amount);
        $trans_stmt->execute();
        $trans_stmt->close();
        
        echo "  ✅ Refunded $" . ($amount/100) . " to user's wallet\n";
        echo "  ✅ Marked request as REJECTED\n\n";
        
        $refunded_count++;
    }
    
    // 3. Commit all changes
    $conn->commit();
    
    echo "==================================================\n";
    echo "Migration Complete!\n";
    echo "==================================================\n\n";
    echo "Summary:\n";
    echo "  - Requests processed: " . count($pending_requests) . "\n";
    echo "  - Requests refunded: $refunded_count\n\n";
    
    echo "Next Steps:\n";
    echo "1. Notify affected users about the system upgrade\n";
    echo "2. Ask them to re-request their withdrawals\n";
    echo "3. New requests will use the improved approval flow\n\n";
    
    // 4. Generate email notification list
    echo "Email List (for notifications):\n";
    echo "================================================\n";
    foreach ($pending_requests as $request) {
        echo $request['email'] . "\n";
    }
    echo "================================================\n\n";
    
} catch (Exception $e) {
    $conn->rollback();
    echo "\n❌ ERROR: " . $e->getMessage() . "\n";
    echo "Migration rolled back. No changes made.\n\n";
    exit(1);
} finally {
    $conn->autocommit(TRUE);
    $conn->close();
}

echo "Script completed successfully.\n";
?>