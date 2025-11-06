/**
 * main.js - Core application logic (Fixed Version)
 * Chart functionality has been moved to chart.js
 */

/**
 * Escape HTML to prevent XSS attacks
 */
function escapeHTML(str) {
    if (str === null || str === undefined) return '';
    const p = document.createElement('p');
    p.textContent = str;
    return p.innerHTML;
}

// --- GLOBAL STATE (Using window for chart.js access) ---
let userWallets = { real: 0.00, demo: 10000.00 };
let tradeHistory = [];
let selectedWallet = 'demo';
let currentPrices = {};
let profileData = {};

// Make these available to chart.js
window.dealDurationInSeconds = 5;
window.currentTimeframe = '5s';

// --- DYNAMIC ASSETS ---
let activeAssets = [];
let selectedPair = null;
window.selectedPair = null; // Make available globally

// Global variable to store filtered assets and search term
let filteredAssets = [];
let currentSearchTerm = '';

// --- GLOBAL REFS ---
let volumeChart, pairChart, ws;

const dealList = document.getElementById('dealList'),
      toast = document.getElementById('toast'),
      btnPredictHigh = document.getElementById('btnPredictHigh'),
      btnPredictLow = document.getElementById('btnPredictLow'),
      leftPanel = document.getElementById('leftPanel'),
      rightPanel = document.getElementById('rightPanel'),
      pageOverlay = document.getElementById('pageOverlay');

// --- APP INITIALIZATION ---

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
            if (adminLink) {
                adminLink.classList.remove('hidden');
            }
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
        await initTradingView(); // This is now in chart.js
        setupBinanceWebSocket(selectedPair.symbol.toLowerCase());
    }

    pageOverlay.addEventListener('click', closeSidePanels);
    btnPredictHigh.addEventListener('click', () => placePrediction('HIGH'));
    btnPredictLow.addEventListener('click', () => placePrediction('LOW'));
    
    console.log("Application initialized successfully");
}

window.onload = initializeApp;

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

// --- AUTHENTICATION & NAVIGATION ---

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

async function logout() {
    await logoutUser();
    showToast("You have been logged out.");
    setTimeout(() => {
        window.location.reload();
    }, 1500);
}

// --- UI & INTERACTION FUNCTIONS ---

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

function selectWallet(w) { 
    selectedWallet = w; 
    updateWalletUI(); 
    renderDeals();
    updateAnalytics();
    showToast(`Switched to ${w === 'demo' ? 'Demo' : 'Real'} Wallet`); 
}

function showToast(msg) { 
    toast.textContent = msg; 
    toast.classList.remove('hidden'); 
    clearTimeout(toast._t); 
    toast._t = setTimeout(() => toast.classList.add('hidden'), 2500); 
}

function isMobile() { 
    return window.innerWidth < 768; 
}

function closeSidePanels() {
    if (isMobile()) {
        leftPanel.classList.add('hidden');
        rightPanel.classList.add('hidden');
        pageOverlay.classList.add('hidden');
    }
}

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

function setActive(element) {
    document.querySelectorAll('.nav-link').forEach(link => link.classList.remove('active'));
    element.classList.add('active');
}

// --- WEBSOCKET FUNCTIONS ---

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

            // Update the global current price for trading
            currentPrices[pair] = parseFloat(candle.c);
            
            // Update the chart with the live candle data
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

/**
 * Place a prediction/trade with comprehensive validation
 */
async function placePrediction(type) {
    if (!profileData || profileData.error) {
        showToast("Please log in to place a trade.");
        openLoginPopup();
        return;
    }
    
    if (selectedWallet === 'demo' && userWallets.demo < 100) {
        openRechargePopup();
        return;
    }

    const bidValueEl = document.getElementById('bidValue');
    const bid_amount = parseFloat(bidValueEl.value);

    // Validate selectedPair exists
    if (!selectedPair || !selectedPair.symbol) {
        showToast("Please select a trading pair");
        return;
    }

    const pair = selectedPair.symbol;
    const direction = type;
    const duration = window.dealDurationInSeconds;
    const wallet_type = selectedWallet;
        
    const entry_price = currentPrices[selectedPair.symbol.toLowerCase()];
    const currentBalance = userWallets[wallet_type];

    // Comprehensive validations
    if (currentBalance <= 0) { 
        showToast("Wallet balance is zero, please recharge."); 
        return; 
    }
    if (bid_amount > currentBalance) { 
        showToast("Wallet balance not sufficient."); 
        return; 
    }
    if (isNaN(bid_amount) || bid_amount <= 0) { 
        showToast('Invalid bid amount'); 
        return; 
    }
    if (!entry_price || entry_price <= 0) { 
        showToast('Live price not available. Please wait a moment.'); 
        return; 
    }

    console.log(`Placing ${direction} trade: $${bid_amount} on ${pair} at ${entry_price}`);

    btnPredictHigh.disabled = true;
    btnPredictLow.disabled = true;
    const clickedButton = (type === 'HIGH') ? btnPredictHigh : btnPredictLow;
    const originalButtonText = clickedButton.innerHTML;
    clickedButton.innerHTML = `<span class="btn-loader"></span>Placing...`;

    try {
        const tradeData = { pair, bid_amount, direction, duration, wallet_type };
        const newTrade = await placeTrade(tradeData);

        if (newTrade && newTrade.id) {
            console.log("Trade placed successfully:", newTrade.id);
            showToast(`Placed ${direction} on ${selectedPair.display_name} for $${bid_amount.toFixed(2)}`);

            // Draw trade on chart
            if (typeof drawTradeOnChart === 'function') {
                drawTradeOnChart(newTrade, type);
            }
                 
            tradeHistory.unshift(newTrade);
            startTradeTimer(newTrade);
            renderDeals();
            userWallets[wallet_type] -= bid_amount;
            updateWalletUI();
        } else {
            console.error("Trade placement failed:", newTrade);
            showToast(newTrade?.message || "An error occurred placing the trade.");
        }
    } catch (error) {
        console.error("Trade placement error:", error);
        showToast("Failed to place trade. Please try again.");
    } finally {
        clickedButton.innerHTML = originalButtonText;
        btnPredictHigh.disabled = false;
        btnPredictLow.disabled = false;
    }
}

/**
 * main.js - Part 2: Trade Management & UI Functions
 */

/**
 * Start a timer for a trade and handle settlement
 */
function startTradeTimer(trade) {
    const expiresAt = new Date(trade.expires_at.replace(' ', 'T') + 'Z').getTime();
    const now = new Date().getTime();
    const durationMs = Math.max(0, expiresAt - now);

    console.log(`Trade ${trade.id} will expire in ${durationMs}ms`);

    // Live countdown for the UI
    const interval = setInterval(() => {
        renderDeals();
    }, 1000);

    // Final settlement at expiry
    setTimeout(async () => {
        clearInterval(interval);
        console.log(`Trade ${trade.id} expired, settling...`);

        // Remove trade visuals from chart
        if (typeof removeTradeFromChart === 'function') {
            removeTradeFromChart(trade);
        }

        // Automated settlement call
        try {
            const response = await fetch(`${API_BASE_URL}/trades/settle_single_trade.php?id=${trade.id}`);
            const finalTrade = await response.json();
            
            console.log(`Trade ${trade.id} settled:`, finalTrade.status);
            
            // Update local history with final result
            const tradeIndex = tradeHistory.findIndex(t => t.id === finalTrade.id);
            if (tradeIndex !== -1) {
                tradeHistory[tradeIndex] = finalTrade;
            }

            // Re-fetch wallet balance
            const freshBalances = await getWalletBalance();
            if (freshBalances && !freshBalances.error) {
                userWallets = freshBalances;
                updateWalletUI();
            }

            renderDeals();
            updateAnalytics();
        } catch (error) {
            console.error("Error settling trade:", error);
            showToast("Trade settlement error");
        }
    }, durationMs);
}

/**
 * Render the list of historical deals
 */
function renderDeals() {
    const filteredHistory = tradeHistory.filter(trade => trade.wallet_type === selectedWallet);

    if (!filteredHistory || filteredHistory.length === 0) {
        dealList.innerHTML = `<p class="text-center text-gray-400 mt-4">No trades for this wallet.</p>`;
        return;
    }

    filteredHistory.sort((a, b) => 
        new Date(b.created_at.replace(' ', 'T') + 'Z') - new Date(a.created_at.replace(' ', 'T') + 'Z')
    );

    let html = '';
    let lastDate = null;
    
    filteredHistory.forEach(d => {
        if (!d || !d.created_at) {
            console.error("Skipping invalid trade object:", d);
            return;
        }

        const tradeDate = new Date(d.created_at.replace(' ', 'T') + 'Z').toLocaleDateString();

        if (tradeDate !== lastDate) {
            html += `<h4 class="font-semibold mt-4 text-gray-400">${tradeDate}</h4>`;
            lastDate = tradeDate;
        }

        const statusColor = d.status === 'WIN' ? 'text-green-500' : 
                           d.status === 'LOSE' ? 'text-red-500' : 'text-gray-400';
        let statusDisplayHtml = '';
        
        if (d.status === 'PENDING' && d.expires_at) {
            const expiresAt = new Date(d.expires_at.replace(' ', 'T') + 'Z').getTime();
            const now = new Date().getTime();
            const remaining = Math.max(0, Math.round((expiresAt - now) / 1000));
            statusDisplayHtml = `<p>PENDING</p><p class="font-semibold text-blue-500">(${remaining}s)</p>`;
        } else {
            const profitDisplay = d.status === 'WIN' ? 
                `+${parseFloat(d.profit_loss).toFixed(2)}` : 
                d.status === 'LOSE' ? 
                `-${parseFloat(d.bid_amount).toFixed(2)}` : 'Refund';
            statusDisplayHtml = `<p>${d.status}</p><p class="font-semibold">${profitDisplay}</p>`;
        }
        
        html += `<div onclick="openDealDetailsPopup(${d.id})" class="cursor-pointer border-b-2 border-gray-700 rounded mb-1 flex justify-between items-center hover:bg-gray-800"><div><p class="font-semibold text-gray-200">${escapeHTML(d.pair)} - ${escapeHTML(d.direction)}</p><p class="text-xs text-gray-400">Bid: $${parseFloat(d.bid_amount).toFixed(2)}</p></div><div class="text-right ${statusColor}">${statusDisplayHtml}</div></div>`;
    });
    
    dealList.innerHTML = html;
}

