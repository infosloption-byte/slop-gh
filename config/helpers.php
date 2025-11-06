<?php
use Pusher\Pusher;

use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mime\Email;

/**
 * Gets the user's country based on their IP address using a free GeoIP service.
 * @return string The country name, or an empty string on failure.
 */
function getCountryFromIP() {
    // Get the user's real IP address, even if they are behind a proxy
    $ip = $_SERVER['HTTP_CLIENT_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'];

    // Use a free and simple API for a basic lookup
    $url = "http://ip-api.com/json/" . urlencode($ip);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_TIMEOUT, 3);
    $output = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($output, true);

    if ($data && $data['status'] == 'success' && isset($data['country'])) {
        return $data['country'];
    }

    return ''; // Return empty string if lookup fails
}

/**
 * Handles the full notification process: saves to DB and sends a real-time push.
 * This function now uses the centralized PusherService.
 *
 * @param int $userId The ID of the user to notify.
 * @param string $eventName The name of the Pusher event.
 * @param array $data The data payload for the notification.
 * @param string|null $link An optional URL to associate with the notification in the DB.
 * @return bool True if the real-time push was successful, false otherwise.
 */
function send_notification($userId, $eventName, $data, $link = null) {
    // Make the global connection, logger, and NEW pusherService available
    global $conn, $log, $pusherService; 

    // 1. Save the notification to the database (This logic is preserved)
    try {
        $message = $data['message'] ?? 'You have a new notification.';
        $stmt = $conn->prepare("INSERT INTO notifications (user_id, message, link) VALUES (?, ?, ?)");
        $stmt->bind_param("iss", $userId, $message, $link);
        $stmt->execute();
        $stmt->close();
    } catch (Exception $e) {
        $log->error("Failed to save notification to database.", ['error' => $e->getMessage()]);
        // We can continue to the Pusher part even if DB save fails
    }

    // 2. Push the real-time notification via the PusherService
    if (!$pusherService) {
        $log->error('Pusher service is not available in send_notification helper.');
        return false;
    }

    // Use the service to send the real-time part and return its success/failure status
    return $pusherService->sendToUser($userId, $eventName, $data);
}

/**
 * Fetches the historical closing price for a given pair at a specific time from Binance.
 * It first attempts to get a high-precision price from aggregate trades.
 * If that fails, it falls back to the closing price of the 1-minute candle.
 *
 * @param string $pair The trading pair (e.g., 'BTC/USDT').
 * @param string $expiry_time The SQL DATETIME string of the trade's expiry.
 * @param object $log The global logger object for error logging.
 * @return float Returns the price as a float, or 0.0 on failure.
 */
function getClosingPrice($pair, $expiry_time, $log) {
    $api_pair = str_replace('/', '', $pair);
    $expiry_timestamp_ms = strtotime($expiry_time) * 1000;

    // --- Method 1: High Precision (Recent Aggregate Trades) ---
    $url_trades = "https://api.binance.com/api/v3/aggTrades?symbol=" . urlencode($api_pair) . "&startTime=" . $expiry_timestamp_ms . "&limit=1";
    
    $ch = curl_init($url_trades);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    $output = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    if (curl_errno($ch)) {
        $log->error('Binance cURL error (aggTrades)', ['pair' => $pair, 'error' => curl_error($ch)]);
    }
    curl_close($ch);
    
    $data = json_decode($output, true);
    if ($http_code == 200 && !empty($data) && isset($data[0]['p'])) {
        return (float)$data[0]['p'];
    }

    // --- Method 2: Fallback (1-Minute Candle Close Price) ---
    $log->warning('High-precision price failed, using k-line fallback.', ['pair' => $pair, 'expiry' => $expiry_time]);
    
    // Calculate the start of the minute for the k-line query
    $start_of_minute_ts = strtotime($expiry_time) - (strtotime($expiry_time) % 60);
    $start_of_minute_ms = $start_of_minute_ts * 1000;
    $url_kline = "https://api.binance.com/api/v3/klines?symbol=" . urlencode($api_pair) . "&interval=1m&startTime=" . $start_of_minute_ms . "&limit=1";
    
    $ch = curl_init($url_kline);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    $output = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if (curl_errno($ch)) {
        $log->error('Binance cURL error (klines)', ['pair' => $pair, 'error' => curl_error($ch)]);
    }
    curl_close($ch);

    $data = json_decode($output, true);
    if ($http_code == 200 && !empty($data) && isset($data[0][4])) {
        // Index 4 is the closing price in a k-line array
        return (float)$data[0][4];
    }

    $log->error('All price fetching methods failed for pair.', ['pair' => $pair, 'expiry' => $expiry_time]);
    return 0.0;
}

