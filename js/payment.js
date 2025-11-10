/**
 * main-payments.js - Payment & Withdrawal System (NEW UPGRADED VERSION)
 * Dependencies: main-core.js, Stripe.js
 * Handles: Deposits, multi-provider withdrawals, and payout method management
 */

// ============================================================================
// FINANCES POPUP - MAIN CONTAINER
// ============================================================================

/**
 * Open finances popup with deposits/withdrawals
 */
async function openFinancesPopup() {
    if (!profileData || !profileData.email) {
        showToast("Please log in to view your profile.");
        openLoginPopup();
        return;
    }

    const popupContent = document.getElementById('popupContent');
    const popupContainer = document.getElementById('popupContainer');

    popupContent.classList.add('popup-dark-theme', 'popup-large');
    popupContent.style.padding = '20px';
    
    popupContent.innerHTML = `<h3 class="text-xl font-semibold mb-4 text-center">Finances</h3><div class="text-center p-8 text-gray-50">Loading...</div>`;
    popupContainer.classList.add('active');

    // --- NEW DYNAMIC FLOW ---
    // Fetch both transactions AND the new payout methods
    const [allTransactions, payoutMethods] = await Promise.all([
        getTransactionHistory(),
        getPayoutMethods() // This is our new API call
    ]);
    
    // Build transaction tabs (no change)
    const paymentHistory = allTransactions.filter(tx => tx.type === 'DEPOSIT' || tx.type === 'WITHDRAWAL');
    const ledgerHistory = allTransactions;
    const transactionsHtml = generateTransactionTable(paymentHistory);
    const ledgerHtml = generateTransactionTable(ledgerHistory);

    // Build deposit tab (no change)
    const depositFormHtml = buildDepositForm(); 

    // Build NEW withdrawal tab
    const withdrawalFormHtml = buildWithdrawalTab(payoutMethods);
    // --- END NEW DYNAMIC FLOW ---

    popupContent.innerHTML = `
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-xl font-bold">Finances</h3>
            <button onclick="closePopup()" class="text-2xl text-gray-400 hover:text-white">&times;</button>
        </div>
        
        <div class="p-1.5 flex space-x-2 rounded-lg bg-gray-600">
            <button onclick="showFinanceTab('deposits', this)" class="popup-tab active flex-1 py-1.5 text-sm font-semibold rounded-md">Deposits</button>
            <button onclick="showFinanceTab('withdrawal', this)" class="popup-tab flex-1 py-1.5 text-sm font-semibold rounded-md">Withdrawal</button>
            <button onclick="showFinanceTab('transactions', this)" class="popup-tab flex-1 py-1.5 text-sm font-semibold rounded-md">Transactions</button>
            <button onclick="showFinanceTab('ledger', this)" class="popup-tab flex-1 py-1.5 text-sm font-semibold rounded-md">Ledger</button>
        </div>
        
        <div class="mt-2 h-96 overflow-y-auto">
            <div id="deposits-content" class="popup-content">${depositFormHtml}</div>
            <div id="withdrawal-content" class="popup-content hidden">${withdrawalFormHtml}</div>
            <div id="transactions-content" class="popup-content hidden">${transactionsHtml}</div>
            <div id="ledger-content" class="popup-content hidden">${ledgerHtml}</div>
        </div>
    `;

    // Initialize Stripe for deposits
    if (typeof Stripe !== 'undefined') {
        initializeStripeDeposit();
    }

    // Initialize withdrawal form handler (if it was rendered)
    if (payoutMethods && payoutMethods.length > 0) {
        initializeWithdrawalForm();
    }
    
    // Check for URL params to auto-open a tab (e.g., after PayPal redirect)
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.has('popup') && urlParams.get('popup') === 'finances') {
        const tab = urlParams.get('tab') || 'withdrawal';
        const tabButton = document.querySelector(`.popup-tab[onclick*="'${tab}'"]`);
        if (tabButton) {
            showFinanceTab(tab, tabButton);
        }
        
        // Show error if PayPal callback failed
        if (urlParams.has('error')) {
            showToast('Error: ' + urlParams.get('error'));
        }
        
        // Remove params from URL
        window.history.replaceState({}, document.title, window.location.pathname);
    }
}

/**
 * Show finance tab
 */
function showFinanceTab(tabName, clickedElement) {
    document.querySelectorAll('.popup-content').forEach(el => el.classList.add('hidden'));
    document.querySelectorAll('.popup-tab').forEach(el => el.classList.remove('active'));
    
    document.getElementById(tabName + '-content').classList.remove('hidden');
    clickedElement.classList.add('active');
}

/**
 * Generate transaction table HTML
 */
