<?php
// api/v1/payments/paypal/connect.php
require_once __DIR__ . '/../../../config/api_secure_bootstrap.php';

// TODO:
// 1. PayPal Client ID and Secret from your PayPal Developer account.
// 2.Client Id from env.
$paypal_client_id = $_ENV['PAYPAL_CLIENT_ID'];
$paypal_redirect_uri = 'https://www.sloption.com/api/v1/payments/paypal/callback.php';

// 3. Define the scopes you need. For Payouts, you might need 'https://api.paypal.com/v1/payments/payouts'
//    For getting user info, you need 'openid email profile'.
$scopes = [
    'openid',
    'email',
    'profile',
    'https://api.paypal.com/v1/payments/payouts' // Check PayPal docs for the correct scope
];
$scope_string = urlencode(implode(' ', $scopes));

// 4. Build the PayPal OAuth URL
$paypal_url = "https://www.paypal.com/connect/?flowEntry=LOGIN&client_id={$paypal_client_id}&scope={$scope_string}&redirect_uri={$paypal_redirect_uri}&response_type=code";

// 5. Redirect the user
header('Location: ' . $paypal_url);
exit();
?>