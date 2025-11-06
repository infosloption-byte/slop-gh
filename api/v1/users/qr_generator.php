<?php
// /api/users/qr_generator.php

require_once __DIR__ . '/../../../vendor/autoload.php';

use SimpleSoftwareIO\QrCode\Generator;

$data_to_encode = $_GET['data'] ?? 'Error';

// Set the content type header
header('Content-Type: image/png');

// Create a new generator and output the image directly
$qrCodeGenerator = new Generator;
echo $qrCodeGenerator->format('png')->size(200)->margin(2)->generate($data_to_encode);
?>