/**
 * main-auth.js - Authentication & Basic User Management
 * Dependencies: main-core.js (requires global state)
 * Handles: Login, registration, password reset, 2FA, phone verification
 */

// ============================================================================
// AUTHENTICATION POPUPS
// ============================================================================

/**
 * Open login popup
 */
function openLoginPopup() {
    const popupContent = document.getElementById('popupContent');
    const popupContainer = document.getElementById('popupContainer');

    popupContent.classList.add('popup-dark-theme');
    popupContent.style.width = '400px';
    popupContent.style.maxWidth = '90vw';
    popupContent.style.padding = '20px';
    popupContainer.classList.remove('popup-large');
    
    popupContent.innerHTML = `
        <h3 class="text-xl font-semibold mb-4 text-center text-white">Login</h3> 
        <form id="loginForm" class="space-y-4">
            <div>
                <label for="loginEmail" class="block text-sm font-medium text-gray-300">Email</label> 
                <input type="email" id="loginEmail" placeholder="Enter Email" required class="mt-1 block w-full px-3 py-2 bg-gray-700 border border-gray-600 text-white rounded-md shadow-sm" style="font-size: 16px;"> 
            </div>
            <div>
                <label for="loginPassword" class="block text-sm font-medium text-gray-300">Password</label> 
                <input type="password" id="loginPassword" placeholder="Enter Password" required class="mt-1 block w-full px-3 py-2 bg-gray-700 border border-gray-600 text-white rounded-md shadow-sm" style="font-size: 16px;"> 
            </div>
            <div class="flex items-center justify-between">
                <div></div> 
                <div class="text-sm">
                    <a href="#" onclick="openForgotPasswordPopup()" class="font-medium text-blue-500 hover:text-blue-400"> 
                        Forgot your password?
                    </a>
                </div>
            </div>
            <div id="loginError" class="text-red-500 text-sm text-center"></div>
            <div>
                <button type="submit" class="w-full flex justify-center py-2 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700">Login</button>
            </div>
        </form>

        <div class="mt-6">
            <div class="relative">
                <div class="absolute inset-0 flex items-center" aria-hidden="true">
                    <div class="w-full border-t border-gray-600"></div> 
                </div>
                <div class="relative flex justify-center text-sm">
                    <span class="px-2 text-gray-400">Or continue with</span> 
                </div>
            </div>
            <div class="mt-6">
                <a href="https://www.sloption.com/api/v1/users/google_login.php" class="w-full flex items-center justify-center px-4 py-2 border border-gray-600 rounded-md shadow-sm text-sm font-medium text-gray-200 bg-gray-700 hover:bg-gray-600"> 
                    <svg class="w-5 h-5 mr-3" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48">
                        <path fill="#4285F4" d="M43.611,20.083H42V20H24v8h11.303c-1.649,4.657-6.08,8-11.303,8c-6.627,0-12-5.373-12-12c0-6.627,5.373-12,12-12c3.059,0,5.842,1.154,7.961,3.039l5.657-5.657C34.046,6.053,29.268,4,24,4C12.955,4,4,12.955,4,24c0,11.045,8.955,20,20,20c11.045,0,20-8.955,20-20C44,22.659,43.862,21.35,43.611,20.083z"/><path fill="#34A853" d="M6.306,14.691l6.571,4.819C14.655,15.108,18.961,12,24,12c3.059,0,5.842,1.154,7.961,3.039l5.657-5.657C34.046,6.053,29.268,4,24,4C16.318,4,9.656,8.337,6.306,14.691z"/><path fill="#FBBC05" d="M24,44c5.166,0,9.86-1.977,13.409-5.192l-6.19-5.238C29.211,35.091,26.715,36,24,36c-5.202,0-9.619-3.317-11.283-7.946l-6.522,5.025C9.505,39.556,16.227,44,24,44z"/><path fill="#EA4335" d="M43.611,20.083L43.595,20L42,20H24v8h11.303c-0.792,2.237-2.231,4.166-4.087,5.574l6.19,5.238C39.999,35.266,44,29.696,44,24C44,22.659,43.862,21.35,43.611,20.083z"/>
                    </svg>
                    <span>Sign in with Google</span>
                </a>
            </div>
        </div>
        <p class="mt-4 text-center text-sm text-gray-400"> 
            Don't have an account? 
            <a href="#" onclick="openRegisterPopup()" class="font-medium text-blue-500 hover:text-blue-400">Register here</a> 
        </p>
    `;

    popupContainer.classList.add('active');

    document.getElementById('loginForm').addEventListener('submit', async (event) => {
        event.preventDefault();
        const email = document.getElementById('loginEmail').value;
        const password = document.getElementById('loginPassword').value;
        const errorDiv = document.getElementById('loginError');
        const form = document.getElementById('loginForm');
        
        errorDiv.textContent = '';
        const result = await loginUser(email, password);

        if (result.message === "Login successful.") {
            showToast('Login successful!');
            closePopup();
            window.location.reload();
        } else if (result.message === "2FA required") {
            form.innerHTML = `
                <div>
                    <label for="2faCode" class="block text-sm font-medium text-gray-300">6-Digit Authentication Code</label> 
                    <input type="text" id="2faCode" required class="mt-1 block w-full text-center tracking-widest px-3 py-2 bg-gray-700 border border-gray-600 text-white rounded-md shadow-sm" style="font-size: 16px;" maxlength="6" autocomplete="one-time-code"> 
                </div>
                <div id="loginError" class="text-red-500 text-sm text-center h-4"></div>
                <div>
                    <button type="submit" class="w-full flex justify-center py-2 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700">Verify</button>
                </div>
            `;
            document.getElementById('2faCode').focus();
            
            form.onsubmit = async (e) => {
                e.preventDefault();
                const code = document.getElementById('2faCode').value;
                const verifyResult = await verify2FA(code);

                if (verifyResult.message === "Login successful.") {
                    showToast('Login successful!');
                    closePopup();
                    window.location.reload();
                } else {
                    document.getElementById('loginError').textContent = verifyResult.message || 'Verification failed.';
                }
            };
        } else {
            errorDiv.textContent = result.message || 'Login failed.';
        }
    });
}

