// chart.js - Fixed version with scroll limits

// Global chart references
let activeChart = null;
let mainSeries = null;
let historicalData = [];
let activeTradeMarkers = [];
let currentChartType = 'candlestick';
let currentTimeframe = '5s';
let isChartInitializing = false;

// Temporary variables for the style popup
let selectedChartTypeInPopup = 'candlestick';
let selectedTimeframeInPopup = '5s';

// Indicator series storage
let indicatorSeries = {
    sma: null,
    ema: null,
    macd: null,
    rsi: null,
    bbUpper: null,
    bbMiddle: null,
    bbLower: null,
    alligator: { jaw: null, teeth: null, lips: null },
    parabolicSAR: null
};

// Active indicators tracking
let activeIndicators = new Set();
let isFetchingHistory = false;

// Scroll limit configuration
const FUTURE_GAP_BARS = 20; // Number of bars to show as gap on the right

const timeframeSecondsMap = {
  '5s': 5, '10s': 10, '15s': 15, '30s': 30,
  '1m': 60, '5m': 300, '15m': 900, '30m': 1800,
  '1h': 3600, '4h': 14400, '1d': 86400
};

function getTimeframeSeconds(tf) {
  return timeframeSecondsMap[tf] || 60;
}

// ============================================
// CHART INITIALIZATION
// ============================================

/**
 * Initialize the lightweight chart
 */
async function initTradingView() {
    if (isChartInitializing) {
        console.log("Chart is already initializing. Aborting extra call.");
        return;
    }
    isChartInitializing = true;

    try {
        if (!window.selectedPair && !selectedPair) {
            console.error("ERROR: selectedPair is null.");
            showToast("No trading pair selected");
            return;
        }

        const pair = window.selectedPair || selectedPair;

        document.getElementById('btnPredictHigh').textContent = 'Loading Chart...';
        document.getElementById('btnPredictLow').textContent = 'Loading Chart...';
        document.getElementById('btnPredictHigh').disabled = true;
        document.getElementById('btnPredictLow').disabled = true;

        const container = document.getElementById("tradingview-widget");
        if (!container) {
            console.error("FATAL ERROR: Chart container not found.");
            return;
        }

        container.innerHTML = '';

        const chart = LightweightCharts.createChart(container, {
            width: container.clientWidth,
            height: container.clientHeight,
            layout: {
                background: { color: '#1f2937' },
                textColor: '#d1d5db',
            },
            grid: {
                vertLines: { color: '#374151' },
                horzLines: { color: '#374151' },
            },
            crosshair: {
                mode: LightweightCharts.CrosshairMode.Normal,
            },
            rightPriceScale: {
                borderColor: '#4b5563',
            },
            timeScale: {
                borderColor: '#4b5563',
                timeVisible: true,
                secondsVisible: true,
                rightOffset: FUTURE_GAP_BARS, // Add gap on the right
                tickMarkFormatter: (time, tickMarkType, locale) => {
                    const d = new Date(time * 1000);
                    const tf = window.currentTimeframe || currentTimeframe || '1m';
                    if (tf.includes('s') || tf === '1m') {
                        return d.toLocaleTimeString();
                    } else if (tf.includes('m') || tf.includes('h')) {
                        return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
                    } else {
                        return d.toLocaleDateString();
                    }
                }
            },
        });

        const candlestickSeries = chart.addCandlestickSeries({
            upColor: '#22c55e',
            downColor: '#ef4444',
            borderVisible: false,
            wickUpColor: '#22c55e',
            wickDownColor: '#ef4444',
        });

        activeChart = chart;
        mainSeries = candlestickSeries;

        console.log("✅ Chart created successfully");

        const data = await fetchHistoricalData(pair.symbol, currentTimeframe);
        if (data && data.length > 0) {
            historicalData = data;
            candlestickSeries.setData(data);
            console.log(`✅ Loaded ${data.length} historical candles`);

            // Subscribe to visible time range changes for lazy loading and scroll limits
            activeChart.timeScale().subscribeVisibleTimeRangeChange(() => {
                enforceScrollLimits();
                
                const logicalRange = activeChart.timeScale().getVisibleLogicalRange();
                
                if (logicalRange !== null && logicalRange.from < 5 && !isFetchingHistory) {
                    loadMoreHistoricalData();
                }
            });
        } else {
            console.error("No historical data received");
            showToast("Failed to load chart data");
        }

        // Make chart responsive
        new ResizeObserver(entries => {
            if (entries.length === 0 || entries[0].target !== container) return;
            const newRect = entries[0].contentRect;
            chart.applyOptions({ width: newRect.width, height: newRect.height });
        }).observe(container);

        const btnHigh = document.getElementById('btnPredictHigh');
        const btnLow = document.getElementById('btnPredictLow');
        btnHigh.disabled = false;
        btnLow.disabled = false;
        btnHigh.textContent = 'Predict High';
        btnLow.textContent = 'Predict Low';

        const preloader = document.getElementById('preloader');
        if (preloader) {
            preloader.classList.add('fade-out');
            setTimeout(() => {
                preloader.style.display = 'none';
            }, 700);
        }

        console.log("✅ Chart initialization complete");

    } catch (error) {
        console.error("Chart initialization error:", error);
        showToast("Failed to initialize chart");
    } finally {
        isChartInitializing = false;
    }
}

