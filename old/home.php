<?php
// PHP SCRIPT START
session_start();

// Debug: Log start of script
error_log("Starting index.php execution");

// Load Composer autoload
$autoload_path = __DIR__ . '/vendor/autoload.php';
if (!file_exists($autoload_path)) {
    error_log("Composer autoload file not found at: $autoload_path");
    die("Fatal error: Composer autoload file not found. Run 'composer install' in " . __DIR__);
}
require $autoload_path;

// Debug: Confirm autoload inclusion
error_log("Composer autoload included successfully");

// Load PayPack SDK
use Paypack\Paypack;

// Debug: Check if Paypack\Paypack class exists
if (!class_exists('Paypack\Paypack')) {
    error_log("Paypack\Paypack class not found. Ensure 'quarksgroup/paypack-php' is installed via Composer.");
    die("Fatal error: Paypack SDK not installed. Run 'composer require quarksgroup/paypack-php'.");
}

// Load environment variables
function loadEnv($path) {
    if (!file_exists($path)) {
        error_log("No .env file found at $path");
        die("Configuration error: .env file not found.");
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        list($name, $value) = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value);
        if (!array_key_exists($name, $_ENV)) {
            $_ENV[$name] = $value;
        }
    }
}
loadEnv(__DIR__ . '/.env');

// Database connection details with fallbacks
$servername = $_ENV['DB_HOST'] ?? "localhost";
$username = $_ENV['DB_USER'] ?? "masacxpy_parking";
$password = $_ENV['DB_PASS'] ?? "masacxpy_parking";
$dbname = $_ENV['DB_NAME'] ?? "masacxpy_parking";

// PayPack configuration with fallbacks
$paypack_client_id = $_ENV['PAYPACK_CLIENT_ID'] ?? "";
$paypack_client_secret = $_ENV['PAYPACK_CLIENT_SECRET'] ?? "";
$paypack_webhook_mode = $_ENV['PAYPACK_WEBHOOK_MODE'] ?? "development";

// Validate PayPack credentials
if (empty($paypack_client_id) || empty($paypack_client_secret)) {
    error_log("Missing PayPack credentials in .env");
    die("Configuration error: PayPack credentials not set.");
}

// Initialize PayPack SDK
try {
    $paypack = new Paypack();
    $paypack->config([
        'client_id' => $paypack_client_id,
        'client_secret' => $paypack_client_secret,
        'webhook_mode' => $paypack_webhook_mode,
    ]);
    error_log("PayPack SDK initialized successfully");
} catch (Exception $e) {
    error_log("PayPack SDK configuration failed: " . $e->getMessage());
    die("Configuration error: Failed to initialize PayPack SDK: " . $e->getMessage());
}

// Create database connection
$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    error_log("Database connection failed: " . $conn->connect_error);
    die("Connection failed: " . $conn->connect_error);
}

// Verify stored procedures exist
$procedure_check = $conn->query("SHOW PROCEDURE STATUS WHERE Db = 'masacxpy_parking' AND Name IN ('ExitVehicle', 'ParkVehicle')");
if ($procedure_check->num_rows < 2) {
    error_log("Missing stored procedures. Found: " . $procedure_check->num_rows . " expected: 2");
    die("Database error: Stored procedures ExitVehicle or ParkVehicle missing. Please apply schema.sql.");
}
$procedure_check->free();

// Fetch current parking rates
$current_rates = [];
$rate_result = $conn->query("SELECT parking_id, rate_per_minute FROM parking_config WHERE parking_id IN (SELECT parking_id FROM parkings) ORDER BY updated_at DESC");
if ($rate_result) {
    while ($row = $rate_result->fetch_assoc()) {
        $current_rates[$row['parking_id']] = $row['rate_per_minute'];
    }
    $rate_result->free();
}

$message = "";
$error = "";
$action = isset($_GET['action']) ? $_GET['action'] : 'login';

// Handle user login
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['login'])) {
    $user = $_POST['username'];
    $pass = $_POST['password'];

    $stmt = $conn->prepare("SELECT id, password, role FROM users WHERE username = ?");
    if (!$stmt) {
        error_log("Prepare failed for login query: " . $conn->error);
        $error = "Database error: " . $conn->error;
    } else {
        $stmt->bind_param("s", $user);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $user_data = $result->fetch_assoc();
            if (password_verify($pass, $user_data['password'])) {
                $_SESSION['loggedin'] = true;
                $_SESSION['user_id'] = $user_data['id'];
                $_SESSION['role'] = $user_data['role'];
                // Fetch assigned parking for ParkingManager
                if ($user_data['role'] === 'ParkingManager') {
                    $stmt = $conn->prepare("SELECT parking_id FROM manager_assignments WHERE user_id = ?");
                    $stmt->bind_param("i", $user_data['id']);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    if ($row = $result->fetch_assoc()) {
                        $_SESSION['parking_id'] = $row['parking_id'];
                    }
                    $stmt->close();
                }
            } else {
                $error = "Invalid username or password.";
            }
        } else {
            $error = "Invalid username or password.";
        }
        $stmt->close();
    }
}