function generateTransactionTable(transactions) {
    if (transactions.length === 0) { 
        return '<div class="h-96 flex items-center justify-center"><p class="text-gray-50">No transactions to show.</p></div>'; 
    }
    let rows = transactions.map(tx => {
        const isCredit = tx.type.includes('CREDIT') || tx.type.includes('DEPOSIT');
        const typeClass = isCredit ? 'text-green-600' : 'text-red-600';
        const amount = parseFloat(tx.amount);
        const formattedAmount = (isCredit ? '+' : '-') + amount.toLocaleString('en-US', { style: 'currency', currency: 'USD' });
        return `
            <tr class="border-b border-gray-600 last:border-b-0">
                <td class="p-3 text-gray-50 whitespace-nowrap">${new Date(tx.created_at).toLocaleString()}</td>
                <td class="p-3">${tx.type.replace('_', ' ')}</td>
                <td class="p-3 font-semibold ${typeClass}">${formattedAmount}</td>
                <td class="p-3 text-gray-50">${tx.status}</td>
            </tr>
        `;
    }).join('');
    return `<div class="h-96 overflow-y-auto border border-gray-600 rounded-lg"><table class="w-full text-left text-sm"><thead class="bg-gray-600"><tr class="border-b border-gray-600"><th class="p-3 font-semibold text-gray-50">Date</th><th class="p-3 font-semibold text-gray-50">Type</th><th class="p-3 font-semibold text-gray-50">Amount</th><th class="p-3 font-semibold text-gray-50">Status</th></tr></thead><tbody class="divide-y divide-gray-600">${rows}</tbody></table></div>`;
}

// ============================================================================
// DEPOSITS (No changes from original file)
// ============================================================================

/**
 * Build deposit form HTML
 */
function buildDepositForm() {
    return `
        <div class="p-6 space-y-4">
            <div class="bg-blue-900/20 border border-blue-500/30 rounded-lg p-4 mb-4">
                <div class="flex items-start space-x-3">
                    <svg class="w-6 h-6 text-blue-400 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                    <div class="text-sm text-gray-300">
                        <p class="font-semibold text-blue-400 mb-1">Current Balance</p>
                        <p class="text-2xl font-bold text-white">$${(userWallets.real || 0).toFixed(2)}</p>
                    </div>
                </div>
            </div>

            <form id="depositForm" class="space-y-4">
                <div>
                    <label for="depositAmount" class="block text-sm font-medium text-gray-300 mb-2">Deposit Amount (USD)</label>
                    <div class="relative">
                        <span class="absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400">$</span>
                        <input type="number" id="depositAmount" min="10" step="0.01" required 
                            class="w-full pl-8 pr-4 py-3 bg-gray-700 border border-gray-600 text-white rounded-lg focus:outline-none focus:border-blue-500" 
                            placeholder="Enter amount (min $10)">
                    </div>
                    <p class="text-xs text-gray-400 mt-1">Minimum deposit: $10.00</p>
                </div>

                <div id="card-element" class="p-4 bg-gray-700 border border-gray-600 rounded-lg"></div>
                <div id="card-errors" class="text-red-400 text-sm h-4"></div>

                <button type="submit" id="depositSubmitBtn" class="w-full py-3 bg-green-600 text-white font-semibold rounded-lg hover:bg-green-700 transition-colors">
                    Process Deposit
                </button>
            </form>
        </div>
    `;
}

/**
 * Initialize Stripe for deposits
 */
async function initializeStripeDeposit() {
    const keysResponse = await getPublicKeys();
    
    if (!keysResponse || !keysResponse.stripePublishableKey) {
        console.error('Failed to load Stripe key');
        document.getElementById('card-errors').textContent = 'Payment system not available. Please try again later.';
        return;
    }
    
    const stripe = Stripe(keysResponse.stripePublishableKey);
    const elements = stripe.elements();
    
    const cardElement = elements.create('card', {
        style: {
            base: {
                color: '#fff',
                fontFamily: '"Helvetica Neue", Helvetica, sans-serif',
                fontSmoothing: 'antialiased',
                fontSize: '16px',
                '::placeholder': { color: '#9ca3af' }
            },
            invalid: { color: '#ef4444', iconColor: '#ef4444' }
        }
    });
    
    cardElement.mount('#card-element');
    
    cardElement.on('change', (event) => {
        const displayError = document.getElementById('card-errors');
        if (event.error) {
            displayError.textContent = event.error.message;
        } else {
            displayError.textContent = '';
        }
    });
    
    const form = document.getElementById('depositForm');
    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        
        const amount = parseFloat(document.getElementById('depositAmount').value);
        const submitBtn = document.getElementById('depositSubmitBtn');
        const errorDiv = document.getElementById('card-errors');
        
        if (amount < 10) {
            errorDiv.textContent = 'Minimum deposit amount is $10';
            return;
        }
        
        submitBtn.disabled = true;
        submitBtn.textContent = 'Processing...';
        errorDiv.textContent = '';
        
        try {
            const {token, error} = await stripe.createToken(cardElement);
            
            if (error) {
                errorDiv.textContent = error.message;
                submitBtn.disabled = false;
                submitBtn.textContent = 'Process Deposit';
                return;
            }
            
            const result = await processDeposit(amount, token.id);
            
            if (result.message === 'Deposit successful.') {
                showToast('Deposit successful!');
                cardElement.clear();
                
                const freshBalances = await getWalletBalance();
                if (freshBalances && !freshBalances.error) {
                    userWallets = freshBalances;
                    updateWalletUI();
                }
                
                setTimeout(() => {
                    closePopup();
                    setTimeout(() => {
                        openFinancesPopup().then(() => {
                            showFinanceTab('transactions', document.querySelectorAll('.popup-tab')[2]);
                        });
                    }, 300);
                }, 1500);
            } else {
                errorDiv.textContent = result.message || 'Deposit failed';
                submitBtn.disabled = false;
                submitBtn.textContent = 'Process Deposit';
            }
        } catch (err) {
            console.error('Deposit error:', err);
            errorDiv.textContent = 'An error occurred. Please try again.';
            submitBtn.disabled = false;
            submitBtn.textContent = 'Process Deposit';
        }
    });
}