/**
 * Enforces scroll limits to prevent scrolling beyond present time
 */
function enforceScrollLimits() {
    if (!activeChart || !historicalData.length) return;
    
    const timeScale = activeChart.timeScale();
    const logicalRange = timeScale.getVisibleLogicalRange();
    
    if (!logicalRange) return;  // CHANGED: fixed variable name
    
    // Get the current time in seconds
    const nowTimestamp = Math.floor(Date.now() / 1000);
    
    // Calculate the maximum allowed time (present + gap)
    const timeframeSeconds = getTimeframeSeconds(currentTimeframe);
    const maxAllowedTime = nowTimestamp + (FUTURE_GAP_BARS * timeframeSeconds);
    
    // NEW: Get the rightmost visible bar index
    const rightmostIndex = Math.floor(logicalRange.to);
    
    // NEW: Check if the index is beyond our data
    if (rightmostIndex >= historicalData.length) {
        // NEW: Get the time of the last data point
        const lastDataTime = historicalData[historicalData.length - 1].time;
        
        // NEW: Calculate how far beyond the data the user has scrolled
        const barsBeyondData = rightmostIndex - (historicalData.length - 1);
        const estimatedTime = lastDataTime + (barsBeyondData * timeframeSeconds);
        
        // NEW: If scrolled beyond the allowed limit, restrict it
        if (estimatedTime > maxAllowedTime) {
            // NEW: Calculate how many bars we should allow beyond the last data point
            const allowedBarsFromLastData = Math.floor((maxAllowedTime - lastDataTime) / timeframeSeconds);
            const maxAllowedLogicalIndex = (historicalData.length - 1) + allowedBarsFromLastData;
            
            // NEW: Calculate the range width
            const rangeWidth = logicalRange.to - logicalRange.from;
            
            // CHANGED: Reset using setVisibleLogicalRange instead of setVisibleRange
            timeScale.setVisibleLogicalRange({
                from: maxAllowedLogicalIndex - rangeWidth,
                to: maxAllowedLogicalIndex
            });
        }
    }
}


/**
 * Fetch historical candlestick data from Binance
 */
async function fetchHistoricalData(symbol, timeframe) {
    try {
        const intervalMap = {
            '5s': '1m', '10s': '1m', '15s': '1m', '30s': '1m',
            '1m': '1m', '5m': '5m', '15m': '15m', '30m': '30m',
            '1h': '1h', '4h': '4h', '1d': '1d'
        };
        
        const limitMap = {
            '5s': 60, '10s': 60, '15s': 60, '30s': 60,
            '1m': 500, '5m': 500, '15m': 500, '30m': 500,
            '1h': 500, '4h': 500, '1d': 365
        };

        const interval = intervalMap[timeframe] || '1m';
        const limit = limitMap[timeframe] || 500;

        console.log(`Fetching historical data: ${symbol}, ${interval}, limit ${limit}`);

        const response = await fetch(
            `https://api.binance.com/api/v3/klines?symbol=${symbol.toUpperCase()}&interval=${interval}&limit=${limit}`
        );
        
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        const data = await response.json();
        
        return data.map(candle => ({
            time: Math.floor(candle[0] / 1000),
            open: parseFloat(candle[1]),
            high: parseFloat(candle[2]),
            low: parseFloat(candle[3]),
            close: parseFloat(candle[4])
        }));
    } catch (error) {
        console.error("Error fetching historical data:", error);
        return [];
    }
}