// Handle user signup
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['signup'])) {
    $user = $_POST['username'];
    $pass = $_POST['password'];
    $role = $_POST['role'];

    // Restrict Admin registration to SuperAdmin
    if ($role === 'Admin' && (!isset($_SESSION['loggedin']) || $_SESSION['role'] !== 'SuperAdmin')) {
        $error = "Only SuperAdmin can register an Admin.";
    } elseif ($role === 'ParkingManager' && (!isset($_SESSION['loggedin']) || !in_array($_SESSION['role'], ['Admin', 'SuperAdmin']))) {
        $error = "Only Admin or SuperAdmin can register a ParkingManager.";
    } else {
        $hashed_pass = password_hash($pass, PASSWORD_DEFAULT);
        $created_by = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;
        $stmt = $conn->prepare("INSERT INTO users (username, password, role, created_by) VALUES (?, ?, ?, ?)");
        if (!$stmt) {
            error_log("Prepare failed for signup query: " . $conn->error);
            $error = "Database error: " . $conn->error;
        } else {
            $stmt->bind_param("sssi", $user, $hashed_pass, $role, $created_by);
            if ($stmt->execute()) {
                $message = "User registered successfully!";
                $action = 'login';
            } else {
                $error = "Error: " . $stmt->error;
                error_log("Signup failed for user '$user': " . $stmt->error);
            }
            $stmt->close();
        }
    }
}

// Handle parking creation
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_SESSION['loggedin']) && isset($_POST['create_parking']) && in_array($_SESSION['role'], ['SuperAdmin', 'Admin'])) {
    $name = trim($_POST['parking_name']);
    $location = trim($_POST['parking_location']);
    $total_slots = (int)$_POST['total_slots'];

    if (empty($name) || empty($location) || $total_slots <= 0) {
        $error = "All fields are required, and total slots must be positive.";
    } else {
        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("INSERT INTO parkings (name, location, total_slots, created_by) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("ssii", $name, $location, $total_slots, $_SESSION['user_id']);
            $stmt->execute();
            $parking_id = $conn->insert_id;
            $stmt->close();

            // Insert slots
            $values = [];
            for ($i = 1; $i <= $total_slots; $i++) {
                $values[] = "($i, $parking_id, 'Available')";
            }
            $conn->query("INSERT INTO slots (slot_id, parking_id, status) VALUES " . implode(',', $values));

            // Set default rate
            $default_rate = 1.6667; // 100 FRCS/hour
            $stmt = $conn->prepare("INSERT INTO parking_config (parking_id, rate_per_minute, updated_by) VALUES (?, ?, ?)");
            $stmt->bind_param("idi", $parking_id, $default_rate, $_SESSION['user_id']);
            $stmt->execute();
            $stmt->close();

            $conn->commit();
            $message = "Parking '$name' created with $total_slots slots.";
        } catch (Exception $e) {
            $conn->rollback();
            $error = "Error creating parking: " . $e->getMessage();
            error_log("Parking creation failed: " . $e->getMessage());
        }
    }
}

// Handle manager assignment
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_SESSION['loggedin']) && isset($_POST['assign_manager']) && in_array($_SESSION['role'], ['SuperAdmin', 'Admin'])) {
    $user_id = (int)$_POST['manager_id'];
    $parking_id = (int)$_POST['parking_id'];

    $conn->begin_transaction();
    try {
        // Verify user is ParkingManager
        $stmt = $conn->prepare("SELECT role FROM users WHERE id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows === 0 || $result->fetch_assoc()['role'] !== 'ParkingManager') {
            throw new Exception("Selected user is not a ParkingManager.");
        }
        $stmt->close();

        // Check if assignment already exists
        $stmt = $conn->prepare("SELECT id FROM manager_assignments WHERE user_id = ? AND parking_id = ?");
        $stmt->bind_param("ii", $user_id, $parking_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            throw new Exception("Manager is already assigned to this parking.");
        }
        $stmt->close();

        // Assign manager
        $stmt = $conn->prepare("INSERT INTO manager_assignments (user_id, parking_id) VALUES (?, ?)");
        $stmt->bind_param("ii", $user_id, $parking_id);
        $stmt->execute();
        $stmt->close();

        $conn->commit();
        $message = "Manager assigned to parking successfully.";
    } catch (Exception $e) {
        $conn->rollback();
        $error = "Error assigning manager: " . $e->getMessage();
        error_log("Manager assignment failed: " . $e->getMessage());
    }
}

// Handle rate configuration
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_SESSION['loggedin']) && isset($_POST['update_rate']) && in_array($_SESSION['role'], ['SuperAdmin', 'Admin'])) {
    $parking_id = (int)$_POST['parking_id'];
    $new_rate = floatval($_POST['rate_per_minute']);
    if ($new_rate <= 0) {
        $error = "Rate per minute must be greater than 0.";
    } else {
        $conn->begin_transaction();
        try {
            // Log the old rate
            $current_rate = $current_rates[$parking_id] ?? 1.6667;
            $stmt = $conn->prepare("INSERT INTO config_logs (parking_id, user_id, old_rate, new_rate) VALUES (?, ?, ?, ?)");
            if (!$stmt) {
                throw new Exception("Prepare failed for config_logs: " . $conn->error);
            }
            $stmt->bind_param("iidd", $parking_id, $_SESSION['user_id'], $current_rate, $new_rate);
            $stmt->execute();
            $stmt->close();

            // Update parking_config
            $stmt = $conn->prepare("INSERT INTO parking_config (parking_id, rate_per_minute, updated_by) VALUES (?, ?, ?)");
            if (!$stmt) {
                throw new Exception("Prepare failed for parking_config: " . $conn->error);
            }
            $stmt->bind_param("idi", $parking_id, $new_rate, $_SESSION['user_id']);
            $stmt->execute();
            $stmt->close();

            $conn->commit();
            $message = "Parking rate updated to " . number_format($new_rate, 4) . " FRCS/minute for parking ID $parking_id.";
            $current_rates[$parking_id] = $new_rate;
        } catch (Exception $e) {
            $conn->rollback();
            $error = "Error updating rate: " . $e->getMessage();
            error_log("Rate update failed: " . $e->getMessage());
        }
    }
}