// ============================================================================
// WITHDRAWALS - NEW MINIMALIST FLOW
// ============================================================================

/**
 * NEW: Dynamically builds the withdrawal tab based on connected methods
 */
function buildWithdrawalTab(methods) {
    let methodOptionsHtml = '';
    if (methods && methods.length > 0) {
        methodOptionsHtml = methods.map(method => {
            const isDefault = method.is_default ? ' (Default)' : '';
            // Use method.id which is the user_payout_methods.id
            return `<option value="${method.id}">${escapeHTML(method.display_name)}${isDefault}</option>`;
        }).join('');
    }

    // Case 1: User has connected methods
    if (methods && methods.length > 0) {
        return `
            <div class="p-6 space-y-4">
                <div class="bg-green-900/20 border border-green-500/30 rounded-lg p-4 mb-4">
                    <div class="flex items-center space-x-3">
                        <svg class="w-6 h-6 text-green-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                        <div class="text-right flex-1">
                            <p class="text-xs text-gray-400 mb-1">Available Balance</p>
                            <p class="text-2xl font-bold text-white">$${(userWallets.real || 0).toFixed(2)}</p>
                        </div>
                    </div>
                </div>

                <form id="withdrawalForm" class="space-y-4">
                    <div>
                        <label for="withdrawalAmount" class="block text-sm font-medium text-gray-300 mb-2">Withdrawal Amount (USD)</label>
                        <div class="relative">
                            <span class="absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400">$</span>
                            <input type="number" id="withdrawalAmount" min="10" max="${(userWallets.real || 0).toFixed(2)}" step="0.01" required 
                                class="w-full pl-8 pr-4 py-3 bg-gray-700 border border-gray-600 text-white rounded-lg focus:outline-none focus:border-blue-500" 
                                placeholder="Enter amount (min $10)">
                        </div>
                    </div>

                    <div>
                        <label for="payoutMethodId" class="block text-sm font-medium text-gray-300 mb-2">Payout To</label>
                        <select id="payoutMethodId" class="w-full px-4 py-3 bg-gray-700 border border-gray-600 text-white rounded-lg focus:outline-none focus:border-blue-500">
                            ${methodOptionsHtml}
                        </select>
                    </div>

                    <div id="withdrawalMessage" class="text-sm text-center h-4 mb-4"></div>

                    <button type="submit" id="withdrawalSubmitBtn" class="w-full py-3 bg-red-600 text-white font-semibold rounded-lg hover:bg-red-700 transition-colors">
                        Withdraw $10.00
                    </button>
                </form>
                
                <hr class="border-gray-600 my-6" />
                
                <button onclick="openAddPayoutMethodPopup()" 
                    class="w-full py-3 bg-gray-700 text-white font-semibold rounded-lg hover:bg-gray-600 transition-colors">
                    + Add New Payout Method
                </button>
            </div>
        `;
    } 
    // Case 2: User has NO connected methods
    else {
        return `
            <div class="p-6 space-y-4">
                <div class="bg-yellow-900/20 border border-yellow-500/30 rounded-lg p-4">
                    <div class="flex items-start space-x-3">
                        <svg class="w-6 h-6 text-yellow-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
                        <div class="flex-1">
                            <p class="font-semibold text-yellow-400 mb-1">Add a Payout Method</p>
                            <p class="text-sm text-gray-300 mb-3">To withdraw funds, please connect a payout account first.</p>
                            <button onclick="openAddPayoutMethodPopup()" 
                                class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 text-sm font-medium">
                                Add Payout Method Now
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        `;
    }
}

/**
 * NEW: Opens a popup to choose which payout service to add
 * (This is the final version with "Coming Soon" removed)
 */
