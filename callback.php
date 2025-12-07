<?php
// Custom logging function: Writes ONLY to the server logs (stderr/stdout) 
// without contaminating the HTTP response stream.
function log_to_console($message) {
    error_log("[M-PESA DEBUG] " . $message);
}

// Ensure clean JSON response
header("Content-Type: application/json");

// Read raw POST data from Safaricom
$rawInput = file_get_contents("php://input");

// Logging: Start of callback
log_to_console("\n" . str_repeat("=", 50));
log_to_console("M-PESA CALLBACK RECEIVED");
log_to_console("Raw Input: " . $rawInput);

// Decode JSON safely
$data = json_decode($rawInput, true);

// Initialize default response to M-Pesa
$response = [
    "ResultCode" => 0,
    "ResultDesc" => "Accepted"
];

if (json_last_error() !== JSON_ERROR_NONE) {
    log_to_console("ERROR: Failed to decode JSON");
    $response["ResultCode"] = 1;
    $response["ResultDesc"] = "Invalid JSON";
} 
elseif (!isset($data['Body']['stkCallback'])) {
    log_to_console("ERROR: Invalid callback structure");
    log_to_console("Decoded Data: " . json_encode($data, JSON_PRETTY_PRINT));
    $response["ResultCode"] = 1;
    $response["ResultDesc"] = "Invalid callback format";
} 
else {
    $callback = $data['Body']['stkCallback'];
    $resultCode = $callback['ResultCode'] ?? 'N/A';
    $resultDesc = $callback['ResultDesc'] ?? 'No description';
    $merchantRequestId = $callback['MerchantRequestID'] ?? 'N/A';
    $checkoutRequestId = $callback['CheckoutRequestID'] ?? 'N/A';

    // Log key details for analysis
    log_to_console("🔑 CALLBACK DETAILS: ");
    log_to_console("   • ResultCode: " . $resultCode);
    log_to_console("   • ResultDesc: " . $resultDesc);
    log_to_console("   • MerchantRequestID: " . $merchantRequestId);
    log_to_console("   • CheckoutRequestID: " . $checkoutRequestId);

    if ($resultCode == 0) {
        // SUCCESS: Extract transaction metadata
        log_to_console("✅ PAYMENT SUCCESSFUL - Ready for DB Update");
        
        if (isset($callback['CallbackMetadata']['Item'])) {
            $metadata = $callback['CallbackMetadata']['Item'];
            $details = [];
            foreach ($metadata as $item) {
                $name = $item['Name'] ?? 'Unknown';
                $value = $item['Value'] ?? 'N/A';
                $details[$name] = $value;
            }

            $phone = $details['PhoneNumber'] ?? null;
            $receipt = $details['MpesaReceiptNumber'] ?? null;
            $amount = $details['Amount'] ?? null;

            if ($phone && $receipt && $amount) {
                // ✅ TODO: Update user's account here
                log_to_console("🟢 Activation Data: Phone={$phone}, Receipt={$receipt}, Amount={$amount}");
            }
        }
    } else {
        // FAILURE or CANCELLED - This is your instant failure reason
        log_to_console("❌ PAYMENT FAILED/CANCELLED. Check ResultCode ({$resultCode}) for reason.");
    }
}

// Always respond promptly to Safaricom
log_to_console(str_repeat("=", 50) . "\n");

echo json_encode($response);
?>