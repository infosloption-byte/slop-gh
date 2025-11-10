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
use Stripe\Account;
use Stripe\Transfer;

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
     * ⭐ IMPROVED: Check and fix Connect account capabilities with better error handling
     * Returns true if account is ready, false if needs verification
     */
    public function checkAndFixCapabilities($connect_account_id) {
        try {
            $this->log->info('Checking Connect account capabilities', [
                'account_id' => $connect_account_id
            ]);
            
            $account = \Stripe\Account::retrieve($connect_account_id);
            
            $card_payments_status = $account->capabilities->card_payments ?? 'inactive';
            $transfers_status = $account->capabilities->transfers ?? 'inactive';
            
            $this->log->info('Current capability status', [
                'account_id' => $connect_account_id,
                'card_payments' => $card_payments_status,
                'transfers' => $transfers_status
            ]);
            
            // If capabilities are inactive, request them
            if ($card_payments_status === 'inactive' || $transfers_status === 'inactive') {
                $this->log->warning('Capabilities are inactive, requesting activation', [
                    'account_id' => $connect_account_id
                ]);
                
                // Request the capabilities
                $updated_account = \Stripe\Account::update(
                    $connect_account_id,
                    [
                        'capabilities' => [
                            'card_payments' => ['requested' => true],
                            'transfers' => ['requested' => true],
                        ]
                    ]
                );
                
                $new_card_payments = $updated_account->capabilities->card_payments ?? 'inactive';
                $new_transfers = $updated_account->capabilities->transfers ?? 'inactive';
                
                $this->log->info('Capabilities update requested', [
                    'account_id' => $connect_account_id,
                    'card_payments' => $new_card_payments,
                    'transfers' => $new_transfers
                ]);
                
                // If still inactive after update, verification is needed
                if ($new_card_payments === 'inactive' && $new_transfers === 'inactive') {
                    return false; // Needs verification
                }
            }
            
            // If capabilities are active or pending, account is ready
            if ($card_payments_status === 'active' || $card_payments_status === 'pending' ||
                $transfers_status === 'active' || $transfers_status === 'pending') {
                return true;
            }
            
            return false; // Still needs work
            
        } catch (\Stripe\Exception\InvalidRequestException $e) {
            $this->log->error('Invalid Connect account', [
                'account_id' => $connect_account_id,
                'error' => $e->getMessage()
            ]);
            throw new Exception("Connect account not found or invalid: " . $e->getMessage());
            
        } catch (Exception $e) {
            $this->log->error('Failed to check/fix capabilities', [
                'account_id' => $connect_account_id,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * ⭐ NEW: Comprehensive capability status check
     * Returns detailed information about account status
     */
    public function getDetailedCapabilityStatus($connect_account_id) {
        try {
            $account = \Stripe\Account::retrieve($connect_account_id);
            
            $card_payments = $account->capabilities->card_payments ?? 'inactive';
            $transfers = $account->capabilities->transfers ?? 'inactive';
            
            return [
                'account_id' => $connect_account_id,
                'capabilities' => [
                    'card_payments' => [
                        'status' => $card_payments,
                        'is_active' => $card_payments === 'active',
                        'is_pending' => $card_payments === 'pending',
                        'needs_fix' => $card_payments === 'inactive'
                    ],
                    'transfers' => [
                        'status' => $transfers,
                        'is_active' => $transfers === 'active',
                        'is_pending' => $transfers === 'pending',
                        'needs_fix' => $transfers === 'inactive'
                    ]
                ],
                'account_status' => [
                    'charges_enabled' => $account->charges_enabled ?? false,
                    'payouts_enabled' => $account->payouts_enabled ?? false,
                    'details_submitted' => $account->details_submitted ?? false
                ],
                'requirements' => $account->requirements ?? null,
                'is_ready_for_payouts' => ($account->payouts_enabled ?? false) && 
                                         ($card_payments === 'active' || $card_payments === 'pending') &&
                                         ($transfers === 'active' || $transfers === 'pending')
            ];
            
        } catch (Exception $e) {
            $this->log->error('Failed to get detailed capability status', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Add a debit card as external account to Connect account
     */
    public function addPayoutCard($card_token, $connect_account_id, $card_holder_name, $metadata = []) {
        try {
            $this->log->info('Adding external account to Connect', [
                'connect_account' => $connect_account_id,
                'cardholder' => $card_holder_name
            ]);

            // Create external account (card) on the Connect account
            $external_account = \Stripe\Account::createExternalAccount(
                $connect_account_id,
                [
                    'external_account' => $card_token,
                    'default_for_currency' => true,
                    'metadata' => array_merge([
                        'cardholder_name' => $card_holder_name
                    ], $metadata)
                ]
            );

            // Verify it's a debit card
            if ($external_account->object === 'card' && $external_account->funding !== 'debit') {
                // Remove the card we just added
                \Stripe\Account::deleteExternalAccount(
                    $connect_account_id,
                    $external_account->id
                );
                throw new Exception("Only debit cards are supported for payouts. Please use a debit card.");
            }

            $this->log->info('External account added successfully', [
                'connect_account' => $connect_account_id,
                'external_account_id' => $external_account->id,
                'brand' => $external_account->brand ?? 'N/A',
                'last4' => $external_account->last4 ?? 'N/A'
            ]);

            return [
                'connect_account_id' => $connect_account_id,
                'external_account_id' => $external_account->id,
                'brand' => strtolower($external_account->brand ?? 'unknown'),
                'last4' => $external_account->last4 ?? '0000',
                'country' => $external_account->country ?? 'US',
                'exp_month' => $external_account->exp_month ?? null,
                'exp_year' => $external_account->exp_year ?? null,
                'funding' => $external_account->funding ?? 'unknown'
            ];

        } catch (\Stripe\Exception\CardException $e) {
            $this->log->error('Card validation failed', [
                'error' => $e->getMessage()
            ]);
            throw new Exception("Card validation failed: " . $e->getMessage());
            
        } catch (\Stripe\Exception\InvalidRequestException $e) {
            $this->log->error('Invalid request to Stripe', [
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
     * Process instant payout to Connect external account (card)
     */
    public function processInstantCardPayout($amount, $connect_account_id, $external_account_id, $metadata = []) {
        $amount_in_cents = (int)($amount * 100);
        
        try {
            $this->log->info('Creating instant payout', [
                'connect_account' => $connect_account_id,
                'amount' => $amount,
                'external_account' => $external_account_id
            ]);

            $payout = \Stripe\Payout::create([
                'amount' => $amount_in_cents,
                'currency' => 'usd',
                'destination' => $external_account_id,
                'method' => 'instant',
                'description' => $metadata['description'] ?? 'Withdrawal payout',
                'metadata' => $metadata,
                'statement_descriptor' => 'SLOPTION'
            ], [
                'stripe_account' => $connect_account_id
            ]);

            $this->log->info('Instant payout created successfully', [
                'payout_id' => $payout->id,
                'amount' => $amount,
                'status' => $payout->status,
                'arrival_date' => $payout->arrival_date
            ]);

            return $payout;

        } catch (\Stripe\Exception\InvalidRequestException $e) {
            $this->log->error('Invalid payout request', [
                'error' => $e->getMessage(),
                'connect_account' => $connect_account_id
            ]);
            
            if (strpos($e->getMessage(), 'insufficient funds') !== false) {
                throw new Exception("Insufficient balance in Connect account. Please contact support.");
            }
            
            throw new Exception("Payout failed: " . $e->getMessage());
            
        } catch (Exception $e) {
            $this->log->error('Unexpected error during payout', [
                'error' => $e->getMessage()
            ]);
            throw new Exception("Payout failed: " . $e->getMessage());
        }
    }

    /**
     * Process standard payout to Connect external account
     */
    public function processStandardCardPayout($amount, $connect_account_id, $external_account_id, $metadata = []) {
        $amount_in_cents = (int)($amount * 100);
        
        try {
            $this->log->info('Creating standard payout', [
                'connect_account' => $connect_account_id,
                'amount' => $amount,
                'external_account' => $external_account_id
            ]);

            $payout = \Stripe\Payout::create([
                'amount' => $amount_in_cents,
                'currency' => 'usd',
                'destination' => $external_account_id,
                'method' => 'standard',
                'description' => $metadata['description'] ?? 'Withdrawal payout',
                'metadata' => $metadata,
                'statement_descriptor' => 'SLOPTION'
            ], [
                'stripe_account' => $connect_account_id
            ]);

            $this->log->info('Standard payout created successfully', [
                'payout_id' => $payout->id,
                'amount' => $amount,
                'status' => $payout->status,
                'arrival_date' => $payout->arrival_date
            ]);

            return $payout;

        } catch (Exception $e) {
            $this->log->error('Standard payout failed', [
                'error' => $e->getMessage()
            ]);
            throw new Exception("Standard payout failed: " . $e->getMessage());
        }
    }

    /**
     * Transfer funds from platform to Connect account balance
     */
    public function transferToConnectAccount($amount, $connect_account_id, $metadata = []) {
        $amount_in_cents = (int)($amount * 100);
        
        try {
            $this->log->info('Creating transfer to Connect account', [
                'connect_account' => $connect_account_id,
                'amount' => $amount
            ]);

            $transfer = \Stripe\Transfer::create([
                'amount' => $amount_in_cents,
                'currency' => 'usd',
                'destination' => $connect_account_id,
                'description' => $metadata['description'] ?? 'Withdrawal transfer',
                'metadata' => $metadata
            ]);

            $this->log->info('Transfer created successfully', [
                'transfer_id' => $transfer->id,
                'amount' => $amount
            ]);

            return $transfer;

        } catch (Exception $e) {
            $this->log->error('Transfer failed', [
                'error' => $e->getMessage()
            ]);
            throw new Exception("Transfer failed: " . $e->getMessage());
        }
    }

    /**
     * Get external account details from Connect account
     */
    public function getExternalAccount($connect_account_id, $external_account_id) {
        try {
            return \Stripe\Account::retrieveExternalAccount(
                $connect_account_id,
                $external_account_id
            );
        } catch (Exception $e) {
            throw new Exception("Failed to retrieve external account: " . $e->getMessage());
        }
    }

    /**
     * Remove external account from Connect account
     */
    public function removeExternalAccount($connect_account_id, $external_account_id) {
        try {
            \Stripe\Account::deleteExternalAccount(
                $connect_account_id,
                $external_account_id
            );
            
            $this->log->info('External account removed', [
                'connect_account' => $connect_account_id,
                'external_account' => $external_account_id
            ]);
            
            return true;
        } catch (Exception $e) {
            $this->log->error('Failed to remove external account', [
                'error' => $e->getMessage()
            ]);
            throw new Exception("Failed to remove card: " . $e->getMessage());
        }
    }

    /**
     * Check Connect account status
     */
    public function getConnectAccountStatus($connect_account_id) {
        try {
            $account = \Stripe\Account::retrieve($connect_account_id);
            
            return [
                'charges_enabled' => $account->charges_enabled ?? false,
                'payouts_enabled' => $account->payouts_enabled ?? false,
                'details_submitted' => $account->details_submitted ?? false,
                'requirements' => $account->requirements ?? null
            ];
        } catch (Exception $e) {
            throw new Exception("Failed to get account status: " . $e->getMessage());
        }
    }

    /**
     * Get Connect account details including external accounts
     */
    public function getConnectAccount($connect_account_id) {
        try {
            $account = \Stripe\Account::retrieve($connect_account_id);
            
            $default_external_account = null;
            
            if ($account->external_accounts && $account->external_accounts->total_count > 0) {
                $external = $account->external_accounts->data[0];
                $default_external_account = [
                    'id' => $external->id,
                    'last4' => $external->last4,
                    'brand' => $external->brand ?? 'Card',
                    'account_holder_name' => $external->account_holder_name ?? 'Cardholder',
                    'country' => $external->country ?? 'US',
                    'currency' => $external->currency ?? 'usd'
                ];
            }
            
            return [
                'id' => $account->id,
                'charges_enabled' => $account->charges_enabled ?? false,
                'payouts_enabled' => $account->payouts_enabled ?? false,
                'details_submitted' => $account->details_submitted ?? false,
                'default_external_account' => $default_external_account,
                'requirements' => $account->requirements ?? null
            ];
            
        } catch (\Stripe\Exception\InvalidRequestException $e) {
            $this->log->error('Connect account not found', [
                'connect_account' => $connect_account_id,
                'error' => $e->getMessage()
            ]);
            throw new Exception("Connect account not found: " . $e->getMessage());
            
        } catch (Exception $e) {
            $this->log->error('Failed to retrieve Connect account', [
                'error' => $e->getMessage()
            ]);
            throw new Exception("Failed to retrieve account details: " . $e->getMessage());
        }
    }

    /**
     * Calculate payout fees based on method
     */
    public function calculatePayoutFees($amount, $method = 'standard') {
        $fees = [
            'instant' => [
                'percentage' => 0.01,
                'fixed' => 0.50,
                'min' => 0.50,
                'max' => 10.00
            ],
            'standard' => [
                'percentage' => 0,
                'fixed' => 0.25,
                'min' => 0.25,
                'max' => 0.25
            ]
        ];

        $fee_info = $fees[$method] ?? $fees['standard'];
        $calculated_fee = ($amount * $fee_info['percentage']) + $fee_info['fixed'];
        
        $fee = max($fee_info['min'], min($calculated_fee, $fee_info['max']));
        
        return [
            'amount' => $amount,
            'fee' => round($fee, 2),
            'net_amount' => round($amount - $fee, 2),
            'method' => $method,
            'arrives_in' => $method === 'instant' ? '30 minutes' : '1-3 business days'
        ];
    }

    /**
     * Create Connect account with proper capabilities
     */
    public function createConnectAccountWithCapabilities($user_email, $user_data = []) {
        try {
            $this->log->info('Creating Connect account with capabilities', [
                'email' => $user_email
            ]);

            $account = \Stripe\Account::create([
                'type' => 'custom',
                'country' => 'US',
                'email' => $user_email,
                'capabilities' => [
                    'card_payments' => ['requested' => true],
                    'transfers' => ['requested' => true],
                ],
                'business_type' => 'individual',
                'tos_acceptance' => [
                    'date' => time(),
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
                ],
                'metadata' => array_merge([
                    'platform' => 'sloption',
                    'created_at' => date('Y-m-d H:i:s')
                ], $user_data)
            ]);

            $this->log->info('Connect account created with capabilities', [
                'account_id' => $account->id,
                'capabilities' => $account->capabilities
            ]);

            return [
                'account_id' => $account->id,
                'capabilities' => [
                    'card_payments' => $account->capabilities->card_payments ?? 'inactive',
                    'transfers' => $account->capabilities->transfers ?? 'inactive',
                ],
                'charges_enabled' => $account->charges_enabled ?? false,
                'payouts_enabled' => $account->payouts_enabled ?? false,
                'details_submitted' => $account->details_submitted ?? false
            ];

        } catch (Exception $e) {
            $this->log->error('Failed to create Connect account', [
                'error' => $e->getMessage()
            ]);
            throw new Exception("Failed to create payout account: " . $e->getMessage());
        }
    }

    /**
     * Update existing Connect account to add missing capabilities
     */
    public function updateConnectAccountCapabilities($connect_account_id) {
        try {
            $this->log->info('Updating Connect account capabilities', [
                'account_id' => $connect_account_id
            ]);

            $account = \Stripe\Account::update(
                $connect_account_id,
                [
                    'capabilities' => [
                        'card_payments' => ['requested' => true],
                        'transfers' => ['requested' => true],
                    ]
                ]
            );

            $this->log->info('Connect account capabilities updated', [
                'account_id' => $account->id,
                'capabilities' => $account->capabilities
            ]);

            return [
                'account_id' => $account->id,
                'capabilities' => [
                    'card_payments' => $account->capabilities->card_payments ?? 'inactive',
                    'transfers' => $account->capabilities->transfers ?? 'inactive',
                ],
                'requirements' => $account->requirements ?? null
            ];

        } catch (Exception $e) {
            $this->log->error('Failed to update Connect account capabilities', [
                'account_id' => $connect_account_id,
                'error' => $e->getMessage()
            ]);
            throw new Exception("Failed to update account capabilities: " . $e->getMessage());
        }
    }
}
?>