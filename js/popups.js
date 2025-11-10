/**
 * main-popups.js - Popup UI Components
 * Dependencies: main-core.js (requires global state)
 * Handles: All popup dialogs (pair selector, timeframe, deal details, notifications)
 */

// ============================================================================
// POPUP UTILITIES
// ============================================================================

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
 * Helper function to show tabs in popups
 */
function showPopupTab(tabName, clickedElement) {
    document.querySelectorAll('.popup-content').forEach(el => el.classList.add('hidden'));
    document.querySelectorAll('.popup-tab').forEach(el => el.classList.remove('active'));
    
    document.getElementById(tabName + '-content').classList.remove('hidden');
    clickedElement.classList.add('active');
}

// ============================================================================
// PAIR SELECTOR POPUP
// ============================================================================

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

// ============================================================================
// TIMEFRAME POPUP
// ============================================================================

/**
 * Timeframe popup with wheel picker
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
        const s_index = Math.round(s / 5);
        secondsCol.scrollTop = s_index * itemHeight;
    }

    document.getElementById('confirmTimeframe').addEventListener('click', () => {
        const isBasicActive = !document.getElementById('basic-content').classList.contains('hidden');
        
        if (isBasicActive) {
            window.dealDurationInSeconds = window.tempTime;
        } else {
            const h_val = Math.round(hoursCol.scrollTop / itemHeight);
            const m_val = Math.round(minutesCol.scrollTop / itemHeight);
            const s_val = Math.round(secondsCol.scrollTop / itemHeight) * 5;
            
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
 * Show timeframe tab (basic/custom)
 */
function showTimeframeTab(tabName, clickedElement) {
    document.querySelectorAll('.timeframe-content').forEach(el => el.classList.add('hidden'));
    document.querySelectorAll('.popup-tab').forEach(el => el.classList.remove('active'));
    
    document.getElementById(tabName + '-content').classList.remove('hidden');
    clickedElement.classList.add('active');
}

// ============================================================================
// DEAL DETAILS POPUP
// ============================================================================

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

// ============================================================================
// NOTIFICATIONS POPUP
// ============================================================================

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