function openAddPayoutMethodPopup() {
    const popupContent = document.getElementById('popupContent');
    const popupContainer = document.getElementById('popupContainer');

    popupContent.classList.add('popup-dark-theme');
    popupContent.style.padding = '20px';
    popupContainer.classList.remove('popup-large'); // Make it a standard size popup
    
    popupContent.innerHTML = `
        <div class="flex justify-between items-center mb-6">
            <h3 class="text-2xl font-bold text-white">Add Payout Method</h3>
            <button onclick="closePopup()" class="text-2xl text-gray-400 hover:text-white">&times;</button>
        </div>
        <p class="text-gray-400 text-sm mb-4">Select a service to connect for receiving payouts. You will be redirected or asked for your account details.</p>
        <div class="space-y-3">
            <button onclick="navigateToAddCard()" class="w-full p-4 bg-gray-700 hover:bg-gray-600 rounded-lg text-left flex items-center space-x-4">
                <img src="/assets/images/stripe_logo.png" alt="PayPal" class="w-8 h-8">
                <div>
                    <span class="font-semibold text-white">Visa / Mastercard Debit</span>
                    <p class="text-sm text-gray-400">Payout to your debit card via Stripe.</p>
                </div>
            </button>
            <button onclick="window.location.href='/api/v1/payments/paypal/connect.php'" class="w-full p-4 bg-gray-700 hover:bg-gray-600 rounded-lg text-left flex items-center space-x-4">
                <img src="/assets/images/paypal_logo.png" alt="PayPal" class="w-8 h-8">
                <div>
                    <span class="font-semibold text-white">PayPal</span>
                    <p class="text-sm text-gray-400">Connect your PayPal account.</p>
                </div>
            </button>
            <button onclick="openAddSimpleMethodPopup('binance')" class="w-full p-4 bg-gray-700 hover:bg-gray-600 rounded-lg text-left flex items-center space-x-4">
                <img src="/assets/images/binance_logo.png" alt="Binance" class="w-8 h-8">
                <div>
                    <span class="font-semibold text-white">Binance Pay</span>
                    <p class="text-sm text-gray-400">Payout to your Binance Pay ID.</p>
                </div>
            </button>
            <button onclick="openAddSimpleMethodPopup('skrill')" class="w-full p-4 bg-gray-700 hover:bg-gray-600 rounded-lg text-left flex items-center space-x-4">
                <img src="/assets/images/skrill_logo.png" alt="Skrill" class="w-8 h-8">
                <div>
                    <span class="font-semibold text-white">Skrill</span>
                    <p class="text-sm text-gray-400">Payout to your Skrill email.</p>
                </div>
            </button>
        </div>
    `;
    
    popupContainer.classList.add('active');
}

/**
 * NEW: Opens a simple form for ID/email based methods
 */
function openAddSimpleMethodPopup(methodType) {
    const methodTitle = methodType.charAt(0).toUpperCase() + methodType.slice(1);
    
    // Stub for services that are not ready
    if (['paypal', 'binance', 'skrill'].includes(methodType)) {
        showToast(`${methodTitle} payouts are coming soon!`);
        return;
    }
    
    const popupContent = document.getElementById('popupContent');
    popupContent.innerHTML = `
        <div class="flex justify-between items-center mb-6">
            <h3 class="text-2xl font-bold text-white">Connect ${methodTitle}</h3>
            <button onclick="openAddPayoutMethodPopup()" class="text-2xl text-gray-400 hover:text-white">&larr;</button>
        </div>
        
        <form id="simpleMethodForm" class="space-y-4">
            <p class="text-gray-400 text-sm">Enter your ${methodTitle} account identifier (e.g., email or Pay ID).</p>
            <div>
                <label for="identifierInput" class="block text-sm font-medium text-gray-300 mb-2">${methodTitle} Account ID</label>
                <input type="text" id="identifierInput" required 
                    class="w-full px-4 py-3 bg-gray-700 border border-gray-600 text-white rounded-lg focus:outline-none focus:border-blue-500">
            </div>
            <div id="simpleMethodMessage" class="text-sm text-center h-4 text-red-400"></div>
            <button type="submit" id="simpleMethodSubmitBtn" class="w-full py-3 bg-blue-600 text-white font-semibold rounded-lg hover:bg-blue-700 transition-colors">
                Save ${methodTitle} Account
            </button>
        </form>
    `;
    
    document.getElementById('simpleMethodForm').addEventListener('submit', async (e) => {
        e.preventDefault();
        const identifier = document.getElementById('identifierInput').value;
        const msgDiv = document.getElementById('simpleMethodMessage');
        const submitBtn = document.getElementById('simpleMethodSubmitBtn');
        
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<span class="btn-loader"></span>Saving...';

        try {
            const result = await addSimpleMethod(methodType, identifier);
            if (result.message && result.message.includes('successfully')) {
                showToast(`${methodTitle} account added!`);
                closePopup();
                setTimeout(() => openFinancesPopup(), 400); // Re-open finances to show new method
            } else {
                throw new Error(result.message || 'Failed to add account');
            }
        } catch (error) {
            msgDiv.textContent = error.message;
            submitBtn.disabled = false;
            submitBtn.innerHTML = `Save ${methodTitle} Account`;
        }
    });
}

/**
 * NEW: Initialize withdrawal form handler for the new instant-payout flow
 */
