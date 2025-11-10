<?php

class BinancePayService {
    private $log;
    private $api_key;
    private $secret_key;
    private $api_url;
    private $max_retries = 3;

    public function __construct($log) {
        $this->log = $log;
        $this->api_key = $_ENV['BINANCE_API_KEY'] ?? '';
        $this->secret_key = $_ENV['BINANCE_SECRET_KEY'] ?? '';
        $this->api_url = $_ENV['BINANCE_PAY_API_URL'] ?? 'https://bpay.binanceapi.com';

        if (empty($this->api_key) || empty($this->secret_key)) {
            throw new Exception('Binance Pay is not configured. Please check your environment variables.');
        }

        $this->log->info('Binance Pay Service initialized', [
            'api_url' => $this->api_url,
            'mode' => strpos($this->api_url, 'testnet') !== false ? 'testnet' : 'production'
        ]);
    }

    /**
     * Generate Binance Pay signature
     */
    private function generateSignature($timestamp, $nonce, $body) {
        $payload = $timestamp . "\n" . $nonce . "\n" . $body . "\n";
        return strtoupper(hash_hmac('SHA512', $payload, $this->secret_key));
    }

    /**
     * Create a payout via Binance Pay transfer
     *
     * @param float $amount_dollars Amount in USD (will be converted to USDT)
     * @param string $receiver_id Binance Pay ID, Email, or Phone
     * @param array $metadata Additional metadata
     * @return string Transaction order ID
     */
    public function createPayout($amount_dollars, $receiver_id, $metadata = []) {
        try {
            $this->log->info('Binance Pay: Initiating payout', [
                'amount' => $amount_dollars,
                'receiver' => substr($receiver_id, 0, 8) . '***'
            ]);

            // Determine receiver type
            $receiver_type = $this->determineReceiverType($receiver_id);

            // Generate unique order ID
            $order_id = 'SLO_' . time() . '_' . bin2hex(random_bytes(4));

            // Prepare request body
            $body_array = [
                'requestId' => $order_id,
                'receiver' => [
                    'receiverType' => $receiver_type,
                    'receiverId' => $receiver_id
                ],
                'transferAmount' => number_format($amount_dollars, 2, '.', ''),
                'transferAsset' => 'USDT', // Transfer in USDT (Tether stablecoin)
                'description' => $metadata['description'] ?? 'SL Option Withdrawal'
            ];

            $json_body = json_encode($body_array);

            // Make API call with retry logic
            $response = $this->makeApiCallWithRetry(
                $this->api_url . '/binancepay/openapi/wallet/v1/transfer',
                $json_body
            );

            $transaction_id = $response['data']['tranId'] ?? $order_id;

            $this->log->info('Binance Pay: Payout created successfully', [
                'transaction_id' => $transaction_id,
                'amount' => $amount_dollars,
                'status' => $response['status']
            ]);

            return $transaction_id;

        } catch (Exception $e) {
            $this->log->error('Binance Pay: Payout failed', [
                'amount' => $amount_dollars,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Determine receiver type based on identifier format
     */
    private function determineReceiverType($receiver_id) {
        // Email format
        if (filter_var($receiver_id, FILTER_VALIDATE_EMAIL)) {
            return 'EMAIL';
        }

        // Phone format (starts with + and contains only digits)
        if (preg_match('/^\+\d{10,15}$/', $receiver_id)) {
            return 'PHONE';
        }

        // Default to PAY_ID (Binance Pay ID)
        return 'PAY_ID';
    }

    /**
     * Make API call with retry logic
     */
    private function makeApiCallWithRetry($url, $json_body) {
        $attempt = 0;
        $last_error = null;

        while ($attempt < $this->max_retries) {
            try {
                $timestamp = round(microtime(true) * 1000);
                $nonce = bin2hex(random_bytes(16));
                $signature = $this->generateSignature($timestamp, $nonce, $json_body);

                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                curl_setopt($ch, CURLOPT_POST, 1);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $json_body);
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'Content-Type: application/json',
                    'BinancePay-Timestamp: ' . $timestamp,
                    'BinancePay-Nonce: ' . $nonce,
                    'BinancePay-Certificate-SN: ' . $this->api_key,
                    'BinancePay-Signature: ' . $signature
                ]);
                curl_setopt($ch, CURLOPT_TIMEOUT, 30);

                $response_body = curl_exec($ch);
                $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curl_error = curl_error($ch);
                curl_close($ch);

                if ($curl_error) {
                    throw new Exception("Network error: " . $curl_error);
                }

                $response = json_decode($response_body, true);

                // Success
                if ($http_code === 200 && isset($response['status']) && $response['status'] === 'SUCCESS') {
                    return $response;
                }

                // Error handling
                $error_code = $response['code'] ?? 'UNKNOWN';
                $error_message = $response['errorMessage'] ?? $response['message'] ?? 'Unknown error';

                // Permanent errors (don't retry)
                $permanent_errors = ['000001', '000002', '400001', '400002', '400003']; // Invalid params, auth errors
                if (in_array($error_code, $permanent_errors) || ($http_code >= 400 && $http_code < 500)) {
                    throw new Exception("Binance Pay error [{$error_code}]: {$error_message}");
                }

                // Temporary error (retry)
                $last_error = "Binance Pay returned [{$error_code}]: {$error_message}";

            } catch (Exception $e) {
                $last_error = $e->getMessage();

                // Don't retry on permanent errors
                if (strpos($last_error, 'Binance Pay error') === 0) {
                    throw $e;
                }
            }

            $attempt++;
            if ($attempt < $this->max_retries) {
                $wait_time = pow(2, $attempt); // Exponential backoff: 2s, 4s, 8s
                $this->log->warning('Binance Pay: Retry attempt ' . $attempt, [
                    'wait_seconds' => $wait_time,
                    'error' => $last_error
                ]);
                sleep($wait_time);
            }
        }

        throw new Exception("Binance Pay payout failed after {$this->max_retries} attempts: " . $last_error);
    }

    /**
     * Query transfer status
     */
    public function getTransferStatus($transaction_id) {
        try {
            $body_array = [
                'tranId' => $transaction_id
            ];
            $json_body = json_encode($body_array);

            $timestamp = round(microtime(true) * 1000);
            $nonce = bin2hex(random_bytes(16));
            $signature = $this->generateSignature($timestamp, $nonce, $json_body);

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $this->api_url . '/binancepay/openapi/wallet/v1/transfer/query');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $json_body);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'BinancePay-Timestamp: ' . $timestamp,
                'BinancePay-Nonce: ' . $nonce,
                'BinancePay-Certificate-SN: ' . $this->api_key,
                'BinancePay-Signature: ' . $signature
            ]);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);

            $response_body = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLOPT_HTTP_CODE);
            curl_close($ch);

            if ($http_code !== 200) {
                throw new Exception("Failed to get transfer status");
            }

            return json_decode($response_body, true);

        } catch (Exception $e) {
            $this->log->error('Binance Pay: Failed to get transfer status', [
                'transaction_id' => $transaction_id,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Validate Binance Pay account identifier
     */
    public function validateAccountIdentifier($identifier) {
        // Email
        if (filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
            return true;
        }

        // Phone (with country code)
        if (preg_match('/^\+\d{10,15}$/', $identifier)) {
            return true;
        }

        // Binance Pay ID (typically numeric, 8-12 digits)
        if (preg_match('/^\d{8,12}$/', $identifier)) {
            return true;
        }

        return false;
    }
}
?>