// Handle logout
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

// Function to handle stored procedure calls with enhanced error handling
function call_sp($conn, $sp_name, $params = []) {
    $param_types = str_repeat('s', count($params));
    if ($sp_name === 'ExitVehicle' || $sp_name === 'ParkVehicle') {
        $param_types = str_repeat('i', count($params) - 1) . 's';
    }
    $placeholders = implode(',', array_fill(0, count($params), '?'));
    $sql = "CALL $sp_name($placeholders)";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log("Prepare failed for $sp_name: " . $conn->error);
        return ['error' => "Database error: " . $conn->error];
    }
    if (!empty($params)) {
        $stmt->bind_param($param_types, ...$params);
    }
    try {
        if ($stmt->execute()) {
            $res = [];
            do {
                if ($result_set = $stmt->get_result()) {
                    while ($row = $result_set->fetch_assoc()) {
                        $res[] = $row;
                    }
                    $result_set->free();
                }
            } while ($stmt->more_results() && $stmt->next_result());
            $stmt->close();
            return ['success' => $res];
        } else {
            $error_msg = $stmt->error;
            $stmt->close();
            error_log("Execute failed for $sp_name with params " . json_encode($params) . ": " . $error_msg);
            return ['error' => $error_msg];
        }
    } catch (mysqli_sql_exception $e) {
        $stmt->close();
        error_log("mysqli_sql_exception in $sp_name with params " . json_encode($params) . ": " . $e->getMessage());
        return ['error' => $e->getMessage()];
    }
}

// Handle parking action
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_SESSION['loggedin']) && isset($_POST['park_vehicle'])) {
    $plate = trim($_POST['plate_number']);
    $car_type = $_POST['car_type'];
    $slot_id = empty($_POST['slot_id']) ? null : (int)$_POST['slot_id'];
    $parking_id = (int)$_POST['parking_id'];

    // For ParkingManager, use assigned parking
    if ($_SESSION['role'] === 'ParkingManager' && $parking_id != $_SESSION['parking_id']) {
        $error = "You can only manage your assigned parking.";
    } else {
        // Validate plate number format
        if (!preg_match('/^[A-Za-z0-9]{1,20}$/', $plate)) {
            $error = "Invalid plate number format. Use up to 20 alphanumeric characters.";
        } else {
            // Check slot availability
            if ($slot_id !== null) {
                $stmt = $conn->prepare("SELECT status FROM slots WHERE slot_id = ? AND parking_id = ?");
                $stmt->bind_param("ii", $slot_id, $parking_id);
                $stmt->execute();
                $result = $stmt->get_result();
                if ($result->num_rows === 0) {
                    $error = "Invalid slot ID: $slot_id does not exist in parking ID $parking_id.";
                } elseif ($result->fetch_assoc()['status'] === 'Occupied') {
                    $error = "Slot $slot_id is already occupied.";
                }
                $stmt->close();
            }

            if (empty($error)) {
                $params = $slot_id ? [$plate, $car_type, $parking_id, (string)$slot_id, $_SESSION['user_id']] : [$plate, $car_type, $parking_id, null, $_SESSION['user_id']];
                $result = call_sp($conn, "ParkVehicle", $params);

                if (isset($result['success']) && !empty($result['success'])) {
                    $message = "Vehicle parked in slot " . $result['success'][0]['slot_id'] . " in parking ID $parking_id.";
                } else {
                    $error = "Error parking vehicle: " . ($result['error'] ?? 'Unknown error');
                }
            }
        }
    }
}

