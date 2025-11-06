/**
 * ADMIN WITHDRAWAL MANAGEMENT
 * Add this to your admin.js
 */

let allWithdrawals = [];

async function loadWithdrawals() {
    try {
        // Build query params
        const params = new URLSearchParams({
            filter_status: document.getElementById('withdrawalStatusFilter')?.value || '',
            search_email: document.getElementById('withdrawalEmailSearch')?.value || '',
            sort_by: 'requested_at',
            sort_order: 'DESC'
        });

        const response = await fetch(`/api/v1/admin/withdrawals.php?${params}`, {
            credentials: 'include'
        });
        
        if (!response.ok) throw new Error('Failed to load withdrawals');
        
        allWithdrawals = await response.json();
        
        // Filter by method if selected
        const methodFilter = document.getElementById('withdrawalMethodFilter')?.value;
        let filteredWithdrawals = allWithdrawals;
        
        if (methodFilter) {
            filteredWithdrawals = allWithdrawals.filter(w => {
                // Add withdrawal_method to your API response
                return (w.payout_method?.includes('manual') && methodFilter === 'manual') ||
                       (w.payout_method?.includes('automated') && methodFilter === 'automated');
            });
        }
        
        renderWithdrawals(filteredWithdrawals);
        updateWithdrawalStats(allWithdrawals);
        
    } catch (error) {
        console.error('Error loading withdrawals:', error);
        document.getElementById('withdrawalList').innerHTML = `
            <div class="bg-red-900/20 border border-red-500/30 rounded-lg p-4 text-red-400">
                ❌ Failed to load withdrawals: ${error.message}
            </div>
        `;
    }
}

function renderWithdrawals(withdrawals) {
    const container = document.getElementById('withdrawalList');
    
    if (withdrawals.length === 0) {
        container.innerHTML = `
            <div class="bg-gray-800 rounded-lg p-8 text-center text-gray-400">
                📭 No withdrawal requests found
            </div>
        `;
        return;
    }

    container.innerHTML = withdrawals.map(w => {
        const isManual = w.payout_method?.includes('manual');
        const isPending = w.status === 'PENDING';
        
        const statusColors = {
            'PENDING': 'bg-yellow-600',
            'APPROVED': 'bg-green-600',
            'REJECTED': 'bg-red-600'
        };
        
        const methodBadge = isManual 
            ? '<span class="px-3 py-1 bg-green-600 text-white text-xs rounded-full">📋 Manual</span>'
            : '<span class="px-3 py-1 bg-blue-600 text-white text-xs rounded-full">⚡ Automated</span>';

        return `
            <div class="bg-gray-800 rounded-lg border-2 ${isPending ? 'border-yellow-500' : 'border-gray-700'} overflow-hidden">
                <!-- Header -->
                <div class="p-6 ${isPending ? 'bg-yellow-900/20' : ''}">
                    <div class="flex justify-between items-start mb-4">
                        <div>
                            <h3 class="text-xl font-bold text-white mb-1">Request #${w.id}</h3>
                            <p class="text-gray-400">
                                ${w.email} 
                                <span class="text-gray-500">(User ID: ${w.user_id})</span>
                            </p>
                            <p class="text-sm text-gray-500 mt-1">
                                Requested: ${new Date(w.requested_at).toLocaleString()}
                            </p>
                        </div>
                        <div class="text-right">
                            <p class="text-3xl font-bold text-white mb-2">$${w.amount.toFixed(2)}</p>
                            <div class="flex items-center justify-end space-x-2">
                                ${methodBadge}
                                <span class="px-3 py-1 ${statusColors[w.status]} text-white text-xs rounded-full font-semibold">
                                    ${w.status}
                                </span>
                            </div>
                        </div>
                    </div>

                    ${isPending ? `
                        <!-- Withdrawal Details -->
                        <div class="bg-gray-700/50 rounded-lg p-4 mb-4">
                            <h4 class="font-semibold text-gray-200 mb-3">💳 Payment Details:</h4>
                            <div class="grid grid-cols-2 gap-4 text-sm">
                                <div>
                                    <p class="text-gray-400">Card/Account Last 4:</p>
                                    <p class="text-white font-mono text-lg">****${w.manual_card_number_last4 || w.card_last4 || 'N/A'}</p>
                                </div>
                                <div>
                                    <p class="text-gray-400">Cardholder Name:</p>
                                    <p class="text-white">${escapeHTML(w.manual_card_holder_name || w.card_holder_name || 'N/A')}</p>
                                </div>
                                ${w.manual_bank_name ? `
                                <div>
                                    <p class="text-gray-400">Bank:</p>
                                    <p class="text-white">${escapeHTML(w.manual_bank_name)}</p>
                                </div>
                                ` : ''}
                            </div>
                        </div>

                        <!-- Action Buttons -->
                        <div class="flex gap-4">
                            ${isManual ? `
                                <!-- Manual Approval -->
                                <button onclick="approveManualWithdrawal(${w.id})" 
                                    class="flex-1 bg-green-600 hover:bg-green-700 px-6 py-3 rounded-lg font-semibold text-white transition-colors">
                                    ✅ Mark as Sent & Complete
                                </button>
                            ` : `
                                <!-- Automated Approval -->
                                <button onclick="approveAutomatedWithdrawal(${w.id})" 
                                    class="flex-1 bg-blue-600 hover:bg-blue-700 px-6 py-3 rounded-lg font-semibold text-white transition-colors">
                                    ⚡ Process via Stripe
                                </button>
                            `}
                            <button onclick="rejectWithdrawal(${w.id})" 
                                class="flex-1 bg-red-600 hover:bg-red-700 px-6 py-3 rounded-lg font-semibold text-white transition-colors">
                                ❌ Reject
                            </button>
                        </div>
                    ` : `
                        <!-- Processed Info -->
                        <div class="bg-gray-700/50 rounded-lg p-4">
                            <p class="text-sm text-gray-400">
                                Processed: ${new Date(w.processed_at).toLocaleString()}
                            </p>
                            ${w.gateway_transaction_id ? `
                                <p class="text-sm text-gray-400 mt-1">
                                    Transaction ID: <code class="text-blue-400">${w.gateway_transaction_id}</code>
                                </p>
                            ` : ''}
                            ${w.admin_notes ? `
                                <p class="text-sm text-gray-400 mt-1">
                                    Admin Notes: ${escapeHTML(w.admin_notes)}
                                </p>
                            ` : ''}
                        </div>
                    `}
                </div>
            </div>
        `;
    }).join('');
}

