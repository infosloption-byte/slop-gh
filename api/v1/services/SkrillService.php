<?php

class SkrillService {
    private $log;
    private $merchant_email;
    private $api_password;
    private $secret_word;
    private $api_url;
    private $max_retries = 3;

    public function __construct($log) {
        $this->log = $log;
        $this->merchant_email = $_ENV['SKRILL_MERCHANT_EMAIL'] ?? '';
        $this->api_password = $_ENV['SKRILL_API_PASSWORD'] ?? '';
        $this->secret_word = $_ENV['SKRILL_SECRET_WORD'] ?? '';
        $this->api_url = $_ENV['SKRILL_API_URL'] ?? 'https://www.skrill.com';

        if (empty($this->merchant_email) || empty($this->api_password)) {
            throw new Exception('Skrill is not configured. Please check your environment variables.');
        }

        $this->log->info('Skrill Service initialized', [
            'merchant_email' => $this->merchant_email,
            'api_url' => $this->api_url
        ]);
    }

    /**
     * Create a payout via Skrill Automated Payout Interface
     *
     * @param float $amount_dollars Amount in USD
     * @param string $receiver_email Recipient's Skrill email
     * @param array $metadata Additional metadata
     * @return string Transaction ID
     */
    public function createPayout($amount_dollars, $receiver_email, $metadata = []) {
        try {
            $this->log->info('Skrill: Initiating payout', [
                'amount' => $amount_dollars,
                'receiver' => $receiver_email
            ]);

            // Validate email
            if (!filter_var($receiver_email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception("Invalid Skrill email address: {$receiver_email}");
            }

            // Step 1: Prepare the payout
            $session_id = $this->preparePayout($amount_dollars, $receiver_email, $metadata);

            // Step 2: Execute the payout
            $transaction_id = $this->executePayout($session_id);

            $this->log->info('Skrill: Payout created successfully', [
                'transaction_id' => $transaction_id,
                'amount' => $amount_dollars,
                'receiver' => $receiver_email
            ]);

            return $transaction_id;

        } catch (Exception $e) {
            $this->log->error('Skrill: Payout failed', [
                'amount' => $amount_dollars,
                'receiver' => $receiver_email,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Step 1: Prepare payout (get session ID)
     */
    private function preparePayout($amount_dollars, $receiver_email, $metadata) {
        $attempt = 0;
        $last_error = null;

        while ($attempt < $this->max_retries) {
            try {
                // Generate unique transaction ID
                $transaction_id = 'SLO_' . time() . '_' . bin2hex(random_bytes(4));

                // Prepare request parameters
                $params = [
                    'action' => 'prepare',
                    'email' => $this->merchant_email,
                    'password' => md5($this->api_password),
                    'amount' => number_format($amount_dollars, 2, '.', ''),
                    'currency' => 'USD',
                    'bnf_email' => $receiver_email,
                    'subject' => $metadata['subject'] ?? 'Withdrawal from SL Option',
                    'note' => $metadata['note'] ?? 'Thank you for trading with SL Option',
                    'frn_trn_id' => $transaction_id
                ];

                // Make API call
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $this->api_url . '/app/pay.pl');
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                curl_setopt($ch, CURLOPT_POST, 1);
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
                curl_setopt($ch, CURLOPT_TIMEOUT, 30);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

                $response = curl_exec($ch);
                $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curl_error = curl_error($ch);
                curl_close($ch);

                if ($curl_error) {
                    throw new Exception("Network error: " . $curl_error);
                }

                // Parse response (Skrill returns session ID as plain text)
                $session_id = trim($response);

                // Check for errors
                if ($http_code !== 200 || empty($session_id) || !is_numeric($session_id)) {
                    $last_error = "Skrill prepare failed: " . ($response ?: 'Empty response');
                    throw new Exception($last_error);
                }

                return $session_id;

            } catch (Exception $e) {
                $last_error = $e->getMessage();
            }

            $attempt++;
            if ($attempt < $this->max_retries) {
                $wait_time = pow(2, $attempt);
                $this->log->warning('Skrill: Prepare retry attempt ' . $attempt, [
                    'wait_seconds' => $wait_time,
                    'error' => $last_error
                ]);
                sleep($wait_time);
            }
        }

        throw new Exception("Skrill prepare failed after {$this->max_retries} attempts: " . $last_error);
    }

    /**
     * Step 2: Execute payout using session ID
     */
    private function executePayout($session_id) {
        $attempt = 0;
        $last_error = null;

        while ($attempt < $this->max_retries) {
            try {
                // Prepare execution request
                $params = [
                    'action' => 'transfer',
                    'email' => $this->merchant_email,
                    'password' => md5($this->api_password),
                    'sid' => $session_id
                ];

                // Make API call
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $this->api_url . '/app/pay.pl');
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                curl_setopt($ch, CURLOPT_POST, 1);
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
                curl_setopt($ch, CURLOPT_TIMEOUT, 30);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

                $response = curl_exec($ch);
                $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curl_error = curl_error($ch);
                curl_close($ch);

                if ($curl_error) {
                    throw new Exception("Network error: " . $curl_error);
                }

                // Parse XML response
                $xml = simplexml_load_string($response);

                if ($xml === false) {
                    throw new Exception("Invalid XML response from Skrill");
                }

                // Check for errors
                if (isset($xml->error)) {
                    $error_code = (string)$xml->error->error_msg;
                    throw new Exception("Skrill error: {$error_code}");
                }

                // Get transaction ID
                if (isset($xml->transaction)) {
                    $transaction_id = (string)$xml->transaction->id;

                    if (empty($transaction_id)) {
                        throw new Exception("Skrill returned empty transaction ID");
                    }

                    return $transaction_id;
                }

                throw new Exception("Skrill response missing transaction ID");

            } catch (Exception $e) {
                $last_error = $e->getMessage();

                // Don't retry on certain errors
                if (strpos($last_error, 'Skrill error:') === 0) {
                    throw $e;
                }
            }

            $attempt++;
            if ($attempt < $this->max_retries) {
                $wait_time = pow(2, $attempt);
                $this->log->warning('Skrill: Execute retry attempt ' . $attempt, [
                    'wait_seconds' => $wait_time,
                    'error' => $last_error
                ]);
                sleep($wait_time);
            }
        }

        throw new Exception("Skrill execute failed after {$this->max_retries} attempts: " . $last_error);
    }

    /**
     * Get transaction status
     */
    public function getTransactionStatus($transaction_id, $transaction_type = 'transaction_id') {
        try {
            $params = [
                'action' => 'status_trn',
                'email' => $this->merchant_email,
                'password' => md5($this->api_password),
                'trn_id' => $transaction_id
            ];

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $this->api_url . '/app/query.pl');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);

            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($http_code !== 200) {
                throw new Exception("Failed to get transaction status");
            }

            // Parse XML response
            $xml = simplexml_load_string($response);

            if ($xml === false || isset($xml->error)) {
                throw new Exception("Transaction not found or error occurred");
            }

            return [
                'status' => (string)($xml->status ?? 'unknown'),
                'amount' => (string)($xml->amount ?? '0.00'),
                'currency' => (string)($xml->currency ?? 'USD'),
                'transaction_id' => $transaction_id
            ];

        } catch (Exception $e) {
            $this->log->error('Skrill: Failed to get transaction status', [
                'transaction_id' => $transaction_id,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Validate Skrill email address
     */
    public function validateAccountIdentifier($identifier) {
        return filter_var($identifier, FILTER_VALIDATE_EMAIL);
    }

    /**
     * Calculate Skrill fees
     * Skrill charges approximately 1% for transfers
     */
    public function calculateFees($amount_dollars) {
        $fee_percentage = 0.01; // 1%
        $fee = $amount_dollars * $fee_percentage;

        return [
            'amount' => $amount_dollars,
            'fee' => round($fee, 2),
            'net_amount' => round($amount_dollars - $fee, 2)
        ];
    }
}
?>