/**
 * Open register popup
 */
function openRegisterPopup() {
    const popupContent = document.getElementById('popupContent');
    const popupContainer = document.getElementById('popupContainer');

    popupContent.classList.add('popup-dark-theme');
    popupContent.style.width = '400px';
    popupContent.style.maxWidth = '90vw';
    popupContent.style.padding = '20px';
    popupContainer.classList.remove('popup-large');

    popupContent.innerHTML = `
        <h3 class="text-xl font-semibold mb-4 text-center text-white">Register</h3> 
        
        <form id="registerForm" class="space-y-4">
            <div>
                <label for="registerEmail" class="block text-sm font-medium text-gray-300">Email</label> 
                <input type="email" id="registerEmail" placeholder="Enter Email" required class="mt-1 block w-full px-3 py-2 bg-gray-700 border border-gray-600 text-white rounded-md shadow-sm" style="font-size: 16px;"> 
            </div>
            <div>
                <label for="registerPassword" class="block text-sm font-medium text-gray-300">Password</label> 
                <input type="password" id="registerPassword" placeholder="Enter Password" required class="mt-1 block w-full px-3 py-2 bg-gray-700 border border-gray-600 text-white rounded-md shadow-sm" style="font-size: 16px;"> 
            </div>
            <div id="registerError" class="text-red-500 text-sm text-center"></div>
            <div>
                <button type="submit" class="w-full flex justify-center py-2 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700">Register</button>
            </div>
        </form>

        <div class="mt-6">
            <div class="relative">
                <div class="absolute inset-0 flex items-center" aria-hidden="true">
                    <div class="w-full border-t border-gray-600"></div> 
                </div>
                <div class="relative flex justify-center text-sm">
                    <span class="px-2 text-gray-400">Or continue with</span> 
                </div>
            </div>
            <div class="mt-6">
                <a href="https://www.sloption.com/api/v1/users/google_login.php" class="w-full flex items-center justify-center px-4 py-2 border border-gray-600 rounded-md shadow-sm text-sm font-medium text-gray-200 bg-gray-700 hover:bg-gray-600"> 
                    <svg class="w-5 h-5 mr-3" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48">
                        <path fill="#4285F4" d="M43.611,20.083H42V20H24v8h11.303c-1.649,4.657-6.08,8-11.303,8c-6.627,0-12-5.373-12-12c0-6.627,5.373-12,12-12c3.059,0,5.842,1.154,7.961,3.039l5.657-5.657C34.046,6.053,29.268,4,24,4C12.955,4,4,12.955,4,24c0,11.045,8.955,20,20,20c11.045,0,20-8.955,20-20C44,22.659,43.862,21.35,43.611,20.083z"/><path fill="#34A853" d="M6.306,14.691l6.571,4.819C14.655,15.108,18.961,12,24,12c3.059,0,5.842,1.154,7.961,3.039l5.657-5.657C34.046,6.053,29.268,4,24,4C16.318,4,9.656,8.337,6.306,14.691z"/><path fill="#FBBC05" d="M24,44c5.166,0,9.86-1.977,13.409-5.192l-6.19-5.238C29.211,35.091,26.715,36,24,36c-5.202,0-9.619-3.317-11.283-7.946l-6.522,5.025C9.505,39.556,16.227,44,24,44z"/><path fill="#EA4335" d="M43.611,20.083L43.595,20L42,20H24v8h11.303c-0.792,2.237-2.231,4.166-4.087,5.574l6.19,5.238C39.999,35.266,44,29.696,44,24C44,22.659,43.862,21.35,43.611,20.083z"/>
                    </svg>
                    <span>Sign up with Google</span>
                </a>
            </div>
        </div>

        <p class="mt-4 text-center text-sm text-gray-400"> 
            Already have an account? 
            <a href="#" onclick="openLoginPopup()" class="font-medium text-blue-500 hover:text-blue-400">Login here</a> 
        </p>
    `;
    
    popupContainer.classList.add('active');
    
    document.getElementById('registerForm').addEventListener('submit', async (event) => {
        event.preventDefault();
        const email = document.getElementById('registerEmail').value;
        const password = document.getElementById('registerPassword').value;
        const result = await registerUser(email, password);
        
        if (result.message === "User was registered successfully.") {
            showToast('Registration successful! Please log in.');
            openLoginPopup();
        } else {
            document.getElementById('registerError').textContent = result.message || 'Registration failed.';
        }
    });
}

