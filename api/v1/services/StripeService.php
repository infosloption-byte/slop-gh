<?php

if (!class_exists('\Stripe\Stripe')) {
    error_log('CRITICAL: Stripe SDK not loaded!');
    throw new Exception('Payment system not configured');
}

use Stripe\Stripe;
use Stripe\Charge;
use Stripe\Token;
use Stripe\Payout;
use Stripe\PaymentMethod;
use Stripe\Customer;

class StripeService {
    private $log;

    public function __construct($log) {
        $this->log = $log;
    
        if (empty($_ENV['STRIPE_SECRET_KEY'])) {
            throw new Exception('STRIPE_SECRET_KEY not configured');
        }
        
        Stripe::setApiKey($_ENV['STRIPE_SECRET_KEY']);
        
        $this->log->info('Stripe initialized', [
            'mode' => strpos($_ENV['STRIPE_SECRET_KEY'], 'test') !== false ? 'test' : 'live'
        ]);
    }

    /**
     * Creates a Stripe charge for a deposit.
     */
    public function createDepositCharge($amount, $token, $user_email) {
        $amount_in_cents = (int)($amount * 100);
        return Charge::create([
            'amount' => $amount_in_cents,
            'currency' => 'usd',
            'description' => "SL Option wallet deposit for user: " . $user_email,
            'source' => $token,
        ]);
    }

    /**
     * Add a debit card for payouts
     * 
     * @param string $card_token Stripe card token from frontend (tok_xxx)
     * @param string $user_email User's email for Stripe customer creation
     * @param array $metadata Additional card metadata
     * @return array Card details ['card_id', 'brand', 'last4', 'country']
     */
    public function addPayoutCard($card_token, $user_email, $metadata = []) {
        try {
            // Create or get Stripe customer
            $customer = Customer::create([
                'email' => $user_email,
                'source' => $card_token,
                'description' => "Payout customer for {$user_email}",
                'metadata' => $metadata
            ]);

            // Get the default card
            $card = $customer->sources->data[0];

            // Verify it's a debit card
            if ($card->funding !== 'debit') {
                throw new Exception("Only debit cards are supported for payouts. Please use a debit card.");
            }

            $this->log->info('Payout card added successfully', [
                'email' => $user_email,
                'card_id' => $card->id,
                'brand' => $card->brand,
                'last4' => $card->last4,
                'country' => $card->country
            ]);

            return [
                'customer_id' => $customer->id,
                'card_id' => $card->id,
                'brand' => strtolower($card->brand), // 'visa', 'mastercard'
                'last4' => $card->last4,
                'country' => $card->country,
                'exp_month' => $card->exp_month,
                'exp_year' => $card->exp_year
            ];

        } catch (\Stripe\Exception\CardException $e) {
            $this->log->error('Card validation failed', [
                'error' => $e->getMessage()
            ]);
            throw new Exception("Card validation failed: " . $e->getMessage());
            
        } catch (\Stripe\Exception\InvalidRequestException $e) {
            $this->log->error('Invalid card token', [
                'error' => $e->getMessage()
            ]);
            throw new Exception("Invalid card information: " . $e->getMessage());
            
        } catch (Exception $e) {
            $this->log->error('Failed to add payout card', [
                'error' => $e->getMessage()
            ]);
            throw new Exception("Failed to add payout card: " . $e->getMessage());
        }
    }

