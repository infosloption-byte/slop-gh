/**
 * core.js - Core Application Logic & Initialization
 * Dependencies: None (loads first)
 * Exports: Global state variables, initialization, WebSocket, basic UI utilities
 */

// ============================================================================
// GLOBAL STATE
// ============================================================================

let userWallets = { real: 0.00, demo: 10000.00 };
let tradeHistory = [];
let selectedWallet = 'demo';
let currentPrices = {};
let profileData = {};
let activeAssets = [];
let selectedPair = null;
let filteredAssets = [];
let currentSearchTerm = '';
let ws;

// Chart-related globals (shared with chart.js)
window.dealDurationInSeconds = 5;
window.currentTimeframe = '5s';
window.selectedPair = null;

// ============================================================================
// DOM REFERENCES
// ============================================================================

const dealList = document.getElementById('dealList');
const toast = document.getElementById('toast');
const btnPredictHigh = document.getElementById('btnPredictHigh');
const btnPredictLow = document.getElementById('btnPredictLow');
const leftPanel = document.getElementById('leftPanel');
const rightPanel = document.getElementById('rightPanel');
const pageOverlay = document.getElementById('pageOverlay');

// ============================================================================
// UTILITY FUNCTIONS
// ============================================================================

/**
 * Escape HTML to prevent XSS attacks
 */
function escapeHTML(str) {
    if (str === null || str === undefined) return '';
    const p = document.createElement('p');
    p.textContent = str;
    return p.innerHTML;
}

/**
 * Show toast notification
 */
function showToast(msg) { 
    toast.textContent = msg; 
    toast.classList.remove('hidden'); 
    clearTimeout(toast._t); 
    toast._t = setTimeout(() => toast.classList.add('hidden'), 2500); 
}

/**
 * Check if mobile viewport
 */
function isMobile() { 
    return window.innerWidth < 768; 
}

/**
 * Format time in HH:MM:SS
 */
function formatTime(totalSeconds) {
    const hours = Math.floor(totalSeconds / 3600).toString().padStart(2, '0');
    const minutes = Math.floor((totalSeconds % 3600) / 60).toString().padStart(2, '0');
    const seconds = (totalSeconds % 60).toString().padStart(2, '0');
    return `${hours}:${minutes}:${seconds}`;
}

/**
 * Handle unauthorized access
 */
function handleUnauthorized() {
    showToast("Your session has expired. Please log in again.");
    setTimeout(() => window.location.reload(), 2500);
}

// ============================================================================
// UI INTERACTION FUNCTIONS
// ============================================================================

/**
 * Close side panels on mobile
 */
function closeSidePanels() {
    if (isMobile()) {
        leftPanel.classList.add('hidden');
        rightPanel.classList.add('hidden');
        pageOverlay.classList.add('hidden');
    }
}

/**
 * Toggle left sidebar
 */
function toggleLeftPanel() {
    if (isMobile()) {
        const isHidden = leftPanel.classList.contains('hidden');
        closeSidePanels(); 
        if (isHidden) {
            leftPanel.classList.remove('hidden');
            pageOverlay.classList.remove('hidden');
        }
    } else {
        leftPanel.classList.toggle('sidebar-minimized');
    }
}

/**
 * Toggle right sidebar
 */
function toggleRightPanel() {
    if (isMobile()) {
        const isHidden = rightPanel.classList.contains('hidden');
        closeSidePanels();
        if (isHidden) {
            rightPanel.classList.remove('hidden');
            pageOverlay.classList.remove('hidden');
        }
    } else {
        document.body.classList.toggle('right-panel-minimized');
    }
}

/**
 * Toggle accordion sections
 */
function toggleAccordion(buttonElement, contentId, iconId) {
    if (!isMobile() && document.body.classList.contains('right-panel-minimized')) {
        toggleRightPanel();
    }

    const c = document.getElementById(contentId);
    const i = document.getElementById(iconId);
    buttonElement.classList.toggle('active');
    c.classList.toggle('open');
    i.classList.toggle('rotate');
    
    if(c.classList.contains('open') && contentId === 'analyticsWrapper') {
        updateAnalytics();
    }
}

/**
 * Set active navigation link
 */
function setActive(element) {
    document.querySelectorAll('.nav-link').forEach(link => link.classList.remove('active'));
    element.classList.add('active');
}

