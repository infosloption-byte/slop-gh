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
    return apiFetch('/payments/create_deposit.php', {
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
    return apiFetch('/payments/create_withdrawal.php', {
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

async function checkPayoutStatus() {
    return apiFetch('/payments/payout_status.php');
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
 * API functions for card management
 */
async function getPayoutCards() {
    return apiFetch('/payments/list_payout_cards.php');
}

async function addPayoutCard(cardToken, cardHolderName) {
    return apiFetch('/payments/add_payout_card.php', {
        method: 'POST',
        body: JSON.stringify({
            card_token: cardToken,
            card_holder_name: cardHolderName
        })
    });
}

async function setDefaultCard(cardId) {
    return apiFetch('/payments/set_default_card.php', {
        method: 'POST',
        body: JSON.stringify({ card_id: cardId })
    });
}

async function removePayoutCard(cardId) {
    return apiFetch('/payments/remove_payout_card.php', {
        method: 'POST',
        body: JSON.stringify({ card_id: cardId })
    });
}

/**
 * Create withdrawal request (manual or automated)
 */
async function createWithdrawalRequest(withdrawalData) {
    return apiFetch('/payments/create_withdrawal.php', {
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