    /**
     * Process payout directly to debit card
     * 
     * @param float $amount Amount in dollars
     * @param string $card_id Stripe card ID (card_xxx)
     * @param array $metadata Transaction metadata
     * @return \Stripe\Payout Payout object
     */
    public function processCardPayout($amount, $card_id, $metadata = []) {
        $amount_in_cents = (int)($amount * 100);
        
        try {
            // Create instant payout to card
            $payout = Payout::create([
                'amount' => $amount_in_cents,
                'currency' => 'usd',
                'destination' => $card_id,
                'method' => 'instant', // Instant payout (arrives in minutes, has fee)
                'description' => $metadata['description'] ?? 'Withdrawal payout',
                'metadata' => $metadata
            ]);

            $this->log->info('Card payout created', [
                'payout_id' => $payout->id,
                'amount' => $amount,
                'card_id' => $card_id,
                'status' => $payout->status
            ]);

            return $payout;

        } catch (\Stripe\Exception\InvalidRequestException $e) {
            $this->log->error('Invalid payout request', [
                'error' => $e->getMessage(),
                'card_id' => $card_id
            ]);
            
            // Check for common errors
            if (strpos($e->getMessage(), 'insufficient funds') !== false) {
                throw new Exception("Insufficient platform balance. Please contact support.");
            }
            
            throw new Exception("Payout failed: " . $e->getMessage());
            
        } catch (\Stripe\Exception\ApiErrorException $e) {
            $this->log->error('Stripe API error during payout', [
                'error' => $e->getMessage(),
                'card_id' => $card_id
            ]);
            throw new Exception("Payout service error: " . $e->getMessage());
            
        } catch (Exception $e) {
            $this->log->error('Unexpected error during payout', [
                'error' => $e->getMessage()
            ]);
            throw new Exception("Payout failed: " . $e->getMessage());
        }
    }

    /**
     * Process standard (slower, cheaper) payout to card
     * Arrives in 1-3 business days, lower fees
     */
    public function processStandardCardPayout($amount, $card_id, $metadata = []) {
        $amount_in_cents = (int)($amount * 100);
        
        try {
            $payout = Payout::create([
                'amount' => $amount_in_cents,
                'currency' => 'usd',
                'destination' => $card_id,
                'method' => 'standard', // Standard payout (1-3 days, lower fee)
                'description' => $metadata['description'] ?? 'Withdrawal payout',
                'metadata' => $metadata
            ]);

            $this->log->info('Standard card payout created', [
                'payout_id' => $payout->id,
                'amount' => $amount,
                'card_id' => $card_id
            ]);

            return $payout;

        } catch (Exception $e) {
            throw new Exception("Standard payout failed: " . $e->getMessage());
        }
    }

    /**
     * Get card details from Stripe
     * 
     * @param string $customer_id Stripe customer ID
     * @param string $card_id Stripe card ID
     * @return object Card object
     */
    public function getCardDetails($customer_id, $card_id) {
        try {
            $customer = Customer::retrieve($customer_id);
            return $customer->sources->retrieve($card_id);
        } catch (Exception $e) {
            throw new Exception("Failed to retrieve card: " . $e->getMessage());
        }
    }

    /**
     * Remove a card from Stripe
     * 
     * @param string $customer_id Stripe customer ID
     * @param string $card_id Stripe card ID
     * @return bool Success status
     */
    public function removeCard($customer_id, $card_id) {
        try {
            $customer = Customer::retrieve($customer_id);
            $card = $customer->sources->retrieve($card_id);
            $card->delete();
            
            $this->log->info('Card removed', [
                'customer_id' => $customer_id,
                'card_id' => $card_id
            ]);
            
            return true;
        } catch (Exception $e) {
            $this->log->error('Failed to remove card', [
                'error' => $e->getMessage()
            ]);
            throw new Exception("Failed to remove card: " . $e->getMessage());
        }
    }

    /**
     * Check payout fees
     * 
     * @param float $amount Amount in dollars
     * @param string $method 'instant' or 'standard'
     * @return array Fee information
     */
    public function calculatePayoutFees($amount, $method = 'standard') {
        // Stripe payout fees (as of 2025)
        $fees = [
            'instant' => [
                'percentage' => 0.01, // 1%
                'fixed' => 0.50, // $0.50
                'min' => 0.50,
                'max' => 10.00
            ],
            'standard' => [
                'percentage' => 0,
                'fixed' => 0.25, // $0.25
                'min' => 0.25,
                'max' => 0.25
            ]
        ];

        $fee_info = $fees[$method] ?? $fees['standard'];
        $calculated_fee = ($amount * $fee_info['percentage']) + $fee_info['fixed'];
        
        // Apply min/max caps
        $fee = max($fee_info['min'], min($calculated_fee, $fee_info['max']));
        
        return [
            'amount' => $amount,
            'fee' => round($fee, 2),
            'net_amount' => round($amount - $fee, 2),
            'method' => $method,
            'arrives_in' => $method === 'instant' ? '30 minutes' : '1-3 business days'
        ];
    }
}