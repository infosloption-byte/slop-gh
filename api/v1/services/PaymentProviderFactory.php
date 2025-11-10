<?php

require_once __DIR__ . '/PayPalService.php';
require_once __DIR__ . '/BinancePayService.php';
require_once __DIR__ . '/SkrillService.php';

class PaymentProviderFactory {
    private static $instances = [];
    private $log;

    public function __construct($log) {
        $this->log = $log;
    }

    /**
     * Get payment provider service instance
     *
     * @param string $provider_type 'paypal', 'binance', 'skrill', or 'stripe_card'
     * @return PayPalService|BinancePayService|SkrillService
     * @throws Exception
     */
    public function getProvider($provider_type) {
        // Normalize provider type
        $provider_type = strtolower($provider_type);

        // Return cached instance if exists
        if (isset(self::$instances[$provider_type])) {
            return self::$instances[$provider_type];
        }

        // Create new instance based on type
        switch ($provider_type) {
            case 'paypal':
                self::$instances[$provider_type] = new PayPalService($this->log);
                break;

            case 'binance':
                self::$instances[$provider_type] = new BinancePayService($this->log);
                break;

            case 'skrill':
                self::$instances[$provider_type] = new SkrillService($this->log);
                break;

            case 'stripe_card':
                // Stripe is already loaded globally in api_bootstrap.php
                // We don't need a separate instance
                return null; // Indicates to use global $stripeService

            default:
                throw new Exception("Unsupported payment provider: {$provider_type}");
        }

        return self::$instances[$provider_type];
    }

    /**
     * Process payout using the appropriate provider
     *
     * @param string $provider_type Payment provider type
     * @param float $amount_dollars Amount in USD
     * @param string $account_identifier Recipient account (email, ID, etc.)
     * @param object $stripe_service Global Stripe service (for Stripe payouts)
     * @param array $stripe_data Stripe-specific data (connect_account_id, external_account_id)
     * @param array $metadata Additional metadata
     * @return string Gateway transaction ID
     * @throws Exception
     */
    public function processPayout($provider_type, $amount_dollars, $account_identifier, $stripe_service = null, $stripe_data = [], $metadata = []) {
        try {
            $provider_type = strtolower($provider_type);

            $this->log->info('PaymentProviderFactory: Processing payout', [
                'provider' => $provider_type,
                'amount' => $amount_dollars
            ]);

            switch ($provider_type) {
                case 'paypal':
                    $service = $this->getProvider('paypal');
                    return $service->createPayout($amount_dollars, $account_identifier, $metadata);

                case 'binance':
                    $service = $this->getProvider('binance');
                    return $service->createPayout($amount_dollars, $account_identifier, $metadata);

                case 'skrill':
                    $service = $this->getProvider('skrill');
                    return $service->createPayout($amount_dollars, $account_identifier, $metadata);

                case 'stripe_card':
                    if (!$stripe_service) {
                        throw new Exception("Stripe service not provided");
                    }

                    $connect_account_id = $stripe_data['connect_account_id'] ?? null;
                    $external_account_id = $stripe_data['external_account_id'] ?? null;

                    if (empty($connect_account_id) || empty($external_account_id)) {
                        throw new Exception("Stripe account is not properly configured.");
                    }

                    // Step 1: Transfer to Connect account
                    $transfer = $stripe_service->transferToConnectAccount(
                        $amount_dollars,
                        $connect_account_id,
                        $metadata
                    );

                    // Step 2: Process payout (standard method)
                    $payout = $stripe_service->processStandardCardPayout(
                        $amount_dollars,
                        $connect_account_id,
                        $external_account_id,
                        array_merge($metadata, ['transfer_id' => $transfer->id])
                    );

                    return $payout->id;

                default:
                    throw new Exception("Unsupported payment provider: {$provider_type}");
            }

        } catch (Exception $e) {
            $this->log->error('PaymentProviderFactory: Payout failed', [
                'provider' => $provider_type,
                'amount' => $amount_dollars,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Validate account identifier for a specific provider
     *
     * @param string $provider_type Payment provider type
     * @param string $account_identifier Account identifier to validate
     * @return bool
     */
    public function validateAccountIdentifier($provider_type, $account_identifier) {
        try {
            $provider_type = strtolower($provider_type);

            switch ($provider_type) {
                case 'paypal':
                    $service = $this->getProvider('paypal');
                    return $service->validateAccountIdentifier($account_identifier);

                case 'binance':
                    $service = $this->getProvider('binance');
                    return $service->validateAccountIdentifier($account_identifier);

                case 'skrill':
                    $service = $this->getProvider('skrill');
                    return $service->validateAccountIdentifier($account_identifier);

                case 'stripe_card':
                    // Stripe validation is done during card addition
                    return !empty($account_identifier);

                default:
                    return false;
            }

        } catch (Exception $e) {
            $this->log->error('Validation failed', [
                'provider' => $provider_type,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Get human-readable provider name
     *
     * @param string $provider_type
     * @return string
     */
    public static function getProviderDisplayName($provider_type) {
        $names = [
            'paypal' => 'PayPal',
            'binance' => 'Binance Pay',
            'skrill' => 'Skrill',
            'stripe_card' => 'Debit Card (Stripe)'
        ];

        return $names[strtolower($provider_type)] ?? ucfirst($provider_type);
    }

    /**
     * Get list of supported providers
     *
     * @return array
     */
    public static function getSupportedProviders() {
        return ['paypal', 'binance', 'skrill', 'stripe_card'];
    }

    /**
     * Check if provider is configured
     *
     * @param string $provider_type
     * @return bool
     */
    public function isProviderConfigured($provider_type) {
        try {
            $provider_type = strtolower($provider_type);

            switch ($provider_type) {
                case 'paypal':
                    return !empty($_ENV['PAYPAL_CLIENT_ID']) && !empty($_ENV['PAYPAL_CLIENT_SECRET']);

                case 'binance':
                    return !empty($_ENV['BINANCE_API_KEY']) && !empty($_ENV['BINANCE_SECRET_KEY']);

                case 'skrill':
                    return !empty($_ENV['SKRILL_MERCHANT_EMAIL']) && !empty($_ENV['SKRILL_API_PASSWORD']);

                case 'stripe_card':
                    return !empty($_ENV['STRIPE_SECRET_KEY']);

                default:
                    return false;
            }

        } catch (Exception $e) {
            return false;
        }
    }
}
?>