async function loadMoreHistoricalData() {
    if (isFetchingHistory || !historicalData.length) {
        return;
    }

    isFetchingHistory = true;
    console.log("Fetching older historical data...");

    try {
        const oldestTimestampMs = historicalData[0].time * 1000;
        
        const intervalMap = {
            '5s': '1m', '10s': '1m', '15s': '1m', '30s': '1m',
            '1m': '1m', '5m': '5m', '15m': '15m', '30m': '30m',
            '1h': '1h', '4h': '4h', '1d': '1d'
        };
        const interval = intervalMap[currentTimeframe] || '1m';
        const limit = 500;

        const pair = window.selectedPair || selectedPair;
        if (!pair) return;

        const response = await fetch(
            `https://api.binance.com/api/v3/klines?symbol=${pair.symbol.toUpperCase()}&interval=${interval}&limit=${limit}&endTime=${oldestTimestampMs - 1}`
        );
        const newRawData = await response.json();

        if (newRawData && newRawData.length > 0) {
            const newCandles = newRawData.map(candle => ({
                time: Math.floor(candle[0] / 1000),
                open: parseFloat(candle[1]),
                high: parseFloat(candle[2]),
                low: parseFloat(candle[3]),
                close: parseFloat(candle[4])
            }));

            historicalData = [...newCandles, ...historicalData];
            
            if (currentChartType === 'line' || currentChartType === 'area') {
                const seriesData = historicalData.map(d => ({ time: d.time, value: d.close }));
                mainSeries.setData(seriesData);
            } else {
                mainSeries.setData(historicalData);
            }
            
            console.log(`Loaded ${newCandles.length} more historical candles`);
        } else {
            console.log("No more historical data to load.");
        }
    } catch (error) {
        console.error("Error fetching more historical data:", error);
    } finally {
        setTimeout(() => {
            isFetchingHistory = false;
        }, 500);
    }
}

/**
 * Open graph style selection popup
 */
function openGraphStylePopup() {
    selectedChartTypeInPopup = currentChartType;
    selectedTimeframeInPopup = currentTimeframe;

    const popupContainer = document.getElementById('popupContainer');
    const popupContent = document.getElementById('popupContent');
    
    popupContent.classList.add('chart-popup-theme');
    popupContent.style.padding = '20px';

    popupContent.innerHTML = `
        <div class="chart-popup">
            <div class="chart-popup-header">
                <h3>Graph style</h3>
                <button onclick="closePopup()" class="chart-popup-close">&times;</button>
            </div>
            
            <div class="chart-popup-body">
                <label class="chart-section-label">Graph type</label>
                <div class="chart-type-grid">
                    <button class="chart-type-btn ${selectedChartTypeInPopup === 'area' ? 'active' : ''}" onclick="selectChartType('area')">
                        <svg class="chart-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M3 17l6-6 4 4 8-8M3 21h18" stroke-width="2"/></svg>
                        <span>Area</span>
                    </button>
                    <button class="chart-type-btn ${selectedChartTypeInPopup === 'line' ? 'active' : ''}" onclick="selectChartType('line')">
                        <svg class="chart-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M3 17l6-6 4 4 8-8" stroke-width="2"/></svg>
                        <span>Line</span>
                    </button>
                    <button class="chart-type-btn ${selectedChartTypeInPopup === 'candlestick' ? 'active' : ''}" onclick="selectChartType('candlestick')">
                        <svg class="chart-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor"><rect x="9" y="6" width="2" height="12" stroke-width="2"/><rect x="8" y="9" width="4" height="6" stroke-width="2"/><rect x="15" y="4" width="2" height="16" stroke-width="2"/><rect x="14" y="8" width="4" height="8" stroke-width="2"/></svg>
                        <span>Candles</span>
                    </button>
                    <button class="chart-type-btn ${selectedChartTypeInPopup === 'bar' ? 'active' : ''}" onclick="selectChartType('bar')">
                        <svg class="chart-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M10 6v12M10 6l-2 2M10 6l2 2M10 18l-2-2M10 18l2-2M16 4v16M16 4l-2 2M16 4l2 2M16 20l-2-2M16 20l2-2" stroke-width="2"/></svg>
                        <span>Bars</span>
                    </button>
                </div>
                
                <div id="timeframeSection" class="${(selectedChartTypeInPopup === 'candlestick' || selectedChartTypeInPopup === 'bar') ? '' : 'hidden'}">
                    <label class="chart-section-label">Time frames</label>
                    <div class="timeframe-grid">
                        ${['5s', '10s', '15s', '30s', '1m', '5m', '15m', '30m', '1h', '4h', '1d'].map(tf => `
                            <button class="timeframe-btn ${selectedTimeframeInPopup === tf ? 'active' : ''}" onclick="selectTimeframe('${tf}')">
                                ${tf.includes('s') ? tf.replace('s', '') : tf}
                                <span class="timeframe-unit">${tf.includes('s') ? 'sec' : (tf.includes('m') ? 'min' : (tf.includes('h') ? 'hr' : 'day'))}</span>
                            </button>
                        `).join('')}
                    </div>
                </div>
            </div>
            
            <button onclick="applyChartStyle()" class="chart-confirm-btn">Confirm</button>
        </div>
    `;

    popupContainer.classList.add('active');
}