// Handle exit and payment initiation
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_SESSION['loggedin']) && isset($_POST['exit_vehicle'])) {
    $plate = trim($_POST['plate_number']);
    $phone = trim($_POST['phone_number']);
    $parking_id = (int)$_POST['parking_id'];

    // For ParkingManager, use assigned parking
    if ($_SESSION['role'] === 'ParkingManager' && $parking_id != $_SESSION['parking_id']) {
        $error = "You can only manage your assigned parking.";
    } else {
        // Validate plate and phone format
        if (!preg_match('/^[A-Za-z0-9]{1,20}$/', $plate)) {
            $error = "Invalid plate number format. Use up to 20 alphanumeric characters.";
        } elseif (!preg_match('/^07[0-9]{8}$/', $phone)) {
            $error = "Invalid Momo phone number. Use format: 07xxxxxxxx";
        } else {
            // Start transaction
            $conn->begin_transaction();
            try {
                // Check if plate number exists in occupied slot
                $check_stmt = $conn->prepare("SELECT slot_id, car_type, entry_time FROM slots WHERE plate_number = ? AND status = 'Occupied' AND parking_id = ?");
                $check_stmt->bind_param("si", $plate, $parking_id);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                if ($check_result->num_rows === 0) {
                    $conn->rollback();
                    $error = "Vehicle with plate number '$plate' not found in any occupied slot in parking ID $parking_id.";
                    error_log("Exit attempt failed: Plate '$plate' not found in parking ID $parking_id.");
                } else {
                    $slot_row = $check_result->fetch_assoc();
                    $slot_id = $slot_row['slot_id'];

                    // Call ExitVehicle
                    $exit_result = call_sp($conn, "ExitVehicle", [$plate, $parking_id, $_SESSION['user_id']]);

                    if (isset($exit_result['success']) && !empty($exit_result['success'])) {
                        $fee = $exit_result['success'][0]['fee'];
                        $log_id = $exit_result['success'][0]['log_id'];

                        // Verify parking_logs entry
                        $log_stmt = $conn->prepare("SELECT id FROM parking_logs WHERE id = ?");
                        $log_stmt->bind_param("i", $log_id);
                        $log_stmt->execute();
                        $log_result = $log_stmt->get_result();
                        if ($log_row = $log_result->fetch_assoc()) {
                            // Initiate PayPack cash-in
                            try {
                                $payment_data = [
                                    'phone' => $phone,
                                    'amount' => (string)$fee // PayPack expects whole FRCS
                                ];
                                $payment_result = $paypack->Cashin($payment_data);

                                if (isset($payment_result['ref']) && $payment_result['status'] === 'pending') {
                                    $ref = $payment_result['ref'];

                                    $update_stmt = $conn->prepare("UPDATE parking_logs SET payment_ref = ?, paid = FALSE WHERE id = ?");
                                    $update_stmt->bind_param("si", $ref, $log_id);
                                    $update_stmt->execute();
                                    $update_stmt->close();

                                    // Commit transaction
                                    $conn->commit();
                                    $message = "Vehicle exited from parking ID $parking_id. Fee: " . number_format($fee, 2) . " FRCS. Momo payment initiated. Reference: $ref. Please complete payment via Momo.";
                                } else {
                                    $conn->rollback();
                                    $error = "Payment initiation failed: " . json_encode($payment_result);
                                    error_log("Payment initiation failed for plate '$plate' in parking ID $parking_id: " . json_encode($payment_result));
                                }
                            } catch (Exception $e) {
                                $conn->rollback();
                                $error = "PayPack Cashin error: " . $e->getMessage();
                                error_log("PayPack Cashin error for plate '$plate' in parking ID $parking_id: " . $e->getMessage());
                            }
                        } else {
                            $conn->rollback();
                            $error = "Could not find transaction log for plate '$plate' (log ID: $log_id) after exit.";
                            error_log("No transaction log found for plate '$plate' (log ID: $log_id) after exit in parking ID $parking_id.");
                        }
                        $log_stmt->close();
                    } else {
                        $conn->rollback();
                        $error = "Error exiting vehicle: " . ($exit_result['error'] ?? 'Unknown error');
                        error_log("ExitVehicle failed for plate '$plate' in parking ID $parking_id: " . ($exit_result['error'] ?? 'Unknown error'));
                    }
                }
                $check_stmt->close();
            } catch (Exception $e) {
                $conn->rollback();
                $error = "Transaction error: " . $e->getMessage();
                error_log("Transaction error for plate '$plate' in parking ID $parking_id: " . $e->getMessage());
            }
        }
    }
}

// Handle payment status check
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_SESSION['loggedin']) && isset($_POST['check_payment'])) {
    $ref = trim($_POST['payment_ref']);
    $parking_id = (int)$_POST['parking_id'];

    // For ParkingManager, restrict to assigned parking
    if ($_SESSION['role'] === 'ParkingManager' && $parking_id != $_SESSION['parking_id']) {
        $error = "You can only manage your assigned parking.";
    } else {
        try {
            $status_result = $paypack->Transaction($ref);

            if (isset($status_result['status']) && $status_result['status'] === 'successful') {
                $update_stmt = $conn->prepare("UPDATE parking_logs SET paid = TRUE WHERE payment_ref = ? AND parking_id = ?");
                $update_stmt->bind_param("si", $ref, $parking_id);
                if ($update_stmt->execute()) {
                    $message = "Payment confirmed for reference $ref in parking ID $parking_id.";
                } else {
                    $error = "Error updating payment status: " . $conn->error;
                    error_log("Failed to update payment status for ref '$ref' in parking ID $parking_id: " . $conn->error);
                }
                $update_stmt->close();
            } else {
                $error = "Payment not yet confirmed. Status: " . ($status_result['status'] ?? 'unknown');
                error_log("Payment check for ref '$ref' in parking ID $parking_id returned status: " . json_encode($status_result));
            }
        } catch (Exception $e) {
            $error = "PayPack Transaction check error: " . $e->getMessage();
            error_log("PayPack Transaction check error for ref '$ref' in parking ID $parking_id: " . $e->getMessage());
        }
    }
}

// Fetch parkings
$parkings = [];
$parking_query = $_SESSION['role'] === 'ParkingManager' 
    ? "SELECT p.parking_id, p.name, p.location, p.total_slots FROM parkings p JOIN manager_assignments ma ON p.parking_id = ma.parking_id WHERE ma.user_id = ?"
    : "SELECT parking_id, name, location, total_slots FROM parkings";
$stmt = $conn->prepare($parking_query);
if ($_SESSION['role'] === 'ParkingManager') {
    $stmt->bind_param("i", $_SESSION['user_id']);
}
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $parkings[] = $row;
}
$stmt->close();

// Fetch slots for selected parking
$selected_parking_id = $_SESSION['role'] === 'ParkingManager' ? $_SESSION['parking_id'] : (isset($_GET['parking_id']) ? (int)$_GET['parking_id'] : ($parkings[0]['parking_id'] ?? null));
$slots = [];
if ($selected_parking_id) {
    $stmt = $conn->prepare("SELECT slot_id, status, plate_number, car_type, entry_time FROM slots WHERE parking_id = ? ORDER BY slot_id");
    $stmt->bind_param("i", $selected_parking_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $slots[] = $row;
    }
    $stmt->close();
}

// Fetch income views
$daily_income = [];
$weekly_income = [];
$monthly_income = [];

$income_query = $_SESSION['role'] === 'ParkingManager' 
    ? "SELECT transaction_date, total_income FROM daily_income WHERE parking_id = ? ORDER BY transaction_date DESC LIMIT 7"
    : "SELECT parking_id, transaction_date, total_income FROM daily_income ORDER BY transaction_date DESC LIMIT 7";