/**
 * Update analytics with current data
 */
let currentVolumeFilter = '7d';

function updateAnalytics() {
    const filteredHistory = tradeHistory.filter(trade => trade.wallet_type === selectedWallet);
    const closedTrades = filteredHistory.filter(d => d.status === 'WIN' || d.status === 'LOSE');
    
    // Calculate KPIs
    const wins = closedTrades.filter(d => d.status === 'WIN');
    const losses = closedTrades.filter(d => d.status === 'LOSE');
    const totalDeals = closedTrades.length;
    const totalVolume = closedTrades.reduce((sum, trade) => sum + parseFloat(trade.bid_amount), 0);
    const netPL = closedTrades.reduce((sum, trade) => sum + parseFloat(trade.profit_loss), 0);
    const winRate = totalDeals > 0 ? ((wins.length / totalDeals) * 100).toFixed(1) : 0;

    document.getElementById('totalWins').textContent = wins.length.toLocaleString();
    document.getElementById('totalLosses').textContent = losses.length.toLocaleString();
    document.getElementById('winRate').textContent = `${winRate}%`;
    document.getElementById('totalDeals').textContent = totalDeals.toLocaleString();
    document.getElementById('totalVolume').textContent = totalVolume.toLocaleString('en-US', { 
        style: 'currency', 
        currency: 'USD' 
    });
    
    const netPLElement = document.getElementById('netPL');
    netPLElement.textContent = netPL.toLocaleString('en-US', { 
        style: 'currency', 
        currency: 'USD', 
        signDisplay: 'always' 
    });
    netPLElement.className = `text-lg font-semibold ${netPL >= 0 ? 'text-green-500' : 'text-red-500'}`;

    // Check if analytics wrapper is visible
    const analyticsWrapper = document.getElementById('analyticsWrapper');
    if (!analyticsWrapper.classList.contains('open')) {
        return; 
    }

    // Update volume chart
    updateVolumeChart(currentVolumeFilter);

    // Update pair volume pie chart
    const pairVolume = closedTrades.reduce((acc, trade) => {
        acc[trade.pair] = (acc[trade.pair] || 0) + parseFloat(trade.bid_amount);
        return acc;
    }, {});

    const pairLabels = Object.keys(pairVolume);
    const pairData = Object.values(pairVolume);
    const totalPairVolume = pairData.reduce((sum, val) => sum + val, 0);
    
    const pairCtx = document.getElementById('pairChart').getContext('2d');
    if (pairChart) pairChart.destroy();
    pairChart = new Chart(pairCtx, {
        type: 'pie',
        data: {
            labels: pairLabels,
            datasets: [{
                data: pairData,
                backgroundColor: ['#3b82f6', '#ef4444', '#22c55e', '#f97316', '#8b5cf6'],
                borderColor: '#1f2937',
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: { color: '#d1d5db' }
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            const label = context.label || '';
                            const value = context.raw || 0;
                            const percentage = totalPairVolume > 0 ? 
                                ((value / totalPairVolume) * 100).toFixed(1) : 0;
                            return `${label}: ${value.toLocaleString('en-US', { 
                                style: 'currency', 
                                currency: 'USD' 
                            })} (${percentage}%)`;
                        }
                    }
                }
            }
        }
    });
}

/**
 * Update volume bar chart based on timeframe
 */
function updateVolumeChart(timeframe) {
    currentVolumeFilter = timeframe;
    
    // Update active button style
    document.querySelectorAll('.volume-filter-btn').forEach(btn => {
        btn.classList.remove('active-filter');
    });
    const activeBtn = document.querySelector(`button[onclick="updateVolumeChart('${timeframe}')"]`);
    if (activeBtn) activeBtn.classList.add('active-filter');

    const now = new Date();
    const allTrades = tradeHistory.filter(t => t.status === 'WIN' || t.status === 'LOSE');
    let labels = [];
    let data = [];

    if (timeframe === '7d') {
        const startDate = new Date();
        startDate.setHours(0, 0, 0, 0);
        startDate.setDate(now.getDate() - 6);
        
        labels = Array.from({length: 7}, (_, i) => {
            const d = new Date(startDate);
            d.setDate(startDate.getDate() + i);
            return d.toLocaleDateString('en-US', { weekday: 'short' });
        });
        data = new Array(7).fill(0);

        allTrades.filter(t => new Date(t.created_at.replace(' ', 'T')) >= startDate)
            .forEach(t => {
                const dayIndex = Math.floor(
                    (new Date(t.created_at.replace(' ', 'T')) - startDate) / (1000 * 60 * 60 * 24)
                );
                if (dayIndex >= 0 && dayIndex < 7) {
                    data[dayIndex] += parseFloat(t.bid_amount);
                }
            });

    } else if (timeframe === '1M') {
        const startDate = new Date();
        startDate.setHours(0, 0, 0, 0);
        startDate.setDate(now.getDate() - 29);
        labels = ["3 Weeks Ago", "2 Weeks Ago", "Last Week", "This Week"];
        data = new Array(4).fill(0);

        allTrades.filter(t => new Date(t.created_at.replace(' ', 'T')) >= startDate)
            .forEach(t => {
                const daysAgo = Math.floor(
                    (now - new Date(t.created_at.replace(' ', 'T'))) / (1000 * 60 * 60 * 24)
                );
                const weekIndex = 3 - Math.floor(daysAgo / 7);
                if (weekIndex >= 0 && weekIndex < 4) {
                    data[weekIndex] += parseFloat(t.bid_amount);
                }
            });
    }

    // Re-render the chart
    const volumeCtx = document.getElementById('volumeChart').getContext('2d');
    if (volumeChart) volumeChart.destroy();
    volumeChart = new Chart(volumeCtx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{
                label: 'Volume',
                data: data,
                backgroundColor: '#3b82f6',
                borderRadius: 4,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                y: { 
                    beginAtZero: true, 
                    ticks: { color: '#9ca3af' }, 
                    grid: { color: '#374151'} 
                },
                x: { 
                    ticks: { color: '#9ca3af' }, 
                    grid: { display: false } 
                }
            }
        }
    });
}

/**
 * Open deal details popup
 */
function openDealDetailsPopup(tradeId) {
    const trade = tradeHistory.find(t => t.id == tradeId);
    if (trade) {
        showDealPopup(trade);
    } else {
        console.error("Could not find trade with ID:", tradeId);
        showToast("Could not find trade details.");
    }
}

/**
 * Show detailed deal popup with mini chart
 */
async function showDealPopup(trade) {
    const popupContainer = document.getElementById('popupContainer');
    const popupContent = document.getElementById('popupContent');
    
    popupContent.classList.add('popup-dark-theme');
    popupContainer.classList.add('popup-large');
    popupContent.style.width = '';
    popupContent.style.padding = '20px';

    const isWin = trade.status === 'WIN';
    const totalReturn = isWin ? parseFloat(trade.bid_amount) + parseFloat(trade.profit_loss) : 0;
    
    const profitSign = isWin ? '+' : '-';
    const formattedProfit = `${profitSign}$${Math.abs(parseFloat(trade.profit_loss)).toFixed(2)}`;
    const profitColorClass = isWin ? 'deal-profit' : 'deal-loss';

    const formatTradeTime = (dateTimeString) => {
        if (!dateTimeString) return 'N/A';
        return new Date(dateTimeString.replace(' ', 'T')).toLocaleTimeString();
    };

    const directionIcon = trade.direction === 'HIGH' 
        ? '<svg class="w-4 h-4 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 10l7-7m0 0l7 7m-7-7v18"></path></svg>' 
        : '<svg class="w-4 h-4 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 14l-7 7m0 0l-7-7m7 7V3"></path></svg>';

    // Payout rate display
    let payoutRateHtml = '';
    if (trade.status !== 'PENDING') {
        const payoutRatePercent = (parseFloat(trade.payout_rate) * 100).toFixed(2);
        payoutRateHtml = `
            <div class="deal-detail-item">
                <div class="label">Payout Rate</div>
                <div class="value">${payoutRatePercent}%</div>
            </div>
        `;
    }

    popupContent.innerHTML = `
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-2xl font-bold text-white">${escapeHTML(trade.pair)}</h3>
            <div class="p-2 text-center rounded-lg ${isWin ? 'bg-green-500/20' : 'bg-red-500/20'}">
                <div class="text-lg font-bold ${isWin ? 'text-green-400' : 'text-red-400'}">$${totalReturn.toFixed(2)}</div>
            </div>
            <button onclick="closePopup()" class="text-3xl text-gray-400 hover:text-white">&times;</button>
        </div>

        <div id="deal-mini-chart" class="h-40 bg-gray-900 rounded-lg my-4 flex items-center justify-center text-gray-500">
             <div class="btn-loader"></div>
        </div>
        
        <div class="deal-details-grid">
            <div class="deal-detail-item">
                <div class="label">Amount</div>
                <div class="value">$${parseFloat(trade.bid_amount).toFixed(2)}</div>
            </div>
            <div class="deal-detail-item">
                <div class="label">Open time</div>
                <div class="value">${formatTradeTime(trade.created_at)}</div>
            </div>
            <div class="deal-detail-item">
                <div class="label">Open rate</div>
                <div class="value">${directionIcon} ${parseFloat(trade.entry_price).toFixed(5)}</div>
            </div>
            <div class="deal-detail-item">
                <div class="label">Profit</div>
                <div class="value ${profitColorClass}">${formattedProfit}</div>
            </div>
            <div class="deal-detail-item">
                <div class="label">Close time</div>
                <div class="value">${formatTradeTime(trade.expires_at)}</div>
            </div>
            <div class="deal-detail-item">
                <div class="label">Close rate</div>
                <div class="value">${directionIcon} ${trade.close_price ? parseFloat(trade.close_price).toFixed(5) : 'N/A'}</div>
            </div>
            ${payoutRateHtml}
        </div>

        <div class="mt-6 flex gap-4">
            <button onclick="closePopup()" class="w-full py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 font-bold">Continue</button>
        </div>
    `;

    popupContainer.classList.add('active');

    // Load mini chart
    try {
        const symbol = trade.pair.replace('/', '');
        const startTime = new Date(trade.created_at.replace(' ', 'T')).getTime();
        const endTime = new Date(trade.expires_at.replace(' ', 'T')).getTime();

        const response = await fetch(
            `https://api.binance.com/api/v3/klines?symbol=${symbol.toUpperCase()}&interval=1s&startTime=${startTime}&endTime=${endTime}&limit=1000`
        );
        const klines = await response.json();

        const chartContainer = document.getElementById('deal-mini-chart');
        if (klines.length < 2) {
            chartContainer.innerHTML = '<span class="text-gray-500">Chart data not available for this duration.</span>';
            return;
        }

        const chartData = klines.map(k => ({ 
            time: k[0] / 1000, 
            value: parseFloat(k[4]) 
        }));
        chartContainer.innerHTML = '';

        const chart = LightweightCharts.createChart(chartContainer, {
            layout: { 
                background: { color: 'transparent' }, 
                textColor: '#d1d5db' 
            },
            grid: { 
                vertLines: { color: 'rgba(255, 255, 255, 0.1)' }, 
                horzLines: { color: 'rgba(255, 255, 255, 0.1)' } 
            },
            rightPriceScale: { visible: false },
            timeScale: { visible: false },
            handleScroll: false,
            handleScale: false,
        });

        const areaSeries = chart.addAreaSeries({
            topColor: 'rgba(59, 130, 246, 0.2)',
            bottomColor: 'rgba(59, 130, 246, 0.0)',
            lineColor: '#3b82f6',
            lineWidth: 2,
        });
        areaSeries.setData(chartData);
        
        // Draw entry price line
        const tradeLineSeries = chart.addLineSeries({
            color: trade.direction === 'HIGH' ? '#22c55e' : '#ef4444',
            lineWidth: 2,
            lineStyle: LightweightCharts.LineStyle.Solid,
            priceLineVisible: false,
            lastValueVisible: false,
        });
        tradeLineSeries.setData([
            { time: chartData[0].time, value: trade.entry_price },
            { time: chartData[chartData.length - 1].time, value: trade.entry_price }
        ]);

        // Draw vertical line at close
        if (chartData.length > 1) {
            const prices = chartData.map(d => d.value);
            prices.push(trade.entry_price);
            const minPrice = Math.min(...prices);
            const maxPrice = Math.max(...prices);
            const closeTime = chartData[chartData.length - 1].time;

            const verticalLineSeries = chart.addLineSeries({
                color: '#facc15',
                lineWidth: 1,
                priceLineVisible: false,
                lastValueVisible: false,
            });

            verticalLineSeries.setData([
                { time: closeTime, value: minPrice },
                { time: closeTime, value: maxPrice }
            ]);
        }
        
        // Add marker
        const markers = [{
            time: chartData[0].time,
            position: trade.direction === 'HIGH' ? 'belowBar' : 'aboveBar',
            color: trade.direction === 'HIGH' ? '#22c55e' : '#ef4444',
            shape: trade.direction === 'HIGH' ? 'arrowUp' : 'arrowDown',
            text: `$${parseFloat(trade.bid_amount).toFixed(2)}`
        }];
        areaSeries.setMarkers(markers);
        
        chart.timeScale().fitContent();

    } catch (error) {
        console.error("Failed to load mini-chart data:", error);
        document.getElementById('deal-mini-chart').innerHTML = 
            '<span class="text-gray-500">Failed to load chart.</span>';
    }
}

