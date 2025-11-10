<?php
/**
 * Withdrawal System Test Suite
 *
 * This file contains test utilities and examples for testing the withdrawal system.
 * Run these tests in a sandbox/test environment only!
 */

class WithdrawalTestSuite {
    private $api_base_url;
    private $jwt_token;
    private $admin_jwt_token;
    private $log;

    public function __construct($api_base_url, $jwt_token, $admin_jwt_token = null, $log = null) {
        $this->api_base_url = rtrim($api_base_url, '/');
        $this->jwt_token = $jwt_token;
        $this->admin_jwt_token = $admin_jwt_token;
        $this->log = $log;
    }

    /**
     * Test 1: Small withdrawal (auto-approved, under $500)
     */
    public function testSmallWithdrawal($amount = 50.00, $payout_method_id = 1) {
        echo "\n=== TEST 1: Small Withdrawal (Auto-Approved) ===\n";

        $response = $this->makeRequest(
            'POST',
            '/api/v1/payments/withdrawals/create_withdrawal.php',
            [
                'amount' => $amount,
                'payout_method_id' => $payout_method_id
            ],
            $this->jwt_token
        );

        $this->assertResponse($response, [
            'expected_status' => 200,
            'expected_fields' => ['message', 'request_id', 'amount', 'gateway_transaction_id', 'status'],
            'expected_status_value' => 'APPROVED'
        ]);

        echo "✅ Small withdrawal test passed\n";
        return $response;
    }

    /**
     * Test 2: Large withdrawal (manual review, over $500)
     */
    public function testLargeWithdrawal($amount = 750.00, $payout_method_id = 1) {
        echo "\n=== TEST 2: Large Withdrawal (Manual Review) ===\n";

        $response = $this->makeRequest(
            'POST',
            '/api/v1/payments/withdrawals/create_withdrawal.php',
            [
                'amount' => $amount,
                'payout_method_id' => $payout_method_id
            ],
            $this->jwt_token
        );

        $this->assertResponse($response, [
            'expected_status' => 200,
            'expected_fields' => ['message', 'request_id', 'status'],
            'expected_status_value' => 'PENDING'
        ]);

        echo "✅ Large withdrawal test passed\n";
        return $response;
    }

    /**
     * Test 3: Insufficient balance
     */
    public function testInsufficientBalance($amount = 999999.00, $payout_method_id = 1) {
        echo "\n=== TEST 3: Insufficient Balance ===\n";

        $response = $this->makeRequest(
            'POST',
            '/api/v1/payments/withdrawals/create_withdrawal.php',
            [
                'amount' => $amount,
                'payout_method_id' => $payout_method_id
            ],
            $this->jwt_token
        );

        $this->assertResponse($response, [
            'expected_status' => 400,
            'expected_error_contains' => 'Insufficient funds'
        ]);

        echo "✅ Insufficient balance test passed\n";
        return $response;
    }

    /**
     * Test 4: Minimum withdrawal validation
     */
    public function testMinimumWithdrawal($amount = 5.00, $payout_method_id = 1) {
        echo "\n=== TEST 4: Minimum Withdrawal Validation ===\n";

        $response = $this->makeRequest(
            'POST',
            '/api/v1/payments/withdrawals/create_withdrawal.php',
            [
                'amount' => $amount,
                'payout_method_id' => $payout_method_id
            ],
            $this->jwt_token
        );

        $this->assertResponse($response, [
            'expected_status' => 400,
            'expected_error_contains' => 'Minimum withdrawal'
        ]);

        echo "✅ Minimum withdrawal test passed\n";
        return $response;
    }

    /**
     * Test 5: Invalid payout method
     */
    public function testInvalidPayoutMethod($amount = 50.00, $payout_method_id = 99999) {
        echo "\n=== TEST 5: Invalid Payout Method ===\n";

        $response = $this->makeRequest(
            'POST',
            '/api/v1/payments/withdrawals/create_withdrawal.php',
            [
                'amount' => $amount,
                'payout_method_id' => $payout_method_id
            ],
            $this->jwt_token
        );

        $this->assertResponse($response, [
            'expected_status' => 404,
            'expected_error_contains' => 'not found'
        ]);

        echo "✅ Invalid payout method test passed\n";
        return $response;
    }