function initializeWithdrawalForm() {
    const form = document.getElementById('withdrawalForm');
    if (!form) return; // No form to initialize (user has no methods yet)
    
    const amountInput = document.getElementById('withdrawalAmount');
    const submitBtn = document.getElementById('withdrawalSubmitBtn');
    
    // Update button text as user types amount
    amountInput.addEventListener('input', () => {
        const amount = parseFloat(amountInput.value) || 0;
        if (amount >= 10) {
            submitBtn.textContent = `Withdraw $${amount.toFixed(2)}`;
        } else {
            submitBtn.textContent = 'Request Withdrawal';
        }
    });
    
    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        
        const amount = parseFloat(amountInput.value);
        const payoutMethodId = document.getElementById('payoutMethodId').value; // This is the new, crucial part
        
        const msgDiv = document.getElementById('withdrawalMessage');
        
        const maxAmount = userWallets.real || 0;
        
        if (amount < 10) {
            msgDiv.className = 'text-sm text-center h-4 text-red-400';
            msgDiv.textContent = 'Minimum withdrawal amount is $10';
            return;
        }
        
        if (amount > maxAmount) {
            msgDiv.className = 'text-sm text-center h-4 text-red-400';
            msgDiv.textContent = 'Insufficient balance';
            return;
        }
        
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<span class="btn-loader"></span>Processing...';
        msgDiv.textContent = '';
        
        try {
            // Call the *new* createWithdrawal API which now does the payout
            const result = await apiFetch('/payments/withdrawals/create_withdrawal.php', {
                method: 'POST',
                body: JSON.stringify({
                    amount: amount,
                    payout_method_id: parseInt(payoutMethodId, 10)
                })
            });
            
            if (result.message && result.message.includes('successfully')) {
                msgDiv.className = 'text-sm text-center h-4 text-green-400';
                msgDiv.textContent = 'Withdrawal successful!';
                showToast('Withdrawal processed successfully!');
                
                // Refresh balance
                const freshBalances = await getWalletBalance();
                if (freshBalances && !freshBalances.error) {
                    userWallets = freshBalances;
                    updateWalletUI();
                }
                
                setTimeout(() => {
                    closePopup();
                    setTimeout(() => {
                        openFinancesPopup().then(() => {
                            showFinanceTab('transactions', document.querySelectorAll('.popup-tab')[2]);
                        });
                    }, 300);
                }, 2000);
            } else {
                // The API will return specific errors (e.g., "Payout failed: ...")
                throw new Error(result.message || 'Withdrawal failed');
            }
        } catch (err) {
            msgDiv.className = 'text-sm text-center h-4 text-red-400';
            msgDiv.textContent = err.message;
            submitBtn.disabled = false;
            submitBtn.textContent = `Withdraw $${amount.toFixed(2)}`;
        }
    });
}

// ============================================================================
// PAYOUT CARD MANAGEMENT
// ============================================================================

/**
 * Navigate to add card - checks onboarding first
 * This is now the flow for "Visa / Mastercard"
 */
async function navigateToAddCard() {
    closePopup(); // Close the "select method" popup
    setTimeout(() => {
        openAddCardPopupWithAutoFix();
    }, 400);
}

/**
 * Navigate to payout cards management
 */
function navigateToPayoutCards() {
    closePopup();
    setTimeout(() => {
        openProfilePopup().then(() => {
            // Wait for popup to render, then switch to payouts tab
            setTimeout(() => {
                const payoutsTab = document.querySelectorAll('.popup-tab')[1];
                if (payoutsTab) {
                    showPopupTab('payouts', payoutsTab);
                }
            }, 100);
        });
    }, 400);
}

/**
 * Open add card popup with capability check
 */
async function openAddCardPopupWithAutoFix() {
    const popupContent = document.getElementById('popupContent');
    const popupContainer = document.getElementById('popupContainer');
    
    popupContent.classList.add('popup-dark-theme');
    popupContent.style.padding = '20px';
    popupContainer.classList.remove('popup-large');
    
    popupContent.innerHTML = `
        <div class="text-center py-8">
            <div class="spinner mx-auto mb-4"></div>
            <p class="text-gray-300">Checking your account status...</p>
        </div>
    `;
    popupContainer.classList.add('active');
    
    try {
        const capabilityStatus = await checkAndFixMyCapabilities();
        
        if (!capabilityStatus.is_ready) {
            closePopup();
            setTimeout(() => {
                showCapabilityStatusPopup(capabilityStatus);
            }, 300);
            return;
        }
        
        showAddCardForm();
        
    } catch (error) {
        console.error('Capability check failed:', error);
        
        closePopup();
        setTimeout(() => showExpressOnboardingPopup(), 400);
        showToast("Please complete verification to add a card.");
    }
}

/**
 * Show the actual add card form (only called when all checks pass)
 */
