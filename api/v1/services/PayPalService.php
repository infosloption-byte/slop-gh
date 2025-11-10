<?php

class PayPalService {
    private $log;
    private $client_id;
    private $client_secret;
    private $api_url;
    private $max_retries = 3;

    public function __construct($log) {
        $this->log = $log;
        $this->client_id = $_ENV['PAYPAL_CLIENT_ID'] ?? '';
        $this->client_secret = $_ENV['PAYPAL_CLIENT_SECRET'] ?? '';
        $this->api_url = $_ENV['PAYPAL_API_URL'] ?? 'https://api-m.paypal.com';

        if (empty($this->client_id) || empty($this->client_secret)) {
            throw new Exception('PayPal is not configured. Please check your environment variables.');
        }

        $this->log->info('PayPal Service initialized', [
            'api_url' => $this->api_url,
            'mode' => strpos($this->api_url, 'sandbox') !== false ? 'sandbox' : 'live'
        ]);
    }

    /**
     * Get PayPal OAuth access token
     */
    private function getAccessToken() {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->api_url . '/v1/oauth2/token');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, 'grant_type=client_credentials');
        curl_setopt($ch, CURLOPT_USERPWD, $this->client_id . ':' . $this->client_secret);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $response_body = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);

        if ($curl_error) {
            throw new Exception("PayPal connection error: " . $curl_error);
        }

        $token_data = json_decode($response_body, true);

        if ($http_code !== 200 || !isset($token_data['access_token'])) {
            $this->log->error('PayPal: Failed to get access token', [
                'response' => $response_body,
                'http_code' => $http_code
            ]);
            throw new Exception("PayPal authentication failed. Please contact support.");
        }

        return $token_data['access_token'];
    }

    /**
     * Create a payout to PayPal account
     *
     * @param float $amount_dollars Amount in USD
     * @param string $receiver_payer_id PayPal Payer ID or Email
     * @param array $metadata Additional metadata
     * @return string Payout batch ID
     */
    public function createPayout($amount_dollars, $receiver_payer_id, $metadata = []) {
        try {
            $this->log->info('PayPal: Initiating payout', [
                'amount' => $amount_dollars,
                'receiver' => substr($receiver_payer_id, 0, 8) . '***'
            ]);

            // Get access token
            $access_token = $this->getAccessToken();

            // Generate unique batch ID
            $sender_batch_id = 'SLO_PAYOUT_' . time() . '_' . bin2hex(random_bytes(4));

            // Determine recipient type (email vs payer_id)
            $recipient_type = filter_var($receiver_payer_id, FILTER_VALIDATE_EMAIL) ? 'EMAIL' : 'PAYER_ID';

            // Prepare payout request
            $payout_body = [
                'sender_batch_header' => [
                    'sender_batch_id' => $sender_batch_id,
                    'email_subject' => 'You have a payout from SL Option!',
                    'email_message' => 'You have received a payout from SL Option. Thank you for trading with us!'
                ],
                'items' => [
                    [
                        'recipient_type' => $recipient_type,
                        'amount' => [
                            'value' => number_format($amount_dollars, 2, '.', ''),
                            'currency' => 'USD'
                        ],
                        'receiver' => $receiver_payer_id,
                        'note' => 'SL Option Withdrawal',
                        'sender_item_id' => 'ITEM_' . time() . '_' . bin2hex(random_bytes(2))
                    ]
                ]
            ];

            // Make API call with retry logic
            $response = $this->makeApiCallWithRetry(
                $this->api_url . '/v1/payments/payouts',
                $payout_body,
                $access_token
            );

            $payout_batch_id = $response['batch_header']['payout_batch_id'];

            $this->log->info('PayPal: Payout created successfully', [
                'batch_id' => $payout_batch_id,
                'amount' => $amount_dollars,
                'status' => $response['batch_header']['batch_status']
            ]);

            return $payout_batch_id;

        } catch (Exception $e) {
            $this->log->error('PayPal: Payout failed', [
                'amount' => $amount_dollars,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Make API call with retry logic
     */
    private function makeApiCallWithRetry($url, $body, $access_token) {
        $attempt = 0;
        $last_error = null;

        while ($attempt < $this->max_retries) {
            try {
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                curl_setopt($ch, CURLOPT_POST, 1);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $access_token
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
                if ($http_code === 201 && isset($response['batch_header']['payout_batch_id'])) {
                    return $response;
                }

                // Permanent error (don't retry)
                if ($http_code >= 400 && $http_code < 500) {
                    $error_message = $response['message'] ??
                                   ($response['details'][0]['issue'] ?? 'Unknown error');
                    throw new Exception("PayPal error: " . $error_message);
                }

                // Temporary error (retry)
                $last_error = "PayPal returned HTTP {$http_code}: " . ($response['message'] ?? 'Unknown error');

            } catch (Exception $e) {
                $last_error = $e->getMessage();

                // Don't retry on permanent errors
                if (strpos($last_error, 'PayPal error:') === 0) {
                    throw $e;
                }
            }

            $attempt++;
            if ($attempt < $this->max_retries) {
                $wait_time = pow(2, $attempt); // Exponential backoff: 2s, 4s, 8s
                $this->log->warning('PayPal: Retry attempt ' . $attempt, [
                    'wait_seconds' => $wait_time,
                    'error' => $last_error
                ]);
                sleep($wait_time);
            }
        }

        throw new Exception("PayPal payout failed after {$this->max_retries} attempts: " . $last_error);
    }

    /**
     * Get payout status
     */
    public function getPayoutStatus($payout_batch_id) {
        try {
            $access_token = $this->getAccessToken();

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $this->api_url . '/v1/payments/payouts/' . $payout_batch_id);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $access_token
            ]);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);

            $response_body = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($http_code !== 200) {
                throw new Exception("Failed to get payout status");
            }

            return json_decode($response_body, true);

        } catch (Exception $e) {
            $this->log->error('PayPal: Failed to get payout status', [
                'batch_id' => $payout_batch_id,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Validate PayPal account identifier
     */
    public function validateAccountIdentifier($identifier) {
        // Can be email or Payer ID
        if (filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
            return true;
        }

        // Payer ID format: usually 13 characters alphanumeric
        if (preg_match('/^[A-Z0-9]{13}$/', $identifier)) {
            return true;
        }

        return false;
    }
}
?>
