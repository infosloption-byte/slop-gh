<?php
// /api/users/generate_2fa_secret.php
session_start();

require_once __DIR__ . '/../../../config/api_secure_bootstrap.php';

use PragmaRX\Google2FA\Google2FA;
// --- Use the new Simple QrCode Generator ---
use SimpleSoftwareIO\QrCode\Generator;

try {
    $user_id = authenticate_and_track_user($conn);
    $google2fa = new Google2FA();
    $secret_key = $google2fa->generateSecretKey();
    $_SESSION['2fa_temp_secret'] = $secret_key;

    $stmt = $conn->prepare("SELECT email FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $user_email = $user['email'];
    $stmt->close();

    $otpauth_url = $google2fa->getQRCodeUrl(
        'SL Option',
        $user_email,
        $secret_key
    );

    // --- NEW, SIMPLER LOGIC ---
    $qrCodeGenerator = new Generator;
    $qrCodeImageString = $qrCodeGenerator->format('png')->size(200)->margin(2)->generate($otpauth_url);
    $qr_code_data_uri = 'data:image/png;base64,' . base64_encode($qrCodeImageString);
    // --- END NEW LOGIC ---

    http_response_code(200);
    echo json_encode([
        'qr_code_url' => $qr_code_data_uri,
        'secret_key' => $secret_key
    ]);

} catch (Exception $e) {
    http_response_code(500);
    error_log("2FA Generation Error: " . $e->getMessage());
    echo json_encode(["message" => "Could not generate 2FA secret.","error"=>$e->getMessage()]);
}
?>