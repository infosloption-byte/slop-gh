<?php

class EmailService {
    private $log;
    private $from_email;
    private $from_name;
    private $smtp_host;
    private $smtp_port;
    private $smtp_username;
    private $smtp_password;
    private $use_smtp;

    public function __construct($log) {
        $this->log = $log;
        $this->from_email = $_ENV['MAIL_FROM_ADDRESS'] ?? 'noreply@sloption.com';
        $this->from_name = $_ENV['MAIL_FROM_NAME'] ?? 'SL Option';
        $this->smtp_host = $_ENV['MAIL_HOST'] ?? '';
        $this->smtp_port = $_ENV['MAIL_PORT'] ?? 587;
        $this->smtp_username = $_ENV['MAIL_USERNAME'] ?? '';
        $this->smtp_password = $_ENV['MAIL_PASSWORD'] ?? '';
        $this->use_smtp = !empty($this->smtp_host);

        $this->log->info('Email Service initialized', [
            'from' => $this->from_email,
            'smtp_enabled' => $this->use_smtp
        ]);
    }

    /**
     * Send withdrawal approved email
     */
    public function sendWithdrawalApproved($to_email, $data) {
        $subject = "Withdrawal Approved - ${$data['amount_formatted']}";

        $body = $this->renderTemplate('withdrawal_approved', [
            'amount' => $data['amount_formatted'],
            'method' => $data['payout_method'],
            'transaction_id' => $data['transaction_id'],
            'processed_at' => $data['processed_at']
        ]);

        return $this->sendEmail($to_email, $subject, $body);
    }

    /**
     * Send withdrawal pending email
     */
    public function sendWithdrawalPending($to_email, $data) {
        $subject = "Withdrawal Pending Review - ${$data['amount_formatted']}";

        $body = $this->renderTemplate('withdrawal_pending', [
            'amount' => $data['amount_formatted'],
            'method' => $data['payout_method'],
            'request_id' => $data['request_id'],
            'expected_processing_time' => $data['sla_hours'] ?? 24
        ]);

        return $this->sendEmail($to_email, $subject, $body);
    }

    /**
     * Send withdrawal rejected email
     */
    public function sendWithdrawalRejected($to_email, $data) {
        $subject = "Withdrawal Rejected - ${$data['amount_formatted']}";

        $body = $this->renderTemplate('withdrawal_rejected', [
            'amount' => $data['amount_formatted'],
            'reason' => $data['rejection_reason'],
            'processed_at' => $data['processed_at']
        ]);

        return $this->sendEmail($to_email, $subject, $body);
    }

    /**
     * Send withdrawal failed email
     */
    public function sendWithdrawalFailed($to_email, $data) {
        $subject = "Withdrawal Failed - ${$data['amount_formatted']}";

        $body = $this->renderTemplate('withdrawal_failed', [
            'amount' => $data['amount_formatted'],
            'method' => $data['payout_method'],
            'error_message' => $data['error_message']
        ]);

        return $this->sendEmail($to_email, $subject, $body);
    }

    /**
     * Send admin alert for high-value withdrawal
     */
    public function sendAdminHighValueAlert($admin_emails, $data) {
        if (empty($admin_emails) || !is_array($admin_emails)) {
            return false;
        }

        $subject = "🚨 High-Value Withdrawal Alert - ${$data['amount_formatted']}";

        $body = $this->renderTemplate('admin_high_value_alert', [
            'amount' => $data['amount_formatted'],
            'user_email' => $data['user_email'],
            'user_name' => $data['user_name'],
            'method' => $data['payout_method'],
            'request_id' => $data['request_id'],
            'created_at' => $data['created_at']
        ]);

        foreach ($admin_emails as $admin_email) {
            $this->sendEmail($admin_email, $subject, $body);
        }

        return true;
    }

