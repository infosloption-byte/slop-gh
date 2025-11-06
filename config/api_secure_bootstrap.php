<?php
// /config/api_secure_bootstrap.php

// This file is for SECURE endpoints that require a user to be logged in.

// 1. First, start the session, which is needed for authentication.
session_start();

// 2. Load the common setup from our main bootstrap file.
// This gives us access to $conn, $log, and helper functions.
require_once __DIR__ . '/api_bootstrap.php';

// 3. Perform the authentication and get the user ID.
// This line will throw a 401 exception and stop the script if the user is not logged in.
$user_id = authenticate_and_track_user($conn);

// Any script that includes this file will now have a validated $user_id variable.
?>