    /**
     * Test 6: Daily limit check
     */
    public function testDailyLimit($payout_method_id = 1) {
        echo "\n=== TEST 6: Daily Limit Check ===\n";

        // Attempt multiple withdrawals to hit daily limit
        $total_withdrawn = 0;
        $daily_limit = 10000; // $10,000
        $withdrawal_amount = 3000; // $3,000 per attempt

        $attempts = 0;
        $max_attempts = 5;

        while ($total_withdrawn < $daily_limit && $attempts < $max_attempts) {
            $response = $this->makeRequest(
                'POST',
                '/api/v1/payments/withdrawals/create_withdrawal.php',
                [
                    'amount' => $withdrawal_amount,
                    'payout_method_id' => $payout_method_id
                ],
                $this->jwt_token
            );

            $attempts++;

            if ($response['status_code'] == 200) {
                $total_withdrawn += $withdrawal_amount;
                echo "  Attempt $attempts: Withdrawn $$withdrawal_amount (Total: $$total_withdrawn)\n";
            } else if ($response['status_code'] == 400 &&
                       strpos(json_encode($response['body']), 'Daily withdrawal limit') !== false) {
                echo "✅ Daily limit reached at $$total_withdrawn\n";
                return $response;
            } else {
                echo "⚠️  Unexpected error: " . json_encode($response['body']) . "\n";
                break;
            }
        }

        echo "✅ Daily limit test completed (withdrew $$total_withdrawn)\n";
        return $response;
    }

    /**
     * Test 7: Admin approval
     */
    public function testAdminApproval($request_id) {
        echo "\n=== TEST 7: Admin Approval ===\n";

        if (!$this->admin_jwt_token) {
            echo "⚠️  Skipped: No admin token provided\n";
            return null;
        }

        $response = $this->makeRequest(
            'POST',
            '/api/v1/admin/process_withdrawal.php',
            [
                'request_id' => $request_id,
                'new_status' => 'APPROVED',
                'admin_notes' => 'Test approval'
            ],
            $this->admin_jwt_token
        );

        $this->assertResponse($response, [
            'expected_status' => 200,
            'expected_fields' => ['message', 'status']
        ]);

        echo "✅ Admin approval test passed\n";
        return $response;
    }

    /**
     * Test 8: Admin rejection
     */
    public function testAdminRejection($request_id) {
        echo "\n=== TEST 8: Admin Rejection ===\n";

        if (!$this->admin_jwt_token) {
            echo "⚠️  Skipped: No admin token provided\n";
            return null;
        }

        $response = $this->makeRequest(
            'POST',
            '/api/v1/admin/process_withdrawal.php',
            [
                'request_id' => $request_id,
                'new_status' => 'REJECTED',
                'admin_notes' => 'Test rejection'
            ],
            $this->admin_jwt_token
        );

        $this->assertResponse($response, [
            'expected_status' => 200,
            'expected_fields' => ['message', 'status']
        ]);

        echo "✅ Admin rejection test passed\n";
        return $response;
    }

    /**
     * Helper: Make API request
     */
    private function makeRequest($method, $endpoint, $data = null, $token = null) {
        $url = $this->api_base_url . $endpoint;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $headers = ['Content-Type: application/json'];
        if ($token) {
            $headers[] = 'Authorization: Bearer ' . $token;
        }

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($data) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $response_body = curl_exec($ch);
        $status_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        $result = [
            'status_code' => $status_code,
            'body' => json_decode($response_body, true),
            'raw_body' => $response_body,
            'error' => $error
        ];

        if ($this->log) {
            $this->log->info('Test API Request', [
                'method' => $method,
                'endpoint' => $endpoint,
                'status' => $status_code,
                'response' => $result['body']
            ]);
        }

        return $result;
    }