/**
 * main.js - Part 3: Popup Functions & Utilities
 */

/**
 * Open pair selector popup
 */
function openPairSelectorPopup() {
    const popupContent = document.getElementById('popupContent');
    const popupContainer = document.getElementById('popupContainer');

    popupContent.classList.add('popup-dark-theme');
    popupContent.style.width = '';
    popupContent.style.padding = '20px';
    popupContainer.classList.remove('popup-large');

    popupContent.innerHTML = `
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-xl font-bold">Select Asset</h3>
            <button onclick="closePopup()" class="text-2xl text-gray-400 hover:text-white">&times;</button>
        </div>
        
        <div class="relative mb-4">
            <input type="text" id="assetSearchInput" onkeyup="filterAssets()" placeholder="Search assets" class="w-full pl-10 pr-4 py-2 rounded-lg bg-gray-700 text-gray-200 border border-gray-600 focus:outline-none focus:border-blue-500" value="${currentSearchTerm}">
            <svg class="absolute left-3 top-1/2 transform -translate-y-1/2 w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
        </div>

        <div id="asset-list-container" class="space-y-2 overflow-y-auto" style="max-height: calc(100vh - 220px);"></div>
    `;

    popupContainer.classList.add('active');
    renderAssetList();
    document.getElementById('assetSearchInput').focus();
}

/**
 * Filter assets based on search
 */
function filterAssets() {
    currentSearchTerm = document.getElementById('assetSearchInput').value.toLowerCase();
    filteredAssets = activeAssets.filter(asset => 
        asset.display_name.toLowerCase().includes(currentSearchTerm)
    );
    renderAssetList();
}

/**
 * Render asset list
 */
function renderAssetList() {
    const assetListContainer = document.getElementById('asset-list-container');
    if (!assetListContainer) return;

    let assetsToDisplay = currentSearchTerm ? filteredAssets : activeAssets;
    
    if (assetsToDisplay.length === 0) {
        assetListContainer.innerHTML = `<div class="text-center py-8 text-gray-400"><p>No matching assets found.</p></div>`;
        return;
    }

    assetListContainer.innerHTML = assetsToDisplay.map(asset => {
        const iconSrc = asset.icon_url || 'assets/images/crypto/default.png';
        const payoutRatePercent = (parseFloat(asset.payout_rate) * 100).toFixed(2);
        const isSelected = selectedPair && selectedPair.symbol === asset.symbol;

        return `
            <button onclick="selectPair('${asset.symbol}')" class="w-full flex items-center justify-between p-3 rounded-lg ${isSelected ? 'bg-blue-600' : 'bg-gray-800 hover:bg-gray-700'} text-left transition-colors duration-200">
                <div class="flex items-center space-x-3">
                    <img src="${iconSrc}" alt="${asset.display_name} icon" class="w-7 h-7 rounded-full">
                    <div class="font-semibold text-gray-200">${escapeHTML(asset.display_name)}</div>
                </div>
                <div class="text-sm font-semibold text-green-400">${payoutRatePercent}%</div>
            </button>
        `;
    }).join('');
}

/**
 * Select a trading pair
 */
function selectPair(symbol) {
    const newPair = activeAssets.find(p => p.symbol === symbol);
    if (!newPair || (selectedPair && newPair.symbol === selectedPair.symbol)) {
        closePopup();
        return;
    }
    
    console.log("Switching to pair:", newPair.display_name);
    
    selectedPair = newPair;
    window.selectedPair = newPair;
    document.getElementById('selectedPairLabel').textContent = selectedPair.display_name;
    
    // Reinitialize chart with new pair
    initTradingView();
    setupBinanceWebSocket(selectedPair.symbol.toLowerCase());
    closePopup();
}

/**
 * Close popup utility
 */
function closePopup() {
    const popupContainer = document.getElementById('popupContainer');
    const popupContentDiv = document.getElementById('popupContent');

    popupContainer.classList.remove('active');

    setTimeout(() => {
        popupContentDiv.innerHTML = '';
        popupContentDiv.classList.remove('popup-dark-theme', 'chart-popup-theme');
        popupContentDiv.style.width = '';
        popupContentDiv.style.padding = '';
        popupContainer.classList.remove('popup-large');
    }, 300);
}

/**
 * Timeframe popup with wheel picker - FIXED
 */
function openTimeframePopup() {
    let selectedTimeInSeconds = window.dealDurationInSeconds;

    const popupContent = document.getElementById('popupContent');
    const popupContainer = document.getElementById('popupContainer');

    popupContent.classList.add('popup-dark-theme');
    popupContent.style.width = '380px';
    popupContent.style.maxWidth = '90vw';
    popupContent.style.padding = '20px';
    popupContainer.classList.remove('popup-large');

    const generateItems = (max, step = 1) => {
        let items = '<div class="time-picker-item" style="height: 40px;"></div><div class="time-picker-item" style="height: 40px;"></div>';
        for (let i = 0; i <= max; i += step) {
            items += `<div class="time-picker-item">${i.toString().padStart(2, '0')}</div>`;
        }
        items += '<div class="time-picker-item" style="height: 40px;"></div><div class="time-picker-item" style="height: 40px;"></div><div class="time-picker-item" style="height: 40px;"></div>';
        return items;
    };

    popupContent.innerHTML = `
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-xl font-bold">Deal Duration</h3>
            <button onclick="closePopup()" class="text-2xl text-gray-400 hover:text-white">&times;</button>
        </div>
        
        <div class="p-1.5 flex space-x-2 rounded-lg bg-gray-800">
            <button onclick="showTimeframeTab('basic', this)" class="popup-tab active flex-1 py-1.5 text-sm font-semibold rounded-md">Basic</button>
            <button onclick="showTimeframeTab('custom', this)" class="popup-tab flex-1 py-1.5 text-sm font-semibold rounded-md">Custom</button>
        </div>

        <div class="mt-4">
            <div id="basic-content" class="timeframe-content flex flex-col gap-2">
                ${[5, 10, 15, 20, 25, 30, 35, 40].map(sec => `
                    <button class="p-2 rounded-lg text-center text-white ${selectedTimeInSeconds === sec ? 'bg-blue-600' : 'bg-gray-700 hover:bg-gray-600'}" onclick="this.parentElement.querySelectorAll('button').forEach(b=>{b.classList.remove('bg-blue-600'); b.classList.add('bg-gray-700', 'hover:bg-gray-600')}); this.classList.remove('bg-gray-700', 'hover:bg-gray-600'); this.classList.add('bg-blue-600'); window.tempTime = ${sec};">
                        ${formatTime(sec)}
                    </button>
                `).join('')}
            </div>

            <div id="custom-content" class="timeframe-content hidden">
                <div class="time-picker-container">
                    <div class="flex-1 text-center">
                        <div class="time-picker-label">hours</div>
                        <div class="time-picker-column" id="hours-col">${generateItems(8)}</div>
                    </div>
                    <div class="flex-1 text-center">
                        <div class="time-picker-label">minutes</div>
                        <div class="time-picker-column" id="minutes-col">${generateItems(59)}</div>
                    </div>
                    <div class="flex-1 text-center">
                        <div class="time-picker-label">seconds</div>
                        <div class="time-picker-column" id="seconds-col">${generateItems(59, 5)}</div>
                    </div>
                    <div class="time-picker-overlay"></div>
                </div>
            </div>
        </div>

        <div class="mt-6 text-right">
            <button id="confirmTimeframe" class="w-full px-5 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">Confirm</button>
        </div>
    `;

    window.tempTime = selectedTimeInSeconds;

    const itemHeight = 40;
    const hoursCol = document.getElementById('hours-col');
    const minutesCol = document.getElementById('minutes-col');
    const secondsCol = document.getElementById('seconds-col');

    // Pre-scroll to current selection
    const h = Math.floor(selectedTimeInSeconds / 3600);
    const m = Math.floor((selectedTimeInSeconds % 3600) / 60);
    const s = selectedTimeInSeconds % 60;
    
    if(hoursCol) hoursCol.scrollTop = h * itemHeight;
    if(minutesCol) minutesCol.scrollTop = m * itemHeight;
    if(secondsCol) {
        const s_index = Math.round(s / 5); // FIXED: Index for 5-second increments
        secondsCol.scrollTop = s_index * itemHeight;
    }

    document.getElementById('confirmTimeframe').addEventListener('click', () => {
        const isBasicActive = !document.getElementById('basic-content').classList.contains('hidden');
        
        if (isBasicActive) {
            window.dealDurationInSeconds = window.tempTime;
        } else {
            // FIXED: Calculate values correctly
            const h_val = Math.round(hoursCol.scrollTop / itemHeight);
            const m_val = Math.round(minutesCol.scrollTop / itemHeight);
            const s_val = Math.round(secondsCol.scrollTop / itemHeight) * 5; // FIXED
            
            const total = (h_val * 3600) + (m_val * 60) + s_val;

            if (total > 28800) { 
                showToast("Maximum duration is 8 hours."); 
                return; 
            }
            if (total < 5) { 
                showToast("Minimum duration is 5 seconds."); 
                return; 
            }
            
            window.dealDurationInSeconds = total;
        }
        
        console.log("Deal duration updated to:", window.dealDurationInSeconds);
        document.getElementById('dealTimeframeLabel').textContent = formatTime(window.dealDurationInSeconds);
        
        // Update lookahead line
        if (typeof updateLookAheadLine === 'function') {
            updateLookAheadLine(window.dealDurationInSeconds, window.currentTimeframe);
        }
        
        closePopup();
    });

    popupContainer.classList.add('active');
}

