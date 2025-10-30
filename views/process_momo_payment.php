<?php
// process_momo_payment.php
session_start();

// Load necessary files.
// Assuming db_connect.php handles your PDO or MySQLi database connection.
require 'db_connect.php';
// Load Composer's autoloader for the Paypack SDK.
require __DIR__ . '/vendor/autoload.php';

use Paypack\Paypack;

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

// Check if the user is logged in. If not, redirect to the login page.
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Check if the request method is POST. If not, redirect to the dashboard.
if ($_SERVER["REQUEST_METHOD"] != "POST") {
    header("Location: dashboard.php?page=home");
    exit();
}

// Sanitize and validate the incoming POST data.
$log_id = filter_input(INPUT_POST, 'log_id', FILTER_VALIDATE_INT);
$amount = filter_input(INPUT_POST, 'amount', FILTER_VALIDATE_FLOAT);
$phone_number = trim($_POST['phone_number']);

// Check for valid inputs.
if ($log_id === false || $amount === false || empty($phone_number) || !preg_match('/^07[0-9]{8}$/', $phone_number)) {
    $message = "Invalid payment details provided. Please check the amount and phone number.";
    header("Location: dashboard.php?page=home&status=" . urlencode($message));
    exit();
}

// Initialize PayPack SDK using credentials from .env.
$paypack_client_id = $_ENV['PAYPACK_CLIENT_ID'] ?? "";
$paypack_client_secret = $_ENV['PAYPACK_CLIENT_SECRET'] ?? "";
$paypack_webhook_mode = $_ENV['PAYPACK_WEBHOOK_MODE'] ?? "development";

try {
    $paypack = new Paypack();
    $paypack->config([
        'client_id' => $paypack_client_id,
        'client_secret' => $paypack_client_secret,
        'webhook_mode' => $paypack_webhook_mode,
    ]);
} catch (Exception $e) {
    $message = "PayPack configuration error: " . $e->getMessage();
    header("Location: dashboard.php?page=home&status=" . urlencode($message));
    exit();
}

// Start a database transaction to ensure data consistency.
$conn->begin_transaction();
try {
    // Round the amount to a whole number as PayPack's API for RWF requires an integer.
    $amount_int = ceil($amount);

    // Prepare the payment data for the PayPack API.
    // The phone number must be in the international format (+2507...).
    $payment_data = [
        'phone' => str_replace('07', '+2507', $phone_number),
        'amount' => (string)$amount_int,
    ];

    // Initiate the Cash-in request via PayPack.
    $payment_result = $paypack->Cashin($payment_data);

    // Check if the payment initiation was successful and returned a reference.
    if (isset($payment_result['ref']) && $payment_result['status'] === 'pending') {
        $ref = $payment_result['ref'];

        // Update the parking log with the payment reference.
        // The 'paid' status is set to FALSE, as it will be updated by a webhook upon successful payment.
        $stmt = $conn->prepare("UPDATE parking_logs SET payment_ref = ?, paid = FALSE WHERE id = ?");
        $stmt->bind_param("si", $ref, $log_id);
        $stmt->execute();
        $stmt->close();

        // Commit the transaction since the update was successful.
        $conn->commit();

        $message = "Momo payment initiated for FRW " . number_format($amount, 2) . ". Please ask the driver to approve the payment on their phone. Reference: " . htmlspecialchars($ref);
    } else {
        // Payment initiation failed, so roll back the database transaction.
        $conn->rollback();
        $message = "Momo payment initiation failed: " . ($payment_result['message'] ?? 'Unknown error');
    }
} catch (Exception $e) {
    // Catch any exceptions and roll back the transaction.
    $conn->rollback();
    $message = "An error occurred during payment processing: " . $e->getMessage();
}

// Redirect back to the dashboard with the status message.
header("Location: dashboard.php?page=home&status=" . urlencode($message));
exit();

?>
