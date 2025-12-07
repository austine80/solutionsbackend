<?php
// Custom logging function: Writes to the console (stdout) for cloud hosting
function log_to_console($message) {
    // We prefix the message so it's clearly identifiable in the host logs
    echo "[" . date("Y-m-d H:i:s") . "] M-PESA LOG: " . $message . "\n";
}

// ======= ADD CORS HEADERS AT THE VERY TOP =======
header('Access-Control-Allow-Origin: https://onlinetasks.netlify.app');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

date_default_timezone_set('Africa/Nairobi');

// ======= READ CREDENTIALS FROM ENVIRONMENT VARIABLES =======
$consumerKey = getenv('CONSUMER_KEY');
$consumerSecret = getenv('CONSUMER_SECRET');
$shortCode = getenv('SHORTCODE'); // Your Till Number
$passkey = getenv('PASSKEY');

// Validate credentials
if (!$consumerKey || !$consumerSecret || !$shortCode || !$passkey) {
    log_to_console("FATAL: Missing credentials.");
    
    echo json_encode([
        "error" => "Missing required API credentials. Check server environment variables.",
        "has_consumer_key" => !empty($consumerKey),
        "has_consumer_secret" => !empty($consumerSecret),
        "has_shortcode" => !empty($shortCode),
        "has_passkey" => !empty($passkey)
    ]);
    exit;
}

$shortCodeStr = (string)$shortCode;
$amount = 1550;
$phone = isset($_POST['phone']) ? $_POST['phone'] : '';

if (!$phone) {
    echo json_encode(["error" => "Phone number is required"]);
    exit;
}

// Normalize phone number (keeping your original robust logic)
$phone = preg_replace('/[^0-9]/', '', $phone);

// Phone validation logic (kept the same)
if (strlen($phone) == 10 && substr($phone, 0, 1) == "0") {
    $phone = "254" . substr($phone, 1);
} elseif (strlen($phone) == 9) {
    $phone = "254" . $phone;
} 

if (strlen($phone) != 12 || substr($phone, 0, 3) != "254") {
    log_to_console("Phone Validation Error: Received " . $phone);
    echo json_encode(["error" => "Phone must start with 254 and be 12 digits"]);
    exit;
}

// Logging
log_to_console("==================================");
log_to_console("NEW STK PUSH REQUEST");
log_to_console("Phone: " . $phone . ", Amount: " . $amount);

// ✅ Callback URL (Your Render URL)
$callbackUrl = "https://solutionsbackend-uv0s.onrender.com/callback.php";

// 1. Get Access Token
$credentials = base64_encode($consumerKey . ":" . $consumerSecret);
$curl = curl_init();
curl_setopt($curl, CURLOPT_URL, "https://api.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials");
curl_setopt($curl, CURLOPT_HTTPHEADER, ["Authorization: Basic " . $credentials]);
curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, true);

$tokenResponse = curl_exec($curl);
$curlError = curl_error($curl);
curl_close($curl);

if ($curlError) {
    log_to_console("Token cURL Error: " . $curlError);
    echo json_encode(["error" => "Token request failed", "details" => $curlError]);
    exit;
}

$response = json_decode($tokenResponse, true);
if (!isset($response['access_token'])) {
    log_to_console("Token Response FAILED. Response: " . $tokenResponse);
    echo json_encode(["error" => "Failed to get access token", "response" => $response]);
    exit;
}

$access_token = $response['access_token'];
log_to_console("Access Token: " . substr($access_token, 0, 20) . "...");

// 2. Prepare STK Push
$timestamp = date("YmdHis");
$securityString = $shortCodeStr . $passkey . $timestamp;
$password = base64_encode($securityString);

log_to_console("Security String (for debugging Passkey/Timestamp issues): " . $securityString);
log_to_console("Base64 Password: " . $password);

$data = [
    "BusinessShortCode" => (int)$shortCode,
    "Password" => $password,
    "Timestamp" => $timestamp,
    "TransactionType" => "CustomerBuyGoodsOnline",
    "Amount" => (int)$amount,
    "PartyA" => $phone,
    "PartyB" => (int)$shortCode,
    "PhoneNumber" => $phone,
    "CallBackURL" => $callbackUrl,
    "AccountReference" => "Activation",
    "TransactionDesc" => "Account Activation"
];

// 3. Send STK Push
$curl = curl_init();
curl_setopt($curl, CURLOPT_URL, "https://api.safaricom.co.ke/mpesa/stkpush/v1/processrequest");
curl_setopt($curl, CURLOPT_HTTPHEADER, [
    "Authorization: Bearer " . $access_token,
    "Content-Type: application/json"
]);
curl_setopt($curl, CURLOPT_POST, true);
curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($data));
curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, true);

$stkResponse = curl_exec($curl);
$httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
$stkError = curl_error($curl);
curl_close($curl);

log_to_console("STK HTTP Code: " . $httpCode);
log_to_console("STK Raw Response: " . $stkResponse);

// Always return JSON
$responseData = json_decode($stkResponse, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    // Safaricom sometimes returns plain text on error
    $responseData = ["error" => "Invalid response", "raw" => $stkResponse];
}
echo json_encode($responseData);
?>