/**
 * Open forgot password popup
 */
function openForgotPasswordPopup() {
    const popupContent = document.getElementById('popupContent');
    const popupContainer = document.getElementById('popupContainer');

    popupContent.classList.add('popup-dark-theme');
    popupContent.style.width = '400px';
    popupContent.style.maxWidth = '90vw';
    popupContent.style.padding = '20px';
    popupContainer.classList.remove('popup-large');
    
    popupContent.innerHTML = `
        <h3 class="text-xl font-semibold mb-2 text-center text-white">Reset Password</h3> 
        <p class="text-center text-gray-400 mb-4">Enter your email address and we will send you a link to reset your password.</p> 
        <form id="forgotPasswordForm" class="space-y-4">
            <div>
                <label for="resetEmail" class="block text-sm font-medium text-gray-300">Email</label> 
                <input type="email" id="resetEmail" required class="mt-1 block w-full px-3 py-2 bg-gray-700 border border-gray-600 text-white rounded-md shadow-sm" style="font-size: 16px;"> 
            </div>
            <div id="resetMessage" class="text-sm text-center h-4"></div>
            <div>
                <button type="submit" class="w-full flex justify-center py-2 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700">Send Reset Link</button>
            </div>
        </form>
        <p class="mt-4 text-center text-sm text-gray-400"> 
            Remember your password? 
            <a href="#" onclick="openLoginPopup()" class="font-medium text-blue-500 hover:text-blue-400">Login here</a> 
        </p>
    `;

    popupContainer.classList.add('active');

    document.getElementById('forgotPasswordForm').addEventListener('submit', async (e) => {
        e.preventDefault();
        const email = document.getElementById('resetEmail').value;
        const msgDiv = document.getElementById('resetMessage');
        const button = e.target.querySelector('button');

        button.disabled = true;
        button.textContent = 'Sending...';

        const result = await requestPasswordReset(email);
        
        msgDiv.textContent = result.message;
        msgDiv.className = 'text-sm text-center h-4 text-green-600';
        button.textContent = 'Link Sent!';
    });
}