/**
 * Select chart type in popup
 */
function selectChartType(type) {
    selectedChartTypeInPopup = type;
    
    document.querySelectorAll('.chart-type-btn').forEach(btn => btn.classList.remove('active'));
    event.currentTarget.classList.add('active');
    
    const timeframeSection = document.getElementById('timeframeSection');
    if (type === 'candlestick' || type === 'bar') {
        timeframeSection.classList.remove('hidden');
    } else {
        timeframeSection.classList.add('hidden');
    }
}

/**
 * Select timeframe in popup
 */
function selectTimeframe(tf) {
    selectedTimeframeInPopup = tf;
    
    document.querySelectorAll('.timeframe-btn').forEach(btn => btn.classList.remove('active'));
    event.currentTarget.classList.add('active');
}

/**
 * Apply selected chart style with validation
 */
async function applyChartStyle() {
    const timeframeChanged = currentTimeframe !== selectedTimeframeInPopup;
    currentChartType = selectedChartTypeInPopup;
    currentTimeframe = selectedTimeframeInPopup;
    
    // Update window globals
    window.currentTimeframe = currentTimeframe;

    if (!activeChart) {
        console.error("Cannot apply chart style: activeChart is null");
        closePopup();
        showToast("Chart not initialized");
        return;
    }
    
    console.log(`Applying chart style: ${currentChartType}, timeframe: ${currentTimeframe}`);
    
    // Remove old series
    if (mainSeries) {
        activeChart.removeSeries(mainSeries);
    }
    
    // Fetch new data if timeframe changed OR if we don't have data
    if ((currentChartType === 'candlestick' || currentChartType === 'bar') && 
        (timeframeChanged || !historicalData.length)) {
        
        const pair = window.selectedPair || selectedPair;
        if (!pair) {
            closePopup();
            showToast("No trading pair selected");
            return;
        }
        
        const data = await fetchHistoricalData(pair.symbol, currentTimeframe);
        if (data && data.length > 0) {
            historicalData = data;
        } else {
            closePopup();
            showToast("Failed to load chart data");
            return;
        }
    }
    
    // Validate we have data
    if (!historicalData || historicalData.length === 0) {
        console.error("No historical data available for chart");
        closePopup();
        showToast("No chart data available");
        return;
    }
    
    // Create new series based on type
    let newSeries;
    const closeData = historicalData.map(d => ({ time: d.time, value: d.close }));
    
    try {
        switch(currentChartType) {
            case 'line':
                newSeries = activeChart.addLineSeries({
                    color: '#3b82f6',
                    lineWidth: 2,
                });
                newSeries.setData(closeData);
                console.log("Line chart applied");
                break;
                
            case 'area':
                newSeries = activeChart.addAreaSeries({
                    topColor: 'rgba(59, 130, 246, 0.4)',
                    bottomColor: 'rgba(59, 130, 246, 0.0)',
                    lineColor: '#3b82f6',
                    lineWidth: 2,
                });
                newSeries.setData(closeData);
                console.log("Area chart applied");
                break;
                
            case 'bar':
                newSeries = activeChart.addBarSeries({
                    upColor: '#22c55e',
                    downColor: '#ef4444',
                });
                newSeries.setData(historicalData);
                console.log("Bar chart applied");
                break;
                
            default: // candlestick
                newSeries = activeChart.addCandlestickSeries({
                    upColor: '#22c55e',
                    downColor: '#ef4444',
                    borderVisible: false,
                    wickUpColor: '#22c55e',
                    wickDownColor: '#ef4444',
                });
                newSeries.setData(historicalData);
                console.log("Candlestick chart applied");
        }
        
        mainSeries = newSeries;

        // Reapply active indicators
        reapplyIndicators();
        
        closePopup();
        showToast(`Chart updated: ${currentChartType}`);
        
    } catch (error) {
        console.error("Error applying chart style:", error);
        showToast("Failed to update chart");
        closePopup();
    }
}

