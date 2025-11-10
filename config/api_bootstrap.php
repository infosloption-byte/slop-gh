<?php
// /config/api_bootstrap.php

// This file handles the common setup for ALL API endpoints.

// Set the default timezone for all date/time functions
date_default_timezone_set('Asia/Colombo');

// Load Composer's autoloader for all libraries
require_once __DIR__ . '/../vendor/autoload.php';

// Make the logger available globally
$log = require_once __DIR__ . '/../config/logger.php';

// The database.php file loads the .env file and creates the $conn connection
require_once __DIR__ . '/../config/database.php';

// Load all helper functions
require_once __DIR__ . '/../config/helpers.php';

// Load all helper functions
require_once __DIR__ . '/../api/v1/services/PusherService.php';

// Load all services
require_once __DIR__ . '/../api/v1/services/StripeService.php';
require_once __DIR__ . '/../api/v1/services/PaymentProviderFactory.php';
require_once __DIR__ . '/WithdrawalConfig.php';

// Create a global instance of the Pusher service
$pusherService = new PusherService($log);

// Create a global instance of the Stripe service
$stripeService = new StripeService($log);

// Create a global instance of the Payment Provider Factory
$paymentFactory = new PaymentProviderFactory($log);
?>