const API_BASE_URL = '/api/v1'; // Your live server IP/domain

async function getPublicKeys() {
    // This is a public request, so we use fetch directly, not apiFetch
    const response = await fetch('/api/v1/config/get_keys.php');
    return response.json();
}

/**
 * Fetches the list of active trading assets from the server.
 * This is a public endpoint, so we don't need apiFetch.
 */
async function getActiveAssets() {
    // We use the new API_BASE_URL to call the versioned endpoint
    const response = await fetch(`${API_BASE_URL}/assets/get_active_assets.php`);
    if (!response.ok) {
        throw new Error("Network response was not ok");
    }
    return response.json();
}

/**
 * A centralized fetch handler that automatically adds credentials (cookies)
 * and handles unauthorized (401) responses.
 * @param {string} endpoint The API endpoint to call.
 * @param {object} options The options for the fetch call.
 * @param {boolean} handleAuthError If true, will trigger a page reload on 401 errors.
 * @returns {Promise<object>} The JSON response from the server.
 */
async function apiFetch(endpoint, options = {}, handleAuthError = true) {
    try {
        const response = await fetch(`${API_BASE_URL}${endpoint}`, {
            ...options,
            credentials: 'include', 
            headers: {
                'Content-Type': 'application/json',
                ...options.headers,
            },
        });

        // UPDATED LOGIC: Only handle auth errors if instructed to.
        if (response.status === 401 && endpoint !== '/users/login.php' && handleAuthError) {
            handleUnauthorized(); 
            throw new Error("Unauthorized");
        }

        if (!response.ok) {
            // For login failures, we don't want to throw, just return the JSON error
            if (response.status === 401 && endpoint === '/users/login.php') {
                return response.json();
            }
            throw new Error(`Server error: ${response.statusText}`);
        }
        
        const text = await response.text();
        return text ? JSON.parse(text) : {};

    } catch (error) {
        console.error("API Fetch Error:", error);
        return { error: error.message };
    }
}

// Add this NEW function. Do NOT change your original apiFetch.
async function apiFetchWithDetails(endpoint, options = {}) {
    try {
        const response = await fetch(`${API_BASE_URL}${endpoint}`, {
            ...options,
            credentials: 'include', 
            headers: {
                'Content-Type': 'application/json',
                ...options.headers,
            },
        });

        // This new version will ALWAYS try to return the JSON, even on an error.
        const data = await response.json();
        return data;

    } catch (error) {
        console.error("API Fetch Error:", error);
        return { message: "A network error occurred. Please try again." };
    }
}

async function registerUser(email, password) {
    return apiFetch('/users/register.php', {
        method: 'POST',
        body: JSON.stringify({ email, password })
    });
}

async function loginUser(email, password) {
    return apiFetch('/users/login.php', {
        method: 'POST',
        body: JSON.stringify({ email, password })
    });
}

async function logoutUser() {
    return apiFetch('/users/logout.php', {
        method: 'POST'
    });
}

async function getWalletBalance() {
    return apiFetch('/wallet/get_balance.php');
}

async function getTradeHistory() {
    return apiFetch('/trades/get_history.php');
}

async function getTransactionHistory() {
    return apiFetch('/transactions/history.php');
}

async function placeTrade(tradeData) {
    return apiFetch('/trades/place_trade.php', {
        method: 'POST',
        body: JSON.stringify(tradeData)
    });
}

async function rechargeDemoWallet() {
    return apiFetch('/wallet/recharge_demo.php', { method: 'POST' });
}

async function processDeposit(amount, token) {
    return apiFetch('/payments/deposits/create_deposit.php', {
        method: 'POST',
        body: JSON.stringify({ amount, token })
    });
}

/**
 * Creates a real withdrawal request for the logged-in user.
 * @param {number} amount The amount to withdraw.
 * @returns {Promise<object>} The JSON response from the server.
 */
async function createWithdrawal(amount) {
    return apiFetch('/payments/withdrawals/create_withdrawal.php', {
        method: 'POST',
        body: JSON.stringify({ amount })
    });
}

/**
 * Fetches the profile data for the logged-in user.
 * @param {boolean} handleAuthError - Whether to trigger a reload on failure.
 * @returns {Promise<object>} The user's profile data.
 */
async function getUserProfile(handleAuthError = true) {
    // Pass the handleAuthError flag to the fetch handler
    return apiFetch('/user/profile.php', {}, handleAuthError);
}

/**
 * Updates the profile data for the logged-in user.
 * @param {object} profileData The data to update.
 * @returns {Promise<object>} The JSON response from the server.
 */
async function updateUserProfile(profileData) {
    return apiFetch('/user/profile.php', {
        method: 'POST',
        body: JSON.stringify(profileData)
    });
}

