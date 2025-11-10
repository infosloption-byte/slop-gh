# 📚 Withdrawal System API Documentation

## Base URL
```
https://your-domain.com/api/v1
```

## Authentication
All endpoints require JWT authentication via the `Authorization` header:
```
Authorization: Bearer {your_jwt_token}
```

---

## 🔷 User Endpoints

### 1. Create Withdrawal Request

Create a new withdrawal request.

**Endpoint**: `POST /payments/withdrawals/create_withdrawal.php`

**Request**:
```json
{
  "amount": 150.00,
  "payout_method_id": 5
}
```

**Response (Auto-Approved < $500)**:
```json
{
  "message": "Withdrawal processed successfully!",
  "request_id": 123,
  "amount": 150.00,
  "method": "PayPal - john@example.com",
  "gateway_transaction_id": "SLO_PAYOUT_1234567890_abc123",
  "status": "APPROVED"
}
```

**Response (Pending Review ≥ $500)**:
```json
{
  "message": "Withdrawal request submitted! As this is a large amount, it will be processed after a brief manual review (within 24 hours).",
  "request_id": 124,
  "status": "PENDING"
}
```

**Errors**:
- `400` - Insufficient funds, below minimum, or exceeded daily limit
- `404` - Payout method not found

**Example**:
```bash
curl -X POST https://your-domain.com/api/v1/payments/withdrawals/create_withdrawal.php \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"amount": 150.00, "payout_method_id": 5}'
```

---

### 2. Get Withdrawal History

Retrieve paginated withdrawal history with filtering.

**Endpoint**: `GET /payments/withdrawals/withdrawal_history.php`

**Query Parameters**:
- `page` (optional, default: 1) - Page number
- `limit` (optional, default: 20, max: 100) - Items per page
- `status` (optional) - Filter by status: PENDING, APPROVED, REJECTED, FAILED

**Response**:
```json
{
  "success": true,
  "data": [
    {
      "id": 123,
      "amount": 150.00,
      "amount_formatted": "$150.00",
      "status": "APPROVED",
      "status_display": {
        "text": "Completed",
        "color": "green"
      },
      "payout_method": {
        "type": "paypal",
        "name": "PayPal - john@example.com",
        "account": "john@exa***"
      },
      "gateway_transaction_id": "SLO_PAYOUT_1234567890",
      "processing_time": "0.5 hours",
      "created_at": "2025-01-10 14:30:00",
      "created_at_formatted": "Jan 10, 2025 02:30 PM",
      "days_ago": 0,
      "processed_at": "2025-01-10 15:00:00",
      "processed_at_formatted": "Jan 10, 2025 03:00 PM"
    }
  ],
  "pagination": {
    "current_page": 1,
    "total_pages": 5,
    "total_records": 87,
    "per_page": 20,
    "has_next_page": true,
    "has_prev_page": false,
    "next_page": 2,
    "prev_page": null
  },
  "summary": {
    "total_withdrawals": 87,
    "pending_count": 2,
    "approved_count": 80,
    "rejected_count": 3,
    "failed_count": 2,
    "total_withdrawn": 12500.00,
    "total_withdrawn_formatted": "$12,500.00",
    "pending_amount": 1000.00,
    "pending_amount_formatted": "$1,000.00"
  },
  "filters": {
    "status": null,
    "available_statuses": ["PENDING", "APPROVED", "REJECTED", "FAILED"]
  }
}
```

**Example**:
```bash
curl "https://your-domain.com/api/v1/payments/withdrawals/withdrawal_history.php?page=1&limit=20&status=APPROVED" \
  -H "Authorization: Bearer YOUR_JWT_TOKEN"
```

---

### 3. Get Withdrawal Analytics

Get detailed analytics for user's withdrawals.

**Endpoint**: `GET /payments/withdrawals/withdrawal_analytics.php`

**Query Parameters**:
- `days` (optional, default: 30, max: 365) - Number of days to analyze