/**
 * Show timeframe tab
 */
function showTimeframeTab(tabName, clickedElement) {
    document.querySelectorAll('.timeframe-content').forEach(el => el.classList.add('hidden'));
    document.querySelectorAll('.popup-tab').forEach(el => el.classList.remove('active'));
    
    document.getElementById(tabName + '-content').classList.remove('hidden');
    clickedElement.classList.add('active');
}

/**
 * Format time helper
 */
function formatTime(totalSeconds) {
    const hours = Math.floor(totalSeconds / 3600).toString().padStart(2, '0');
    const minutes = Math.floor((totalSeconds % 3600) / 60).toString().padStart(2, '0');
    const seconds = (totalSeconds % 60).toString().padStart(2, '0');
    return `${hours}:${minutes}:${seconds}`;
}

/**
 * Number pad functions - FIXED
 */
function showNumpad() {
    document.getElementById('prediction-buttons').classList.add('hidden');
    document.getElementById('numpad').classList.remove('hidden');
}

function confirmBidAmount() {
    const bidValueEl = document.getElementById('bidValue');
    const currentValue = parseFloat(bidValueEl.value);
    
    // Validate bid amount
    if (isNaN(currentValue) || currentValue <= 0) {
        bidValueEl.value = '10';
        showToast('Bid amount must be greater than 0');
        return;
    }
    
    // Optional: Check against wallet balance
    const currentBalance = userWallets[selectedWallet];
    if (currentValue > currentBalance) {
        bidValueEl.value = currentBalance.toString();
        showToast('Bid amount adjusted to wallet balance');
    }
    
    document.getElementById('numpad').classList.add('hidden');
    document.getElementById('prediction-buttons').classList.remove('hidden');
}

function appendDigit(digit) {
    const bidValueEl = document.getElementById('bidValue');
    const currentValue = bidValueEl.value;

    if (currentValue === '0') {
        bidValueEl.value = digit;
    } else {
        bidValueEl.value += digit;
    }
}

function handleBackspace() {
    const bidValueEl = document.getElementById('bidValue');
    const currentValue = bidValueEl.value;

    let newValue = currentValue.slice(0, -1);
    if (newValue === '') {
        newValue = '0';
    }
    bidValueEl.value = newValue;
}

/**
 * Recharge popup
 */
function openRechargePopup() {
    document.getElementById('popupContent').innerHTML = `
        <h3 class="text-lg font-semibold mb-2 text-center">Demo Balance Low</h3>
        <p class="text-center text-gray-600 mb-4">Your demo account is below $100. Would you like to recharge it to $10,000?</p>
        <div class="flex justify-center gap-4 mt-4">
            <button id="rechargeBtn" class="px-6 py-2 bg-blue-500 text-white rounded hover:bg-blue-600">Recharge</button>
            <button onclick="closePopup()" class="px-6 py-2 bg-gray-200 rounded">Later</button>
        </div>
    `;
    document.getElementById('popupContainer').classList.add('active');

    document.getElementById('rechargeBtn').addEventListener('click', handleRecharge);
}

async function handleRecharge() {
    const rechargeBtn = document.getElementById('rechargeBtn');
    rechargeBtn.disabled = true;
    rechargeBtn.innerHTML = `<span class="btn-loader"></span>Recharging...`;

    const result = await rechargeDemoWallet();

    if (result.message === "Demo wallet recharged successfully.") {
        const freshBalances = await getWalletBalance();
        if (freshBalances && !freshBalances.error) {
            userWallets = freshBalances;
            updateWalletUI();
        }
        showToast('Demo wallet recharged!');
        closePopup();
    } else {
        showToast(result.message || "Failed to recharge.");
        rechargeBtn.disabled = false;
        rechargeBtn.innerHTML = 'Recharge';
    }
}

/**
 * Unauthorized handler
 */
function handleUnauthorized() {
    showToast("Your session has expired. Please log in again.");
    setTimeout(() => {
        window.location.reload();
    }, 2500);
}

/**
 * Notification center initialization - FIXED
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

/**
 * main.js - Part 4: Authentication & Profile Popups
 * NOTE: This file should be concatenated with parts 1, 2, and 3
 */

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

/**
 * Open finances popup with transactions
 */
// In main.js - Update openFinancesPopup() function
async function openFinancesPopup() {
    if (!profileData || !profileData.email) {
        showToast("Please log in to view your profile.");
        openLoginPopup();
        return;
    }

    const popupContent = document.getElementById('popupContent');
    const popupContainer = document.getElementById('popupContainer');

    popupContent.classList.add('popup-dark-theme');
    popupContent.classList.add('popup-large');
    popupContent.style.padding = '20px';
    
    popupContent.innerHTML = `<h3 class="text-xl font-semibold mb-4 text-center">Finances</h3><div class="text-center p-8 text-gray-50">Loading...</div>`;
    popupContainer.classList.add('active');

    const allTransactions = await getTransactionHistory();
    const paymentHistory = allTransactions.filter(tx => tx.type === 'DEPOSIT' || tx.type === 'WITHDRAWAL');
    const ledgerHistory = allTransactions;

    const generateTransactionTable = (transactions) => {
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
    };

    // Deposit Form (unchanged)
    const depositFormHtml = `
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

    // UPDATED WITHDRAWAL SECTION - Redirects to new flow
    const withdrawalFormHtml = `
        <div class="p-6 space-y-4">
            <div class="bg-gradient-to-r from-blue-600 to-purple-600 rounded-lg p-6 text-center text-white mb-6">
                <h3 class="text-2xl font-bold mb-2">💰 Withdraw Your Funds</h3>
                <p class="text-lg">Available Balance: <strong>$${(userWallets.real || 0).toFixed(2)}</strong></p>
            </div>

            <div class="space-y-4">
                <div class="bg-gray-800 rounded-lg p-6 border-2 border-gray-700">
                    <h4 class="text-lg font-bold text-white mb-3">Choose Your Withdrawal Method:</h4>
                    <p class="text-gray-400 text-sm mb-4">We offer two convenient ways to withdraw your funds</p>
                    
                    <button onclick="closePopup(); setTimeout(() => openWithdrawalSelectionPopup(), 300);" 
                        class="w-full bg-gradient-to-r from-green-600 to-blue-600 hover:from-green-700 hover:to-blue-700 text-white font-bold py-4 rounded-lg transition-all transform hover:scale-105">
                        🚀 Start Withdrawal Process
                    </button>
                </div>

                <div class="bg-gray-700/50 border border-gray-600 rounded-lg p-4">
                    <h5 class="font-semibold text-gray-200 mb-2">📋 Available Methods:</h5>
                    <ul class="space-y-2 text-sm text-gray-300">
                        <li class="flex items-center space-x-2">
                            <span class="text-green-400">✓</span>
                            <span><strong>Manual Withdrawal</strong> - Simple, processed within 24-48 hours</span>
                        </li>
                        <li class="flex items-center space-x-2">
                            <span class="text-blue-400">✓</span>
                            <span><strong>Automated Withdrawal</strong> - Instant after one-time verification</span>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    `;

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
            <div id="transactions-content" class="popup-content hidden">${generateTransactionTable(paymentHistory)}</div>
            <div id="ledger-content" class="popup-content hidden">${generateTransactionTable(ledgerHistory)}</div>
        </div>
    `;

    // Initialize Stripe for deposits
    if (typeof Stripe !== 'undefined') {
        initializeStripeDeposit();
    }
}

/**
 * Helper function to show finance tabs
 */
function showFinanceTab(tabName, clickedElement) {
    document.querySelectorAll('.popup-content').forEach(el => el.classList.add('hidden'));
    document.querySelectorAll('.popup-tab').forEach(el => el.classList.remove('active'));
    
    document.getElementById(tabName + '-content').classList.remove('hidden');
    clickedElement.classList.add('active');
}