$stmt = $conn->prepare($income_query);
if ($_SESSION['role'] === 'ParkingManager') {
    $stmt->bind_param("i", $_SESSION['parking_id']);
}
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $daily_income[] = $row;
}
$stmt->close();

$income_query = $_SESSION['role'] === 'ParkingManager' 
    ? "SELECT transaction_week, total_income FROM weekly_income WHERE parking_id = ? ORDER BY transaction_week DESC LIMIT 4"
    : "SELECT parking_id, transaction_week, total_income FROM weekly_income ORDER BY transaction_week DESC LIMIT 4";
$stmt = $conn->prepare($income_query);
if ($_SESSION['role'] === 'ParkingManager') {
    $stmt->bind_param("i", $_SESSION['parking_id']);
}
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $weekly_income[] = $row;
}
$stmt->close();

$income_query = $_SESSION['role'] === 'ParkingManager' 
    ? "SELECT transaction_month, total_income FROM monthly_income WHERE parking_id = ? ORDER BY transaction_month DESC LIMIT 12"
    : "SELECT parking_id, transaction_month, total_income FROM monthly_income ORDER BY transaction_month DESC LIMIT 12";
$stmt = $conn->prepare($income_query);
if ($_SESSION['role'] === 'ParkingManager') {
    $stmt->bind_param("i", $_SESSION['parking_id']);
}
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $monthly_income[] = $row;
}
$stmt->close();

// Fetch parking status
$parking_status = [];
$status_query = $_SESSION['role'] === 'ParkingManager' 
    ? "SELECT parking_id, parking_name, location, total_slots, occupied_slots, available_slots, daily_income FROM parking_status WHERE parking_id = ?"
    : "SELECT parking_id, parking_name, location, total_slots, occupied_slots, available_slots, daily_income FROM parking_status";
$stmt = $conn->prepare($status_query);
if ($_SESSION['role'] === 'ParkingManager') {
    $stmt->bind_param("i", $_SESSION['parking_id']);
}
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $parking_status[] = $row;
}
$stmt->close();

// Fetch manager activity
$manager_activity = [];
$activity_query = $_SESSION['role'] === 'ParkingManager' 
    ? "SELECT username, parking_id, parking_name, log_id, plate_number, car_type, slot_id, entry_time, exit_time, fee, paid, payment_ref FROM manager_activity WHERE user_id = ?"
    : "SELECT username, parking_id, parking_name, log_id, plate_number, car_type, slot_id, entry_time, exit_time, fee, paid, payment_ref FROM manager_activity";
$stmt = $conn->prepare($activity_query);
if ($_SESSION['role'] === 'ParkingManager') {
    $stmt->bind_param("i", $_SESSION['user_id']);
}
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $manager_activity[] = $row;
}
$stmt->close();

// Dynamic Stats
$total_income = 0;
$income_query = $_SESSION['role'] === 'ParkingManager' 
    ? "SELECT SUM(total_income) AS grand_total FROM monthly_income WHERE parking_id = ?"
    : "SELECT SUM(total_income) AS grand_total FROM monthly_income";
$stmt = $conn->prepare($income_query);
if ($_SESSION['role'] === 'ParkingManager') {
    $stmt->bind_param("i", $_SESSION['parking_id']);
}
$stmt->execute();
$result = $stmt->get_result();
if ($row = $result->fetch_assoc()) {
    $total_income = $row['grand_total'] ?? 0;
}
$stmt->close();

$parked_vehicles = 0;
$slots_query = $_SESSION['role'] === 'ParkingManager' 
    ? "SELECT COUNT(*) AS parked FROM slots WHERE status = 'Occupied' AND parking_id = ?"
    : "SELECT COUNT(*) AS parked FROM slots WHERE status = 'Occupied'";
$stmt = $conn->prepare($slots_query);
if ($_SESSION['role'] === 'ParkingManager') {
    $stmt->bind_param("i", $_SESSION['parking_id']);
}
$stmt->execute();
$result = $stmt->get_result();
if ($row = $result->fetch_assoc()) {
    $parked_vehicles = $row['parked'];
}
$stmt->close();

$total_slots = 0;
$slots_query = $_SESSION['role'] === 'ParkingManager' 
    ? "SELECT SUM(total_slots) AS total FROM parkings WHERE parking_id = ?"
    : "SELECT SUM(total_slots) AS total FROM parkings";
$stmt = $conn->prepare($slots_query);
if ($_SESSION['role'] === 'ParkingManager') {
    $stmt->bind_param("i", $_SESSION['parking_id']);
}
$stmt->execute();
$result = $stmt->get_result();
if ($row = $result->fetch_assoc()) {
    $total_slots = $row['total'];
}
$stmt->close();

$available_slots = $total_slots - $parked_vehicles;

// Fetch managers for assignment
$managers = [];
$stmt = $conn->prepare("SELECT id, username FROM users WHERE role = 'ParkingManager'");
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $managers[] = $row;
}
$stmt->close();

// Paid Transactions
$paid_transactions = [];
$paid_query = $_SESSION['role'] === 'ParkingManager' 
    ? "SELECT plate_number, car_type, slot_id, entry_time, exit_time, fee FROM parking_logs WHERE parking_id = ? AND DATE(exit_time) = CURDATE() AND paid = TRUE AND plate_number IS NOT NULL AND car_type IS NOT NULL AND slot_id IS NOT NULL AND entry_time IS NOT NULL AND exit_time IS NOT NULL AND fee IS NOT NULL ORDER BY exit_time DESC"
    : "SELECT parking_id, plate_number, car_type, slot_id, entry_time, exit_time, fee FROM parking_logs WHERE DATE(exit_time) = CURDATE() AND paid = TRUE AND plate_number IS NOT NULL AND car_type IS NOT NULL AND slot_id IS NOT NULL AND entry_time IS NOT NULL AND exit_time IS NOT NULL AND fee IS NOT NULL ORDER BY exit_time DESC";
