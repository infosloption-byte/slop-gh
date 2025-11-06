<?php
// /api/users/qr_test.php

// Include ONLY the new, stable QR code library
require_once __DIR__ . '/../../../config/phpqrcode.php';

// A simple string to test encoding
$test_data = "It works!";

// Call the library's png() method to output the image directly
// The parameters are: text, outfile (false for direct output), level, size, margin
QRcode::png($test_data, false, QR_ECLEVEL_L, 8, 4);

?>