/**
 * Creates a Stripe Connected Account for the logged-in user.
 * @returns {Promise<object>} The JSON response from the server.
 */
async function createConnectAccount() {
    return apiFetch('/payments/create_connect_account.php', {
        method: 'POST'
    });
}

/**
 * Check payout status with better error handling
 */
async function checkPayoutStatus() {
    try {
        const response = await fetch(`${API_BASE_URL}/payments/status/payout_status.php`, {
            method: 'GET',
            credentials: 'include'
        });

        if (!response.ok) {
            console.warn('Payout status check failed:', response.status);
            return {
                payout_enabled: false,
                requirements: {
                    has_connect_account: false,
                    connect_verified: false,
                    has_payout_card: false
                }
            };
        }

        const data = await response.json();
        console.log('Payout status:', data);
        return data;
        
    } catch (error) {
        console.error('Check payout status error:', error);
        return {
            payout_enabled: false,
            requirements: {
                has_connect_account: false,
                connect_verified: false,
                has_payout_card: false
            }
        };
    }
}

/**
 * Creates a one-time onboarding link for the user to set up their Stripe account.
 * @returns {Promise<object>} The JSON response containing the onboarding URL.
 */
async function createOnboardingLink() {
    return apiFetch('/payments/create_onboarding_link.php', {
        method: 'POST'
    });
}

// In api.js
async function verify2FA(code) {
    return apiFetch('/users/verify_2fa.php', {
        method: 'POST',
        body: JSON.stringify({ verification_code: code })
    });
}

// Add this to api.js
async function disable2FA(password) {
    return apiFetchWithDetails('/users/disable_2fa.php', { // Now uses the NEW function
        method: 'POST',
        body: JSON.stringify({ password })
    });
}

async function requestPasswordReset(email) {
    return apiFetch('/users/request_reset.php', {
        method: 'POST',
        body: JSON.stringify({ email })
    });
}

// Add this function to /js/api.js
async function changePassword(current_password, new_password) {
    return apiFetch('/users/change_password.php', {
        method: 'POST',
        body: JSON.stringify({ current_password, new_password })
    });
}

async function requestPhoneVerification(phone_number) {
    return apiFetch('/users/request_phone_verification.php', {
        method: 'POST',
        body: JSON.stringify({ phone_number })
    });
}

async function verifyPhoneCode(code) {
    return apiFetch('/users/verify_phone_code.php', {
        method: 'POST',
        body: JSON.stringify({ code })
    });
}

/**
 * get all notifications as read for the user.
 */
async function getNotifications() {
    return apiFetch('/notifications/get_all.php');
}

/**
 * Sends a request to the backend to mark all notifications as read for the user.
 */
async function markNotificationsAsRead() {
    // Note: The endpoint path assumes you create a file at /api/v1/notifications/mark_read.php
    return apiFetch('/notifications/mark_read.php', {
        method: 'POST'
        // No body needed unless you want to send specific IDs
    });
}

/**
 * Get all payout cards for the current user
 * @returns {Promise<Object>} List of payout cards
 */
async function getPayoutCards() {
    try {
        const response = await fetch('/api/v1/payments/get_payout_cards.php', {
            method: 'GET',
            credentials: 'include'
        });
        
        const data = await response.json();
        
        if (!response.ok) {
            throw new Error(data.message || 'Failed to fetch payout cards');
        }
        
        return data;
        
    } catch (error) {
        console.error('getPayoutCards error:', error);
        throw error;
    }
}

/**
 * Add a payout card to user's account
 * @param {string} cardToken - Stripe card token (tok_xxx)
 * @param {string} cardHolderName - Name on the card
 * @returns {Promise<Object>} API response
 */
async function addPayoutCard(cardToken, cardHolderName) {
    try {
        console.log('addPayoutCard called with:', { 
            cardToken: cardToken ? 'present' : 'missing', 
            cardHolderName 
        });
        
        if (!cardToken) {
            throw new Error('Card token is required');
        }
        
        if (!cardHolderName || cardHolderName.trim() === '') {
            throw new Error('Cardholder name is required');
        }
        
        const response = await fetch('/api/v1/payments/stripe/cards/add_card.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            credentials: 'include',
            body: JSON.stringify({
                card_token: cardToken,
                card_holder_name: cardHolderName.trim()
            })
        });
        
        console.log('addPayoutCard response status:', response.status);
        
        // Check if response is JSON
        const contentType = response.headers.get('content-type');
        if (!contentType || !contentType.includes('application/json')) {
            const text = await response.text();
            console.error('Non-JSON response:', text);
            throw new Error('Server returned an invalid response');
        }
        
        const data = await response.json();
        console.log('addPayoutCard response data:', data);
        
        if (!response.ok) {
            throw new Error(data.message || 'Failed to add payout card');
        }
        
        return data;
        
    } catch (error) {
        console.error('addPayoutCard error:', error);
        throw error;
    }
}