/**
 * Open indicators selection popup
 */
function openIndicatorsPopup() {
    const indicators = [
        { id: 'alligator', name: 'Alligator', desc: 'Trend smoothing mechanism' },
        { id: 'ao', name: 'Awesome Oscillator', desc: 'Measuring market dynamics' },
        { id: 'bollinger', name: 'Bollinger Bands', desc: 'Volatility and price boundaries' },
        { id: 'fractal', name: 'Fractal', desc: 'Breakout pattern indicators' },
        { id: 'macd', name: 'MACD', desc: 'Momentum trend-following' },
        { id: 'sma', name: 'Moving Average', desc: 'Average price over time' },
        { id: 'ema', name: 'EMA', desc: 'Exponential moving average' },
        { id: 'sar', name: 'Parabolic SAR', desc: 'Determines potential reversals' },
        { id: 'rsi', name: 'RSI', desc: 'Relative strength index' }
    ];
    
    const popupContainer = document.getElementById('popupContainer');
    const popupContent = document.getElementById('popupContent');
    
    popupContent.classList.add('chart-popup-theme');
    popupContent.style.padding = '20px';
    
    popupContent.innerHTML = `
        <div class="chart-popup">
            <div class="chart-popup-header">
                <h3>Indicators</h3>
                <button onclick="closePopup()" class="chart-popup-close">&times;</button>
            </div>
            
            <div class="chart-popup-body indicators-body">
                <div class="indicator-tabs">
                    <button class="indicator-tab active">New</button>
                    <button class="indicator-tab">Added</button>
                </div>
                
                <label class="chart-section-label">Available indicators</label>
                <div class="indicators-list">
                    ${indicators.map(ind => `
                        <div class="indicator-item" onclick="toggleIndicatorInList('${ind.id}', this)">
                            <div>
                                <div class="indicator-name">${ind.name}</div>
                                <div class="indicator-desc">${ind.desc}</div>
                            </div>
                            <svg class="indicator-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M9 18l6-6-6-6" stroke-width="2"/></svg>
                            ${activeIndicators.has(ind.id) ? '<span class="indicator-active-badge">●</span>' : ''}
                        </div>
                    `).join('')}
                </div>
            </div>
            
            <button onclick="closePopup()" class="chart-confirm-btn">Close</button>
        </div>
    `;

    popupContainer.classList.add('active');
}

/**
 * Toggle indicator from list
 */
function toggleIndicatorInList(indicatorId, element) {
    const isActive = activeIndicators.has(indicatorId);
    
    if (isActive) {
        removeIndicator(indicatorId);
        activeIndicators.delete(indicatorId);
    } else {
        addIndicator(indicatorId);
        activeIndicators.add(indicatorId);
    }
    
    openIndicatorsPopup();
}

/**
 * Add an indicator to the chart
 */