/**
 * Update wallet UI
 */
function updateWalletUI() {
    document.getElementById('demoBalance').textContent = `$${(userWallets.demo || 0).toFixed(2)}`;
    document.getElementById('realBalance').textContent = `$${(userWallets.real || 0).toFixed(2)}`;
    
    const wallets = { 
        demo: document.getElementById('wallet-demo'), 
        real: document.getElementById('wallet-real') 
    };
    const activeClasses = ['bg-green-600', 'text-white'];
    const inactiveClasses = ['bg-gray-700', 'text-gray-300'];
    
    Object.values(wallets).forEach(el => {
        el.classList.remove(...activeClasses, ...inactiveClasses);
    });
    
    wallets[selectedWallet].classList.add(...activeClasses);
    const inactiveWallet = selectedWallet === 'demo' ? 'real' : 'demo';
    wallets[inactiveWallet].classList.add(...inactiveClasses);
}

/**
 * Select wallet (demo/real)
 */
function selectWallet(w) { 
    selectedWallet = w; 
    updateWalletUI(); 
    renderDeals();
    updateAnalytics();
    showToast(`Switched to ${w === 'demo' ? 'Demo' : 'Real'} Wallet`); 
}

// ============================================================================
// WEBSOCKET MANAGEMENT
// ============================================================================

/**
 * Setup Binance WebSocket for live price data
 */
function setupBinanceWebSocket(pair) {
    if (ws && ws.readyState === WebSocket.OPEN) {
        ws.onclose = null;
        ws.close();
    }

    console.log(`Connecting to Binance WebSocket for ${pair}...`);
    ws = new WebSocket(`wss://stream.binance.com:9443/ws/${pair}@kline_1s`);

    ws.onopen = () => {
        console.log(`WebSocket connected to ${pair} kline stream.`);
    };

    ws.onmessage = msg => {
        try {
            const event = JSON.parse(msg.data);
            const candle = event.k;

            // Update global current price for trading
            currentPrices[pair] = parseFloat(candle.c);
            
            // Update the chart with live candle data
            if (mainSeries && activeChart) {
                const currentType = window.currentChartType || currentChartType || 'candlestick';
                
                if (currentType === 'candlestick' || currentType === 'bar') {
                    mainSeries.update({
                        time: Math.floor(candle.t / 1000),
                        open: parseFloat(candle.o),
                        high: parseFloat(candle.h),
                        low: parseFloat(candle.l),
                        close: parseFloat(candle.c)
                    });
                } else if (currentType === 'line' || currentType === 'area') {
                    mainSeries.update({
                        time: Math.floor(candle.t / 1000),
                        value: parseFloat(candle.c)
                    });
                }
            }
        } catch (e) {
            console.error('WebSocket parse error:', e);
        }
    };

    ws.onclose = () => {
        console.log(`WebSocket disconnected. Attempting to reconnect in 3s...`);
        setTimeout(() => setupBinanceWebSocket(pair), 3000);
    };

    ws.onerror = (err) => {
        console.error('WebSocket Error:', err);
        ws.close();
    };
}

// ============================================================================
// PUSHER INITIALIZATION
// ============================================================================

/**
 * Initialize Pusher for real-time notifications
 */
function initializePusher(userId, keys) {
    try {
        const pusher = new Pusher(keys.pusherAppKey, {
            cluster: keys.pusherCluster,
            authEndpoint: '/api/v1/pusher/pusher_auth.php'
        });
        const channel = pusher.subscribe(`private-user-${userId}`);
        console.log('Pusher connected for user:', userId);
        return channel;
    } catch (error) {
        console.error("Pusher initialization failed:", error);
        return null;
    }
}

// ============================================================================
// NOTIFICATION CENTER
// ============================================================================

/**
 * Initialize notification center with real-time updates
 */