function showAddCardForm() {
    const popupContent = document.getElementById('popupContent');
    const popupContainer = document.getElementById('popupContainer');
    
    getPublicKeys().then(keys => {
        const isTestMode = keys.stripePublishableKey && keys.stripePublishableKey.includes('pk_test');
        
        const testModeHelper = isTestMode ? `
            <div class="bg-purple-900/20 border border-purple-500/30 rounded-lg p-4 mb-4">
                <p class="font-semibold text-purple-400 mb-2">🧪 Test Mode Active</p>
                <p class="text-xs text-gray-400">Use test debit card: <b class="text-green-400">4000 0566 5566 5556</b></p>
            </div>
        ` : '';
        
        popupContent.innerHTML = `
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-xl font-bold text-white">Add Visa/Mastercard Debit</h3>
                 <button onclick="openAddPayoutMethodPopup()" class="text-2xl text-gray-400 hover:text-white">&larr;</button>
            </div>
            
            <form id="addCardForm" class="space-y-4 p-1">
                ${testModeHelper}
                
                <div class="bg-blue-900/20 border border-blue-500/30 rounded-lg p-4 mb-4">
                    <p class="font-semibold text-blue-400 mb-1">✓ Only debit cards accepted</p>
                    <p class="text-sm text-gray-400">Credit/prepaid cards are not supported for payouts.</p>
                </div>

                <div>
                    <label for="cardHolderName" class="block text-sm font-medium text-gray-300 mb-2">Cardholder Name</label>
                    <input type="text" id="cardHolderName" required placeholder="John Doe" 
                        class="w-full px-4 py-3 bg-gray-700 border border-gray-600 text-white rounded-lg focus:outline-none focus:border-blue-500">
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-300 mb-2">Card Details</label>
                    <div id="card-element" class="p-4 bg-gray-700 border border-gray-600 rounded-lg"></div>
                    <div id="card-errors" class="text-red-400 text-sm mt-2 min-h-6"></div>
                </div>

                <div id="addCardMessage" class="text-sm text-center min-h-6"></div>

                <div class="flex gap-4 pt-4">
                    <button type="button" onclick="openAddPayoutMethodPopup()" class="flex-1 px-4 py-3 bg-gray-600 text-white rounded-lg hover:bg-gray-700">
                        Cancel
                    </button>
                    <button type="submit" id="addCardSubmitBtn" class="flex-1 px-4 py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 font-semibold">
                        Add Card
                    </button>
                </div>
            </form>
        `;

        popupContainer.classList.add('active');

        if (typeof Stripe !== 'undefined') {
            initializeStripeCardElement();
        }
    });
}

/**
 * Initialize Stripe Card Element
 */
async function initializeStripeCardElement() {
    try {
        const keys = await getPublicKeys();
        
        if (!keys || !keys.stripePublishableKey) {
            throw new Error('Stripe keys not available');
        }
        
        const stripe = Stripe(keys.stripePublishableKey);
        const elements = stripe.elements();
        
        const cardElement = elements.create('card', {
            style: {
                base: {
                    color: '#fff',
                    fontFamily: '"Helvetica Neue", Helvetica, sans-serif',
                    fontSmoothing: 'antialiased',
                    fontSize: '16px',
                    '::placeholder': { color: '#9ca3af' }
                },
                invalid: { color: '#ef4444', iconColor: '#ef4444' }
            }
        });
        
        cardElement.mount('#card-element');
        
        cardElement.on('change', (event) => {
            const displayError = document.getElementById('card-errors');
            if (event.error) {
                displayError.textContent = event.error.message;
            } else {
                displayError.textContent = '';
            }
        });
        
        document.getElementById('addCardForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            
            const cardHolderName = document.getElementById('cardHolderName').value.trim();
            const submitBtn = document.getElementById('addCardSubmitBtn');
            const errorDiv = document.getElementById('card-errors');
            const messageDiv = document.getElementById('addCardMessage');
            
            submitBtn.disabled = true;
            submitBtn.textContent = 'Processing...';
            errorDiv.textContent = '';
            messageDiv.textContent = '';
            
            try {
                const {token, error} = await stripe.createToken(cardElement, {
                    name: cardHolderName
                });
                
                if (error) {
                    throw new Error(error.message);
                }
                
                const result = await addPayoutCard(token.id, cardHolderName);
                
                if (result && result.message && result.message.includes('successfully')) {
                    messageDiv.className = 'text-sm text-center min-h-6 text-green-400';
                    messageDiv.textContent = '✓ Card added successfully!';
                    showToast('Card added successfully!');
                    
                    setTimeout(() => {
                        closePopup();
                        setTimeout(() => openFinancesPopup(), 400);
                    }, 1500);
                    return;
                }
                
                throw new Error(result?.message || 'Failed to add card');
                
            } catch (err) {
                console.error('Add card error:', err);
                errorDiv.textContent = err.message || 'An unexpected error occurred. Please try again.';
                submitBtn.disabled = false;
                submitBtn.textContent = 'Add Card';
            }
        });
        
    } catch (err) {
        console.error('Stripe initialization error:', err);
        document.getElementById('card-errors').textContent = 
            'Payment system initialization failed. Please refresh the page.';
    }
}

/**
 * Check and auto-fix user's Connect capabilities
 */
async function checkAndFixMyCapabilities() {
    try {
        const response = await fetch('/api/v1/payments/stripe/capabilities/check_and_fix.php', {
            method: 'POST',
            credentials: 'include',
            headers: { 'Content-Type': 'application/json' }
        });
        const result = await response.json();
        if (!response.ok) {
            throw new Error(result.message || 'Failed to check capabilities');
        }
        return result;
    } catch (error) {
        console.error('Check/fix capabilities error:', error);
        throw error;
    }
}

/**
 * Show capability status popup to user
 */