$stmt = $conn->prepare($paid_query);
if ($_SESSION['role'] === 'ParkingManager') {
    $stmt->bind_param("i", $_SESSION['parking_id']);
}
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $paid_transactions[] = $row;
}
$stmt->close();

// Unpaid Parked
$unpaid_parked = [];
$unpaid_query = $_SESSION['role'] === 'ParkingManager' 
    ? "SELECT slot_id, plate_number, car_type, entry_time FROM slots WHERE status = 'Occupied' AND parking_id = ? ORDER BY slot_id"
    : "SELECT parking_id, slot_id, plate_number, car_type, entry_time FROM slots WHERE status = 'Occupied' ORDER BY parking_id, slot_id";
$stmt = $conn->prepare($unpaid_query);
if ($_SESSION['role'] === 'ParkingManager') {
    $stmt->bind_param("i", $_SESSION['parking_id']);
}
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $unpaid_parked[] = $row;
}
$stmt->close();

// Pending Payments
$pending_payments = [];
$pending_query = $_SESSION['role'] === 'ParkingManager' 
    ? "SELECT id, plate_number, car_type, slot_id, entry_time, exit_time, fee, payment_ref FROM parking_logs WHERE parking_id = ? AND paid = FALSE AND exit_time IS NOT NULL ORDER BY exit_time DESC"
    : "SELECT parking_id, id, plate_number, car_type, slot_id, entry_time, exit_time, fee, payment_ref FROM parking_logs WHERE paid = FALSE AND exit_time IS NOT NULL ORDER BY parking_id, exit_time DESC";
$stmt = $conn->prepare($pending_query);
if ($_SESSION['role'] === 'ParkingManager') {
    $stmt->bind_param("i", $_SESSION['parking_id']);
}
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $pending_payments[] = $row;
}
$stmt->close();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Parking Management System</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: '#1D4ED8',
                        secondary: '#1F2937',
                    }
                }
            }
        }
    </script>
