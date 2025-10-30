<?php
// paypack_webhook.php

// This script listens for webhook notifications from PayPack to update payment status.

// Load necessary files.
require 'db_connect.php'; // Assuming db_connect.php handles your PDO connection.

// Function to load environment variables from a .env file.
function loadEnv($path) {
    if (!file_exists($path)) {
        error_log("No .env file found at: $path");
        return;
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) {
            continue;
        }
        list($name, $value) = explode('=', $line, 2);
        $_ENV[trim($name)] = trim($value);
    }
}
// Load the .env file from the current directory.
loadEnv(__DIR__ . '/.env');

// Get the raw JSON payload from the request body.
$payload = @file_get_contents('php://input');
$data = json_decode($payload, true);

// Get the signature from the header to verify the request's authenticity.
$signature = $_SERVER['HTTP_X_PAYPACK_SIGNATURE'] ?? null;
$webhook_secret = $_ENV['PAYPACK_WEBHOOK_SECRET'] ?? null;

// Log the incoming webhook data for debugging.
error_log("Incoming Webhook Data: " . print_r($data, true));

// Check for missing data or signature.
if (!$data || !isset($data['ref']) || !isset($data['status']) || !$signature) {
    http_response_code(400); // Bad Request
    echo json_encode(['status' => 'error', 'message' => 'Invalid webhook payload or missing signature.']);
    exit();
}

// Verify the webhook signature to ensure it's from PayPack.
// The provided SDK may have a built-in verification method, but this is a standard approach.
if (hash_hmac('sha256', $payload, $webhook_secret) !== $signature) {
    http_response_code(403); // Forbidden
    echo json_encode(['status' => 'error', 'message' => 'Invalid webhook signature.']);
    exit();
}

// Start a database transaction to ensure atomicity.
$conn->beginTransaction();

try {
    $ref = $data['ref'];
    $status = $data['status'];

    // Determine the new 'paid' status based on the webhook status.
    $is_paid = 0; // Default to not paid.
    if ($status === 'successful') {
        $is_paid = 1;
        // Optionally, you can also update the exit_time here for successful payments.
        // This is a business logic decision.
        $stmt = $conn->prepare("UPDATE parking_logs SET paid = ?, exit_time = NOW() WHERE payment_ref = ?");
        $stmt->execute([$is_paid, $ref]);
    } else {
        // Handle failed, cancelled, or other statuses.
        // We might just update the paid status to 0 (no change if it's already 0).
        $stmt = $conn->prepare("UPDATE parking_logs SET paid = ? WHERE payment_ref = ?");
        $stmt->execute([$is_paid, $ref]);
    }

    $stmt->closeCursor();
    $conn->commit();

    http_response_code(200); // OK
    echo json_encode(['status' => 'success', 'message' => 'Webhook received and processed successfully.']);

} catch (Exception $e) {
    // Rollback the transaction on error.
    $conn->rollBack();
    error_log("Webhook processing error: " . $e->getMessage());

    http_response_code(500); // Internal Server Error
    echo json_encode(['status' => 'error', 'message' => 'Internal server error.']);
}

exit();
?>
