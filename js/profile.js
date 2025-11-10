/**
 * profile.js - Profile Management
 * Dependencies: main-core.js, main-auth.js
 * Handles: Profile popup, personal info updates, payout cards display
 */

// ============================================================================
// PROFILE POPUP - MAIN CONTAINER
// ============================================================================

/**
 * Open comprehensive profile management popup
 */
async function openProfilePopup() {
    if (!profileData || !profileData.email) {
        showToast("Please log in to view your profile.");
        openLoginPopup();
        return;
    }

    const popupContent = document.getElementById('popupContent');
    const popupContainer = document.getElementById('popupContainer');
    
    popupContent.classList.add('popup-dark-theme');
    popupContent.style.padding = '20px';
    popupContainer.classList.remove('popup-large');

    // Show loading state
    popupContent.innerHTML = `
        <h3 class="text-xl font-semibold mb-4 text-center text-white">Profile</h3>
        <div class="text-center p-8 text-gray-300">Loading profile...</div>
    `;
    popupContainer.classList.add('active');

    // Fetch latest profile data
    const freshProfile = await getUserProfile();
    if (freshProfile && freshProfile.email) {
        profileData = freshProfile;
    }

    // Check payout status for the payouts tab
    const payoutStatus = await checkPayoutStatus();
    const hasCards = payoutStatus?.payout_enabled || false;

    // Build tab contents
    const profileInfoHtml = buildProfileInfoTab();
    const payoutsHtml = await buildPayoutsTab(hasCards, payoutStatus);
    const securityHtml = buildSecurityTab();

    // Render complete popup
    popupContent.innerHTML = `
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-xl font-bold text-white">Profile Settings</h3>
            <button onclick="closePopup()" class="text-2xl text-gray-400 hover:text-white">&times;</button>
        </div>

        <div class="p-1.5 flex space-x-2 rounded-lg bg-gray-700 mb-4">
            <button onclick="showPopupTab('profile-info', this)" class="popup-tab active flex-1 py-2 text-sm font-semibold rounded-md">
                Profile
            </button>
            <button onclick="showPopupTab('payouts', this)" class="popup-tab flex-1 py-2 text-sm font-semibold rounded-md">
                Payouts
            </button>
            <button onclick="showPopupTab('security', this)" class="popup-tab flex-1 py-2 text-sm font-semibold rounded-md">
                Security
            </button>
        </div>

        <div class="h-96 overflow-y-auto">
            <div id="profile-info-content" class="popup-content">${profileInfoHtml}</div>
            <div id="payouts-content" class="popup-content hidden">${payoutsHtml}</div>
            <div id="security-content" class="popup-content hidden">${securityHtml}</div>
        </div>
    `;

    // Initialize form handler
    document.getElementById('profileUpdateForm').addEventListener('submit', handleProfileUpdate);
}

// ============================================================================
// TAB BUILDERS
// ============================================================================

/**
 * Build profile information tab
 */
function buildProfileInfoTab() {
    return `
        <div class="space-y-4 p-1">
            <div class="bg-gray-800 rounded-lg p-4 border border-gray-700">
                <h4 class="font-semibold text-gray-200 mb-3">Account Information</h4>
                <div class="space-y-2 text-sm">
                    <div class="flex justify-between">
                        <span class="text-gray-400">Email:</span>
                        <span class="text-white">${escapeHTML(profileData.email)}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-400">Account Type:</span>
                        <span class="text-white capitalize">${escapeHTML(profileData.provider || 'Email')}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-400">Member Since:</span>
                        <span class="text-white">${new Date(profileData.created_at).toLocaleDateString()}</span>
                    </div>
                </div>
            </div>

            <form id="profileUpdateForm" class="space-y-4">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-300 mb-2">First Name</label>
                        <input type="text" id="firstName" value="${escapeHTML(profileData.first_name || '')}" 
                            class="w-full px-4 py-2 bg-gray-700 border border-gray-600 text-white rounded-lg focus:outline-none focus:border-blue-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-300 mb-2">Last Name</label>
                        <input type="text" id="lastName" value="${escapeHTML(profileData.last_name || '')}" 
                            class="w-full px-4 py-2 bg-gray-700 border border-gray-600 text-white rounded-lg focus:outline-none focus:border-blue-500">
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-300 mb-2">Birthday</label>
                    <input type="date" id="birthday" value="${profileData.birthday || ''}" 
                        class="w-full px-4 py-2 bg-gray-700 border border-gray-600 text-white rounded-lg focus:outline-none focus:border-blue-500">
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-300 mb-2">Address</label>
                    <input type="text" id="address" value="${escapeHTML(profileData.address || '')}" 
                        class="w-full px-4 py-2 bg-gray-700 border border-gray-600 text-white rounded-lg focus:outline-none focus:border-blue-500">
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-300 mb-2">City</label>
                        <input type="text" id="city" value="${escapeHTML(profileData.city || '')}" 
                            class="w-full px-4 py-2 bg-gray-700 border border-gray-600 text-white rounded-lg focus:outline-none focus:border-blue-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-300 mb-2">Postal Code</label>
                        <input type="text" id="postalCode" value="${escapeHTML(profileData.postal_code || '')}" 
                            class="w-full px-4 py-2 bg-gray-700 border border-gray-600 text-white rounded-lg focus:outline-none focus:border-blue-500">
                    </div>
                </div>

                <div id="profileUpdateMessage" class="text-sm text-center h-4"></div>

                <button type="submit" class="w-full py-3 bg-blue-600 text-white font-semibold rounded-lg hover:bg-blue-700 transition-colors">
                    Save Changes
                </button>
            </form>
        </div>
    `;
}

/**
 * Build payouts tab (payout cards management)
 */