function addIndicator(indicatorId) {
    if (!activeChart || !historicalData.length) return;
    
    switch(indicatorId) {
        case 'sma':
            if (!indicatorSeries.sma) {
                const smaData = calculateSMA(historicalData, 20);
                indicatorSeries.sma = activeChart.addLineSeries({
                    color: '#eab308',
                    lineWidth: 2,
                });
                indicatorSeries.sma.setData(smaData);
            }
            break;
            
        case 'ema':
            if (!indicatorSeries.ema) {
                const emaData = calculateEMA(historicalData, 20);
                indicatorSeries.ema = activeChart.addLineSeries({
                    color: '#06b6d4',
                    lineWidth: 2,
                });
                indicatorSeries.ema.setData(emaData);
            }
            break;
            
        case 'bollinger':
            if (!indicatorSeries.bbUpper) {
                const bb = calculateBollingerBands(historicalData);
                indicatorSeries.bbUpper = activeChart.addLineSeries({
                    color: '#a855f7',
                    lineWidth: 1,
                });
                indicatorSeries.bbMiddle = activeChart.addLineSeries({
                    color: '#a855f7',
                    lineWidth: 1,
                    lineStyle: 2,
                });
                indicatorSeries.bbLower = activeChart.addLineSeries({
                    color: '#a855f7',
                    lineWidth: 1,
                });
                indicatorSeries.bbUpper.setData(bb.upper);
                indicatorSeries.bbMiddle.setData(bb.middle);
                indicatorSeries.bbLower.setData(bb.lower);
            }
            break;
            
        case 'rsi':
            showToast('RSI added (oscillator indicators require separate panel)');
            break;
            
        case 'macd':
            showToast('MACD added (oscillator indicators require separate panel)');
            break;
            
        default:
            showToast(`${indicatorId} indicator coming soon`);
    }
}

/**
 * Remove an indicator from the chart
 */
function removeIndicator(indicatorId) {
    if (!activeChart) return;
    
    switch(indicatorId) {
        case 'sma':
            if (indicatorSeries.sma) {
                activeChart.removeSeries(indicatorSeries.sma);
                indicatorSeries.sma = null;
            }
            break;
            
        case 'ema':
            if (indicatorSeries.ema) {
                activeChart.removeSeries(indicatorSeries.ema);
                indicatorSeries.ema = null;
            }
            break;
            
        case 'bollinger':
            if (indicatorSeries.bbUpper) {
                activeChart.removeSeries(indicatorSeries.bbUpper);
                activeChart.removeSeries(indicatorSeries.bbMiddle);
                activeChart.removeSeries(indicatorSeries.bbLower);
                indicatorSeries.bbUpper = null;
                indicatorSeries.bbMiddle = null;
                indicatorSeries.bbLower = null;
            }
            break;
    }
}

/**
 * Reapply all active indicators after chart type change
 */
function reapplyIndicators() {
    const activeList = Array.from(activeIndicators);
    
    // Clear all
    Object.values(indicatorSeries).forEach(series => {
        if (series && typeof series.remove === 'function') {
            try { series.remove(); } catch(e) {}
        }
    });
    
    // Reset
    indicatorSeries = {
        sma: null, ema: null, macd: null, rsi: null,
        bbUpper: null, bbMiddle: null, bbLower: null,
        alligator: { jaw: null, teeth: null, lips: null },
        parabolicSAR: null
    };
    
    // Reapply
    activeList.forEach(id => addIndicator(id));
}

// Technical indicator calculations

function calculateSMA(data, period = 20) {
    const sma = [];
    for (let i = period - 1; i < data.length; i++) {
        let sum = 0;
        for (let j = 0; j < period; j++) {
            sum += data[i - j].close;
        }
        sma.push({ time: data[i].time, value: sum / period });
    }
    return sma;
}

function calculateEMA(data, period = 20) {
    const ema = [];
    const multiplier = 2 / (period + 1);
    let sum = 0;
    for (let i = 0; i < period; i++) {
        sum += data[i].close;
    }
    let prevEMA = sum / period;
    ema.push({ time: data[period - 1].time, value: prevEMA });
    
    for (let i = period; i < data.length; i++) {
        const currentEMA = (data[i].close - prevEMA) * multiplier + prevEMA;
        ema.push({ time: data[i].time, value: currentEMA });
        prevEMA = currentEMA;
    }
    return ema;
}

