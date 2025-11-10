<?php

class WithdrawalConfig {
    // Withdrawal Limits (in USD)
    const MIN_WITHDRAWAL = 10.00;
    const MAX_SINGLE_WITHDRAWAL = 50000.00;
    const AUTO_APPROVE_LIMIT = 500.00;

    // Daily Limits (in USD)
    const MAX_DAILY_WITHDRAWAL_PER_USER = 10000.00;
    const MAX_MONTHLY_WITHDRAWAL_PER_USER = 50000.00;

    // Risk Management
    const REQUIRE_MANUAL_REVIEW_OVER = 500.00;
    const REQUIRE_KYC_VERIFICATION_OVER = 2000.00;

    // Processing
    const ENABLE_AUTO_PROCESSING = true;
    const MANUAL_REVIEW_PROCESSING_SLA_HOURS = 24;

    // Network & Retry
    const MAX_RETRY_ATTEMPTS = 3;
    const RETRY_DELAY_SECONDS = 2; // Initial delay, uses exponential backoff

    // Transaction Safety
    const ENABLE_IDEMPOTENCY_CHECK = true;
    const DUPLICATE_WINDOW_MINUTES = 5;

    // Fees (in USD or percentage)
    const STRIPE_INSTANT_PAYOUT_FEE_PERCENT = 1.0;
    const STRIPE_INSTANT_PAYOUT_FEE_FIXED = 0.50;
    const STRIPE_INSTANT_PAYOUT_FEE_MIN = 0.50;
    const STRIPE_INSTANT_PAYOUT_FEE_MAX = 10.00;

    const STRIPE_STANDARD_PAYOUT_FEE = 0.25;

    const PAYPAL_FEE_PERCENT = 0.0; // PayPal typically charges the sender
    const BINANCE_FEE_PERCENT = 0.0; // No fee for internal transfers
    const SKRILL_FEE_PERCENT = 1.0; // Skrill charges ~1%

    // Notification Settings
    const NOTIFY_USER_ON_PENDING = true;
    const NOTIFY_USER_ON_APPROVED = true;
    const NOTIFY_USER_ON_REJECTED = true;
    const NOTIFY_USER_ON_FAILED = true;

    const NOTIFY_ADMIN_ON_HIGH_VALUE = true;
    const HIGH_VALUE_THRESHOLD = 5000.00;

    // Supported Payment Methods
    const SUPPORTED_METHODS = [
        'stripe_card' => [
            'name' => 'Debit Card',
            'provider' => 'Stripe',
            'instant' => true,
            'processing_time' => '30 minutes (instant) or 1-3 business days (standard)',
            'enabled' => true
        ],
        'paypal' => [
            'name' => 'PayPal',
            'provider' => 'PayPal',
            'instant' => false,
            'processing_time' => '1-3 business days',
            'enabled' => true
        ],
        'binance' => [
            'name' => 'Binance Pay',
            'provider' => 'Binance',
            'instant' => true,
            'processing_time' => 'Instant',
            'enabled' => true
        ],
        'skrill' => [
            'name' => 'Skrill',
            'provider' => 'Skrill',
            'instant' => true,
            'processing_time' => 'Instant',
            'enabled' => true
        ]
    ];

    /**
     * Get minimum withdrawal amount
     */
    public static function getMinWithdrawal() {
        return self::MIN_WITHDRAWAL;
    }

    /**
     * Get auto-approve limit
     */
    public static function getAutoApproveLimit() {
        return self::AUTO_APPROVE_LIMIT;
    }

    /**
     * Check if amount requires manual review
     */
    public static function requiresManualReview($amount) {
        return $amount >= self::REQUIRE_MANUAL_REVIEW_OVER;
    }

    /**
     * Check if amount requires KYC verification
     */
    public static function requiresKYCVerification($amount) {
        return $amount >= self::REQUIRE_KYC_VERIFICATION_OVER;
    }

    /**
     * Get max retry attempts
     */
    public static function getMaxRetries() {
        return self::MAX_RETRY_ATTEMPTS;
    }