    /**
     * Send email using SMTP or PHP mail()
     */
    private function sendEmail($to, $subject, $body) {
        try {
            if ($this->use_smtp) {
                return $this->sendViaSMTP($to, $subject, $body);
            } else {
                return $this->sendViaPHPMail($to, $subject, $body);
            }
        } catch (Exception $e) {
            $this->log->error('Failed to send email', [
                'to' => $to,
                'subject' => $subject,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Send via SMTP using PHPMailer (if available)
     */
    private function sendViaSMTP($to, $subject, $body) {
        // Check if PHPMailer is available
        if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
            $this->log->warning('PHPMailer not available, falling back to PHP mail()');
            return $this->sendViaPHPMail($to, $subject, $body);
        }

        $mail = new PHPMailer\PHPMailer\PHPMailer(true);

        try {
            // Server settings
            $mail->isSMTP();
            $mail->Host = $this->smtp_host;
            $mail->SMTPAuth = true;
            $mail->Username = $this->smtp_username;
            $mail->Password = $this->smtp_password;
            $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = $this->smtp_port;

            // Recipients
            $mail->setFrom($this->from_email, $this->from_name);
            $mail->addAddress($to);

            // Content
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $body;
            $mail->AltBody = strip_tags($body);

            $mail->send();

            $this->log->info('Email sent successfully', [
                'to' => $to,
                'subject' => $subject
            ]);

            return true;

        } catch (Exception $e) {
            $this->log->error('SMTP email failed', [
                'to' => $to,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Send via PHP's mail() function
     */
    private function sendViaPHPMail($to, $subject, $body) {
        $headers = [
            'MIME-Version: 1.0',
            'Content-type: text/html; charset=UTF-8',
            "From: {$this->from_name} <{$this->from_email}>",
            'Reply-To: ' . $this->from_email,
            'X-Mailer: PHP/' . phpversion()
        ];

        $success = mail($to, $subject, $body, implode("\r\n", $headers));

        if ($success) {
            $this->log->info('Email sent successfully (PHP mail)', [
                'to' => $to,
                'subject' => $subject
            ]);
        } else {
            $this->log->error('PHP mail() failed', [
                'to' => $to,
                'subject' => $subject
            ]);
        }

        return $success;
    }

    /**
     * Render email template
     */
    private function renderTemplate($template, $data) {
        $templates = [
            'withdrawal_approved' => $this->getApprovedTemplate($data),
            'withdrawal_pending' => $this->getPendingTemplate($data),
            'withdrawal_rejected' => $this->getRejectedTemplate($data),
            'withdrawal_failed' => $this->getFailedTemplate($data),
            'admin_high_value_alert' => $this->getAdminAlertTemplate($data)
        ];

        return $templates[$template] ?? '';
    }

    /**
     * Approved email template
     */
    private function getApprovedTemplate($data) {
        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #4CAF50; color: white; padding: 20px; text-align: center; }
        .content { background: #f9f9f9; padding: 20px; margin-top: 20px; }
        .amount { font-size: 24px; font-weight: bold; color: #4CAF50; }
        .details { background: white; padding: 15px; margin-top: 15px; border-left: 4px solid #4CAF50; }
        .footer { text-align: center; margin-top: 20px; color: #666; font-size: 12px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>✅ Withdrawal Approved</h1>
        </div>
        <div class="content">
            <p>Good news! Your withdrawal has been successfully processed.</p>

            <div class="amount">{$data['amount']}</div>

            <div class="details">
                <p><strong>Payment Method:</strong> {$data['method']}</p>
                <p><strong>Transaction ID:</strong> {$data['transaction_id']}</p>
                <p><strong>Processed At:</strong> {$data['processed_at']}</p>
            </div>

            <p>The funds should arrive in your account within the expected timeframe for your chosen payment method.</p>
        </div>
        <div class="footer">
            <p>Thank you for trading with SL Option</p>
            <p>If you have any questions, please contact our support team.</p>
        </div>
    </div>
</body>
</html>
HTML;
    }

    /**
     * Pending email template
     */
    private function getPendingTemplate($data) {
        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #FF9800; color: white; padding: 20px; text-align: center; }
        .content { background: #f9f9f9; padding: 20px; margin-top: 20px; }
        .amount { font-size: 24px; font-weight: bold; color: #FF9800; }
        .details { background: white; padding: 15px; margin-top: 15px; border-left: 4px solid #FF9800; }
        .footer { text-align: center; margin-top: 20px; color: #666; font-size: 12px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>⏳ Withdrawal Pending Review</h1>
        </div>
        <div class="content">
            <p>Your withdrawal request has been received and is pending manual review.</p>

            <div class="amount">{$data['amount']}</div>

            <div class="details">
                <p><strong>Payment Method:</strong> {$data['method']}</p>
                <p><strong>Request ID:</strong> {$data['request_id']}</p>
                <p><strong>Expected Processing:</strong> Within {$data['expected_processing_time']} hours</p>
            </div>

            <p>This is a standard procedure for large withdrawals. Our team will process your request shortly.</p>
        </div>
        <div class="footer">
            <p>Thank you for trading with SL Option</p>
        </div>
    </div>
</body>
</html>
HTML;
    }

    /**
     * Rejected email template
     */
    private function getRejectedTemplate($data) {
        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #f44336; color: white; padding: 20px; text-align: center; }
        .content { background: #f9f9f9; padding: 20px; margin-top: 20px; }
        .amount { font-size: 24px; font-weight: bold; color: #f44336; }
        .details { background: white; padding: 15px; margin-top: 15px; border-left: 4px solid #f44336; }
        .footer { text-align: center; margin-top: 20px; color: #666; font-size: 12px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>❌ Withdrawal Rejected</h1>
        </div>
        <div class="content">
            <p>We're sorry, but your withdrawal request has been rejected.</p>

            <div class="amount">{$data['amount']}</div>

            <div class="details">
                <p><strong>Reason:</strong> {$data['reason']}</p>
                <p><strong>Processed At:</strong> {$data['processed_at']}</p>
            </div>

            <p>The funds have been returned to your account balance. If you believe this was an error, please contact our support team.</p>
        </div>
        <div class="footer">
            <p>SL Option Support Team</p>
        </div>
    </div>
</body>
</html>
HTML;
    }

    /**
     * Failed email template
     */
    private function getFailedTemplate($data) {
        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #f44336; color: white; padding: 20px; text-align: center; }
        .content { background: #f9f9f9; padding: 20px; margin-top: 20px; }
        .amount { font-size: 24px; font-weight: bold; color: #f44336; }
        .details { background: white; padding: 15px; margin-top: 15px; border-left: 4px solid #f44336; }
        .footer { text-align: center; margin-top: 20px; color: #666; font-size: 12px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>⚠️ Withdrawal Failed</h1>
        </div>
        <div class="content">
            <p>Unfortunately, your withdrawal could not be processed due to a technical error.</p>

            <div class="amount">{$data['amount']}</div>

            <div class="details">
                <p><strong>Payment Method:</strong> {$data['method']}</p>
                <p><strong>Error:</strong> {$data['error_message']}</p>
            </div>

            <p>The funds have been returned to your account balance. Please verify your payment method details and try again, or contact support for assistance.</p>
        </div>
        <div class="footer">
            <p>SL Option Support Team</p>
        </div>
    </div>
</body>
</html>
HTML;
    }

    /**
     * Admin alert template
     */
    private function getAdminAlertTemplate($data) {
        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #2196F3; color: white; padding: 20px; text-align: center; }
        .content { background: #f9f9f9; padding: 20px; margin-top: 20px; }
        .amount { font-size: 28px; font-weight: bold; color: #2196F3; }
        .details { background: white; padding: 15px; margin-top: 15px; border-left: 4px solid #2196F3; }
        .footer { text-align: center; margin-top: 20px; color: #666; font-size: 12px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🚨 High-Value Withdrawal Alert</h1>
        </div>
        <div class="content">
            <p>A high-value withdrawal request requires your attention.</p>

            <div class="amount">{$data['amount']}</div>

            <div class="details">
                <p><strong>User:</strong> {$data['user_name']} ({$data['user_email']})</p>
                <p><strong>Payment Method:</strong> {$data['method']}</p>
                <p><strong>Request ID:</strong> {$data['request_id']}</p>
                <p><strong>Submitted:</strong> {$data['created_at']}</p>
            </div>

            <p>Please review and process this withdrawal request in the admin dashboard.</p>
        </div>
        <div class="footer">
            <p>SL Option Admin System</p>
        </div>
    </div>
</body>
</html>
HTML;
    }
}
?>
