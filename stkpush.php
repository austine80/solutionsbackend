<?php
// Custom logging function
function log_to_console($message) {
    error_log("[M-PESA DEBUG] " . $message);
}

// ===== CORS Headers (MUST be first) =====
header('Access-Control-Allow-Origin: https://onlinetasks.netlify.app');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

date_default_timezone_set('Africa/Nairobi');

// ===== Load credentials from environment =====
$consumerKey = getenv('CONSUMER_KEY');
$consumerSecret = getenv('CONSUMER_SECRET');
$shortCode = getenv('SHORTCODE');
$tillnumber = getenv('TILLNUMBER');
$passkey = getenv('PASSKEY');

if (!$consumerKey || !$consumerSecret || !$shortCode || !$tillnumber || !$passkey) {
    log_to_console("FATAL: Missing environment variables.");
    echo json_encode([
        "error" => "Server misconfigured — missing M-Pesa credentials."
    ]);
    exit;
}

// ===== Validate input =====
$phone = $_POST['phone'] ?? '';
if (!$phone) {
    echo json_encode(["error" => "Phone number is required"]);
    exit;
}

// Normalize phone
$phone = preg_replace('/[^0-9]/', '', $phone);
if (strlen($phone) == 10 && substr($phone, 0, 1) == "0") {
    $phone = "254" . substr($phone, 1);
} elseif (strlen($phone) == 9) {
    $phone = "254" . $phone;
}
if (strlen($phone) != 12 || substr($phone, 0, 3) != "254") {
    echo json_encode(["error" => "Invalid phone format. Use 2547XXXXXXXX"]);
    exit;
}

// ===== Generate transaction ID & save =====
$txn_id = md5($phone . time());
$amount = 599; // Fixed amount for activation

// Ensure directory exists
if (!is_dir('transactions')) {
    mkdir('transactions', 0777, true);
}

// Save pending transaction
file_put_contents("transactions/{$txn_id}.json", json_encode([
    'phone' => $phone,
    'amount' => $amount,
    'status' => 'pending',
    'created_at' => time()
]));

log_to_console("New STK Push: Phone={$phone}, TxnID={$txn_id}");

// ===== Callback URL (NO TRAILING SPACE!) =====
$callbackUrl = "https://solutionsbackend-uv0s.onrender.com/callback.php";

// ===== Step 1: Get Access Token =====
$credentials = base64_encode($consumerKey . ":" . $consumerSecret);
$curl = curl_init();
curl_setopt_array($curl, [
    CURLOPT_URL => "https://api.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials",
    CURLOPT_HTTPHEADER => ["Authorization: Basic " . $credentials],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_SSL_VERIFYPEER => true
]);

$tokenResponse = curl_exec($curl);
curl_close($curl);

$response = json_decode($tokenResponse, true);
if (!isset($response['access_token'])) {
    log_to_console("Token error: " . $tokenResponse);
    echo json_encode(["error" => "Failed to authenticate with M-Pesa"]);
    exit;
}
$access_token = $response['access_token'];

// ===== Step 2: Prepare STK Push =====
$timestamp = date("YmdHis");
$password = base64_encode($shortCode . $passkey . $timestamp);

$data = [
    "BusinessShortCode" => (int)$shortCode,
    "Password" => $password,
    "Timestamp" => $timestamp,
    "TransactionType" => "CustomerBuyGoodsOnline",
    "Amount" => $amount,
    "PartyA" => $phone,
    "PartyB" => (int)$tillnumber,
    "PhoneNumber" => $phone,
    "CallBackURL" => $callbackUrl,
    "AccountReference" => "Activation",
    "TransactionDesc" => "Account activation fee"
];

// ===== Step 3: Send STK Push =====
$curl = curl_init();
curl_setopt_array($curl, [
    CURLOPT_URL => "https://api.safaricom.co.ke/mpesa/stkpush/v1/processrequest",
    CURLOPT_HTTPHEADER => [
        "Authorization: Bearer " . $access_token,
        "Content-Type: application/json"
    ],
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($data),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_SSL_VERIFYPEER => true
]);

$stkResponse = curl_exec($curl);
$httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
curl_close($curl);

log_to_console("STK Response (HTTP {$httpCode}): " . $stkResponse);

// ===== Always return JSON =====
$result = json_decode($stkResponse, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    $result = ["error" => "Invalid M-Pesa response", "raw" => $stkResponse];
}

// Include txn_id in response for frontend polling
$result['txn_id'] = $txn_id;
echo json_encode($result);
?>