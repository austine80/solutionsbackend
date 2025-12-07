<?php
// Ensure clean JSON response
header("Content-Type: application/json");

// Read raw POST data from Safaricom
$rawInput = file_get_contents("php://input");

// Logging: Start of callback
file_put_contents("mpesa_log.txt", "\n" . str_repeat("=", 50) . "\n", FILE_APPEND);
file_put_contents("mpesa_log.txt", date("Y-m-d H:i:s") . " - M-PESA CALLBACK RECEIVED\n", FILE_APPEND);
file_put_contents("mpesa_log.txt", "Raw Input: " . $rawInput . "\n", FILE_APPEND);

// Decode JSON safely
$data = json_decode($rawInput, true);

// Initialize default response
$response = [
    "ResultCode" => 0,
    "ResultDesc" => "Accepted"
];

if (json_last_error() !== JSON_ERROR_NONE) {
    // Log JSON parse error
    file_put_contents("mpesa_log.txt", "ERROR: Failed to decode JSON\n", FILE_APPEND);
    $response["ResultCode"] = 1;
    $response["ResultDesc"] = "Invalid JSON";
} 
elseif (!isset($data['Body']['stkCallback'])) {
    // Unexpected structure
    file_put_contents("mpesa_log.txt", "ERROR: Invalid callback structure\n", FILE_APPEND);
    file_put_contents("mpesa_log.txt", "Decoded Data: " . json_encode($data, JSON_PRETTY_PRINT) . "\n", FILE_APPEND);
    $response["ResultCode"] = 1;
    $response["ResultDesc"] = "Invalid callback format";
} 
else {
    $callback = $data['Body']['stkCallback'];
    $resultCode = $callback['ResultCode'] ?? null;
    $resultDesc = $callback['ResultDesc'] ?? 'No description';
    $merchantRequestId = $callback['MerchantRequestID'] ?? 'N/A';
    $checkoutRequestId = $callback['CheckoutRequestID'] ?? 'N/A';

    // Log key details
    file_put_contents("mpesa_log.txt", "ResultCode: " . $resultCode . "\n", FILE_APPEND);
    file_put_contents("mpesa_log.txt", "ResultDesc: " . $resultDesc . "\n", FILE_APPEND);
    file_put_contents("mpesa_log.txt", "MerchantRequestID: " . $merchantRequestId . "\n", FILE_APPEND);
    file_put_contents("mpesa_log.txt", "CheckoutRequestID: " . $checkoutRequestId . "\n", FILE_APPEND);

    if ($resultCode == 0) {
        // SUCCESS: Extract transaction metadata
        file_put_contents("mpesa_log.txt", "✅ PAYMENT SUCCESSFUL\n", FILE_APPEND);
        
        if (isset($callback['CallbackMetadata']['Item'])) {
            $metadata = $callback['CallbackMetadata']['Item'];
            $details = [];
            foreach ($metadata as $item) {
                $name = $item['Name'] ?? 'Unknown';
                $value = $item['Value'] ?? 'N/A';
                $details[$name] = $value;
                file_put_contents("mpesa_log.txt", "  • {$name}: {$value}\n", FILE_APPEND);
            }

            // 🔑 Example: Get phone number and M-Pesa receipt
            // Format: Item[4] is usually PhoneNumber, Item[1] is MpesaReceiptNumber
            $phone = $details['PhoneNumber'] ?? null;
            $receipt = $details['MpesaReceiptNumber'] ?? null;
            $amount = $details['Amount'] ?? null;

            if ($phone && $receipt && $amount) {
                // ✅ TODO: Update user's account on OnlineTasks (e.g., activate subscription)
                // Example: activateUserFromPayment($phone, $amount, $receipt);
                file_put_contents("mpesa_log.txt", "🟢 Ready to activate user: {$phone}\n", FILE_APPEND);
            }
        }
    } else {
        // FAILURE or CANCELLED
        file_put_contents("mpesa_log.txt", "❌ PAYMENT FAILED OR CANCELLED\n", FILE_APPEND);
        // Common codes: 1032 (cancelled), 1037 (timeout), 1 (insufficient funds)
    }
}

// Always respond promptly to Safaricom (within 5 seconds)
file_put_contents("mpesa_log.txt", "Sending Response: " . json_encode($response) . "\n", FILE_APPEND);
file_put_contents("mpesa_log.txt", str_repeat("=", 50) . "\n\n", FILE_APPEND);

echo json_encode($response);
?>