async function buildPayoutsTab(hasCards, payoutStatus) {
    if (hasCards && payoutStatus.default_card) {
        const cards = await getPayoutCards();
        return `
            <div class="space-y-4 p-1">
                <div class="bg-green-900/20 border border-green-500/30 rounded-lg p-4">
                    <p class="text-green-400 font-semibold">✓ Payout cards configured</p>
                    <p class="text-sm text-gray-400 mt-1">You can receive instant withdrawals</p>
                </div>

                <div class="space-y-3">
                    ${cards.cards?.map(card => `
                        <div class="bg-gray-800 rounded-lg p-4 border ${card.is_default ? 'border-blue-500' : 'border-gray-700'}">
                            <div class="flex items-center justify-between">
                                <div class="flex items-center space-x-3">
                                    <div class="w-10 h-10 bg-gradient-to-br from-blue-500 to-purple-600 rounded-lg flex items-center justify-center">
                                        <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"></path>
                                        </svg>
                                    </div>
                                    <div>
                                        <p class="font-medium text-white">${card.brand.toUpperCase()} ****${card.last4}</p>
                                        <p class="text-xs text-gray-400">${card.holder_name}</p>
                                    </div>
                                </div>
                                <div class="flex items-center space-x-2">
                                    ${card.is_default ? 
                                        '<span class="text-xs bg-blue-600 text-white px-2 py-1 rounded">Default</span>' : 
                                        `<button onclick="setDefaultCardHandler(${card.id})" class="text-xs text-blue-400 hover:text-blue-300">Set Default</button>`
                                    }
                                    <button onclick="confirmRemoveCard(${card.id})" class="text-xs text-red-400 hover:text-red-300">Remove</button>
                                </div>
                            </div>
                        </div>
                    `).join('') || '<p class="text-gray-400">No cards found</p>'}
                </div>

                <button onclick="closePopup(); setTimeout(() => openAddCardPopupWithAutoFix(), 400);" 
                    class="w-full py-3 bg-blue-600 text-white font-semibold rounded-lg hover:bg-blue-700 transition-colors">
                    + Add Another Card
                </button>
            </div>
        `;
    } else {
        return `
            <div class="space-y-4 p-1">
                <div class="bg-yellow-900/20 border border-yellow-500/30 rounded-lg p-4">
                    <p class="text-yellow-400 font-semibold">⚠ No payout cards configured</p>
                    <p class="text-sm text-gray-400 mt-1">Add a card to enable instant withdrawals</p>
                </div>

                <button onclick="closePopup(); setTimeout(() => openAddCardPopupWithAutoFix(), 400);" 
                    class="w-full py-3 bg-blue-600 text-white font-semibold rounded-lg hover:bg-blue-700 transition-colors">
                    + Add Payout Card
                </button>
            </div>
        `;
    }
}

/**
 * Build security tab
 */
function buildSecurityTab() {
    return `
        <div class="space-y-4 p-1">
            <div class="bg-gray-800 rounded-lg p-4 border border-gray-700">
                <div class="flex justify-between items-center">
                    <div>
                        <h4 class="font-semibold text-white">Password</h4>
                        <p class="text-sm text-gray-400">Change your password</p>
                    </div>
                    <button onclick="openChangePasswordPopup()" class="px-4 py-2 bg-gray-700 text-white rounded-lg hover:bg-gray-600">
                        Change
                    </button>
                </div>
            </div>

            <div class="bg-gray-800 rounded-lg p-4 border border-gray-700">
                <div class="flex justify-between items-center">
                    <div>
                        <h4 class="font-semibold text-white">Two-Factor Authentication</h4>
                        <p class="text-sm text-gray-400">
                            ${profileData.google2fa_enabled ? 'Enabled ✓' : 'Add extra security'}
                        </p>
                    </div>
                    ${profileData.google2fa_enabled ? 
                        `<button onclick="openDisable2FAPopup()" class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700">Disable</button>` :
                        `<button onclick="open2FASetupPopup()" class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700">Enable</button>`
                    }
                </div>
            </div>

            <div class="bg-gray-800 rounded-lg p-4 border border-gray-700">
                <div class="flex justify-between items-center">
                    <div>
                        <h4 class="font-semibold text-white">Phone Verification</h4>
                        <p class="text-sm text-gray-400">
                            ${profileData.phone_verified ? `Verified: ${profileData.phone_number}` : 'Not verified'}
                        </p>
                    </div>
                    ${!profileData.phone_verified ? 
                        `<button onclick="openPhoneVerificationPopup()" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">Verify</button>` :
                        `<span class="text-green-400">✓ Verified</span>`
                    }
                </div>
            </div>
        </div>
    `;
}

// ============================================================================
// FORM HANDLERS
// ============================================================================

/**
 * Handle profile update form submission
 */
async function handleProfileUpdate(e) {
    e.preventDefault();
    
    const msgDiv = document.getElementById('profileUpdateMessage');
    const button = e.target.querySelector('button[type="submit"]');
    
    button.disabled = true;
    button.textContent = 'Saving...';
    
    const data = {
        first_name: document.getElementById('firstName').value,
        last_name: document.getElementById('lastName').value,
        birthday: document.getElementById('birthday').value,
        address: document.getElementById('address').value,
        city: document.getElementById('city').value,
        postal_code: document.getElementById('postalCode').value
    };
    
    const result = await updateUserProfile(data);
    
    if (result.message && result.message.includes('successfully')) {
        msgDiv.className = 'text-sm text-center h-4 text-green-400';
        msgDiv.textContent = '✓ Profile updated successfully';
        showToast('Profile updated!');
    } else {
        msgDiv.className = 'text-sm text-center h-4 text-red-400';
        msgDiv.textContent = result.message || 'Update failed';
    }
    
    button.disabled = false;
    button.textContent = 'Save Changes';
}