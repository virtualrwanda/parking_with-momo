<?php
// webhook.php
require __DIR__ . '/vendor/autoload.php';
use Paypack\Paypack;

function loadEnv($path) {
    if (!file_exists($path)) die("No .env file");
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        list($name, $value) = explode('=', $line, 2);
        $_ENV[trim($name)] = trim($value);
    }
}
loadEnv(__DIR__ . '/.env');

$paypack = new Paypack();
$paypack->config([
    'client_id' => $_ENV['PAYPACK_CLIENT_ID'],
    'client_secret' => $_ENV['PAYPACK_CLIENT_SECRET'],
    'webhook_mode' => $_ENV['PAYPACK_WEBHOOK_MODE'],
]);

$conn = new mysqli($_ENV['DB_HOST'], $_ENV['DB_USER'], $_ENV['DB_PASS'], $_ENV['DB_NAME']);
if ($conn->connect_error) {
    error_log("Database connection failed: " . $conn->connect_error);
    http_response_code(500);
    exit();
}

$payload = json_decode(file_get_contents('php://input'), true);
if ($payload['event_kind'] === 'transaction:processed' && isset($payload['data']['ref'])) {
    $ref = $payload['data']['ref'];
    $status = $payload['data']['status'];
    if ($status === 'successful') {
        $stmt = $conn->prepare("UPDATE parking_logs SET paid = TRUE WHERE payment_ref = ?");
        $stmt->bind_param("s", $ref);
        $stmt->execute();
        $stmt->close();
    }
    error_log("Webhook processed for ref '$ref': " . json_encode($payload));
    http_response_code(200);
} else {
    error_log("Invalid webhook payload: " . json_encode($payload));
    http_response_code(400);
}
$conn->close();
?>