    /**
     * Calculate Stripe payout fees
     */
    public static function calculateStripeFees($amount, $method = 'standard') {
        if ($method === 'instant') {
            $fee = ($amount * (self::STRIPE_INSTANT_PAYOUT_FEE_PERCENT / 100)) + self::STRIPE_INSTANT_PAYOUT_FEE_FIXED;
            $fee = max(self::STRIPE_INSTANT_PAYOUT_FEE_MIN, min($fee, self::STRIPE_INSTANT_PAYOUT_FEE_MAX));
        } else {
            $fee = self::STRIPE_STANDARD_PAYOUT_FEE;
        }

        return [
            'amount' => $amount,
            'fee' => round($fee, 2),
            'net_amount' => round($amount - $fee, 2),
            'method' => $method
        ];
    }

    /**
     * Calculate fees for any provider
     */
    public static function calculateProviderFees($provider, $amount) {
        switch (strtolower($provider)) {
            case 'stripe_card':
                return self::calculateStripeFees($amount, 'standard');

            case 'paypal':
                $fee = $amount * (self::PAYPAL_FEE_PERCENT / 100);
                break;

            case 'binance':
                $fee = $amount * (self::BINANCE_FEE_PERCENT / 100);
                break;

            case 'skrill':
                $fee = $amount * (self::SKRILL_FEE_PERCENT / 100);
                break;

            default:
                $fee = 0;
        }

        return [
            'amount' => $amount,
            'fee' => round($fee, 2),
            'net_amount' => round($amount - $fee, 2),
            'provider' => $provider
        ];
    }

    /**
     * Get supported payment methods
     */
    public static function getSupportedMethods() {
        return array_filter(self::SUPPORTED_METHODS, function($method) {
            return $method['enabled'];
        });
    }

    /**
     * Check if payment method is enabled
     */
    public static function isMethodEnabled($method_type) {
        return isset(self::SUPPORTED_METHODS[$method_type]) && self::SUPPORTED_METHODS[$method_type]['enabled'];
    }

    /**
     * Get method display info
     */
    public static function getMethodInfo($method_type) {
        return self::SUPPORTED_METHODS[$method_type] ?? null;
    }

    /**
     * Get all configuration as array (for API responses)
     */
    public static function toArray() {
        return [
            'limits' => [
                'min_withdrawal' => self::MIN_WITHDRAWAL,
                'max_single_withdrawal' => self::MAX_SINGLE_WITHDRAWAL,
                'max_daily_withdrawal' => self::MAX_DAILY_WITHDRAWAL_PER_USER,
                'max_monthly_withdrawal' => self::MAX_MONTHLY_WITHDRAWAL_PER_USER,
                'auto_approve_limit' => self::AUTO_APPROVE_LIMIT
            ],
            'risk_management' => [
                'manual_review_threshold' => self::REQUIRE_MANUAL_REVIEW_OVER,
                'kyc_verification_threshold' => self::REQUIRE_KYC_VERIFICATION_OVER,
                'high_value_threshold' => self::HIGH_VALUE_THRESHOLD
            ],
            'processing' => [
                'auto_processing_enabled' => self::ENABLE_AUTO_PROCESSING,
                'manual_review_sla_hours' => self::MANUAL_REVIEW_PROCESSING_SLA_HOURS
            ],
            'supported_methods' => self::getSupportedMethods()
        ];
    }

    /**
     * Validate withdrawal amount
     */
    public static function validateAmount($amount) {
        $errors = [];

        if ($amount < self::MIN_WITHDRAWAL) {
            $errors[] = "Minimum withdrawal amount is $" . self::MIN_WITHDRAWAL;
        }

        if ($amount > self::MAX_SINGLE_WITHDRAWAL) {
            $errors[] = "Maximum single withdrawal amount is $" . self::MAX_SINGLE_WITHDRAWAL;
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }

    /**
     * Check daily withdrawal limit
     */
    public static function checkDailyLimit($conn, $user_id, $requested_amount) {
        $query = "SELECT COALESCE(SUM(amount), 0) as total_today
                  FROM withdrawal_requests
                  WHERE user_id = ?
                  AND status IN ('APPROVED', 'PENDING')
                  AND DATE(created_at) = CURDATE()";

        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $total_today_cents = $result['total_today'];
        $total_today = $total_today_cents / 100;
        $new_total = $total_today + $requested_amount;

        return [
            'allowed' => $new_total <= self::MAX_DAILY_WITHDRAWAL_PER_USER,
            'current_total' => $total_today,
            'requested' => $requested_amount,
            'new_total' => $new_total,
            'limit' => self::MAX_DAILY_WITHDRAWAL_PER_USER,
            'remaining' => max(0, self::MAX_DAILY_WITHDRAWAL_PER_USER - $total_today)
        ];
    }
}
?>
