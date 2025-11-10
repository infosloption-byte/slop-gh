/**
 * main-trading.js - Trading & Deal Management
 * Dependencies: main-core.js (requires global state variables)
 * Handles: Trade placement, trade timers, deal rendering, numpad controls
 */

// ============================================================================
// TRADE PLACEMENT
// ============================================================================

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

// ============================================================================
// TRADE TIMER & SETTLEMENT
// ============================================================================

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

// ============================================================================
// DEAL RENDERING
// ============================================================================

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

// ============================================================================
// NUMPAD CONTROLS
// ============================================================================

/**
 * Show numpad for bid amount entry
 */
function showNumpad() {
    document.getElementById('prediction-buttons').classList.add('hidden');
    document.getElementById('numpad').classList.remove('hidden');
}

/**
 * Confirm bid amount and hide numpad
 */
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

/**
 * Append digit to bid amount
 */
function appendDigit(digit) {
    const bidValueEl = document.getElementById('bidValue');
    const currentValue = bidValueEl.value;

    if (currentValue === '0') {
        bidValueEl.value = digit;
    } else {
        bidValueEl.value += digit;
    }
}

/**
 * Handle backspace on numpad
 */
function handleBackspace() {
    const bidValueEl = document.getElementById('bidValue');
    const currentValue = bidValueEl.value;

    let newValue = currentValue.slice(0, -1);
    if (newValue === '') {
        newValue = '0';
    }
    bidValueEl.value = newValue;
}

// ============================================================================
// RECHARGE POPUP
// ============================================================================

/**
 * Open demo wallet recharge popup
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

/**
 * Handle demo wallet recharge
 */
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