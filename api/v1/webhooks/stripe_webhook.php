<?php
/**
 * Stripe Webhook Handler
 * Place this at: /api/v1/webhooks/stripe_webhook.php
 * 
 * Configure in Stripe Dashboard:
 * - URL: https://yourdomain.com/api/v1/webhooks/stripe_webhook.php
 * - Events: transfer.created, transfer.failed, transfer.reversed, payout.paid, payout.failed
 */

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../vendor/autoload.php';

use Stripe\Webhook;

// Get raw POST body
$payload = @file_get_contents('php://input');
$sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
$endpoint_secret = $_ENV['STRIPE_WEBHOOK_SECRET'] ?? ''; // Add this to your .env

// Verify webhook signature
try {
    $event = Webhook::constructEvent($payload, $sig_header, $endpoint_secret);
} catch(\UnexpectedValueException $e) {
    http_response_code(400);
    exit();
} catch(\Stripe\Exception\SignatureVerificationException $e) {
    http_response_code(400);
    exit();
}

// Handle the event
switch ($event->type) {
    case 'transfer.created':
        handleTransferCreated($event->data->object, $conn);
        break;
        
    case 'transfer.failed':
        handleTransferFailed($event->data->object, $conn);
        break;
        
    case 'transfer.reversed':
        handleTransferReversed($event->data->object, $conn);
        break;
        
    case 'payout.paid':
        handlePayoutPaid($event->data->object, $conn);
        break;
        
    case 'payout.failed':
        handlePayoutFailed($event->data->object, $conn);
        break;
        
    default:
        // Unhandled event type
}

http_response_code(200);

// ==================== EVENT HANDLERS ====================

function handleTransferCreated($transfer, $conn) {
    $transfer_id = $transfer->id;
    $request_id = $transfer->metadata->request_id ?? null;
    
    if (!$request_id) return;
    
    $stmt = $conn->prepare("UPDATE withdrawal_requests 
                            SET gateway_status = 'transfer_created',
                                updated_at = NOW()
                            WHERE id = ?");
    $stmt->bind_param("i", $request_id);
    $stmt->execute();
    $stmt->close();
    
    logWebhookEvent($conn, 'transfer.created', $transfer_id, $request_id, 'success');
}

function handleTransferFailed($transfer, $conn) {
    $transfer_id = $transfer->id;
    $request_id = $transfer->metadata->request_id ?? null;
    $failure_message = $transfer->failure_message ?? 'Unknown error';
    
    if (!$request_id) return;
    
    $conn->autocommit(FALSE);
    
    try {
        // Get the withdrawal request details
        $stmt = $conn->prepare("SELECT wr.*, u.email 
                                FROM withdrawal_requests wr
                                JOIN users u ON wr.user_id = u.id
                                WHERE wr.id = ?");
        $stmt->bind_param("i", $request_id);
        $stmt->execute();
        $request = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if ($request) {
            // Refund the wallet
            $stmt = $conn->prepare("UPDATE wallets 
                                    SET balance = balance + ? 
                                    WHERE id = ?");
            $stmt->bind_param("ii", $request['amount'], $request['wallet_id']);
            $stmt->execute();
            $stmt->close();
            
            // Update withdrawal request
            $stmt = $conn->prepare("UPDATE withdrawal_requests 
                                    SET status = 'FAILED',
                                        gateway_status = 'transfer_failed',
                                        failure_reason = ?,
                                        updated_at = NOW()
                                    WHERE id = ?");
            $stmt->bind_param("si", $failure_message, $request_id);
            $stmt->execute();
            $stmt->close();
            
            // Update transaction
            $stmt = $conn->prepare("UPDATE transactions 
                                    SET status = 'FAILED'
                                    WHERE reference_id = ? 
                                    AND type = 'WITHDRAWAL'");
            $stmt->bind_param("i", $request_id);
            $stmt->execute();
            $stmt->close();
            
            // Notify user
            $notificationData = [
                'message' => "Your withdrawal failed and has been refunded: " . $failure_message,
                'amount' => $request['amount'] / 100.0
            ];
            send_notification($request['user_id'], 'withdrawal-failed', $notificationData);
        }
        
        $conn->commit();
        logWebhookEvent($conn, 'transfer.failed', $transfer_id, $request_id, 'handled');
        
    } catch (Exception $e) {
        $conn->rollback();
        logWebhookEvent($conn, 'transfer.failed', $transfer_id, $request_id, 'error', $e->getMessage());
    }
    
    $conn->autocommit(TRUE);
}

function handleTransferReversed($transfer, $conn) {
    // Similar to handleTransferFailed
    handleTransferFailed($transfer, $conn);
}

function handlePayoutPaid($payout, $conn) {
    // Mark as successfully paid out to bank
    $transfer_id = $payout->source_transaction ?? null;
    
    if ($transfer_id) {
        $stmt = $conn->prepare("UPDATE withdrawal_requests 
                                SET gateway_status = 'payout_paid',
                                    updated_at = NOW()
                                WHERE gateway_transaction_id = ?");
        $stmt->bind_param("s", $transfer_id);
        $stmt->execute();
        $stmt->close();
    }
    
    logWebhookEvent($conn, 'payout.paid', $payout->id, null, 'success');
}

function handlePayoutFailed($payout, $conn) {
    // Payout to bank failed (after successful transfer)
    $failure_message = $payout->failure_message ?? 'Unknown error';
    $transfer_id = $payout->source_transaction ?? null;
    
    if ($transfer_id) {
        $stmt = $conn->prepare("UPDATE withdrawal_requests 
                                SET gateway_status = 'payout_failed',
                                    failure_reason = CONCAT(IFNULL(failure_reason, ''), ' | Payout failed: ', ?),
                                    updated_at = NOW()
                                WHERE gateway_transaction_id = ?");
        $stmt->bind_param("ss", $failure_message, $transfer_id);
        $stmt->execute();
        $stmt->close();
    }
    
    logWebhookEvent($conn, 'payout.failed', $payout->id, null, 'handled');
}

function logWebhookEvent($conn, $event_type, $stripe_id, $request_id, $status, $error = null) {
    $stmt = $conn->prepare("INSERT INTO webhook_logs 
                            (event_type, stripe_id, request_id, status, error_message, received_at) 
                            VALUES (?, ?, ?, ?, ?, NOW())");
    $stmt->bind_param("ssiss", $event_type, $stripe_id, $request_id, $status, $error);
    $stmt->execute();
    $stmt->close();
}

function send_notification($user_id, $type, $data) {
    // Your existing notification function
    // Or implement basic version here
}
?>