async function initializeStripeDeposit() {
    // Get Stripe publishable key from backend
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
                '::placeholder': {
                    color: '#9ca3af'
                }
            },
            invalid: {
                color: '#ef4444',
                iconColor: '#ef4444'
            }
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
            // Create token using Stripe.js
            const {token, error} = await stripe.createToken(cardElement);
            
            if (error) {
                errorDiv.textContent = error.message;
                submitBtn.disabled = false;
                submitBtn.textContent = 'Process Deposit';
                return;
            }
            
            // Send token to backend which will use StripeService to create charge
            const result = await processDeposit(amount, token.id);
            
            if (result.message === 'Deposit successful.') {
                showToast('Deposit successful!');
                
                // Clear the card element
                cardElement.clear();
                
                // Refresh balance
                const freshBalances = await getWalletBalance();
                if (freshBalances && !freshBalances.error) {
                    userWallets = freshBalances;
                    updateWalletUI();
                }
                
                // Close popup and show transactions
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

function initializeWithdrawalForm() {
    const form = document.getElementById('withdrawalForm');
    if (!form) return;
    
    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        
        const amount = parseFloat(document.getElementById('withdrawalAmount').value);
        const submitBtn = document.getElementById('withdrawalSubmitBtn');
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
        submitBtn.textContent = 'Processing...';
        msgDiv.textContent = '';
        
        try {
            const result = await createWithdrawal(amount);
            
            if (result.message === 'Withdrawal request created successfully.') {
                msgDiv.className = 'text-sm text-center h-4 text-green-400';
                msgDiv.textContent = 'Withdrawal request submitted!';
                
                // Refresh balance
                const freshBalances = await getWalletBalance();
                if (freshBalances && !freshBalances.error) {
                    userWallets = freshBalances;
                    updateWalletUI();
                }
                
                setTimeout(() => closePopup(), 2000);
            } else {
                msgDiv.className = 'text-sm text-center h-4 text-red-400';
                msgDiv.textContent = result.message || 'Withdrawal failed';
                submitBtn.disabled = false;
                submitBtn.textContent = 'Request Withdrawal';
            }
        } catch (err) {
            msgDiv.className = 'text-sm text-center h-4 text-red-400';
            msgDiv.textContent = 'An error occurred. Please try again.';
            submitBtn.disabled = false;
            submitBtn.textContent = 'Request Withdrawal';
        }
    });
}

/**
 * Directly initiates Stripe onboarding verification process
 * @param {HTMLElement} button - The button element that was clicked
 */
async function completeStripeVerification(button) {
    // Disable button and show loading state
    button.disabled = true;
    const originalText = button.innerHTML;
    button.innerHTML = '<span class="btn-loader"></span>Preparing verification...';
    
    try {
        // Create the onboarding link
        const linkResult = await createOnboardingLink();

        if (linkResult && linkResult.onboarding_url) {
            // Show success message
            showToast('Redirecting to verification...');
            
            // Small delay for better UX
            setTimeout(() => {
                // Redirect to Stripe onboarding
                window.location.href = linkResult.onboarding_url;
            }, 500);
        } else {
            throw new Error('Failed to generate verification link');
        }
        
    } catch (error) {
        console.error('Stripe verification error:', error);
        
        // Re-enable button
        button.disabled = false;
        button.innerHTML = originalText;
        
        // Show error message
        showToast(error.message || 'Failed to start verification. Please try again.');
    }
}

/**
 * Sets up a new Stripe Connect account and starts onboarding
 * @param {HTMLElement} button - The button element that was clicked
 */
async function setupStripeAccount(button) {
    button.disabled = true;
    const originalText = button.innerHTML;
    button.innerHTML = '<span class="btn-loader"></span>Setting up...';

    try {
        // Create simplified Connect account
        const createResult = await fetch('/api/v1/payments/create_connect_account_simple.php', {
            method: 'POST',
            credentials: 'include'
        });

        const createData = await createResult.json();

        if (createData.error || !createData.stripe_connect_id) {
            throw new Error(createData.message || 'Failed to create account');
        }

        // Update local profile data
        profileData.stripe_connect_id = createData.stripe_connect_id;
        
        // Now create the onboarding link
        button.innerHTML = '<span class="btn-loader"></span>Redirecting to verification...';
        const linkResult = await createOnboardingLink();

        if (linkResult && linkResult.onboarding_url) {
            showToast('Redirecting to Stripe verification...');
            
            setTimeout(() => {
                window.location.href = linkResult.onboarding_url;
            }, 500);
        } else {
            throw new Error('Failed to generate verification link');
        }
        
    } catch (error) {
        console.error('Stripe setup error:', error);
        
        button.disabled = false;
        button.innerHTML = originalText;
        
        showToast(error.message || 'Failed to set up payout account. Please try again.');
    }
}

function showPopupTab(tabName, clickedElement) {
    document.querySelectorAll('.popup-content').forEach(el => el.classList.add('hidden'));
    document.querySelectorAll('.popup-tab').forEach(el => el.classList.remove('active'));
    
    document.getElementById(tabName + '-content').classList.remove('hidden');
    clickedElement.classList.add('active');
}

/**
 * Open notifications popup
 */
async function openNotificationsPopup() {
    if (!profileData || !profileData.email) {
        showToast("Please log in to view notifications.");
        openLoginPopup();
        return;
    }

    const popupContainer = document.getElementById('popupContainer');
    const popupContentDiv = document.getElementById('popupContent');

    popupContentDiv.classList.add('popup-dark-theme');
    popupContentDiv.style.width = '380px';
    popupContentDiv.style.maxWidth = '90vw';
    popupContainer.classList.remove('popup-large');

    popupContentDiv.innerHTML = `
        <div class="flex justify-between items-center p-4 border-b border-gray-700 flex-shrink-0">
            <h3 class="text-xl font-bold text-white">Notifications</h3>
            <button onclick="closePopup()" class="text-2xl text-gray-400 hover:text-white">&times;</button>
        </div>
        <div id="notification-list-content" class="popup-scroll-content text-gray-400">Loading...</div>`;

    popupContainer.classList.add('active');

    const notifications = await getNotifications();
    const notificationListContent = document.getElementById('notification-list-content');

    if (notifications && !notifications.message) {
        const badge = document.getElementById('notification-badge');
        if (badge) badge.classList.add('hidden');
        markNotificationsAsRead();

        if (notifications.length === 0) {
            notificationListContent.innerHTML = '<p class="text-gray-400 py-4 px-4">No notifications yet.</p>';
            return;
        }

        const notificationHtml = notifications.map(n => `
            <div class="p-3 border-b border-gray-700 ${!n.is_read ? 'bg-gray-700/50' : ''}">
                <p class="text-sm text-gray-200">${escapeHTML(n.message)}</p>
                <p class="text-xs text-gray-400 mt-1">${new Date(n.created_at.replace(' ', 'T')+'Z').toLocaleString()}</p>
            </div>
        `).join('');

        notificationListContent.innerHTML = notificationHtml;
        notificationListContent.style.padding = '20px';

    } else {
        notificationListContent.innerHTML = `<p class="text-red-400 py-4 px-4">Could not load notifications.</p>`;
    }
}

/**
 * UPDATED: Open finances popup with card-based withdrawals
 * Replace the existing openFinancesPopup() function in main.js with this
 */
