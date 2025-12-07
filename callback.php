<?php
header("Content-Type: application/json");

// Get the JSON payload from Safaricom
$mpesaResponse = file_get_contents("php://input");

// Log with clear separation
file_put_contents("mpesa_log.txt", "\n========================================" . PHP_EOL, FILE_APPEND);
file_put_contents("mpesa_log.txt", date("Y-m-d H:i:s") . " - CALLBACK RECEIVED" . PHP_EOL, FILE_APPEND);
file_put_contents("mpesa_log.txt", "Raw Response: " . $mpesaResponse . PHP_EOL, FILE_APPEND);

// Decode JSON
$data = json_decode($mpesaResponse, true);

if ($data) {
    // Log the full structure
    file_put_contents("mpesa_log.txt", "Decoded Data: " . json_encode($data, JSON_PRETTY_PRINT) . PHP_EOL, FILE_APPEND);
    
    // Check if callback exists
    if (isset($data['Body']['stkCallback'])) {
        $callback = $data['Body']['stkCallback'];
        
        file_put_contents("mpesa_log.txt", "Result Code: " . ($callback['ResultCode'] ?? 'N/A') . PHP_EOL, FILE_APPEND);
        file_put_contents("mpesa_log.txt", "Result Desc: " . ($callback['ResultDesc'] ?? 'N/A') . PHP_EOL, FILE_APPEND);
        file_put_contents("mpesa_log.txt", "Merchant Request ID: " . ($callback['MerchantRequestID'] ?? 'N/A') . PHP_EOL, FILE_APPEND);
        file_put_contents("mpesa_log.txt", "Checkout Request ID: " . ($callback['CheckoutRequestID'] ?? 'N/A') . PHP_EOL, FILE_APPEND);
        
        // Process based on result code
        if ($callback['ResultCode'] == 0) {
            file_put_contents("mpesa_log.txt", "✓ PAYMENT SUCCESSFUL" . PHP_EOL, FILE_APPEND);
            
            // Log transaction details
            if (isset($callback['CallbackMetadata']['Item'])) {
                file_put_contents("mpesa_log.txt", "Transaction Details:" . PHP_EOL, FILE_APPEND);
                foreach ($callback['CallbackMetadata']['Item'] as $item) {
                    file_put_contents("mpesa_log.txt", "  - " . $item['Name'] . ": " . $item['Value'] . PHP_EOL, FILE_APPEND);
                }
            }
            
            // HERE: Add your database update logic
            // Example:
            // $phone = $callback['CallbackMetadata']['Item'][4]['Value'] ?? '';
            // $amount = $callback['CallbackMetadata']['Item'][0]['Value'] ?? 0;
            // $mpesaReceiptNumber = $callback['CallbackMetadata']['Item'][1]['Value'] ?? '';
            // updateUserSubscription($phone, $amount, $mpesaReceiptNumber);
            
        } else {
            // Payment failed or cancelled
            file_put_contents("mpesa_log.txt", "✗ PAYMENT FAILED/CANCELLED" . PHP_EOL, FILE_APPEND);
            file_put_contents("mpesa_log.txt", "Reason: " . ($callback['ResultDesc'] ?? 'Unknown') . PHP_EOL, FILE_APPEND);
            
            // Common result codes:
            // 1032 - Request cancelled by user
            // 1037 - Timeout (user didn't enter PIN)
            // 2001 - Invalid initiator information
            // 1 - Insufficient balance
        }
    }
} else {
    file_put_contents("mpesa_log.txt", "ERROR: Could not decode JSON" . PHP_EOL, FILE_APPEND);
}

file_put_contents("mpesa_log.txt", "========================================\n" . PHP_EOL, FILE_APPEND);

// Respond to Safaricom
echo json_encode([
    "ResultCode" => 0,
    "ResultDesc" => "Callback received successfully"
]);
?>