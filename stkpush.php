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
$shortCode = getenv('SHORTCODE'); // This is your Till Number
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

// Amount to charge
$amount = 1550;

// Phone number from POST request
$phone = isset($_POST['phone']) ? $_POST['phone'] : '';

if(!$phone){
    echo json_encode(["error" => "Phone number is required"]);
    exit;
}

// Enhanced phone number processing for 254712345678 format
$phone = preg_replace('/[^0-9]/', '', $phone); // Remove non-digits

// Handle different formats
if (strlen($phone) == 12 && substr($phone, 0, 3) == "254") {
    // Already in correct format: 254712345678
    // Do nothing
} elseif (strlen($phone) == 10 && substr($phone, 0, 1) == "0") {
    // Format: 0712345678 -> 254712345678
    $phone = "254" . substr($phone, 1);
} elseif (strlen($phone) == 9) {
    // Format: 712345678 -> 254712345678
    $phone = "254" . $phone;
} else {
    echo json_encode([
        "error" => "Invalid phone number format",
        "received" => $phone,
        "expected" => "254712345678 or 0712345678"
    ]);
    exit;
}

// Final validation
if (strlen($phone) != 12 || substr($phone, 0, 3) != "254") {
    echo json_encode([
        "error" => "Phone number must be in format 254XXXXXXXXX",
        "processed" => $phone
    ]);
    exit;
}

// Log the phone number being used
file_put_contents("mpesa_log.txt", "==================================" . PHP_EOL, FILE_APPEND);
file_put_contents("mpesa_log.txt", date("Y-m-d H:i:s") . " - NEW REQUEST" . PHP_EOL, FILE_APPEND);
file_put_contents("mpesa_log.txt", "Phone Number: " . $phone . PHP_EOL, FILE_APPEND);
file_put_contents("mpesa_log.txt", "Amount: " . $amount . PHP_EOL, FILE_APPEND);
file_put_contents("mpesa_log.txt", "ShortCode: " . $shortCode . PHP_EOL, FILE_APPEND);

// ======= CALLBACK URL (Render public URL) =======
$callbackUrl = "https://solutionsbackend-uv0s.onrender.com/callback.php";  

// 1. Generate Access Token
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
    file_put_contents("mpesa_log.txt", "Token Request Error: " . $curlError . PHP_EOL, FILE_APPEND);
    echo json_encode(["error" => "Failed to connect to M-Pesa API", "details" => $curlError]);
    exit;
}

$response = json_decode($tokenResponse);

if(!isset($response->access_token)){
    file_put_contents("mpesa_log.txt", "Token Response: " . $tokenResponse . PHP_EOL, FILE_APPEND);
    echo json_encode([
        "error" => "Failed to get access token",
        "response" => $response
    ]);
    exit;
}

$access_token = $response->access_token;
file_put_contents("mpesa_log.txt", "Access Token: " . substr($access_token, 0, 20) . "..." . PHP_EOL, FILE_APPEND);

// 2. Prepare STK Push request for Till Number (BuyGoods)
$timestamp = date("YmdHis");
$password = base64_encode($shortCode . $passkey . $timestamp);

$data = [
    "BusinessShortCode" => $shortCode,
    "Password" => $password,
    "Timestamp" => $timestamp,
    "TransactionType" => "CustomerBuyGoodsOnline",
    "Amount" => (int)$amount,                      // Ensure integer
    "PartyA" => $phone,
    "PartyB" => $shortCode,
    "PhoneNumber" => $phone,
    "CallBackURL" => $callbackUrl,
    "AccountReference" => "Activation",
    "TransactionDesc" => "Account Activation"
];

file_put_contents("mpesa_log.txt", "STK Push Request Data: " . json_encode($data, JSON_PRETTY_PRINT) . PHP_EOL, FILE_APPEND);

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
$stkError = curl_error($curl);
$httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
curl_close($curl);

if ($stkError) {
    file_put_contents("mpesa_log.txt", "STK Push cURL Error: " . $stkError . PHP_EOL, FILE_APPEND);
    echo json_encode(["error" => "STK Push request failed", "details" => $stkError]);
    exit;
}

file_put_contents("mpesa_log.txt", "HTTP Code: " . $httpCode . PHP_EOL, FILE_APPEND);
file_put_contents("mpesa_log.txt", "STK Push Response: " . $stkResponse . PHP_EOL, FILE_APPEND);
file_put_contents("mpesa_log.txt", "==================================" . PHP_EOL . PHP_EOL, FILE_APPEND);

// Return response to browser
echo $stkResponse;
?>