async function openFinancesPopup() {
    if (!profileData || !profileData.email) {
        showToast("Please log in to view your profile.");
        openLoginPopup();
        return;
    }

    const popupContent = document.getElementById('popupContent');
    const popupContainer = document.getElementById('popupContainer');

    popupContent.classList.add('popup-dark-theme');
    popupContent.classList.add('popup-large');
    popupContent.style.padding = '20px';
    
    popupContent.innerHTML = `<h3 class="text-xl font-semibold mb-4 text-center">Finances</h3><div class="text-center p-8 text-gray-50">Loading...</div>`;
    popupContainer.classList.add('active');

    // Check payout status (now checks for cards instead of Stripe Connect)
    const payoutStatus = await checkPayoutStatus();
    
    const allTransactions = await getTransactionHistory();
    const paymentHistory = allTransactions.filter(tx => tx.type === 'DEPOSIT' || tx.type === 'WITHDRAWAL');
    const ledgerHistory = allTransactions;

    const generateTransactionTable = (transactions) => {
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
    };

    // Deposit Form HTML (unchanged)
    const depositFormHtml = `
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

    // UPDATED WITHDRAWAL SECTION - Card-based
    let withdrawalFormHtml = '';

    if (!payoutStatus || !payoutStatus.payout_enabled) {
        // No payout cards added yet - UPDATED BUTTON
        withdrawalFormHtml = `
            <div class="p-6 space-y-4">
                <div class="bg-yellow-900/20 border border-yellow-500/30 rounded-lg p-4">
                    <div class="flex items-start space-x-3">
                        <svg class="w-6 h-6 text-yellow-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
                        <div class="flex-1">
                            <p class="font-semibold text-yellow-400 mb-1">Add Payout Card Required</p>
                            <p class="text-sm text-gray-300 mb-3">Add a Visa or Mastercard debit card to receive instant withdrawals directly to your card.</p>
                            <button onclick="navigateToAddCard()" 
                                class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 text-sm font-medium">
                                Add Payout Card Now
                            </button>
                        </div>
                    </div>
                </div>
                
                <div class="bg-gray-700/50 border border-gray-600 rounded-lg p-4">
                    <h4 class="font-semibold text-gray-200 mb-2">Why Add a Payout Card?</h4>
                    <ul class="text-sm text-gray-400 space-y-1">
                        <li>✓ Instant withdrawals (arrives in 30 minutes)</li>
                        <li>✓ Standard withdrawals (1-3 business days)</li>
                        <li>✓ Secure payment processing by Stripe</li>
                        <li>✓ Works worldwide - 150+ countries supported</li>
                        <li>✓ Only Visa & Mastercard debit cards accepted</li>
                    </ul>
                </div>
            </div>
        `;
        
    } else {
        // Has payout card(s) - show withdrawal form - UPDATED BUTTON
        const defaultCard = payoutStatus.default_card;
        const cardDisplay = defaultCard 
            ? `${defaultCard.brand.toUpperCase()} ****${defaultCard.last4}` 
            : 'Default Card';

        withdrawalFormHtml = `
            <div class="p-6 space-y-4">
                <div class="bg-green-900/20 border border-green-500/30 rounded-lg p-4 mb-4">
                    <div class="flex items-center space-x-3">
                        <svg class="w-6 h-6 text-green-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                        <div class="text-sm text-gray-300 flex-1">
                            <p class="font-semibold text-green-400 mb-1">✓ Ready to Withdraw</p>
                            <p class="text-xs text-gray-400">Payout to: ${cardDisplay}</p>
                        </div>
                        <div class="text-right">
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
                        <p class="text-xs text-gray-400 mt-1">Minimum: $10.00 | Maximum: $${(userWallets.real || 0).toFixed(2)}</p>
                    </div>

                    <div class="bg-gray-700/50 border border-gray-600 rounded-lg p-4">
                        <div class="flex items-center justify-between mb-2">
                            <h4 class="font-semibold text-gray-200">Payout Card</h4>
                            <button type="button" onclick="navigateToPayoutCards()" 
                                class="text-xs text-blue-400 hover:text-blue-300">
                                Manage Cards
                            </button>
                        </div>
                        <div class="flex items-center space-x-3">
                            <div class="w-10 h-10 bg-gradient-to-br from-blue-500 to-purple-600 rounded-lg flex items-center justify-center">
                                <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"></path>
                                </svg>
                            </div>
                            <div>
                                <p class="font-medium text-white">${cardDisplay}</p>
                                <p class="text-xs text-gray-400">${defaultCard ? defaultCard.holder_name : 'Cardholder'}</p>
                            </div>
                        </div>
                    </div>

                    <div class="bg-gray-700/50 border border-gray-600 rounded-lg p-4">
                        <h4 class="font-semibold text-gray-200 mb-2">Processing Times</h4>
                        <ul class="text-sm text-gray-400 space-y-1">
                            <li>• Instant: 30 minutes (1% fee + $0.50)</li>
                            <li>• Standard: 1-3 business days ($0.25 fee)</li>
                            <li>• Admin approval required</li>
                        </ul>
                    </div>

                    <div id="withdrawalMessage" class="text-sm text-center h-4 mb-4"></div>

                    <button type="submit" id="withdrawalSubmitBtn" class="w-full py-3 bg-red-600 text-white font-semibold rounded-lg hover:bg-red-700 transition-colors">
                        Request Withdrawal
                    </button>
                </form>
            </div>
        `;
    }

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
            <div id="transactions-content" class="popup-content hidden">${generateTransactionTable(paymentHistory)}</div>
            <div id="ledger-content" class="popup-content hidden">${generateTransactionTable(ledgerHistory)}</div>
        </div>
    `;

    // Initialize Stripe for deposits
    if (typeof Stripe !== 'undefined') {
        initializeStripeDeposit();
    }

    // Initialize withdrawal form handler if user has cards
    if (payoutStatus && payoutStatus.payout_enabled) {
        initializeWithdrawalForm();
    }
}

function showSecuritySetting(settingName, clickedElement) {
    // Hide all security setting content panels
    document.querySelectorAll('.security-setting').forEach(el => el.classList.add('hidden'));
    // Deactivate all nav links
    document.querySelectorAll('.security-nav-link').forEach(el => el.classList.remove('active'));
    
    // Show the selected content panel
    document.getElementById(settingName + '-setting').classList.remove('hidden');
    // Activate the clicked nav link
    clickedElement.classList.add('active');
}

// Add this new function to main.js

function openDisable2FAPopup() {
    // We use the main popup container but replace its content
    const popupContent = document.getElementById('popupContent');
    popupContent.innerHTML = `
        <h3 class="text-xl font-semibold mb-2 text-center">Disable 2FA</h3>
        <p class="text-center text-gray-600 mb-4">For your security, please enter your password to continue.</p>
        <form id="disable2faForm" class="space-y-4">
            <div>
                <label for="passwordConfirm" class="block text-sm font-medium text-gray-700">Password</label>
                <input type="password" id="passwordConfirm" required class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm" style="font-size: 16px;">
            </div>
            <div id="disable2faError" class="text-red-500 text-sm text-center h-4"></div>
            <div class="flex justify-end gap-4 pt-2">
                <button type="button" onclick="openProfilePopup()" class="px-4 py-2 bg-gray-600 rounded-md">Cancel</button>
                <button type="submit" class="px-4 py-2 bg-red-600 text-white rounded-md hover:bg-red-700">Confirm & Disable</button>
            </div>
        </form>
    `;

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
            // This now correctly displays the "Incorrect password." message
            errorDiv.textContent = result.message || 'An error occurred.';
            button.disabled = false;
            button.textContent = 'Confirm & Disable';
        }
    });
}

/**
 * Navigate directly to add card popup from finances
 */
function navigateToAddCard() {
    closePopup();
    setTimeout(() => {
        openAddCardPopup();
    }, 400);
}

/**
 * Navigate to payout cards management from finances
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
 * Open popup to add a new payout card (UPDATED - standalone version)
 */