// ============================================================================
// SECURITY SETTINGS
// ============================================================================

/**
 * Open change password popup
 */
function openChangePasswordPopup() {
    const popupContent = document.getElementById('popupContent');
    const popupContainer = document.getElementById('popupContainer');
    
    popupContent.classList.add('popup-dark-theme');
    popupContent.style.padding = '20px';
    popupContainer.classList.remove('popup-large');
    
    popupContent.innerHTML = `
        <h3 class="text-xl font-semibold mb-4 text-center text-white">Change Password</h3>
        <form id="changePasswordForm" class="space-y-4">
            <div>
                <label class="block text-sm font-medium text-gray-300 mb-2">Current Password</label>
                <input type="password" id="currentPassword" required 
                    class="w-full px-4 py-2 bg-gray-700 border border-gray-600 text-white rounded-lg">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-300 mb-2">New Password</label>
                <input type="password" id="newPassword" required minlength="8" 
                    class="w-full px-4 py-2 bg-gray-700 border border-gray-600 text-white rounded-lg">
            </div>
            <div id="passwordChangeMessage" class="text-sm text-center h-4"></div>
            <div class="flex gap-4">
                <button type="button" onclick="openProfilePopup()" class="flex-1 px-4 py-2 bg-gray-600 text-white rounded-lg">
                    Cancel
                </button>
                <button type="submit" class="flex-1 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                    Update Password
                </button>
            </div>
        </form>
    `;

    popupContainer.classList.add('active');

    document.getElementById('changePasswordForm').addEventListener('submit', async (e) => {
        e.preventDefault();
        const current = document.getElementById('currentPassword').value;
        const newPass = document.getElementById('newPassword').value;
        const msgDiv = document.getElementById('passwordChangeMessage');
        const button = e.target.querySelector('button[type="submit"]');

        button.disabled = true;
        button.textContent = 'Updating...';

        const result = await changePassword(current, newPass);

        if (result.message && result.message.includes('successfully')) {
            msgDiv.className = 'text-sm text-center h-4 text-green-400';
            msgDiv.textContent = '✓ Password changed successfully';
            showToast('Password updated!');
            setTimeout(() => openProfilePopup(), 2000);
        } else {
            msgDiv.className = 'text-sm text-center h-4 text-red-400';
            msgDiv.textContent = result.message || 'Update failed';
            button.disabled = false;
            button.textContent = 'Update Password';
        }
    });
}

// ============================================================================
// TWO-FACTOR AUTHENTICATION (2FA)
// ============================================================================

/**
 * Open 2FA setup popup
 */
function open2FASetupPopup() {
    const popupContent = document.getElementById('popupContent');
    const popupContainer = document.getElementById('popupContainer');
    
    popupContent.classList.add('popup-dark-theme');
    popupContent.style.padding = '20px';
    popupContainer.classList.remove('popup-large');
    
    popupContent.innerHTML = `
        <h3 class="text-xl font-semibold mb-4 text-center text-white">Enable 2FA</h3>
        <div id="2fa-setup-container" class="text-center space-y-4">
            <p class="text-gray-400">Scan the QR code with your authenticator app</p>
            <div class="flex gap-4">
                <button type="button" onclick="openProfilePopup()" class="flex-1 px-4 py-2 bg-gray-600 text-white rounded-lg">
                    Cancel
                </button>
                <button onclick="start2FA_setup(this)" class="w-full px-4 py-3 bg-green-600 text-white rounded-lg hover:bg-green-700">
                    Generate QR Code
                </button>
            </div>
        </div>
    `;

    popupContainer.classList.add('active');
}

/**
 * Start 2FA setup process
 */