async function initializeNotificationCenter(channel) {
    const notificationBadge = document.getElementById('notification-badge');
    if (!notificationBadge) {
        console.error("Critical: Notification badge element not found in HTML!");
        return;
    }

    // Fetch initial history
    const history = await getNotifications();
    let unreadCount = 0;

    if (history && !history.message) {
        history.forEach(n => {
            if (!n.is_read) unreadCount++;
        });
    }

    // Set initial badge state
    if (unreadCount > 0) {
        notificationBadge.textContent = unreadCount;
        notificationBadge.classList.remove('hidden');
    } else {
        notificationBadge.classList.add('hidden');
    }

    // Bind to real-time events
    channel.bind('trade-settled', function(data) {
        showToast(data.message);
        unreadCount++;
        notificationBadge.textContent = unreadCount;
        notificationBadge.classList.remove('hidden');
    });

    channel.bind('withdrawal-update', function(data) {
        showToast(data.message);
        unreadCount++;
        notificationBadge.textContent = unreadCount;
        notificationBadge.classList.remove('hidden');
    });
}

// ============================================================================
// NAVIGATION & AUTHENTICATION
// ============================================================================

/**
 * Update navigation for logged-in user
 */
function updateNavForLoggedInUser() {
    const loginLink = document.querySelector('a[onclick="openLoginPopup()"]');
    if(loginLink) {
        loginLink.innerHTML = `
            <svg class="w-6 h-6 ml-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path></svg>
            <span class="sidebar-label ml-3">Logout</span>
        `;
        loginLink.setAttribute('onclick', 'logout()');
    }
}

/**
 * Logout user
 */
async function logout() {
    await logoutUser();
    showToast("You have been logged out.");
    setTimeout(() => window.location.reload(), 1500);
}

// ============================================================================
// APPLICATION INITIALIZATION
// ============================================================================

/**
 * Main initialization function
 */
async function initializeApp() {
    console.log("Initializing application...");
    
    // Fetch active assets from the server first
    try {
        activeAssets = await getActiveAssets();
        
        if (activeAssets.length > 0) {
            selectedPair = activeAssets[0];
            window.selectedPair = selectedPair;
            console.log("Selected default pair:", selectedPair.display_name);
        } else {
            showToast("No active trading pairs available.");
            btnPredictHigh.disabled = true;
            btnPredictLow.disabled = true;
            return;
        }
    } catch (error) {
        console.error("Failed to fetch trading assets:", error);
        showToast("Error: Could not load trading assets.");
        return;
    }

    const publicKeys = await getPublicKeys();
    profileData = await getUserProfile(false);

    const mobileLoginBtn = document.getElementById('mobileLoginBtn');
    const mobileAnalyticsBtn = document.getElementById('mobileAnalyticsBtn');

    if (profileData && profileData.email) {
        // --- USER IS LOGGED IN ---
        console.log("User logged in:", profileData.email);
        
        if (mobileLoginBtn) mobileLoginBtn.classList.add('hidden');
        if (mobileAnalyticsBtn) mobileAnalyticsBtn.classList.remove('hidden');
        
        updateNavForLoggedInUser();

        if (profileData.role === 'admin') {
            const adminLink = document.getElementById('admin-link');
            if (adminLink) adminLink.classList.remove('hidden');
        }

        const userChannel = initializePusher(profileData.id, publicKeys);
        if (userChannel) {
            initializeNotificationCenter(userChannel);
        }
        
        const [balances, history] = await Promise.all([
            getWalletBalance(),
            getTradeHistory()
        ]);
        
        if (balances && !balances.error) userWallets = balances;
        if (Array.isArray(history)) {
            tradeHistory = history;
            console.log(`Loaded ${history.length} trades from history`);
        }
        
    } else {
        // --- USER IS LOGGED OUT ---
        console.log("User not logged in");
        
        if (mobileLoginBtn) mobileLoginBtn.classList.remove('hidden');
        if (mobileAnalyticsBtn) mobileAnalyticsBtn.classList.add('hidden');
    }

    updateWalletUI();
    renderDeals();
    updateAnalytics();
    
    if (selectedPair) {
        document.getElementById('selectedPairLabel').textContent = selectedPair.display_name;
        await initTradingView(); // This is in chart.js
        setupBinanceWebSocket(selectedPair.symbol.toLowerCase());
    }

    pageOverlay.addEventListener('click', closeSidePanels);
    btnPredictHigh.addEventListener('click', () => placePrediction('HIGH'));
    btnPredictLow.addEventListener('click', () => placePrediction('LOW'));
    
    console.log("Application initialized successfully");
}

// Initialize app on page load
window.onload = initializeApp;