function calculateBollingerBands(data, period = 20, stdDev = 2) {
    const sma = calculateSMA(data, period);
    const upper = [];
    const middle = [];
    const lower = [];
    
    for (let i = period - 1; i < data.length; i++) {
        let sumSquares = 0;
        for (let j = 0; j < period; j++) {
            const diff = data[i - j].close - sma[i - period + 1].value;
            sumSquares += diff * diff;
        }
        const stdDeviation = Math.sqrt(sumSquares / period);
        const time = data[i].time;
        const mid = sma[i - period + 1].value;
        
        middle.push({ time, value: mid });
        upper.push({ time, value: mid + stdDev * stdDeviation });
        lower.push({ time, value: mid - stdDev * stdDeviation });
    }
    
    return { upper, middle, lower };
}

/**
 * Draws the visual representation of a placed trade on the chart.
 */
function drawTradeOnChart(trade, direction) {
    if (!activeChart || !mainSeries) {
        console.error("Cannot draw trade, chart is not ready.");
        return;
    }

    const entryTime = Math.floor(new Date(trade.created_at.replace(' ', 'T') + 'Z').getTime() / 1000);
    const expiryTime = Math.floor(new Date(trade.expires_at.replace(' ', 'T') + 'Z').getTime() / 1000);
    const color = direction === 'HIGH' ? '#22c55e' : '#ef4444';
    
    console.log(`Drawing trade ${trade.id}: ${direction} from ${entryTime} to ${expiryTime}`);
    
    // 1. Create horizontal line from entry to expiry
    const horizontalLineSeries = activeChart.addLineSeries({
        color: color,
        lineWidth: 2,
        lineStyle: LightweightCharts.LineStyle.Solid,
        priceLineVisible: false,
        lastValueVisible: false,
    });
    horizontalLineSeries.setData([
        { time: entryTime, value: trade.entry_price },
        { time: expiryTime, value: trade.entry_price }
    ]);

    // 2. Create vertical line using DOM overlay
    const container = document.getElementById('tradingview-widget');
    const verticalLine = document.createElement('div');
    verticalLine.id = `trade-vertical-${trade.id}`;
    verticalLine.style.position = 'absolute';
    verticalLine.style.top = '0';
    verticalLine.style.bottom = '0';
    verticalLine.style.width = '2px';
    verticalLine.style.backgroundColor = color;
    verticalLine.style.zIndex = '10';
    verticalLine.style.pointerEvents = 'none';
    container.appendChild(verticalLine);

    // Function to update vertical line position
    const updateVerticalLinePosition = () => {
        const timeScale = activeChart.timeScale();
        const coordinate = timeScale.timeToCoordinate(expiryTime);
        if (coordinate !== null) {
            verticalLine.style.left = `${coordinate}px`;
            verticalLine.style.display = 'block';
        } else {
            verticalLine.style.display = 'none';
        }
    };

    updateVerticalLinePosition();
    const subscription = activeChart.timeScale().subscribeVisibleTimeRangeChange(updateVerticalLinePosition);

    // 3. Create the arrow marker
    const newMarker = {
        time: entryTime,
        position: direction === 'HIGH' ? 'belowBar' : 'aboveBar',
        color: color,
        shape: direction === 'HIGH' ? 'arrowUp' : 'arrowDown',
        text: `${parseFloat(trade.bid_amount).toFixed(2)}`,
        id: `trade-${trade.id}`
    };
    
    activeTradeMarkers.push(newMarker);
    mainSeries.setMarkers(activeTradeMarkers);

    // Store references
    trade.visuals = { 
        horizontalLineSeries,
        verticalLine,
        verticalLineSubscription: subscription,
        markerId: newMarker.id 
    };
}

/**
 * Removes the visuals for a settled trade from the chart.
 */
