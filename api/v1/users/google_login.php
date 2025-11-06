<?php
// Load our configuration and the Composer autoloader
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../vendor/autoload.php';

// 1. Create a new Google API client
$client = new Google_Client();

// 2. Set the credentials from your .env file
$client->setClientId($_ENV['GOOGLE_CLIENT_ID']);
$client->setClientSecret($_ENV['GOOGLE_CLIENT_SECRET']);
$client->setRedirectUri($_ENV['GOOGLE_REDIRECT_URI']);

// 3. Set the "scopes" - the information we are requesting from the user's profile
$client->addScope("email");
$client->addScope("profile");

// 4. Generate the authentication URL
$auth_url = $client->createAuthUrl();

// 5. Redirect the user's browser to that URL
header('Location: ' . filter_var($auth_url, FILTER_SANITIZE_URL));
exit();
?>