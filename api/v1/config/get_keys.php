<?php
// /api/v1/config/get_keys.php

// Use the main bootstrap to load .env variables and other setup.
require_once __DIR__ . '/../../../config/api_bootstrap.php';

// This endpoint provides all necessary PUBLIC keys to the front-end.
// NEVER include secret keys here.
$public_keys = [
    'stripePublishableKey' => $_ENV['STRIPE_PUBLISHABLE_KEY'],
    'pusherAppKey'         => $_ENV['PUSHER_APP_KEY'],
    'pusherCluster'        => $_ENV['PUSHER_APP_CLUSTER']
];

http_response_code(200);
echo json_encode($public_keys);
?>