/**
 * Sends an email using the centralized Symfony Mailer configuration.
 *
 * @param string $to The recipient's email address.
 * @param string $subject The subject of the email.
 * @param string $htmlBody The HTML content of the email.
 * @param object $log The global logger object for error logging.
 * @return bool True on success, false on failure.
 */
function send_email($to, $subject, $htmlBody, $log) {
    // Add the 'use' statements at the top of helpers.php if they aren't there already
    
    try {
        // Create a DSN string from your .env variables
        // We use "smtps" for SSL on port 465
        $dsn = sprintf(
            "smtps://%s:%s@%s:%s",
            urlencode($_ENV['MAIL_USER']),
            urlencode($_ENV['MAIL_PASS']),
            $_ENV['MAIL_HOST'],
            $_ENV['MAIL_PORT']
        );

        $transport = Transport::fromDsn($dsn);
        $mailer = new Mailer($transport);

        $email = (new Email())
            ->from($_ENV['MAIL_USER'])
            ->to($to)
            ->subject($subject)
            ->html($htmlBody);

        $mailer->send($email);
        
        return true;

    } catch (Exception $e) {
        // Log the detailed error
        $log->error('Failed to send email.', [
            'recipient' => $to,
            'subject' => $subject,
            'error' => $e->getMessage()
        ]);
        return false;
    }
}

/* Encrypts data using AES-256-GCM.
 *
 * @param string $plaintext The data to encrypt.
 * @param string $key       The encryption key (must be 32 bytes).
 * @return string|false The base64-encoded encrypted data (IV + Tag + Ciphertext), or false on failure.
 */
function encrypt_data($plaintext, $key) {
    $key_bytes = hex2bin($key);
    if (strlen($key_bytes) !== 32) {
        // Log this error in a real application
        return false;
    }
    $iv_len = openssl_cipher_iv_length('aes-256-gcm');
    $iv = openssl_random_pseudo_bytes($iv_len);
    $tag = ''; // GCM tag will be filled by openssl_encrypt

    $ciphertext = openssl_encrypt(
        $plaintext,
        'aes-256-gcm',
        $key_bytes,
        OPENSSL_RAW_DATA,
        $iv,
        $tag, // Pass by reference
        '',
        16  // Tag length
    );

    if ($ciphertext === false) {
        return false;
    }

    // Return IV, tag, and ciphertext concatenated and base64-encoded
    return base64_encode($iv . $tag . $ciphertext);
}

/**
 * Decrypts data encrypted with AES-256-GCM.
 *
 * @param string $encrypted_data The base64-encoded data (IV + Tag + Ciphertext).
 * @param string $key            The decryption key (must be 32 bytes).
 * @return string|false The original plaintext data, or false on failure.
 */
function decrypt_data($encrypted_data, $key) {
    $key_bytes = hex2bin($key);
    if (strlen($key_bytes) !== 32) {
        return false;
    }
    $decoded_data = base64_decode($encrypted_data);
    $iv_len = openssl_cipher_iv_length('aes-256-gcm');
    $iv = substr($decoded_data, 0, $iv_len);
    $tag_len = 16;
    $tag = substr($decoded_data, $iv_len, $tag_len);
    $ciphertext = substr($decoded_data, $iv_len + $tag_len);

    $decrypted = openssl_decrypt(
        $ciphertext,
        'aes-256-gcm',
        $key_bytes,
        OPENSSL_RAW_DATA,
        $iv,
        $tag
    );

    return $decrypted;
}

?>