async function openAddCardPopup() {
    const popupContent = document.getElementById('popupContent');
    const popupContainer = document.getElementById('popupContainer');
    
    popupContent.classList.add('popup-dark-theme');
    popupContent.style.padding = '20px';
    popupContainer.classList.remove('popup-large');
    
    // Check if we're in test mode
    const keysResponse = await getPublicKeys();
    const isTestMode = keysResponse.stripePublishableKey && keysResponse.stripePublishableKey.includes('pk_test');
    
    // Test mode helper HTML
    const testModeHelper = isTestMode ? `
        <div class="bg-purple-900/20 border border-purple-500/30 rounded-lg p-4 mb-4">
            <div class="flex items-start space-x-3">
                <svg class="w-6 h-6 text-purple-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
                <div class="text-sm text-gray-300 flex-1">
                    <p class="font-semibold text-purple-400 mb-2">🧪 Test Mode Active</p>
                    <p class="text-gray-400 mb-2">Use these test debit cards:</p>
                    <div class="space-y-1 font-mono text-xs bg-gray-800 p-2 rounded">
                        <p class="text-green-400">✓ 4000 0566 5566 5556 (Visa Debit)</p>
                        <p class="text-green-400">✓ 5200 8282 8282 8210 (Mastercard Debit)</p>
                        <p class="text-red-400">✗ 4242 4242 4242 4242 (Credit card - won't work)</p>
                    </div>
                    <p class="text-gray-500 mt-2 text-xs">Any future expiry date and any 3-digit CVC</p>
                </div>
            </div>
        </div>
    ` : '';
    
    popupContent.innerHTML = `
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-xl font-bold text-white">Add Payout Card</h3>
            <button onclick="closePopup()" class="text-2xl text-gray-400 hover:text-white">&times;</button>
        </div>
        
        <form id="addCardForm" class="space-y-4 p-4 scroll-vertically">
            ${testModeHelper}
            
            <div class="bg-blue-900/20 border border-blue-500/30 rounded-lg p-4 mb-4">
                <div class="flex items-start space-x-3">
                    <svg class="w-6 h-6 text-blue-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path>
                    </svg>
                    <div class="text-sm text-gray-300">
                        <p class="font-semibold text-blue-400 mb-1">✓ Only debit cards accepted</p>
                        <p class="text-gray-400">Credit cards and prepaid cards cannot be used for payouts. Your card information is securely encrypted by Stripe.</p>
                    </div>
                </div>
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

            <div class="bg-gray-700/50 border border-gray-600 rounded-lg p-4">
                <h4 class="font-semibold text-gray-200 mb-2">Supported Cards</h4>
                <div class="flex items-center space-x-4 text-sm text-gray-400">
                    <div class="flex items-center space-x-2">
                        <svg class="w-8 h-5" viewBox="0 0 48 32" fill="none">
                            <rect width="48" height="32" rx="4" fill="#1434CB"/>
                            <text x="24" y="20" fill="white" font-size="14" text-anchor="middle" font-weight="bold">VISA</text>
                        </svg>
                        <span>Visa Debit</span>
                    </div>
                    <div class="flex items-center space-x-2">
                        <svg class="w-8 h-5" viewBox="0 0 48 32" fill="none">
                            <rect width="48" height="32" rx="4" fill="#EB001B"/>
                            <circle cx="18" cy="16" r="10" fill="#FF5F00"/>
                            <circle cx="30" cy="16" r="10" fill="#F79E1B"/>
                        </svg>
                        <span>Mastercard Debit</span>
                    </div>
                </div>
            </div>

            <div id="addCardMessage" class="text-sm text-center min-h-6"></div>

            <div class="flex gap-4 pt-4">
                <button type="button" onclick="closePopup()" class="flex-1 px-4 py-3 bg-gray-600 text-white rounded-lg hover:bg-gray-700">
                    Cancel
                </button>
                <button type="submit" id="addCardSubmitBtn" class="flex-1 px-4 py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 font-semibold">
                    Add Card
                </button>
            </div>
        </form>
    `;

    popupContainer.classList.add('active');

    // Initialize Stripe Elements
    if (typeof Stripe !== 'undefined') {
        try {
            if (!keysResponse || !keysResponse.stripePublishableKey) {
                throw new Error('Stripe keys not available');
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
            
            // Form submission with improved error handling
            document.getElementById('addCardForm').addEventListener('submit', async (e) => {
                e.preventDefault();
                
                const cardHolderName = document.getElementById('cardHolderName').value.trim();
                const submitBtn = document.getElementById('addCardSubmitBtn');
                const errorDiv = document.getElementById('card-errors');
                const messageDiv = document.getElementById('addCardMessage');
                
                if (!cardHolderName) {
                    errorDiv.textContent = 'Please enter cardholder name';
                    return;
                }
                
                submitBtn.disabled = true;
                submitBtn.textContent = 'Processing...';
                errorDiv.textContent = '';
                messageDiv.textContent = '';
                
                try {
                    const {token, error} = await stripe.createToken(cardElement, {
                        name: cardHolderName
                    });
                    
                    if (error) {
                        errorDiv.textContent = error.message;
                        submitBtn.disabled = false;
                        submitBtn.textContent = 'Add Card';
                        return;
                    }
                    
                    console.log('Token created:', token.id, 'Card type:', token.card.funding);
                    
                    const result = await addPayoutCard(token.id, cardHolderName);
                    
                    if (result.message && result.message.includes('successfully')) {
                        messageDiv.className = 'text-sm text-center min-h-6 text-green-400';
                        messageDiv.textContent = '✓ Card added successfully!';
                        showToast('Card added successfully!');
                        
                        setTimeout(() => {
                            closePopup();
                            setTimeout(() => openFinancesPopup(), 400);
                        }, 1500);
                    } else {
                        let errorMessage = result.message || 'Failed to add card';
                        
                        if (errorMessage.includes('debit card')) {
                            errorMessage = '⚠️ Only debit cards accepted. Please use a Visa or Mastercard DEBIT card (not credit or prepaid).';
                        }
                        
                        errorDiv.textContent = errorMessage;
                        submitBtn.disabled = false;
                        submitBtn.textContent = 'Add Card';
                    }
                } catch (err) {
                    console.error('Add card error:', err);
                    errorDiv.textContent = err.message || 'An error occurred. Please try again.';
                    submitBtn.disabled = false;
                    submitBtn.textContent = 'Add Card';
                }
            });
            
        } catch (err) {
            console.error('Stripe initialization error:', err);
            document.getElementById('card-errors').textContent = 'Payment system initialization failed. Please refresh the page.';
        }
    } else {
        console.error('Stripe.js not loaded');
        document.getElementById('card-errors').textContent = 'Payment system not available. Please refresh the page.';
    }
}

/**
 * Confirm and remove a payout card
 */
function confirmRemoveCard(cardId) {
    const popupContent = document.getElementById('popupContent');
    popupContent.innerHTML = `
        <h3 class="text-xl font-semibold mb-2 text-center text-white">Remove Card?</h3>
        <p class="text-center text-gray-400 mb-4">Are you sure you want to remove this payout card? You won't be able to receive withdrawals to this card anymore.</p>
        <div class="flex justify-end gap-4 pt-2">
            <button onclick="navigateToPayoutCards()" class="px-4 py-2 bg-gray-600 rounded-md text-white">Cancel</button>
            <button onclick="handleRemoveCard(${cardId})" class="px-4 py-2 bg-red-600 text-white rounded-md hover:bg-red-700">Remove Card</button>
        </div>
    `;
}

/**
 * Handle card removal
 */
async function handleRemoveCard(cardId) {
    const result = await removePayoutCard(cardId);
    if (result.message && result.message.includes('successfully')) {
        showToast('Card removed successfully!');
        setTimeout(() => navigateToPayoutCards(), 1000);
    } else {
        showToast('Failed to remove card');
    }
}

/**
 * Set a card as default
 */
async function setDefaultCardHandler(cardId) {
    try {
        const result = await setDefaultCard(cardId);
        if (result.message && result.message.includes('successfully')) {
            showToast('Default card updated!');
            setTimeout(() => navigateToPayoutCards(), 1000);
        } else {
            showToast('Failed to update default card');
        }
    } catch (err) {
        console.error('Set default card error:', err);
        showToast('An error occurred');
    }
}

// Add this new function to main.js
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
        <p class="font-medium text-gray-800">Configure Your Authenticator App</p>
        <p class="text-sm text-gray-600 mb-4">Scan the QR code below, then enter the 6-digit code from your app to verify.</p>
        <div class="my-4">
            <img src="${qr_code_url}" alt="2FA QR Code" class="mx-auto border rounded-lg p-2 bg-white">
        </div>
        <p class="text-xs text-gray-500 mb-4">Or enter this key manually:<br><strong class="font-mono text-gray-700">${secret_key}</strong></p>
        <div class="flex items-center gap-2 max-w-xs mx-auto">
            <input type="text" id="2fa-verification-code" placeholder="6-digit code" class="w-full text-center px-3 py-2 border border-gray-300 rounded-md" style="font-size: 16px;" maxlength="6">
        </div>
        <button onclick="closePopup()" class="text-sm text-gray-600 hover:underline mt-4">Cancel</button>
        <button onclick="verifyAndEnable2FA(this)" class="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700">Verify</button>
        <div id="2fa-error" class="text-red-500 text-sm h-4 mt-2"></div>
    `;
}

async function verifyAndEnable2FA(button) {
    const codeInput = document.getElementById('2fa-verification-code');
    const errorDiv = document.getElementById('2fa-error');
    const verificationCode = codeInput.value;

    // Basic validation
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
        // Close and reopen the profile popup to show the updated "Disable 2FA" state.
        closePopup();
        // You would typically call openProfilePopup() again here to refresh its content.
        // For now, we'll just reload the page to simplify.
        setTimeout(() => window.location.reload(), 1500);
    } else {
        errorDiv.textContent = result.message || 'An unknown error occurred.';
        button.disabled = false;
        button.textContent = 'Verify';
    }
}

async function handleSendPhoneCode(button) {
    const phoneInput = document.getElementById('phone-number-input');
    const msgDiv = document.getElementById('phone-message');
    const phoneNumber = phoneInput.value;

    const slRegex = /^\+94\d{9}$/;

    if (!slRegex.test(phoneNumber)) {
        console.log("inside regex");
        msgDiv.className = 'text-sm text-center h-4 text-red-400';
        // Display the specific error message from your PHP script
        msgDiv.textContent = 'Please use international format (e.g., +94771234567).';
        return; // Stop the function before calling the API
    }else{

        button.disabled = true;
        button.textContent = 'Sending...';
        msgDiv.textContent = '';

        const result = await requestPhoneVerification(phoneNumber);

        if (result.message === "A verification code has been sent.") {
            msgDiv.className = 'text-sm text-center h-4 text-green-600';
            msgDiv.textContent = result.message;
            // Dynamically change the form to ask for the code
            document.getElementById('phone-verification-form').innerHTML = `
                <label for="phone-code-input" class="block text-sm font-medium text-gray-700">Verification Code</label>
                <div class="mt-1 flex gap-2">
                    <input type="text" id="phone-code-input" placeholder="6-digit code" required class="block w-full px-3 py-2 border border-gray-300 rounded-md" maxlength="6">
                    <button type="button" onclick="handleVerifyPhoneCode(this)" class="px-4 py-2 text-sm font-medium text-white bg-green-600 rounded-md hover:bg-green-700">Verify</button>
                </div>
            `;
        } else {
            msgDiv.className = 'text-sm text-center h-4 text-red-600';
            msgDiv.textContent = result.message || "An error occurred.";
            button.disabled = false;
            button.textContent = 'Send Code';
        }
    }
}

async function handleVerifyPhoneCode(button) {
    const codeInput = document.getElementById('phone-code-input');
    const msgDiv = document.getElementById('phone-message');
    const code = codeInput.value;

    button.disabled = true;
    button.textContent = 'Verifying...';

    const result = await verifyPhoneCode(code);

    if (result.message === "Phone number verified successfully.") {
        showToast('Phone number verified!');
        // Reload the page to get the updated profileData and refresh the UI
        setTimeout(() => window.location.reload(), 1500);
    } else {
        msgDiv.className = 'text-sm text-center h-4 text-red-600';
        msgDiv.textContent = result.message || "Verification failed.";
        button.disabled = false;
        button.textContent = 'Verify';
    }
}

async function openWithdrawalSelectionPopup() {
    const popupContent = document.getElementById('popupContent');
    const popupContainer = document.getElementById('popupContainer');

    popupContent.classList.add('popup-dark-theme');
    popupContent.style.padding = '20px';
    popupContainer.classList.remove('popup-large');

    // Check payout status
    const payoutStatus = await checkPayoutStatus();
    const hasConnect = payoutStatus?.requirements?.has_connect_account || false;
    const connectVerified = payoutStatus?.requirements?.connect_verified || false;
    const hasCard = payoutStatus?.requirements?.has_payout_card || false;

    popupContent.innerHTML = `
        <div class="flex justify-between items-center mb-6">
            <h3 class="text-2xl font-bold text-white">Withdraw Funds</h3>
            <button onclick="closePopup()" class="text-2xl text-gray-400 hover:text-white">&times;</button>
        </div>

        <div class="bg-blue-900/20 border border-blue-500/30 rounded-lg p-4 mb-6">
            <div class="flex items-center space-x-3">
                <svg class="w-6 h-6 text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
                <div>
                    <p class="font-semibold text-blue-400">Available Balance</p>
                    <p class="text-2xl font-bold text-white">$${(userWallets.real || 0).toFixed(2)}</p>
                </div>
            </div>
        </div>

        <h4 class="text-lg font-semibold text-gray-300 mb-4">Choose Withdrawal Method:</h4>

        <div class="space-y-4">
            <!-- OPTION 1: MANUAL WITHDRAWAL (Always Available) -->
            <div class="bg-gray-800 rounded-lg p-6 border-2 border-gray-700 hover:border-blue-500 cursor-pointer transition-all" onclick="openManualWithdrawalForm()">
                <div class="flex items-start space-x-4">
                    <div class="flex-shrink-0">
                        <div class="w-12 h-12 bg-green-600 rounded-full flex items-center justify-center">
                            <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                        </div>
                    </div>
                    <div class="flex-1">
                        <h5 class="text-xl font-bold text-white mb-2">✅ Manual Withdrawal (Recommended)</h5>
                        <p class="text-gray-400 text-sm mb-3">Submit your card details and our team will process the transfer</p>
                        <div class="space-y-1 text-sm">
                            <p class="text-green-400">✓ No verification required</p>
                            <p class="text-green-400">✓ Simple & easy process</p>
                            <p class="text-green-400">✓ Processed within 24-48 hours</p>
                            <p class="text-green-400">✓ Minimum: $10</p>
                        </div>
                        <button class="mt-4 w-full bg-green-600 hover:bg-green-700 text-white font-semibold py-3 rounded-lg transition-colors">
                            Choose Manual Withdrawal →
                        </button>
                    </div>
                </div>
            </div>

            <!-- OPTION 2: AUTOMATED WITHDRAWAL (Requires Setup) -->
            <div class="bg-gray-800 rounded-lg p-6 border-2 ${hasConnect && connectVerified && hasCard ? 'border-blue-500' : 'border-gray-700'} cursor-pointer hover:border-blue-400 transition-all" 
                 onclick="${hasConnect && connectVerified && hasCard ? 'openAutomatedWithdrawalForm()' : 'showAutomatedSetupSteps()'}">
                <div class="flex items-start space-x-4">
                    <div class="flex-shrink-0">
                        <div class="w-12 h-12 ${hasConnect && connectVerified && hasCard ? 'bg-blue-600' : 'bg-gray-700'} rounded-full flex items-center justify-center">
                            <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                            </svg>
                        </div>
                    </div>
                    <div class="flex-1">
                        <h5 class="text-xl font-bold text-white mb-2">⚡ Automated Withdrawal ${hasConnect && connectVerified && hasCard ? '' : '(Setup Required)'}</h5>
                        <p class="text-gray-400 text-sm mb-3">Instant processing after one-time verification</p>
                        <div class="space-y-1 text-sm">
                            <p class="text-blue-400">✓ Instant processing (30 mins)</p>
                            <p class="text-blue-400">✓ No waiting for admin</p>
                            <p class="text-blue-400">✓ Unlimited withdrawals</p>
                            <p class="${hasConnect && connectVerified && hasCard ? 'text-green-400' : 'text-yellow-400'}">
                                ${hasConnect && connectVerified && hasCard ? '✓ Ready to use!' : '⚠ Requires identity verification'}
                            </p>
                        </div>
                        <button class="mt-4 w-full ${hasConnect && connectVerified && hasCard ? 'bg-blue-600 hover:bg-blue-700' : 'bg-gray-700 hover:bg-gray-600'} text-white font-semibold py-3 rounded-lg transition-colors">
                            ${hasConnect && connectVerified && hasCard ? 'Choose Automated Withdrawal →' : 'Set Up Automated Withdrawals →'}
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <div class="mt-6 text-center text-sm text-gray-500">
            <p>💡 Tip: Start with manual withdrawals, upgrade to automated anytime!</p>
        </div>
    `;

    popupContainer.classList.add('active');
}

/**
 * MANUAL WITHDRAWAL FORM
 */
function openManualWithdrawalForm() {
    const popupContent = document.getElementById('popupContent');
    
    popupContent.innerHTML = `
        <div class="flex justify-between items-center mb-4">
            <button onclick="openWithdrawalSelectionPopup()" class="text-gray-400 hover:text-white">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
                </svg>
            </button>
            <h3 class="text-xl font-bold text-white">Manual Withdrawal</h3>
            <button onclick="closePopup()" class="text-2xl text-gray-400 hover:text-white">&times;</button>
        </div>

        <div class="bg-blue-900/20 border border-blue-500/30 rounded-lg p-4 mb-4">
            <p class="text-sm text-gray-300">
                📋 <strong>How it works:</strong> Enter your withdrawal details below. Our team will review and process your request within 24-48 hours.
            </p>
        </div>

        <form id="manualWithdrawalForm" class="space-y-4">
            <div>
                <label class="block text-sm font-medium text-gray-300 mb-2">Withdrawal Amount (USD)</label>
                <div class="relative">
                    <span class="absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400">$</span>
                    <input type="number" id="manualAmount" min="10" max="${(userWallets.real || 0).toFixed(2)}" step="0.01" required 
                        class="w-full pl-8 pr-4 py-3 bg-gray-700 border border-gray-600 text-white rounded-lg focus:outline-none focus:border-blue-500" 
                        placeholder="Enter amount (min $10)">
                </div>
                <p class="text-xs text-gray-400 mt-1">Available: $${(userWallets.real || 0).toFixed(2)}</p>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-300 mb-2">Card/Account Number</label>
                <input type="text" id="manualCardNumber" required maxlength="19" 
                    class="w-full px-4 py-3 bg-gray-700 border border-gray-600 text-white rounded-lg focus:outline-none focus:border-blue-500" 
                    placeholder="Enter your card or account number">
                <p class="text-xs text-gray-400 mt-1">We only store the last 4 digits for security</p>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-300 mb-2">Cardholder/Account Name</label>
                <input type="text" id="manualCardHolder" required 
                    class="w-full px-4 py-3 bg-gray-700 border border-gray-600 text-white rounded-lg focus:outline-none focus:border-blue-500" 
                    placeholder="Enter name as on card">
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-300 mb-2">Bank Name (Optional)</label>
                <input type="text" id="manualBankName" 
                    class="w-full px-4 py-3 bg-gray-700 border border-gray-600 text-white rounded-lg focus:outline-none focus:border-blue-500" 
                    placeholder="e.g., Bank of America">
            </div>

            <div class="bg-gray-700/50 border border-gray-600 rounded-lg p-4">
                <h4 class="font-semibold text-gray-200 mb-2">📌 Important Notes:</h4>
                <ul class="text-sm text-gray-400 space-y-1">
                    <li>• Processing time: 24-48 hours</li>
                    <li>• You'll receive a notification once processed</li>
                    <li>• Funds typically arrive within 1-3 business days</li>
                    <li>• No withdrawal fees</li>
                </ul>
            </div>

            <div id="manualWithdrawalMessage" class="text-sm text-center h-4"></div>

            <button type="submit" id="manualWithdrawalBtn" class="w-full py-3 bg-green-600 text-white font-semibold rounded-lg hover:bg-green-700 transition-colors">
                Submit Withdrawal Request
            </button>
        </form>
    `;

    // Form submission handler
    document.getElementById('manualWithdrawalForm').addEventListener('submit', async (e) => {
        e.preventDefault();
        
        const amount = parseFloat(document.getElementById('manualAmount').value);
        const cardNumber = document.getElementById('manualCardNumber').value.replace(/\s/g, '');
        const cardHolder = document.getElementById('manualCardHolder').value;
        const bankName = document.getElementById('manualBankName').value;
        
        const submitBtn = document.getElementById('manualWithdrawalBtn');
        const msgDiv = document.getElementById('manualWithdrawalMessage');
        
        submitBtn.disabled = true;
        submitBtn.textContent = 'Submitting...';
        msgDiv.textContent = '';
        
        try {
            const result = await apiFetch('/payments/create_withdrawal.php', {
                method: 'POST',
                body: JSON.stringify({
                    amount,
                    withdrawal_method: 'manual',
                    card_number: cardNumber,
                    card_holder_name: cardHolder,
                    bank_name: bankName
                })
            });
            
            if (result.message && result.message.includes('successfully')) {
                msgDiv.className = 'text-sm text-center h-4 text-green-400';
                msgDiv.textContent = '✅ Request submitted successfully!';
                showToast('Withdrawal request submitted! Check your email for updates.');
                
                setTimeout(() => {
                    closePopup();
                    // Refresh balance
                    getWalletBalance().then(balances => {
                        if (balances && !balances.error) {
                            userWallets = balances;
                            updateWalletUI();
                        }
                    });
                }, 2000);
            } else {
                throw new Error(result.message || 'Submission failed');
            }
        } catch (err) {
            msgDiv.className = 'text-sm text-center h-4 text-red-400';
            msgDiv.textContent = err.message;
            submitBtn.disabled = false;
            submitBtn.textContent = 'Submit Withdrawal Request';
        }
    });
}

/**
 * AUTOMATED WITHDRAWAL SETUP STEPS
 */
function showAutomatedSetupSteps() {
    const popupContent = document.getElementById('popupContent');
    
    popupContent.innerHTML = `
        <div class="flex justify-between items-center mb-4">
            <button onclick="openWithdrawalSelectionPopup()" class="text-gray-400 hover:text-white">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
                </svg>
            </button>
            <h3 class="text-xl font-bold text-white">Set Up Automated Withdrawals</h3>
            <button onclick="closePopup()" class="text-2xl text-gray-400 hover:text-white">&times;</button>
        </div>

        <div class="space-y-4">
            <div class="bg-gradient-to-r from-blue-600 to-purple-600 rounded-lg p-6 text-white">
                <h4 class="text-xl font-bold mb-2">⚡ Get Instant Withdrawals!</h4>
                <p class="text-sm opacity-90">Complete a quick 2-3 minute verification to enable instant withdrawals forever.</p>
            </div>

            <div class="bg-gray-800 rounded-lg p-4 border border-gray-700">
                <h5 class="font-semibold text-white mb-3">📝 Setup Steps:</h5>
                <div class="space-y-3">
                    <div class="flex items-start space-x-3">
                        <div class="flex-shrink-0 w-8 h-8 bg-blue-600 rounded-full flex items-center justify-center text-white font-bold">1</div>
                        <div>
                            <p class="font-medium text-gray-200">Verify Your Identity</p>
                            <p class="text-sm text-gray-400">Provide basic info (name, DOB, address) - takes 2 minutes</p>
                        </div>
                    </div>
                    <div class="flex items-start space-x-3">
                        <div class="flex-shrink-0 w-8 h-8 bg-blue-600 rounded-full flex items-center justify-center text-white font-bold">2</div>
                        <div>
                            <p class="font-medium text-gray-200">Add Your Payout Card</p>
                            <p class="text-sm text-gray-400">Link a debit card to receive funds instantly</p>
                        </div>
                    </div>
                    <div class="flex items-start space-x-3">
                        <div class="flex-shrink-0 w-8 h-8 bg-green-600 rounded-full flex items-center justify-center text-white font-bold">✓</div>
                        <div>
                            <p class="font-medium text-gray-200">Done! Withdraw Anytime</p>
                            <p class="text-sm text-gray-400">Enjoy instant withdrawals with no waiting</p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-gray-700/50 border border-gray-600 rounded-lg p-4">
                <h5 class="font-semibold text-gray-200 mb-2">🔐 Your Security:</h5>
                <ul class="text-sm text-gray-400 space-y-1">
                    <li>✓ Powered by Stripe (trusted by millions)</li>
                    <li>✓ Bank-level encryption</li>
                    <li>✓ Your data is never shared</li>
                    <li>✓ One-time verification</li>
                </ul>
            </div>

            <button onclick="setupStripeAccount(this)" class="w-full py-4 bg-blue-600 hover:bg-blue-700 text-white font-bold rounded-lg transition-colors text-lg">
                🚀 Start Verification Now
            </button>

            <p class="text-center text-sm text-gray-500">
                Or <span class="text-blue-400 cursor-pointer hover:underline" onclick="openManualWithdrawalForm()">use manual withdrawal instead</span>
            </p>
        </div>
    `;
}