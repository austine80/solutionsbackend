<?php
date_default_timezone_set('Africa/Nairobi');

// ======= READ CREDENTIALS FROM ENVIRONMENT VARIABLES =======
$consumerKey = getenv('CONSUMER_KEY');
$consumerSecret = getenv('CONSUMER_SECRET');
$shortCode = getenv('SHORTCODE'); // This is your Till Number
$passkey = getenv('PASSKEY');

// Amount to charge
$amount = 1550;

// Phone number from POST request
$phone = isset($_POST['phone']) ? $_POST['phone'] : '';

// Convert phone to 2547XXXXXXXX format
$phone = preg_replace('/[^0-9]/', '', $phone); // remove non-digits
if (strlen($phone) == 10 && substr($phone,0,1)=="0") {
    $phone = "254" . substr($phone,1);
}

if(!$phone){
    echo json_encode(["error" => "Phone number is required"]);
    exit;
}

// ======= CALLBACK URL (Render public URL) =======
$callbackUrl = "https://solutionsbackend-uv0s.onrender.com/callback.php";  

// 1. Generate Access Token
$credentials = base64_encode($consumerKey . ":" . $consumerSecret);

$curl = curl_init();
curl_setopt($curl, CURLOPT_URL, "https://api.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials");
curl_setopt($curl, CURLOPT_HTTPHEADER, ["Authorization: Basic " . $credentials]);
curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);

$response = json_decode(curl_exec($curl));
curl_close($curl);

if(!isset($response->access_token)){
    echo json_encode(["error" => "Failed to get access token"]);
    exit;
}

$access_token = $response->access_token;

// 2. Prepare STK Push request for Till Number (BuyGoods)
$timestamp = date("YmdHis");
$password = base64_encode($shortCode . $passkey . $timestamp);

$data = [
    "BusinessShortCode" => $shortCode,            // Your Till Number
    "Password" => $password,
    "Timestamp" => $timestamp,
    "TransactionType" => "CustomerBuyGoodsOnline", // Important for Till Number
    "Amount" => $amount,
    "PartyA" => $phone,                            // Customer phone
    "PartyB" => $shortCode,                        // Your Till Number
    "PhoneNumber" => $phone,
    "CallBackURL" => $callbackUrl,
    "AccountReference" => "Activation",           // Optional, can use user ID
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

$stkResponse = curl_exec($curl);
curl_close($curl);

// Log the response
file_put_contents("mpesa_log.txt", date("Y-m-d H:i:s") . " - STK Push Request: " . json_encode($stkData) . PHP_EOL, FILE_APPEND);
file_put_contents("mpesa_log.txt", date("Y-m-d H:i:s") . " - STK Push Response: " . $stkResponse . PHP_EOL, FILE_APPEND);

// Return response to browser
header('Content-Type: application/json');
echo $stkResponse;
?>