    /**
     * Helper: Assert response
     */
    private function assertResponse($response, $assertions) {
        // Check status code
        if (isset($assertions['expected_status'])) {
            if ($response['status_code'] !== $assertions['expected_status']) {
                throw new Exception(
                    "Expected status {$assertions['expected_status']}, got {$response['status_code']}. " .
                    "Response: " . json_encode($response['body'])
                );
            }
        }

        // Check required fields
        if (isset($assertions['expected_fields'])) {
            foreach ($assertions['expected_fields'] as $field) {
                if (!isset($response['body'][$field])) {
                    throw new Exception("Missing expected field: $field");
                }
            }
        }

        // Check status value
        if (isset($assertions['expected_status_value'])) {
            if ($response['body']['status'] !== $assertions['expected_status_value']) {
                throw new Exception(
                    "Expected status '{$assertions['expected_status_value']}', got '{$response['body']['status']}'"
                );
            }
        }

        // Check error message contains
        if (isset($assertions['expected_error_contains'])) {
            $message = $response['body']['message'] ?? '';
            if (strpos($message, $assertions['expected_error_contains']) === false) {
                throw new Exception(
                    "Expected error to contain '{$assertions['expected_error_contains']}', got: $message"
                );
            }
        }

        echo "  ✓ Response validated\n";
        echo "  Status: {$response['status_code']}\n";
        echo "  Body: " . json_encode($response['body'], JSON_PRETTY_PRINT) . "\n";
    }

    /**
     * Run all tests
     */
    public function runAllTests($payout_method_id = 1) {
        echo "\n" . str_repeat("=", 60) . "\n";
        echo "WITHDRAWAL SYSTEM TEST SUITE\n";
        echo str_repeat("=", 60) . "\n";

        $results = [];

        try {
            // Test 1: Small withdrawal
            $results['small_withdrawal'] = $this->testSmallWithdrawal(50.00, $payout_method_id);
        } catch (Exception $e) {
            echo "❌ Test failed: " . $e->getMessage() . "\n";
            $results['small_withdrawal'] = ['error' => $e->getMessage()];
        }

        try {
            // Test 2: Large withdrawal
            $results['large_withdrawal'] = $this->testLargeWithdrawal(750.00, $payout_method_id);

            // If we got a pending request, test admin approval
            if (isset($results['large_withdrawal']['body']['request_id'])) {
                $request_id = $results['large_withdrawal']['body']['request_id'];
                $results['admin_approval'] = $this->testAdminApproval($request_id);
            }
        } catch (Exception $e) {
            echo "❌ Test failed: " . $e->getMessage() . "\n";
            $results['large_withdrawal'] = ['error' => $e->getMessage()];
        }

        try {
            // Test 3: Insufficient balance
            $results['insufficient_balance'] = $this->testInsufficientBalance(999999.00, $payout_method_id);
        } catch (Exception $e) {
            echo "❌ Test failed: " . $e->getMessage() . "\n";
            $results['insufficient_balance'] = ['error' => $e->getMessage()];
        }

        try {
            // Test 4: Minimum withdrawal
            $results['minimum_withdrawal'] = $this->testMinimumWithdrawal(5.00, $payout_method_id);
        } catch (Exception $e) {
            echo "❌ Test failed: " . $e->getMessage() . "\n";
            $results['minimum_withdrawal'] = ['error' => $e->getMessage()];
        }

        try {
            // Test 5: Invalid payout method
            $results['invalid_method'] = $this->testInvalidPayoutMethod(50.00, 99999);
        } catch (Exception $e) {
            echo "❌ Test failed: " . $e->getMessage() . "\n";
            $results['invalid_method'] = ['error' => $e->getMessage()];
        }

        echo "\n" . str_repeat("=", 60) . "\n";
        echo "TEST SUITE COMPLETED\n";
        echo str_repeat("=", 60) . "\n";

        return $results;
    }
}

// Example usage:
/*
require_once __DIR__ . '/../config/logger.php';

$tester = new WithdrawalTestSuite(
    'http://localhost:8000',  // Your API base URL
    'your_jwt_token_here',    // User JWT token
    'admin_jwt_token_here',   // Admin JWT token (optional)
    $log                       // Logger instance (optional)
);

// Run all tests
$results = $tester->runAllTests(1); // Pass your payout_method_id

// Or run individual tests
$tester->testSmallWithdrawal(50.00, 1);
$tester->testLargeWithdrawal(750.00, 1);
*/
?>