async function start2FA_setup(button) {
    button.disabled = true;
    button.textContent = 'Generating...';

    const result = await apiFetch('/users/generate_2fa_secret.php');

    if (result.error) {
        showToast(result.message || 'Could not start 2FA setup.');
        button.disabled = false;
        button.textContent = 'Enable 2FA';
        return;
    }

    const { qr_code_url, secret_key } = result;
    const container = document.getElementById('2fa-setup-container');

    container.innerHTML = `
        <p class="font-medium text-gray-200">Configure Your Authenticator App</p>
        <p class="text-sm text-gray-400 mb-4">Scan the QR code below, then enter the 6-digit code from your app to verify.</p>
        <div class="my-4">
            <img src="${qr_code_url}" alt="2FA QR Code" class="mx-auto border rounded-lg p-2 bg-white">
        </div>
        <p class="text-xs text-gray-400 mb-4">Or enter this key manually:<br><strong class="font-mono text-gray-200">${secret_key}</strong></p>
        <div class="flex items-center gap-2 max-w-xs mx-auto">
            <input type="text" id="2fa-verification-code" placeholder="6-digit code" class="w-full text-center px-3 py-2 bg-gray-700 border border-gray-600 text-white rounded-md" maxlength="6">
        </div>
        <div class="flex gap-4">
            <button onclick="openProfilePopup()" class="flex-1 px-4 py-2 bg-gray-600 text-white rounded-lg">Cancel</button>
            <button onclick="verifyAndEnable2FA(this)" class="flex-1 px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700">Verify</button>
        </div>
        <div id="2fa-error" class="text-red-400 text-sm h-4 mt-2"></div>
    `;
}

/**
 * Verify and enable 2FA
 */
async function verifyAndEnable2FA(button) {
    const codeInput = document.getElementById('2fa-verification-code');
    const errorDiv = document.getElementById('2fa-error');
    const verificationCode = codeInput.value;

    if (!verificationCode || verificationCode.length !== 6) {
        errorDiv.textContent = 'Please enter a valid 6-digit code.';
        return;
    }

    button.disabled = true;
    button.textContent = 'Verifying...';
    errorDiv.textContent = '';

    const result = await apiFetch('/users/verify_and_enable_2fa.php', {
        method: 'POST',
        body: JSON.stringify({ verification_code: verificationCode })
    });

    if (result.message === "2FA has been enabled successfully!") {
        showToast('2FA Enabled Successfully!');
        closePopup();
        setTimeout(() => window.location.reload(), 1500);
    } else {
        errorDiv.textContent = result.message || 'An unknown error occurred.';
        button.disabled = false;
        button.textContent = 'Verify';
    }
}

/**
 * Open disable 2FA popup
 */
function openDisable2FAPopup() {
    const popupContent = document.getElementById('popupContent');
    const popupContainer = document.getElementById('popupContainer');
    
    popupContent.classList.add('popup-dark-theme');
    popupContent.style.padding = '20px';
    popupContainer.classList.remove('popup-large');
    
    popupContent.innerHTML = `
        <h3 class="text-xl font-semibold mb-2 text-center text-white">Disable 2FA</h3>
        <p class="text-center text-gray-400 mb-4">For your security, please enter your password to continue.</p>
        <form id="disable2faForm" class="space-y-4">
            <div>
                <label for="passwordConfirm" class="block text-sm font-medium text-gray-300">Password</label>
                <input type="password" id="passwordConfirm" required class="mt-1 block w-full px-3 py-2 bg-gray-700 border border-gray-600 text-white rounded-md">
            </div>
            <div id="disable2faError" class="text-red-400 text-sm text-center h-4"></div>
            <div class="flex justify-end gap-4 pt-2">
                <button type="button" onclick="openProfilePopup()" class="px-4 py-2 bg-gray-600 text-white rounded-md">Cancel</button>
                <button type="submit" class="px-4 py-2 bg-red-600 text-white rounded-md hover:bg-red-700">Confirm & Disable</button>
            </div>
        </form>
    `;

    popupContainer.classList.add('active');

    document.getElementById('disable2faForm').addEventListener('submit', async (e) => {
        e.preventDefault();
        const password = document.getElementById('passwordConfirm').value;
        const errorDiv = document.getElementById('disable2faError');
        const button = e.target.querySelector('button[type="submit"]');

        button.disabled = true;
        button.textContent = 'Disabling...';
        errorDiv.textContent = '';
        
        const result = await disable2FA(password);

        if (result.message === "2FA has been disabled successfully.") {
            showToast('2FA Disabled Successfully!');
            setTimeout(() => window.location.reload(), 1500);
        } else {
            errorDiv.textContent = result.message || 'An error occurred.';
            button.disabled = false;
            button.textContent = 'Confirm & Disable';
        }
    });
}