function removeTradeFromChart(trade) {
    if (trade.visuals && activeChart) {
        console.log(`Removing trade ${trade.id} visuals from chart`);
        
        // Remove the horizontal line
        if (trade.visuals.horizontalLineSeries) {
            try {
                activeChart.removeSeries(trade.visuals.horizontalLineSeries);
            } catch (e) {
                console.warn("Error removing horizontal line:", e);
            }
        }
        
        // Remove the vertical line
        if (trade.visuals.verticalLine && trade.visuals.verticalLine.parentNode) {
            trade.visuals.verticalLine.parentNode.removeChild(trade.visuals.verticalLine);
        }
        
        // Unsubscribe from time range changes
        if (trade.visuals.verticalLineSubscription) {
            try {
                activeChart.timeScale().unsubscribeVisibleTimeRangeChange(trade.visuals.verticalLineSubscription);
            } catch (e) {
                console.warn("Error unsubscribing vertical line:", e);
            }
        }
        
        // Remove the arrow marker
        if (trade.visuals.markerId) {
            activeTradeMarkers = activeTradeMarkers.filter(marker => marker.id !== trade.visuals.markerId);
            if (mainSeries) {
                mainSeries.setMarkers(activeTradeMarkers);
            }
        }
        
        // Clear visuals reference
        trade.visuals = null;
    }
}

/**
 * Zooms the chart in
 */
function zoomIn() {
    if (!activeChart) return;
    
    const timeScale = activeChart.timeScale();
    const logicalRange = timeScale.getVisibleLogicalRange();
    
    if (logicalRange) {
        const zoomAmount = Math.floor((logicalRange.to - logicalRange.from) * 0.1);
        
        timeScale.setVisibleLogicalRange({
            from: logicalRange.from + zoomAmount,
            to: logicalRange.to - zoomAmount,
        });
    }
}

/**
 * Zooms the chart out
 */
function zoomOut() {
    if (!activeChart) return;
    
    const timeScale = activeChart.timeScale();
    const logicalRange = timeScale.getVisibleLogicalRange();
    
    if (logicalRange) {
        const zoomAmount = Math.floor((logicalRange.to - logicalRange.from) * 0.1);

        timeScale.setVisibleLogicalRange({
            from: logicalRange.from - zoomAmount,
            to: logicalRange.to + zoomAmount,
        });
    }
}

/**
 * Scrolls the chart backward (to the left/past)
 */
function scrollBackward() {
    if (!activeChart) return;
    
    const timeScale = activeChart.timeScale();
    const logicalRange = timeScale.getVisibleLogicalRange();
    
    if (logicalRange) {
        const rangeWidth = logicalRange.to - logicalRange.from;
        const scrollAmount = Math.floor(rangeWidth * 0.25); // Scroll 25% of visible range
        
        timeScale.setVisibleLogicalRange({
            from: logicalRange.from - scrollAmount,
            to: logicalRange.to - scrollAmount,
        });
    }
}

/**
 * Scrolls the chart forward (to the right/present)
 */
function scrollForward() {
    if (!activeChart) return;
    
    const timeScale = activeChart.timeScale();
    const logicalRange = timeScale.getVisibleLogicalRange();
    
    if (logicalRange) {
        const rangeWidth = logicalRange.to - logicalRange.from;
        const scrollAmount = Math.floor(rangeWidth * 0.25); // Scroll 25% of visible range
        
        // Calculate the proposed new range
        const proposedFrom = logicalRange.from + scrollAmount;
        const proposedTo = logicalRange.to + scrollAmount;
        
        // Calculate the maximum allowed position
        const nowTimestamp = Math.floor(Date.now() / 1000);
        const timeframeSeconds = getTimeframeSeconds(currentTimeframe);
        const maxAllowedTime = nowTimestamp + (FUTURE_GAP_BARS * timeframeSeconds);
        const lastDataTime = historicalData[historicalData.length - 1].time;
        const allowedBarsFromLastData = Math.floor((maxAllowedTime - lastDataTime) / timeframeSeconds);
        const maxAllowedLogicalIndex = (historicalData.length - 1) + allowedBarsFromLastData;
        
        // Check if the proposed position exceeds the limit
        let finalFrom, finalTo;
        if (proposedTo > maxAllowedLogicalIndex) {
            // Clamp to the maximum allowed position
            finalTo = maxAllowedLogicalIndex;
            finalFrom = finalTo - rangeWidth;
        } else {
            finalFrom = proposedFrom;
            finalTo = proposedTo;
        }
        
        timeScale.setVisibleLogicalRange({
            from: finalFrom,
            to: finalTo,
        });
    }
}