**Response**:
```json
{
  "success": true,
  "period": {
    "days": 30,
    "start_date": "2024-12-11",
    "end_date": "2025-01-10",
    "start_date_formatted": "Dec 11, 2024",
    "end_date_formatted": "Jan 10, 2025"
  },
  "statistics": {
    "total_requests": 25,
    "pending_count": 2,
    "approved_count": 20,
    "rejected_count": 2,
    "failed_count": 1,
    "success_rate": 80.0,
    "total_withdrawn": 3750.00,
    "total_withdrawn_formatted": "$3,750.00",
    "average_withdrawal": 187.50,
    "average_withdrawal_formatted": "$187.50",
    "largest_withdrawal": 500.00,
    "largest_withdrawal_formatted": "$500.00",
    "smallest_withdrawal": 50.00,
    "smallest_withdrawal_formatted": "$50.00"
  },
  "daily_trend": [
    {
      "date": "2025-01-10",
      "date_formatted": "Jan 10",
      "total_requests": 3,
      "approved_count": 3,
      "total_amount": 450.00,
      "total_amount_formatted": "$450.00"
    }
  ],
  "by_payment_method": [
    {
      "method_type": "paypal",
      "display_name": "PayPal",
      "total_requests": 15,
      "approved_count": 14,
      "failed_count": 1,
      "total_amount": 2250.00,
      "total_amount_formatted": "$2,250.00",
      "success_rate": 93.3,
      "success_rate_formatted": "93.3%"
    }
  ],
  "recent_activity": [...],
  "limits": {
    "daily": {
      "limit": 10000.00,
      "used": 450.00,
      "remaining": 9550.00,
      "percentage_used": 4.5
    },
    "monthly": {
      "limit": 50000.00
    },
    "per_withdrawal": {
      "minimum": 10.00,
      "maximum": 50000.00,
      "auto_approve_limit": 500.00
    }
  },
  "processing_times": {
    "average": "2.5 hours",
    "fastest": "15.0 minutes",
    "slowest": "8.0 hours"
  }
}
```

**Example**:
```bash
curl "https://your-domain.com/api/v1/payments/withdrawals/withdrawal_analytics.php?days=30" \
  -H "Authorization: Bearer YOUR_JWT_TOKEN"
```

---

## 🔶 Admin Endpoints

### 4. Admin Dashboard

Get comprehensive dashboard data for admins.

**Endpoint**: `GET /admin/withdrawal_dashboard.php`

**Authorization**: Requires admin privileges

**Query Parameters**:
- `days` (optional, default: 7, max: 90) - Number of days to analyze

**Response**:
```json
{
  "success": true,
  "period": {
    "days": 7,
    "start_date": "2025-01-03",
    "end_date": "2025-01-10"
  },
  "statistics": {
    "total_requests": 150,
    "pending_count": 8,
    "approved_count": 130,
    "rejected_count": 10,
    "failed_count": 2,
    "unique_users": 65,
    "total_withdrawn": 25000.00,
    "total_withdrawn_formatted": "$25,000.00",
    "pending_amount": 4500.00,
    "pending_amount_formatted": "$4,500.00",
    "approval_rate": 86.7
  },
  "pending_withdrawals": {
    "count": 8,
    "items": [
      {
        "id": 125,
        "user": {
          "id": 42,
          "email": "user@example.com",
          "name": "John Doe"
        },
        "amount": 750.00,
        "amount_formatted": "$750.00",
        "payout_method": {
          "type": "paypal",
          "name": "PayPal - john@example.com",
          "account": "john@example.com"
        },
        "balance_check": {
          "current_balance": 1500.00,
          "requested_balance": 1500.00,
          "sufficient": true,
          "balance_changed": false
        },
        "created_at": "2025-01-10 10:30:00",
        "created_at_formatted": "Jan 10, 2025 10:30 AM",
        "hours_waiting": 4.5,
        "priority": "normal",
        "admin_notes": "Pending manual review..."
      }
    ]
  },
  "recent_processed": [...],
  "daily_trend": [...],
  "top_users": [...],
  "by_payment_method": [...],
  "alerts": {
    "high_priority_count": 2,
    "balance_issues_count": 0
  }
}
```

**Example**:
```bash
curl "https://your-domain.com/api/v1/admin/withdrawal_dashboard.php?days=7" \
  -H "Authorization: Bearer ADMIN_JWT_TOKEN"
```

---

### 5. Process Withdrawal (Approve/Reject)