</head>
<body class="bg-gray-100 font-sans">
    <div class="min-h-screen flex flex-col">
        <!-- Header -->
        <header class="bg-primary text-white p-4">
            <div class="container mx-auto flex justify-between items-center">
                <h1 class="text-2xl font-bold">Parking Management System</h1>
                <?php if (isset($_SESSION['loggedin'])): ?>
                    <div class="flex items-center space-x-4">
                        <span class="text-sm">Logged in as: <?php echo htmlspecialchars($_SESSION['role']); ?> (<?php echo htmlspecialchars($_SESSION['username'] ?? 'User'); ?>)</span>
                        <?php if ($_SESSION['role'] === 'ParkingManager' && isset($_SESSION['parking_id'])): ?>
                            <span class="text-xs bg-secondary px-2 py-1 rounded">Assigned: Parking ID <?php echo $_SESSION['parking_id']; ?></span>
                        <?php endif; ?>
                        <a href="?logout=true" class="bg-red-500 hover:bg-red-600 px-4 py-2 rounded">Logout</a>
                    </div>
                <?php endif; ?>
            </div>
        </header>

        <!-- Main Content -->
        <main class="container mx-auto p-6 flex-grow">
            <!-- Messages and Errors -->
            <?php if ($message): ?>
                <div class="bg-green-100 text-green-800 p-4 rounded mb-6"><?php echo htmlspecialchars($message); ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="bg-red-100 text-red-800 p-4 rounded mb-6"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <!-- Login -->
            <?php if (!isset($_SESSION['loggedin']) || $action === 'login'): ?>
                <div class="bg-white p-6 rounded-lg shadow-md max-w-md mx-auto">
                    <h2 class="text-xl font-semibold mb-4">Login</h2>
                    <form method="POST" action="">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? bin2hex(random_bytes(32))); ?>">
                        <div class="mb-4">
                            <label for="username" class="block text-sm font-medium text-gray-700">Username</label>
                            <input type="text" id="username" name="username" class="mt-1 w-full p-2 border rounded focus:outline-none focus:ring-2 focus:ring-primary" required aria-describedby="username-error">
                            <?php if (isset($error) && strpos($error, 'username') !== false): ?>
                                <p id="username-error" class="text-red-600 text-sm mt-1"><?php echo htmlspecialchars($error); ?></p>
                            <?php endif; ?>
                        </div>
                        <div class="mb-4">
                            <label for="password" class="block text-sm font-medium text-gray-700">Password</label>
                            <input type="password" id="password" name="password" class="mt-1 w-full p-2 border rounded focus:outline-none focus:ring-2 focus:ring-primary" required aria-describedby="password-error">
                            <?php if (isset($error) && strpos($error, 'password') !== false): ?>
                                <p id="password-error" class="text-red-600 text-sm mt-1"><?php echo htmlspecialchars($error); ?></p>
                            <?php endif; ?>
                        </div>
                        <button type="submit" name="login" class="w-full bg-primary text-white p-2 rounded hover:bg-blue-600">Login</button>
                    </form>
                    <p class="mt-4 text-center">
                        <a href="?action=signup" class="text-primary hover:underline">Create an account</a>
                    </p>
                </div>

            <!-- Signup (unchanged) -->
            <?php elseif (!isset($_SESSION['loggedin']) && $action === 'signup'): ?>
                <div class="bg-white p-6 rounded-lg shadow-md max-w-md mx-auto">
                    <h2 class="text-xl font-semibold mb-4">Sign Up</h2>
                    <form method="POST" action="">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? bin2hex(random_bytes(32))); ?>">
                        <div class="mb-4">
                            <label for="signup_username" class="block text-sm font-medium text-gray-700">Username</label>
                            <input type="text" id="signup_username" name="username" class="mt-1 w-full p-2 border rounded focus:outline-none focus:ring-2 focus:ring-primary" required>
                        </div>
                        <div class="mb-4">
                            <label for="signup_password" class="block text-sm font-medium text-gray-700">Password</label>
                            <input type="password" id="signup_password" name="password" class="mt-1 w-full p-2 border rounded focus:outline-none focus:ring-2 focus:ring-primary" required>
                        </div>
                        <div class="mb-4">
                            <label for="role" class="block text-sm font-medium text-gray-700">Role</label>
                            <select id="role" name="role" class="mt-1 w-full p-2 border rounded focus:outline-none focus:ring-2 focus:ring-primary" required>
                                <option value="ParkingManager">Parking Manager</option>
                                <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'SuperAdmin'): ?>
                                    <option value="Admin">Admin</option>
                                <?php endif; ?>
                            </select>
                        </div>
                        <button type="submit" name="signup" class="w-full bg-primary text-white p-2 rounded hover:bg-blue-600">Sign Up</button>
                    </form>
                    <p class="mt-4 text-center">
                        <a href="?action=login" class="text-primary hover:underline">Back to Login</a>
                    </p>
                </div>

            <!-- Dashboard -->
            <?php elseif (isset($_SESSION['loggedin'])): ?>
                <?php if ($_SESSION['role'] === 'ParkingManager' && !isset($_SESSION['parking_id'])): ?>
                    <div class="bg-red-100 text-red-800 p-6 rounded-lg shadow-md text-center">
                        <h2 class="text-xl font-semibold mb-4">Access Issue</h2>
                        <p>No parking lot assigned to your account. Please contact a SuperAdmin or Admin to assign you to a parking location.</p>
                        <a href="?logout=true" class="bg-red-500 text-white px-4 py-2 rounded mt-4 inline-block">Logout</a>
                    </div>
                <?php else: ?>
                    <!-- Assigned Parking Info for ParkingManager -->
                    <?php if ($_SESSION['role'] === 'ParkingManager'): ?>
                        <?php
                        // Fetch assigned parking details for display
                        $assigned_parking = [];
                        $assign_stmt = $conn->prepare("SELECT p.name, p.location FROM parkings p JOIN manager_assignments ma ON p.parking_id = ma.parking_id WHERE ma.user_id = ?");
                        $assign_stmt->bind_param("i", $_SESSION['user_id']);
                        $assign_stmt->execute();
                        $assign_result = $assign_stmt->get_result();
                        if ($row = $assign_result->fetch_assoc()) {
                            $assigned_parking = $row;
                        }
                        $assign_stmt->close();
                        ?>
                        <div class="bg-blue-50 p-4 rounded-lg shadow-md mb-6">
                            <h3 class="text-lg font-semibold">Your Assigned Parking</h3>
                            <p><strong>Name:</strong> <?php echo htmlspecialchars($assigned_parking['name'] ?? 'N/A'); ?></p>
                            <p><strong>Location:</strong> <?php echo htmlspecialchars($assigned_parking['location'] ?? 'N/A'); ?></p>
                            <p><strong>Parking ID:</strong> <?php echo $_SESSION['parking_id']; ?></p>
                        </div>
                    <?php endif; ?>

                    <div class="bg-white p-6 rounded-lg shadow-md mb-6">
                        <h2 class="text-xl font-semibold mb-4">Dashboard</h2>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                            <div class="bg-gray-50 p-4 rounded-lg shadow">
                                <h3 class="text-lg font-semibold text-gray-700">Total Income</h3>
                                <p class="text-2xl text-primary"><?php echo number_format($total_income, 2); ?> FRCS</p>
                            </div>
                            <div class="bg-gray-50 p-4 rounded-lg shadow">
                                <h3 class="text-lg font-semibold text-gray-700">Parked Vehicles</h3>
                                <p class="text-2xl text-primary"><?php echo $parked_vehicles; ?></p>
                            </div>
                            <div class="bg-gray-50 p-4 rounded-lg shadow">
                                <h3 class="text-lg font-semibold text-gray-700">Available Slots</h3>
                                <p class="text-2xl text-primary"><?php echo $available_slots; ?></p>
                            </div>
                        </div>
                    </div>

                    <!-- Parking Selection (Only for Non-ParkingManager) -->
                    <?php if ($_SESSION['role'] !== 'ParkingManager' && !empty($parkings)): ?>
                        <div class="mb-6">
                            <label for="parking_id" class="block text-sm font-medium text-gray-700">Select Parking</label>
                            <select id="parking_id" onchange="window.location.href='?parking_id='+this.value" class="mt-1 w-full p-2 border rounded focus:outline-none focus:ring-2 focus:ring-primary">
                                <?php foreach ($parkings as $parking): ?>
                                    <option value="<?php echo $parking['parking_id']; ?>" <?php echo ($selected_parking_id ?? '') == $parking['parking_id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($parking['name'] . ' - ' . $parking['location']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>

                    <!-- Park Vehicle Form -->
                    <div class="bg-white p-6 rounded-lg shadow-md mb-6">
                        <h2 class="text-xl font-semibold mb-4">Park Vehicle</h2>
                        <form method="POST" action="">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? bin2hex(random_bytes(32))); ?>">
                            <input type="hidden" name="parking_id" value="<?php echo $selected_parking_id ?? $_SESSION['parking_id'] ?? ''; ?>">
                            <div class="mb-4">
                                <label for="plate_number" class="block text-sm font-medium text-gray-700">Plate Number</label>
                                <input type="text" id="plate_number" name="plate_number" pattern="[A-Za-z0-9]{1,20}" class="mt-1 w-full p-2 border rounded focus:outline-none focus:ring-2 focus:ring-primary" required aria-describedby="plate_number-error">
                                <?php if (isset($error) && strpos($error, 'plate number') !== false): ?>
                                    <p id="plate_number-error" class="text-red-600 text-sm mt-1"><?php echo htmlspecialchars($error); ?></p>
                                <?php endif; ?>
                            </div>
                            <div class="mb-4">
                                <label for="car_type" class="block text-sm font-medium text-gray-700">Car Type</label>
                                <select id="car_type" name="car_type" class="mt-1 w-full p-2 border rounded focus:outline-none focus:ring-2 focus:ring-primary" required>
                                    <option value="TAXI">TAXI</option>
                                    <option value="IKAMMYO">IKAMMYO</option>
                                    <option value="LIFANI">LIFANI</option>
                                    <option value="DYNA_DAIHATSU">DYNA_DAIHATSU</option>
                                    <option value="HILUX">HILUX</option>
                                </select>
                            </div>
                            <div class="mb-4">
                                <label for="slot_id" class="block text-sm font-medium text-gray-700">Slot (Optional)</label>
                                <select id="slot_id" name="slot_id" class="mt-1 w-full p-2 border rounded focus:outline-none focus:ring-2 focus:ring-primary">
                                    <option value="">Auto-assign</option>
                                    <?php foreach ($slots as $slot): ?>
                                        <?php if ($slot['status'] === 'Available'): ?>
                                            <option value="<?php echo $slot['slot_id']; ?>">Slot <?php echo $slot['slot_id']; ?></option>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <button type="submit" name="park_vehicle" class="w-full bg-primary text-white p-2 rounded hover:bg-blue-600">Park Vehicle</button>
                        </form>
                    </div>

                    <!-- Exit Vehicle Form -->
                    <div class="bg-white p-6 rounded-lg shadow-md mb-6">
                        <h2 class="text-xl font-semibold mb-4">Exit Vehicle</h2>
                        <form method="POST" action="">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? bin2hex(random_bytes(32))); ?>">
                            <input type="hidden" name="parking_id" value="<?php echo $selected_parking_id ?? $_SESSION['parking_id'] ?? ''; ?>">
                            <div class="mb-4">
                                <label for="exit_plate_number" class="block text-sm font-medium text-gray-700">Plate Number</label>
                                <input type="text" id="exit_plate_number" name="plate_number" pattern="[A-Za-z0-9]{1,20}" class="mt-1 w-full p-2 border rounded focus:outline-none focus:ring-2 focus:ring-primary" required>
                            </div>
                            <div class="mb-4">
                                <label for="phone_number" class="block text-sm font-medium text-gray-700">Momo Phone Number</label>
                                <input type="text" id="phone_number" name="phone_number" pattern="07[0-9]{8}" placeholder="07xxxxxxxx" class="mt-1 w-full p-2 border rounded focus:outline-none focus:ring-2 focus:ring-primary" required>
                            </div>
                            <button type="submit" name="exit_vehicle" class="w-full bg-primary text-white p-2 rounded hover:bg-blue-600">Exit Vehicle</button>
                        </form>
                    </div>

                    <!-- Slots Table (Lot-Specific for ParkingManager) -->
                    <div class="bg-white p-6 rounded-lg shadow-md mb-6">
                        <h2 class="text-xl font-semibold mb-4">Current Slots (<?php echo $selected_parking_id ?? $_SESSION['parking_id'] ?? 'N/A'; ?>)</h2>
                        <?php if (empty($slots)): ?>
                            <p class="text-gray-500">No slots data available. Ensure your parking lot has slots configured.</p>
                        <?php else: ?>
                            <div class="overflow-x-auto">
                                <table class="w-full text-left border-collapse">
                                    <thead>
                                        <tr class="bg-secondary text-white">
                                            <th class="p-2">Slot ID</th>
                                            <th class="p-2">Status</th>
                                            <th class="p-2">Plate Number</th>
                                            <th class="p-2">Car Type</th>
                                            <th class="p-2">Entry Time</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($slots as $slot): ?>
                                            <tr class="border-b">
                                                <td class="p-2"><?php echo $slot['slot_id']; ?></td>
                                                <td class="p-2 <?php echo $slot['status'] === 'Occupied' ? 'text-red-600' : 'text-green-600'; ?>">
                                                    <?php echo $slot['status']; ?>
                                                </td>
                                                <td class="p-2"><?php echo htmlspecialchars($slot['plate_number'] ?? 'N/A'); ?></td>
                                                <td class="p-2"><?php echo htmlspecialchars($slot['car_type'] ?? 'N/A'); ?></td>
                                                <td class="p-2"><?php echo $slot['entry_time'] ?? 'N/A'; ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Admin Actions (Hidden for ParkingManager) -->
                    <?php if (in_array($_SESSION['role'], ['SuperAdmin', 'Admin'])): ?>
                        <!-- Add create_parking, assign_manager, update_rate forms here from previous versions -->
                    <?php endif; ?>

                <?php endif; ?>
            <?php endif; ?>
        </main>

        <!-- Footer -->
        <footer class="bg-secondary text-white p-4 text-center">
            <p>&copy; <?php echo date('Y'); ?> Parking Management System. All rights reserved.</p>
        </footer>
    </div>
</body>
</html>