async function approveManualWithdrawal(requestId) {
    const notes = prompt('Add completion notes (optional - e.g., "Sent via Bank Transfer Ref: ABC123"):');
    if (notes === null) return; // Cancelled
    
    if (!confirm('Confirm that you have ALREADY sent the money to the user?')) {
        return;
    }

    try {
        const response = await fetch('/api/v1/admin/process_withdrawal.php', {
            method: 'POST',
            credentials: 'include',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                request_id: requestId,
                new_status: 'APPROVED',
                admin_notes: notes || 'Manual withdrawal processed'
            })
        });

        const result = await response.json();
        
        if (response.ok) {
            alert('✅ Withdrawal marked as completed!');
            loadWithdrawals(); // Reload list
        } else {
            alert('❌ Error: ' + (result.message || 'Failed to process'));
        }
    } catch (error) {
        alert('❌ Network error: ' + error.message);
    }
}

async function approveAutomatedWithdrawal(requestId) {
    if (!confirm('Process this withdrawal via Stripe? The payout will be sent to the user\'s card.')) {
        return;
    }

    const loadingDiv = document.createElement('div');
    loadingDiv.className = 'fixed top-4 right-4 bg-blue-600 text-white px-6 py-4 rounded-lg shadow-lg z-50';
    loadingDiv.textContent = '⏳ Processing via Stripe...';
    document.body.appendChild(loadingDiv);

    try {
        const response = await fetch('/api/v1/admin/process_withdrawal.php', {
            method: 'POST',
            credentials: 'include',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                request_id: requestId,
                new_status: 'APPROVED',
                admin_notes: 'Automated payout via Stripe'
            })
        });

        const result = await response.json();
        document.body.removeChild(loadingDiv);
        
        if (response.ok) {
            alert(`✅ Withdrawal processed via Stripe!\n\nPayout ID: ${result.gateway_transaction_id || 'N/A'}`);
            loadWithdrawals();
        } else {
            alert('❌ Stripe Error: ' + (result.message || 'Failed to process'));
        }
    } catch (error) {
        document.body.removeChild(loadingDiv);
        alert('❌ Network error: ' + error.message);
    }
}

async function rejectWithdrawal(requestId) {
    const reason = prompt('Reason for rejection:');
    if (!reason) {
        alert('Please provide a rejection reason');
        return;
    }

    try {
        const response = await fetch('/api/v1/admin/process_withdrawal.php', {
            method: 'POST',
            credentials: 'include',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                request_id: requestId,
                new_status: 'REJECTED',
                admin_notes: reason
            })
        });

        const result = await response.json();
        
        if (response.ok) {
            alert('✅ Withdrawal rejected. User notified.');
            loadWithdrawals();
        } else {
            alert('❌ Error: ' + (result.message || 'Failed to reject'));
        }
    } catch (error) {
        alert('❌ Network error: ' + error.message);
    }
}

function updateWithdrawalStats(withdrawals) {
    const pending = withdrawals.filter(w => w.status === 'PENDING');
    const today = new Date().toDateString();
    const approvedToday = withdrawals.filter(w => 
        w.status === 'APPROVED' && 
        new Date(w.processed_at).toDateString() === today
    );

    document.getElementById('pendingCount').textContent = pending.length;
    document.getElementById('approvedTodayCount').textContent = approvedToday.length;
    
    const pendingAmount = pending.reduce((sum, w) => sum + w.amount, 0);
    document.getElementById('pendingAmount').textContent = '$' + pendingAmount.toFixed(2);
    
    const processedAmount = approvedToday.reduce((sum, w) => sum + w.amount, 0);
    document.getElementById('processedTodayAmount').textContent = '$' + processedAmount.toFixed(2);
}

// Auto-refresh every 30 seconds
setInterval(loadWithdrawals, 30000);

// Load on page load
if (document.getElementById('withdrawalList')) {
    loadWithdrawals();
}

// Add filter event listeners
document.getElementById('withdrawalStatusFilter')?.addEventListener('change', loadWithdrawals);
document.getElementById('withdrawalMethodFilter')?.addEventListener('change', loadWithdrawals);
document.getElementById('withdrawalEmailSearch')?.addEventListener('input', 
    debounce(loadWithdrawals, 500)
);

function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}