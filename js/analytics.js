/**
 * main-analytics.js - Analytics & Performance Tracking
 * Dependencies: main-core.js (requires global state), Chart.js library
 * Handles: Performance analytics, volume charts, pair distribution
 */

// ============================================================================
// CHART INSTANCES
// ============================================================================

let volumeChart, pairChart;
let currentVolumeFilter = '7d';

// ============================================================================
// ANALYTICS UPDATE
// ============================================================================

/**
 * Update analytics with current trading data
 */
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

    // Update KPI displays
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

    // Update charts
    updateVolumeChart(currentVolumeFilter);
    updatePairChart(closedTrades);
}

// ============================================================================
// VOLUME CHART
// ============================================================================

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

    // Render the chart
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

// ============================================================================
// PAIR DISTRIBUTION CHART
// ============================================================================

/**
 * Update pair volume pie chart
 */
function updatePairChart(closedTrades) {
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