function showCapabilityStatusPopup(statusData) {
    const popupContent = document.getElementById('popupContent');
    const popupContainer = document.getElementById('popupContainer');
    
    popupContent.classList.add('popup-dark-theme');
    popupContent.style.padding = '20px';
    popupContainer.classList.remove('popup-large');

    const isReady = statusData.is_ready;
    
    popupContent.innerHTML = `
        <div class="flex justify-between items-center mb-6">
            <h3 class="text-2xl font-bold text-white">${isReady ? 'Account Ready!' : 'Account Status'}</h3>
            <button onclick="closePopup()" class="text-2xl text-gray-400 hover:text-white">&times;</button>
        </div>

        <div class="bg-${isReady ? 'green' : 'yellow'}-900/20 border border-${isReady ? 'green' : 'yellow'}-500/30 rounded-lg p-6 mb-6">
            <p class="text-${isReady ? 'green' : 'yellow'}-400 font-semibold text-lg">${statusData.message}</p>
        </div>

        <div class="mt-6 flex gap-4">
            <button onclick="closePopup()" class="flex-1 py-3 ${isReady ? 'bg-green-600 hover:bg-green-700' : 'bg-gray-600 hover:bg-gray-700'} text-white rounded-lg">
                ${isReady ? '✓ Continue' : 'Close'}
            </button>
        </div>
    `;
    popupContainer.classList.add('active');
}

/**
 * Start Express account onboarding
 */
async function startExpressOnboardingWithCountry(button) {
    const countrySelect = document.getElementById('userCountry');
    const errorDiv = document.getElementById('onboardingError');
    const country = countrySelect.value;

    if (!country) {
        errorDiv.textContent = 'Please select your country first';
        countrySelect.focus();
        return;
    }

    button.disabled = true;
    const originalText = button.innerHTML;
    button.innerHTML = '<span class="btn-loader"></span>Setting up...';
    errorDiv.textContent = '';

    try {
        console.log('Starting Express onboarding for country:', country);
        
        const createResponse = await fetch('/api/v1/payments/stripe/connect/create_express_account.php', {
            method: 'POST',
            credentials: 'include',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ country: country })
        });

        const contentType = createResponse.headers.get('content-type');
        if (!contentType || !contentType.includes('application/json')) {
            const text = await createResponse.text();
            console.error('Non-JSON response:', text);
            throw new Error('Server returned invalid response. Check PHP error logs.');
        }

        const createData = await createResponse.json();
        console.log('Create account response:', createData);

        if (!createResponse.ok || createData.error) {
            throw new Error(createData.message || 'Failed to create account');
        }

        if (!createData.onboarding_url) {
            throw new Error('No onboarding URL returned from server');
        }

        button.innerHTML = '<span class="btn-loader"></span>Redirecting to verification...';
        showToast('Redirecting to Stripe verification...');
        
        setTimeout(() => {
            window.location.href = createData.onboarding_url;
        }, 500);
        
    } catch (error) {
        console.error('Express onboarding error:', error);
        button.disabled = false;
        button.innerHTML = originalText;
        errorDiv.textContent = error.message;
        showToast(error.message || 'Failed to start onboarding. Please try again.');
    }
}

/**
 * Show Express onboarding popup
 */
function showExpressOnboardingPopup() {
    // This function is still useful for *starting* the Stripe flow
    // if a user tries to add a card but doesn't have a Connect account.
    
    const popupContent = document.getElementById('popupContent');
    const popupContainer = document.getElementById('popupContainer');
    
    popupContent.classList.add('popup-dark-theme');
    popupContent.style.padding = '20px';
    popupContainer.classList.remove('popup-large');

    popupContent.innerHTML = `
        <div class="flex justify-between items-center mb-6">
            <h3 class="text-2xl font-bold text-white">Setup Payout Account</h3>
            <button onclick="closePopup()" class="text-2xl text-gray-400 hover:text-white">&times;</button>
        </div>

        <div class="space-y-4 scroll-vertically">
            <div class="bg-gradient-to-r from-blue-600 to-purple-600 rounded-lg p-6 text-white">
                <h4 class="text-xl font-bold mb-2">🌍 Identity Verification</h4>
                <p class="text-sm opacity-90">To enable payouts, we partner with Stripe for secure identity verification. This is a one-time setup.</p>
            </div>

            <div class="bg-gray-800 rounded-lg p-6 border border-gray-700">
                <h5 class="font-semibold text-white mb-3">Select Your Country:</h5>
                <select id="userCountry" class="w-full px-4 py-3 bg-gray-700 border border-gray-600 text-white rounded-lg focus:outline-none focus:border-blue-500">
                    <option value="">-- Select Country --</option>
                    ${generateCountryOptions()}
                </select>
                <p class="text-xs text-gray-400 mt-2">Select the country of your legal residence.</p>
            </div>

            <div class="bg-gray-800 rounded-lg p-6 border border-gray-700">
                <h5 class="font-semibold text-white mb-3">What You'll Need:</h5>
                <ul class="space-y-2 text-sm text-gray-300">
                    <li>✓ Legal name and date of birth</li>
                    <li>✓ Home address</li>
                    <li>✓ Government ID (varies by country)</li>
                    <li>✓ Bank account or debit card for payouts</li>
                </ul>
            </div>

            <div id="onboardingError" class="text-red-400 text-sm text-center h-4"></div>

            <button onclick="startExpressOnboardingWithCountry(this)" 
                class="w-full py-4 bg-gradient-to-r from-green-600 to-blue-600 hover:from-green-700 hover:to-blue-700 text-white font-bold rounded-lg transition-all transform hover:scale-105 text-lg">
                🚀 Start Verification (5-10 minutes)
            </button>
        </div>
    `;

    popupContainer.classList.add('active');
    
    detectUserCountry();
}

