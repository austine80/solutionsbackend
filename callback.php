<?php
header("Content-Type: application/json");

// Get the JSON payload from Safaricom
$mpesaResponse = file_get_contents("php://input");

// Log it to a file for debugging
file_put_contents("mpesa_log.txt", date("Y-m-d H:i:s") . " - " . $mpesaResponse . PHP_EOL, FILE_APPEND);

// Decode JSON
$data = json_decode($mpesaResponse, true);

// You can process the transaction here
// Example: check ResultCode and update your database
/*
if($data['Body']['stkCallback']['ResultCode'] == 0){
    // Payment successful
} else {
    // Payment failed or cancelled
}
*/

// Respond to Safaricom
echo json_encode([
    "ResultCode" => 0,
    "ResultDesc" => "Callback received successfully"
]);
?>
