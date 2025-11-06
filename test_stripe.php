<?php
require_once __DIR__ . '/config/api_secure_bootstrap.php';

echo "<html><head><style>
body { font-family: Arial, sans-serif; padding: 20px; background: #f5f5f5; }
.success { background: #d4edda; padding: 15px; margin: 10px 0; border-radius: 5px; border: 1px solid #c3e6cb; }
.error { background: #f8d7da; padding: 15px; margin: 10px 0; border-radius: 5px; border: 1px solid #f5c6cb; }
.info { background: #d1ecf1; padding: 15px; margin: 10px 0; border-radius: 5px; border: 1px solid #bee5eb; }
pre { background: white; padding: 10px; border-radius: 3px; overflow-x: auto; }
table { width: 100%; border-collapse: collapse; background: white; margin: 20px 0; }
th, td { padding: 12px; text-align: left; border-bottom: 1px solid #ddd; }
th { background: #007bff; color: white; }
tr:hover { background: #f5f5f5; }
.badge { padding: 4px 8px; border-radius: 3px; font-size: 12px; }
.badge-success { background: #28a745; color: white; }
.badge-danger { background: #dc3545; color: white; }
.badge-warning { background: #ffc107; color: black; }
</style></head><body>";

echo "<h1>🔍 Stripe Account Diagnostic Tool</h1>";

// Get test user from URL
$test_user_id = $_GET['user_id'] ?? null;

// Step 1: Check Stripe configuration
echo "<div class='info'>";
echo "<h2>Step 1: Checking Stripe Configuration</h2>";
echo "<pre>";
echo "Stripe SDK Loaded: " . (class_exists('\Stripe\Stripe') ? '✅ YES' : '❌ NO') . "\n";
echo "API Key Configured: " . (!empty($_ENV['STRIPE_SECRET_KEY']) ? '✅ YES' : '❌ NO') . "\n";
if (!empty($_ENV['STRIPE_SECRET_KEY'])) {
    $key = $_ENV['STRIPE_SECRET_KEY'];
    $mode = strpos($key, 'test') !== false ? 'TEST' : 'LIVE';
    echo "Mode: " . $mode . "\n";
    echo "Key Preview: " . substr($key, 0, 10) . "..." . substr($key, -4) . "\n";
}
echo "</pre>";
echo "</div>";

// Step 2: List all users with Stripe accounts
echo "<div class='info'>";
echo "<h2>Step 2: Users with Stripe Accounts</h2>";

try {
    $query = "SELECT id, email, stripe_connect_id, created_at 
              FROM users 
              WHERE stripe_connect_id IS NOT NULL 
              ORDER BY id DESC";
    $result = $conn->query($query);
    
    if ($result->num_rows == 0) {
        echo "<div class='error'>❌ No users have Stripe Connect accounts set up!</div>";
    } else {
        echo "<table>";
        echo "<tr><th>User ID</th><th>Email</th><th>Stripe Connect ID</th><th>Account Type</th><th>Action</th></tr>";
        
        while ($row = $result->fetch_assoc()) {
            $account_type = 'UNKNOWN';
            $badge_class = 'badge-warning';
            
            if (strpos($row['stripe_connect_id'], 'acct_test_') === 0) {
                $account_type = 'TEST';
                $badge_class = 'badge-warning';
            } elseif (strpos($row['stripe_connect_id'], 'acct_') === 0) {
                $account_type = 'LIVE';
                $badge_class = 'badge-success';
            } else {
                $account_type = 'INVALID';
                $badge_class = 'badge-danger';
            }
            
            echo "<tr>";
            echo "<td><strong>" . $row['id'] . "</strong></td>";
            echo "<td>" . htmlspecialchars($row['email']) . "</td>";
            echo "<td><code>" . htmlspecialchars($row['stripe_connect_id']) . "</code></td>";
            echo "<td><span class='badge $badge_class'>$account_type</span></td>";
            echo "<td><a href='?user_id=" . $row['id'] . "'>Test This Account</a></td>";
            echo "</tr>";
        }
        
        echo "</table>";
    }
} catch (Exception $e) {
    echo "<div class='error'>Error: " . $e->getMessage() . "</div>";
}
echo "</div>";

// Step 3: Test specific user if provided
if ($test_user_id) {
    echo "<div class='info'>";
    echo "<h2>Step 3: Testing User ID: $test_user_id</h2>";
    
    try {
        // Get user's Stripe ID
        $stmt = $conn->prepare("SELECT id, email, stripe_connect_id FROM users WHERE id = ?");
        $stmt->bind_param("i", $test_user_id);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if (!$user) {
            echo "<div class='error'>❌ User not found!</div>";
        } elseif (empty($user['stripe_connect_id'])) {
            echo "<div class='error'>❌ This user has no Stripe Connect ID!</div>";
        } else {
            echo "<pre>";
            echo "User Email: " . htmlspecialchars($user['email']) . "\n";
            echo "Stripe ID: " . htmlspecialchars($user['stripe_connect_id']) . "\n";
            echo "</pre>";
            
            // Try to retrieve the account from Stripe
            echo "<h3>Connecting to Stripe API...</h3>";
            
            try {
                $account = $stripeService->getConnectAccount($user['stripe_connect_id']);
                
                echo "<div class='success'>";
                echo "<h3>✅ Successfully Retrieved Account!</h3>";
                echo "<pre>";
                echo "Account ID: " . $account->id . "\n";
                echo "Email: " . ($account->email ?? 'N/A') . "\n";
                echo "Type: " . ($account->type ?? 'N/A') . "\n";
                echo "Country: " . ($account->country ?? 'N/A') . "\n";
                echo "\n--- PAYOUT STATUS ---\n";
                echo "Payouts Enabled: " . ($account->payouts_enabled ? '✅ YES' : '❌ NO') . "\n";
                echo "Charges Enabled: " . ($account->charges_enabled ? '✅ YES' : '❌ NO') . "\n";
                echo "Details Submitted: " . ($account->details_submitted ? '✅ YES' : '❌ NO') . "\n";
                
                if ($account->requirements) {
                    echo "\n--- REQUIREMENTS ---\n";
                    $currently_due = $account->requirements->currently_due ?? [];
                    $eventually_due = $account->requirements->eventually_due ?? [];
                    
                    if (empty($currently_due)) {
                        echo "Currently Due: ✅ None\n";
                    } else {
                        echo "Currently Due: ❌ " . implode(', ', $currently_due) . "\n";
                    }
                    
                    if (empty($eventually_due)) {
                        echo "Eventually Due: ✅ None\n";
                    } else {
                        echo "Eventually Due: ⚠️  " . implode(', ', $eventually_due) . "\n";
                    }
                }
                
                echo "\n--- CAPABILITIES ---\n";
                if ($account->capabilities) {
                    foreach ($account->capabilities as $capability => $status) {
                        echo ucfirst($capability) . ": " . $status . "\n";
                    }
                }
                
                echo "\n--- WITHDRAWAL ELIGIBILITY ---\n";
                if ($account->payouts_enabled && empty($account->requirements->currently_due)) {
                    echo "✅ This account CAN receive withdrawals!\n";
                } else {
                    echo "❌ This account CANNOT receive withdrawals yet.\n";
                    if (!$account->payouts_enabled) {
                        echo "   Reason: Payouts not enabled\n";
                    }
                    if (!empty($account->requirements->currently_due)) {
                        echo "   Reason: Has pending verification requirements\n";
                    }
                }
                
                echo "</pre>";
                echo "</div>";
                
            } catch (\Stripe\Exception\InvalidRequestException $e) {
                echo "<div class='error'>";
                echo "<h3>❌ Invalid Stripe Account</h3>";
                echo "<pre>";
                echo "Error: " . $e->getMessage() . "\n";
                echo "Type: Invalid Request\n\n";
                echo "This usually means:\n";
                echo "- The account ID doesn't exist in Stripe\n";
                echo "- The account was deleted\n";
                echo "- Wrong API mode (test vs live)\n";
                echo "</pre>";
                echo "</div>";
                
            } catch (\Stripe\Exception\AuthenticationException $e) {
                echo "<div class='error'>";
                echo "<h3>❌ Authentication Error</h3>";
                echo "<pre>";
                echo "Error: " . $e->getMessage() . "\n";
                echo "Type: Authentication Failed\n\n";
                echo "This usually means:\n";
                echo "- Invalid API key\n";
                echo "- API key doesn't have permission\n";
                echo "</pre>";
                echo "</div>";
                
            } catch (\Stripe\Exception\ApiErrorException $e) {
                echo "<div class='error'>";
                echo "<h3>❌ Stripe API Error</h3>";
                echo "<pre>";
                echo "Error: " . $e->getMessage() . "\n";
                echo "Type: " . get_class($e) . "\n";
                echo "HTTP Status: " . $e->getHttpStatus() . "\n";
                echo "</pre>";
                echo "</div>";
                
            } catch (Exception $e) {
                echo "<div class='error'>";
                echo "<h3>❌ Unexpected Error</h3>";
                echo "<pre>";
                echo "Error: " . $e->getMessage() . "\n";
                echo "Type: " . get_class($e) . "\n";
                echo "Code: " . $e->getCode() . "\n";
                echo "\nStack Trace:\n" . $e->getTraceAsString();
                echo "</pre>";
                echo "</div>";
            }
        }
    } catch (Exception $e) {
        echo "<div class='error'>";
        echo "<h3>Database Error</h3>";
        echo "<pre>" . $e->getMessage() . "</pre>";
        echo "</div>";
    }
    echo "</div>";
} else {
    echo "<div class='info'>";
    echo "<p>👆 Click on 'Test This Account' for any user above to check their Stripe account status.</p>";
    echo "</div>";
}

echo "</body></html>";
// ## 📋 Usage Instructions:

// ### Option 1: See All Users
// Just visit:
// ```
// https://www.sloption.com/test_stripe.php
// ```

// This will show you:
// - ✅ All users with Stripe accounts
// - 🔍 Their account IDs
// - 🎯 Click to test each one

// ### Option 2: Test Specific User
// Add the user ID:
// ```
// https://www.sloption.com/test_stripe.php?user_id=5
// ```

// ---

// ## 🎯 What to Look For:

// The improved test will tell you:

// 1. **✅ If Stripe SDK is loaded**
// 2. **✅ If API keys are configured**
// 3. **✅ All users with Stripe accounts**
// 4. **✅ Whether accounts are TEST or LIVE mode**
// 5. **✅ Detailed account status**
// 6. **✅ Why withdrawals might fail**

// ---

// ## 📸 Expected Output:

// You should see something like:
// ```
// Step 1: Checking Stripe Configuration
// ✅ Stripe SDK Loaded: YES
// ✅ API Key Configured: YES
// Mode: LIVE
// Key Preview: sk_live_51...xyz

// Step 2: Users with Stripe Accounts
// ┌─────────┬──────────────────────┬─────────────────────┬──────────────┐
// │ User ID │ Email                │ Stripe Connect ID   │ Account Type │
// ├─────────┼──────────────────────┼─────────────────────┼──────────────┤
// │ 5       │ user@example.com     │ acct_1234567890     │ LIVE         │
// └─────────┴──────────────────────┴─────────────────────┴──────────────┘

// Step 3: Testing User ID: 5
// ✅ Successfully Retrieved Account!
// Payouts Enabled: ✅ YES
// This account CAN receive withdrawals!
?>
