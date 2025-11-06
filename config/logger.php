<?php
require_once __DIR__ . '/../vendor/autoload.php'; // <-- ADD THIS LINE BACK

use Monolog\Logger;
use Monolog\Handler\StreamHandler;

// Create a new Logger instance
$log = new Logger('SLOptionApp');

// Create a handler to write logs to a file.
// Logs will be saved in /sloption/logs/app.log
$log->pushHandler(new StreamHandler(__DIR__ . '/../logs/app.log', Logger::WARNING));

// This file will now return the configured logger object when included.
return $log;
?>