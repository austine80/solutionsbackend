<?php
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
    echo json_encode([
        "error" => "Missing credentials. Please check environment variables.",
        "has_consumer_key" => !empty($consumerKey),
        "has_consumer_secret" => !empty($consumerSecret),
        "has_shortcode" => !empty($shortCode),
        "has_passkey" => !empty($passkey)
    ]);
    exit;
}

$amount = 1550;
$phone = isset($_POST['phone']) ? $_POST['phone'] : '';

if (!$phone) {
    echo json_encode(["error" => "Phone number is required"]);
    exit;
}

// Normalize phone number
$phone = preg_replace('/[^0-9]/', '', $phone);

if (strlen($phone) == 12 && substr($phone, 0, 3) == "254") {
    // OK
} elseif (strlen($phone) == 10 && substr($phone, 0, 1) == "0") {
    $phone = "254" . substr($phone, 1);
} elseif (strlen($phone) == 9) {
    $phone = "254" . $phone;
} else {
    echo json_encode([
        "error" => "Invalid phone number format",
        "received" => $phone,
        "expected" => "e.g., 0712345678 or 254712345678"
    ]);
    exit;
}

if (strlen($phone) != 12 || substr($phone, 0, 3) != "254") {
    echo json_encode(["error" => "Phone must start with 254 and be 12 digits"]);
    exit;
}

// Logging
file_put_contents("mpesa_log.txt", "==================================" . PHP_EOL, FILE_APPEND);
file_put_contents("mpesa_log.txt", date("Y-m-d H:i:s") . " - NEW REQUEST" . PHP_EOL, FILE_APPEND);
file_put_contents("mpesa_log.txt", "Phone: " . $phone . ", Amount: " . $amount . PHP_EOL, FILE_APPEND);

// ✅ CORRECTED: No trailing space!
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
    file_put_contents("mpesa_log.txt", "Token cURL Error: " . $curlError . PHP_EOL, FILE_APPEND);
    echo json_encode(["error" => "Token request failed", "details" => $curlError]);
    exit;
}

$response = json_decode($tokenResponse, true);
if (!isset($response['access_token'])) {
    file_put_contents("mpesa_log.txt", "Token Response: " . $tokenResponse . PHP_EOL, FILE_APPEND);
    echo json_encode(["error" => "Failed to get access token", "response" => $response]);
    exit;
}

$access_token = $response['access_token'];
file_put_contents("mpesa_log.txt", "Access Token: " . substr($access_token, 0, 20) . "..." . PHP_EOL, FILE_APPEND);

// 2. Prepare STK Push (Till - CustomerBuyGoodsOnline)
$timestamp = date("YmdHis");
$password = base64_encode($shortCode . $passkey . $timestamp);

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

file_put_contents("mpesa_log.txt", "STK HTTP Code: " . $httpCode . PHP_EOL, FILE_APPEND);
file_put_contents("mpesa_log.txt", "STK Raw Response: " . $stkResponse . PHP_EOL, FILE_APPEND);

// Always return JSON
$responseData = json_decode($stkResponse, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    // Safaricom sometimes returns plain text on error
    $responseData = ["error" => "Invalid response", "raw" => $stkResponse];
}
echo json_encode($responseData);
?>