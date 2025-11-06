<?php
// /api/users/logout.php

// Define the cookie parameters to ensure it's deleted correctly.
$cookie_name = 'jwt_token';
$path = '/';
$domain = '.sloption.com'; // Use the same domain!

// 1. This essential line that logs the user out is preserved.
setcookie($cookie_name, '', time() - 3600, $path, $domain, true, true);

// 2. The redirect is replaced with a clean JSON success message.
http_response_code(200);
header('Content-Type: application/json');
echo json_encode(['message' => 'Logout successful.']);

exit();
?>