/**
 * Set default payout card
 */
async function setDefaultCard(cardId) {
    try {
        const response = await fetch(`${API_BASE_URL}/payments/stripe/cards/set_default_card.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'include',
            body: JSON.stringify({ card_id: cardId })
        });

        if (!response.ok) {
            const errorData = await response.json();
            throw new Error(errorData.message || 'Failed to set default card');
        }

        return await response.json();
        
    } catch (error) {
        console.error('Set default card error:', error);
        throw error;
    }
}

/**
 * Remove a payout card
 */
async function removePayoutCard(cardId) {
    try {
        const response = await fetch(`${API_BASE_URL}/payments/stripe/cards/remove_card.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'include',
            body: JSON.stringify({ card_id: cardId })
        });

        if (!response.ok) {
            const errorData = await response.json();
            throw new Error(errorData.message || 'Failed to remove card');
        }

        return await response.json();
        
    } catch (error) {
        console.error('Remove payout card error:', error);
        throw error;
    }
}


/**
 * Create withdrawal request (manual or automated)
 */
async function createWithdrawalRequest(withdrawalData) {
    return apiFetch('/payments/withdrawals/create_withdrawal.php', {
        method: 'POST',
        body: JSON.stringify(withdrawalData)
    });
}

/**
 * Get pending withdrawal requests for current user
 */
async function getUserWithdrawals() {
    return apiFetch('/payments/user_withdrawals.php');
}

/**
 * Cancel a pending withdrawal request
 */
async function cancelWithdrawal(requestId) {
    return apiFetch('/payments/cancel_withdrawal.php', {
        method: 'POST',
        body: JSON.stringify({ request_id: requestId })
    });
}

/**
 * Check if user can withdraw (has pending requests, etc.)
 */
async function checkWithdrawalEligibility() {
    return apiFetch('/payments/withdrawal_eligibility.php');
}

/**
 * Admin: Process withdrawal (approve/reject)
 */
async function processWithdrawal(requestId, status, notes, proofImage = null) {
    return apiFetch('/admin/process_withdrawal.php', {
        method: 'POST',
        body: JSON.stringify({
            request_id: requestId,
            new_status: status,
            admin_notes: notes,
            proof_image_url: proofImage
        })
    });
}

/**
 * Admin: Get all withdrawal requests
 */
async function getWithdrawalRequests(filters = {}) {
    const params = new URLSearchParams(filters);
    return apiFetch(`/admin/withdrawals.php?${params}`);
}

/**
 * Sync Connect account status after onboarding
 */
async function syncConnectAccountStatus() {
    return apiFetch('/payments/stripe/connect/sync_status.php', {
        method: 'POST'
    });
}

/**
 * API function for checking capabilities
 */
async function checkMyCapabilities() {
    try {
        const response = await fetch('/api/v1/payments/stripe/capabilities/check_and_fix.php', {
            method: 'POST',
            credentials: 'include',
            headers: {
                'Content-Type': 'application/json'
            }
        });

        const data = await response.json();
        
        if (!response.ok) {
            throw new Error(data.message || 'Failed to check capabilities');
        }

        return data;
        
    } catch (error) {
        console.error('Check capabilities error:', error);
        throw error;
    }
}

async function createExpressAccount() {
    return apiFetch('/payments/stripe/connect/create_express_account.php', {
        method: 'POST'
    });
}

async function getOnboardingLink() {
    return apiFetch('/payments/stripe/connect/get_onboarding_link.php');
}

if (typeof module !== 'undefined' && module.exports) {
    module.exports = {
        addPayoutCard,
        checkPayoutStatus,
        getPayoutCards,
        removePayoutCard,
        setDefaultCard
    };
}

////////////////////////////////////////////////////////////////////////////////
/////////////////////////// New Fucntion ///////////////////////////////////////
////////////////////////////////////////////////////////////////////////////////

/**
 * Get all connected payout methods for the user
 * @returns {Promise<Array>} List of payout methods
 */
async function getPayoutMethods() {
    return apiFetch('/payments/payout_methods/list_methods.php');
}

/**
 * Adds a simple payout method (like Skrill or Binance)
 * @param {string} methodType - 'binance' or 'skrill'
 * @param {string} identifier - The user's email or ID for that service
 * @returns {Promise<Object>} API response
 */
async function addSimpleMethod(methodType, identifier) {
    return apiFetch('/payments/payout_methods/add_simple_method.php', {
        method: 'POST',
        body: JSON.stringify({ method_type: methodType, identifier: identifier })
    });
}