Approve or reject a pending withdrawal.

**Endpoint**: `POST /admin/process_withdrawal.php`

**Authorization**: Requires admin privileges

**Request**:
```json
{
  "request_id": 125,
  "new_status": "APPROVED",
  "admin_notes": "Verified and approved"
}
```

**Fields**:
- `request_id` (required) - Withdrawal request ID
- `new_status` (required) - Either "APPROVED" or "REJECTED"
- `admin_notes` (required) - Processing notes

**Response**:
```json
{
  "message": "Withdrawal request has been approved.",
  "request_id": 125,
  "status": "APPROVED"
}
```

**Errors**:
- `403` - Not authorized (admin privileges required)
- `404` - Withdrawal request not found or already processed
- `400` - Insufficient user balance or invalid input

**Example**:
```bash
curl -X POST https://your-domain.com/api/v1/admin/process_withdrawal.php \
  -H "Authorization: Bearer ADMIN_JWT_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "request_id": 125,
    "new_status": "APPROVED",
    "admin_notes": "Verified and approved"
  }'
```

---

## ⚙️ Configuration Limits

Current system limits (configurable in `WithdrawalConfig.php`):

| Limit | Value |
|-------|-------|
| Minimum Withdrawal | $10.00 |
| Maximum Single Withdrawal | $50,000.00 |
| Auto-Approve Limit | $500.00 |
| Daily Limit per User | $10,000.00 |
| Monthly Limit per User | $50,000.00 |
| Manual Review Threshold | $500.00 |
| KYC Requirement Threshold | $2,000.00 |

---

## 🚨 Error Codes

| Code | Description |
|------|-------------|
| `200` | Success |
| `400` | Bad Request (validation failed, insufficient funds, etc.) |
| `401` | Unauthorized (missing or invalid JWT token) |
| `403` | Forbidden (admin privileges required) |
| `404` | Not Found (resource doesn't exist) |
| `500` | Internal Server Error |

---

## 📝 Status Values

### Withdrawal Request Status

| Status | Description |
|--------|-------------|
| `PENDING` | Awaiting manual review (amount ≥ $500) |
| `APPROVED` | Successfully processed |
| `REJECTED` | Rejected by admin |
| `FAILED` | Payment provider API failure |

---

## 🔔 Notifications

The system automatically sends notifications for:

1. **User Notifications**:
   - Withdrawal approved
   - Withdrawal pending review
   - Withdrawal rejected
   - Withdrawal failed

2. **Admin Notifications**:
   - High-value withdrawal alert (≥ $5,000)

Notifications are sent via:
- In-app notifications (Pusher)
- Email notifications (if configured)

---

## 🧪 Testing

### Test Suite

A comprehensive test suite is available at `tests/WithdrawalTestSuite.php`.

**Usage**:
```php
require_once 'tests/WithdrawalTestSuite.php';

$tester = new WithdrawalTestSuite(
    'http://localhost:8000',
    'your_jwt_token',
    'admin_jwt_token',
    $log
);

// Run all tests
$results = $tester->runAllTests(1); // Pass payout_method_id

// Or run individual tests
$tester->testSmallWithdrawal(50.00, 1);
$tester->testLargeWithdrawal(750.00, 1);
$tester->testInsufficientBalance(999999.00, 1);
```

---

## 📊 Rate Limiting

Currently not implemented. Recommended limits:

- **User Endpoints**: 100 requests/minute
- **Admin Endpoints**: 500 requests/minute

---

## 🔐 Security

### Best Practices

1. **Always use HTTPS** in production
2. **Validate JWT tokens** on every request
3. **Log all admin actions** for audit trail
4. **Use prepared statements** (already implemented)
5. **Implement rate limiting** on production

### PCI Compliance

- ✅ Card numbers never stored in database
- ✅ All card data stored with Stripe (PCI DSS Level 1)
- ✅ Only card tokens and IDs stored locally

---

## 📞 Support

For API support:
- Check logs at `storage/logs/`
- Review `WITHDRAWAL_SYSTEM.md` for detailed system documentation
- Review `DATABASE_SCHEMA.md` for database structure

---

**API Version**: 2.0
**Last Updated**: 2025-01-10
**Author**: Claude (AI Assistant)