// ============================================================================
// PHONE VERIFICATION
// ============================================================================

/**
 * Open phone verification popup
 */
function openPhoneVerificationPopup() {
    const popupContent = document.getElementById('popupContent');
    const popupContainer = document.getElementById('popupContainer');
    
    popupContent.classList.add('popup-dark-theme');
    popupContent.style.padding = '20px';
    popupContainer.classList.remove('popup-large');
    
    popupContent.innerHTML = `
        <h3 class="text-xl font-semibold mb-4 text-center text-white">Verify Phone Number</h3>
        <form id="phone-verification-form" class="space-y-4">
            <div>
                <label class="block text-sm font-medium text-gray-300 mb-2">Phone Number</label>
                <input type="tel" id="phone-number-input" placeholder="+94771234567" required 
                    class="w-full px-4 py-2 bg-gray-700 border border-gray-600 text-white rounded-lg">
                <p class="text-xs text-gray-400 mt-1">Use international format (e.g., +94771234567)</p>
            </div>
            <div id="phone-message" class="text-sm text-center h-4"></div>
            <div class="flex gap-4">
                <button type="button" onclick="openProfilePopup()" class="flex-1 px-4 py-2 bg-gray-600 text-white rounded-lg">
                    Cancel
                </button>
                <button type="button" onclick="handleSendPhoneCode(this)" class="w-full px-4 py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                    Send Verification Code
                </button>
            </div>
        </form>
    `;

    popupContainer.classList.add('active');
}

/**
 * Send phone verification code
 */
async function handleSendPhoneCode(button) {
    const phoneInput = document.getElementById('phone-number-input');
    const msgDiv = document.getElementById('phone-message');
    const phoneNumber = phoneInput.value;

    const slRegex = /^\+94\d{9}$/;

    if (!slRegex.test(phoneNumber)) {
        msgDiv.className = 'text-sm text-center h-4 text-red-400';
        msgDiv.textContent = 'Please use international format (e.g., +94771234567).';
        return;
    }

    button.disabled = true;
    button.textContent = 'Sending...';
    msgDiv.textContent = '';

    const result = await requestPhoneVerification(phoneNumber);

    if (result.message === "A verification code has been sent.") {
        msgDiv.className = 'text-sm text-center h-4 text-green-400';
        msgDiv.textContent = result.message;
        document.getElementById('phone-verification-form').innerHTML = `
            <label for="phone-code-input" class="block text-sm font-medium text-gray-300">Verification Code</label>
            <div class="mt-1 flex gap-2">
                <input type="text" id="phone-code-input" placeholder="6-digit code" required class="block w-full px-3 py-2 bg-gray-700 border border-gray-600 text-white rounded-md" maxlength="6">
                <button type="button" onclick="handleVerifyPhoneCode(this)" class="px-4 py-2 text-sm font-medium text-white bg-green-600 rounded-md hover:bg-green-700">Verify</button>
            </div>
        `;
    } else {
        msgDiv.className = 'text-sm text-center h-4 text-red-400';
        msgDiv.textContent = result.message || "An error occurred.";
        button.disabled = false;
        button.textContent = 'Send Code';
    }
}

/**
 * Verify phone code
 */
async function handleVerifyPhoneCode(button) {
    const codeInput = document.getElementById('phone-code-input');
    const msgDiv = document.getElementById('phone-message');
    const code = codeInput.value;

    button.disabled = true;
    button.textContent = 'Verifying...';

    const result = await verifyPhoneCode(code);

    if (result.message === "Phone number verified successfully.") {
        showToast('Phone number verified!');
        setTimeout(() => window.location.reload(), 1500);
    } else {
        msgDiv.className = 'text-sm text-center h-4 text-red-400';
        msgDiv.textContent = result.message || "Verification failed.";
        button.disabled = false;
        button.textContent = 'Verify';
    }
}