/**
 * Detect user's country from browser/IP
 */
async function detectUserCountry() {
    try {
        const timezone = Intl.DateTimeFormat().resolvedOptions().timeZone;
        const timezoneMap = {
            'Asia/Colombo': 'LK', 'America/New_York': 'US', 'America/Los_Angeles': 'US',
            'Europe/London': 'GB', 'Asia/Singapore': 'SG', 'Asia/Tokyo': 'JP',
            'Australia/Sydney': 'AU', 'Asia/Kolkata': 'IN', 'Europe/Paris': 'FR', 'Europe/Berlin': 'DE',
        };
        const detectedCountry = timezoneMap[timezone];
        if (detectedCountry) {
            const countrySelect = document.getElementById('userCountry');
            if (countrySelect) countrySelect.value = detectedCountry;
        }
    } catch (e) {
        console.log('Could not auto-detect country:', e);
    }
}

/**
 * Generate country select options HTML
 */
function generateCountryOptions() {
    const STRIPE_SUPPORTED_COUNTRIES = [
        { code: 'US', name: 'United States', flag: '🇺🇸' }, { code: 'GB', name: 'United Kingdom', flag: '🇬🇧' },
        { code: 'CA', name: 'Canada', flag: '🇨🇦' }, { code: 'AU', name: 'Australia', flag: '🇦🇺' },
        { code: 'NZ', name: 'New Zealand', flag: '🇳🇿' }, { code: 'SG', name: 'Singapore', flag: '🇸🇬' },
        { code: 'HK', name: 'Hong Kong', flag: '🇭🇰' }, { code: 'JP', name: 'Japan', flag: '🇯🇵' },
        { code: 'AT', name: 'Austria', flag: '🇦🇹' }, { code: 'BE', name: 'Belgium', flag: '🇧🇪' },
        { code: 'BG', name: 'Bulgaria', flag: '🇧🇬' }, { code: 'HR', name: 'Croatia', flag: '🇭🇷' },
        { code: 'CY', name: 'Cyprus', flag: '🇨🇾' }, { code: 'CZ', name: 'Czech Republic', flag: '🇨🇿' },
        { code: 'DK', name: 'Denmark', flag: '🇩🇰' }, { code: 'EE', name: 'Estonia', flag: '🇪🇪' },
        { code: 'FI', name: 'Finland', flag: '🇫🇮' }, { code: 'FR', name: 'France', flag: '🇫🇷' },
        { code: 'DE', name: 'Germany', flag: '🇩🇪' }, { code: 'GR', name: 'Greece', flag: '🇬🇷' },
        { code: 'HU', name: 'Hungary', flag: '🇭🇺' }, { code: 'IE', name: 'Ireland', flag: '🇮🇪' },
        { code: 'IT', name: 'Italy', flag: '🇮🇹' }, { code: 'LV', name: 'Latvia', flag: '🇱🇻' },
        { code: 'LT', name: 'Lithuania', flag: '🇱🇹' }, { code: 'LU', name: 'Luxembourg', flag: '🇱🇺' },
        { code: 'MT', name: 'Malta', flag: '🇲🇹' }, { code: 'NL', name: 'Netherlands', flag: '🇳🇱' },
        { code: 'NO', name: 'Norway', flag: '🇳🇴' }, { code: 'PL', name: 'Poland', flag: '🇵🇱' },
        { code: 'PT', name: 'Portugal', flag: '🇵🇹' }, { code: 'RO', name: 'Romania', flag: '🇷🇴' },
        { code: 'SK', name: 'Slovakia', flag: '🇸🇰' }, { code: 'SI', name: 'Slovenia', flag: '🇸🇮' },
        { code: 'ES', name: 'Spain', flag: '🇪🇸' }, { code: 'SE', name: 'Sweden', flag: '🇸🇪' },
        { code: 'CH', name: 'Switzerland', flag: '🇨🇭' }, { code: 'BR', name: 'Brazil', flag: '🇧🇷' },
        { code: 'MX', name: 'Mexico', flag: '🇲🇽' }, { code: 'TH', name: 'Thailand', flag: '🇹🇭' },
        { code: 'IN', name: 'India', flag: '🇮🇳' }, { code: 'LK', name: 'Sri Lanka', flag: '🇱🇰' },
        { code: 'PH', name: 'Philippines', flag: '🇵🇭' }, { code: 'MY', name: 'Malaysia', flag: '🇲🇾' },
        { code: 'ID', name: 'Indonesia', flag: '🇮🇩' }, { code: 'VN', name: 'Vietnam', flag: '🇻🇳' },
        { code: 'AE', name: 'United Arab Emirates', flag: '🇦🇪' }, { code: 'ZA', name: 'South Africa', flag: '🇿🇦' },
    ];
    return STRIPE_SUPPORTED_COUNTRIES
        .sort((a, b) => a.name.localeCompare(b.name))
        .map(country => `<option value="${country.code}">${country.flag} ${country.name}</option>`)
        .join('');
}