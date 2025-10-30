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
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.tailwindcss.min.css">
    <style>
        :root {
            --primary: #4361ee;
            --secondary: #3f37c9;
            --success: #4cc9f0;
            --danger: #f72585;
            --warning: #f8961e;
            --info: #4895ef;
            --light: #f8f9fa;
            --dark: #212529;
            --background: #f4f7f9;
        }
        
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&display=swap');
        
        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--background);
            color: #333;
            font-size: 0.85rem;
        }
        
        .dashboard-container {
            display: flex;
            min-height: 100vh;
        }
        
        .sidebar {
            width: 200px;
            background: linear-gradient(180deg, var(--primary) 0%, var(--secondary) 100%);
            color: white;
            transition: all 0.3s ease;
        }
        
        .main-content {
            flex: 1;
            padding: 1rem;
            transition: all 0.3s ease;
        }
        
        .card {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
            transition: transform 0.2s, box-shadow 0.2s;
        }
        
        .card:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }
        
        .slot {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 0.75rem;
            border-radius: 6px;
            color: white;
            font-weight: 500;
            transition: all 0.3s ease;
            font-size: 0.75rem;
        }
        
        .slot.occupied {
            background: linear-gradient(135deg, var(--danger) 0%, #b5179e 100%);
        }
        
        .slot.available {
            background: linear-gradient(135deg, var(--success) 0%, #4895ef 100%);
        }
        
        .stat-card {
            padding: 0.75rem;
            border-radius: 8px;
            color: white;
        }
        
        .stat-card.income {
            background: linear-gradient(135deg, #7209b7 0%, #560bad 100%);
        }
        
        .stat-card.vehicles {
            background: linear-gradient(135deg, var(--info) 0%, #4361ee 100%);
        }
        
        .stat-card.slots {
            background: linear-gradient(135deg, var(--warning) 0%, #f3722c 100%);
        }
        
        .btn-primary {
            background: var(--primary);
            color: white;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: 6px;
            font-weight: 500;
            font-size: 0.75rem;
            transition: all 0.2s;
        }
        
        .btn-primary:hover {
            background: var(--secondary);
            transform: translateY(-1px);
        }
        
        .form-control {
            width: 100%;
            padding: 0.5rem 0.75rem;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            font-size: 0.75rem;
            transition: all 0.2s;
        }
        
        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 2px rgba(67, 97, 238, 0.15);
        }
        
        .nav-link {
            display: flex;
            align-items: center;
            padding: 0.5rem 1rem;
            color: rgba(255, 255, 255, 0.8);
            text-decoration: none;
            border-left: 3px solid transparent;
            transition: all 0.2s;
            font-size: 0.75rem;
        }
        
        .nav-link:hover, .nav-link.active {
            background: rgba(255, 255, 255, 0.1);
            color: white;
            border-left-color: white;
        }
        
        .nav-link i {
            margin-right: 0.5rem;
            width: 16px;
            text-align: center;
        }
        
        @media (max-width: 768px) {
            .sidebar {
                width: 50px;
            }
            
            .sidebar .logo-text, .sidebar .nav-text {
                display: none;
            }
            
            .sidebar .nav-link i {
                margin-right: 0;
                font-size: 1rem;
            }
        }
        
        .notification {
            position: fixed;
            top: 0.5rem;
            right: 0.5rem;
            padding: 0.5rem 1rem;
            border-radius: 6px;
            color: white;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            transform: translateX(100%);
            transition: transform 0.3s ease;
            z-index: 1000;
            max-width: 250px;
            font-size: 0.75rem;
        }
        
        .notification.show {
            transform: translateX(0);
        }
        
        .notification.success {
            background: var(--success);
        }
        
        .notification.error {
            background: var(--danger);
        }
        
        .dataTable-container {
            background: white;
            border-radius: 6px;
            padding: 0.5rem;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
        }
        
        .dataTable-container table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
        }
        
        .dataTable-container th {
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            color: white;
            font-weight: 500;
            padding: 0.5rem;
            border-bottom: 1px solid var(--light);
            font-size: 0.75rem;
        }
        
        .dataTable-container td {
            padding: 0.5rem;
            border-bottom: 1px solid #e2e8f0;
            color: var(--dark);
            font-size: 0.7rem;
        }
        
        .dataTable-container tr:hover {
            background-color: rgba(67, 97, 238, 0.1);
        }
        
        .dataTable-container .dataTables_paginate .paginate_button {
            background: var(--primary);
            color: white !important;
            border-radius: 4px;
            margin: 0 3px;
            padding: 0.3rem 0.6rem;
            font-size: 0.7rem;
            transition: all 0.2s;
        }
        
        .dataTable-container .dataTables_paginate .paginate_button:hover {
            background: var(--secondary);
        }
        
        .dataTable-container .dataTables_filter input {
            border: 1px solid var(--primary);
            border-radius: 4px;
            padding: 0.3rem;
            margin-bottom: 0.5rem;
            font-size: 0.7rem;
        }
        
        .dataTable-container .dataTables_info {
            color: var(--dark);
            padding: 0.5rem 0;
            font-size: 0.7rem;
        }

        .pending-payment-btn {
            background: var(--warning);
            color: white;
            border: none;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.7rem;
            cursor: pointer;
        }
    </style>
</head>
<body>
    <?php if (isset($_SESSION['loggedin'])): ?>
    <div class="dashboard-container">
        <!-- Sidebar -->
        <div class="sidebar">
            <div class="p-3">
                <div class="flex items-center">
                    <i class="fas fa-parking text-lg"></i>
                    <span class="logo-text text-base font-bold ml-2">Parking Manager</span>
                </div>
            </div>
            
            <div class="mt-4">
                <a href="?action=dashboard" class="nav-link <?php echo $action === 'dashboard' ? 'active' : ''; ?>">
                    <i class="fas fa-home"></i>
                    <span class="nav-text">Dashboard</span>
                </a>
                <a href="?action=parking" class="nav-link <?php echo $action === 'parking' ? 'active' : ''; ?>">
                    <i class="fas fa-car"></i>
                    <span class="nav-text">Parking</span>
                </a>
                <a href="?action=reports" class="nav-link <?php echo $action === 'reports' ? 'active' : ''; ?>">
                    <i class="fas fa-chart-bar"></i>
                    <span class="nav-text">Reports</span>
                </a>
                <?php if (in_array($_SESSION['role'], ['SuperAdmin', 'Admin'])): ?>
                <a href="?action=management" class="nav-link <?php echo $action === 'management' ? 'active' : ''; ?>">
                    <i class="fas fa-cog"></i>
                    <span class="nav-text">Management</span>
                </a>
                <?php endif; ?>
            </div>
            
            <div class="mt-auto p-3 absolute bottom-0 w-full">
                <a href="?logout=true" class="nav-link">
                    <i class="fas fa-sign-out-alt"></i>
                    <span class="nav-text">Logout</span>
                </a>
            </div>
        </div>
        
        <!-- Main Content -->
        <div class="main-content">
            <!-- Header -->
            <div class="flex justify-between items-center mb-4">
                <h1 class="text-lg font-bold text-gray-800">Parking Dashboard</h1>
                <div class="flex items-center">
                    <div class="mr-2 p-1 bg-white rounded-full shadow">
                        <i class="fas fa-bell text-sm text-gray-600"></i>
                    </div>
                    <div class="flex items-center">
                        <div class="w-8 h-8 rounded-full bg-blue-500 flex items-center justify-center text-white font-bold text-sm">
                            <?php echo strtoupper(substr($_SESSION['role'], 0, 1)); ?>
                        </div>
                        <div class="ml-2">
                            <p class="text-xs font-medium"><?php echo htmlspecialchars($_SESSION['role']); ?></p>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Parking Selection -->
            <?php if ($action !== 'login' && $action !== 'signup'): ?>
            <div class="mb-4">
                <label class="block text-xs font-medium text-gray-700 mb-1">Select Parking</label>
                <select onchange="window.location.href='?action=<?php echo $action; ?>&parking_id='+this.value" class="form-control w-1/4">
                    <?php foreach ($parkings as $parking): ?>
                    <option value="<?php echo $parking['parking_id']; ?>" <?php echo $selected_parking_id == $parking['parking_id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($parking['name'] . ' (' . $parking['location'] . ')'); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            
            <?php if ($action === 'dashboard' && $selected_parking_id): ?>
            <!-- Stats Overview -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                <div class="stat-card income card">
                    <div class="flex justify-between items-center">
                        <div>
                            <p class="text-xs opacity-80">Total Income</p>
                            <p class="text-lg font-bold mt-1"><?php echo number_format($total_income, 2); ?> FRCS</p>
                        </div>
                        <i class="fas fa-dollar-sign text-lg opacity-70"></i>
                    </div>
                </div>
                
                <div class="stat-card vehicles card">
                    <div class="flex justify-between items-center">
                        <div>
                            <p class="text-xs opacity-80">Parked Vehicles</p>
                            <p class="text-lg font-bold mt-1"><?php echo $parked_vehicles; ?>/<?php echo $total_slots; ?></p>
                        </div>
                        <i class="fas fa-car text-lg opacity-70"></i>
                    </div>
                </div>
                
                <div class="stat-card slots card">
                    <div class="flex justify-between items-center">
                        <div>
                            <p class="text-xs opacity-80">Available Slots</p>
                            <p class="text-lg font-bold mt-1"><?php echo $available_slots; ?></p>
                        </div>
                        <i class="fas fa-parking text-lg opacity-70"></i>
                    </div>
                </div>
            </div>
            
            <!-- Parking Actions and Rate Configuration -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-4">
                <!-- Park Vehicle Form -->
                <div class="card p-4">
                    <h2 class="text-base font-semibold mb-2 text-gray-800">Park Vehicle</h2>
                    <form method="POST" action="">
                        <input type="hidden" name="parking_id" value="<?php echo $selected_parking_id; ?>">
                        <div class="mb-2">
                            <label class="block text-xs font-medium text-gray-700 mb-1">Plate Number</label>
                            <input type="text" name="plate_number" required class="form-control" pattern="[A-Za-z0-9]{1,20}" title="Up to 20 alphanumeric characters">
                        </div>
                        <div class="mb-2">
                            <label class="block text-xs font-medium text-gray-700 mb-1">Car Type</label>
                            <select name="car_type" required class="form-control">
                                <option value="Sedan">Sedan</option>
                                <option value="SUV">SUV</option>
                                <option value="Truck">Truck</option>
                                <option value="Motorcycle">Motorcycle</option>
                            </select>
                        </div>
                        <div class="mb-2">
                            <label class="block text-xs font-medium text-gray-700 mb-1">Specific Slot (Optional)</label>
                            <input type="number" name="slot_id" min="1" max="<?php echo $parkings[array_search($selected_parking_id, array_column($parkings, 'parking_id'))]['total_slots']; ?>" class="form-control">
                        </div>
                        <button type="submit" name="park_vehicle" class="btn-primary w-full">
                            <i class="fas fa-sign-in-alt mr-1"></i> Park
                        </button>
                    </form>
                </div>
                
                <!-- Exit Vehicle Form -->
                <div class="card p-4">
                    <h2 class="text-base font-semibold mb-2 text-gray-800">Exit Vehicle</h2>
                    <form method="POST" action="">
                        <input type="hidden" name="parking_id" value="<?php echo $selected_parking_id; ?>">
                        <div class="mb-2">
                            <label class="block text-xs font-medium text-gray-700 mb-1">Plate Number</label>
                            <input type="text" name="plate_number" required class="form-control" pattern="[A-Za-z0-9]{1,20}" title="Up to 20 alphanumeric characters">
                        </div>
                        <div class="mb-2">
                            <label class="block text-xs font-medium text-gray-700 mb-1">Momo Phone Number</label>
                            <input type="text" name="phone_number" required placeholder="07xxxxxxxx" class="form-control" pattern="07[0-9]{8}" title="Rwandan Momo format: 07xxxxxxxx">
                        </div>
                        <button type="submit" name="exit_vehicle" class="btn-primary w-full" style="background: var(--success);">
                            <i class="fas fa-sign-out-alt mr-1"></i> Exit & Pay via Momo
                        </button>
                    </form>
                </div>
                
                <!-- Rate Configuration Form -->
                <?php if (in_array($_SESSION['role'], ['SuperAdmin', 'Admin'])): ?>
                <div class="card p-4">
                    <h2 class="text-base font-semibold mb-2 text-gray-800">Configure Parking Rate</h2>
                    <p class="text-xs text-gray-600 mb-2">Current Rate: <?php echo number_format($current_rates[$selected_parking_id] ?? 1.6667, 4); ?> FRCS/minute (<?php echo number_format(($current_rates[$selected_parking_id] ?? 1.6667) * 60, 2); ?> FRCS/hour)</p>
                    <form method="POST" action="">
                        <input type="hidden" name="parking_id" value="<?php echo $selected_parking_id; ?>">
                        <div class="mb-2">
                            <label class="block text-xs font-medium text-gray-700 mb-1">Rate per Minute (FRCS)</label>
                            <input type="number" name="rate_per_minute" step="0.0001" min="0.0001" required class="form-control" value="<?php echo number_format($current_rates[$selected_parking_id] ?? 1.6667, 4); ?>">
                        </div>
                        <button type="submit" name="update_rate" class="btn-primary w-full" style="background: var(--warning);">
                            <i class="fas fa-cog mr-1"></i> Update Rate
                        </button>
                    </form>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Parking Slots Visualization -->
            <div class="card p-4 mb-4">
                <div class="flex justify-between items-center mb-3">
                    <h2 class="text-base font-semibold text-gray-800">Parking Slots Status</h2>
                    <div class="flex space-x-2">
                        <span class="flex items-center text-xs">
                            <span class="w-2 h-2 rounded-full bg-green-500 mr-1"></span> Available
                        </span>
                        <span class="flex items-center ml-2 text-xs">
                            <span class="w-2 h-2 rounded-full bg-red-500 mr-1"></span> Occupied
                        </span>
                    </div>
                </div>
                
                <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-5 lg:grid-cols-6 xl:grid-cols-8 gap-2">
                    <?php foreach ($slots as $slot): ?>
                    <div class="slot <?php echo $slot['status'] === 'Occupied' ? 'occupied' : 'available'; ?>">
                        <div class="text-base font-bold"><?php echo htmlspecialchars($slot['slot_id']); ?></div>
                        <div class="text-xs mt-0.5 uppercase"><?php echo htmlspecialchars($slot['status']); ?></div>
                        <?php if ($slot['status'] === 'Occupied'): ?>
                        <div class="text-xs text-center mt-0.5">
                            <div class="font-medium"><?php echo htmlspecialchars($slot['plate_number'] ?? 'N/A'); ?></div>
                            <div class="opacity-80"><?php echo htmlspecialchars($slot['car_type'] ?? 'N/A'); ?></div>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <?php if ($action === 'reports'): ?>
            <!-- Financial and Activity Reports -->
            <div class="card p-4">
                <h2 class="text-base font-semibold mb-3 text-gray-800">Reports</h2>
                
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                    <!-- Daily Income -->
                    <div>
                        <h3 class="text-sm font-medium text-gray-700 mb-2">Daily Income</h3>
                        <div class="bg-gray-50 rounded-lg p-2">
                            <?php if (empty($daily_income)): ?>
                            <p class="text-xs text-gray-500">No data</p>
                            <?php else: ?>
                            <?php foreach ($daily_income as $day): ?>
                            <div class="flex justify-between items-center py-1 border-b border-gray-200">
                                <span class="text-xs"><?php echo htmlspecialchars($day['transaction_date']); ?><?php echo isset($day['parking_id']) ? ' (Parking ID: ' . $day['parking_id'] . ')' : ''; ?></span>
                                <span class="font-medium text-green-600 text-xs"><?php echo number_format($day['total_income'], 2); ?> FRCS</span>
                            </div>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Weekly Income -->
                    <div>
                        <h3 class="text-sm font-medium text-gray-700 mb-2">Weekly Income</h3>
                        <div class="bg-gray-50 rounded-lg p-2">
                            <?php if (empty($weekly_income)): ?>
                            <p class="text-xs text-gray-500">No data</p>
                            <?php else: ?>
                            <?php foreach ($weekly_income as $week): ?>
                            <div class="flex justify-between items-center py-1 border-b border-gray-200">
                                <span class="text-xs"><?php echo htmlspecialchars($week['transaction_week']); ?><?php echo isset($week['parking_id']) ? ' (Parking ID: ' . $week['parking_id'] . ')' : ''; ?></span>
                                <span class="font-medium text-green-600 text-xs"><?php echo number_format($week['total_income'], 2); ?> FRCS</span>
                            </div>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Monthly Income -->
                    <div>
                        <h3 class="text-sm font-medium text-gray-700 mb-2">Monthly Income</h3>
                        <div class="bg-gray-50 rounded-lg p-2">
                            <?php if (empty($monthly_income)): ?>
                            <p class="text-xs text-gray-500">No data</p>
                            <?php else: ?>
                            <?php foreach ($monthly_income as $month): ?>
                            <div class="flex justify-between items-center py-1 border-b border-gray-200">
                                <span class="text-xs"><?php echo htmlspecialchars($month['transaction_month']); ?><?php echo isset($month['parking_id']) ? ' (Parking ID: ' . $month['parking_id'] . ')' : ''; ?></span>
                              <span class="font-medium text-green-600 text-xs"><?php echo number_format($month['total_income'], 2); ?> FRCS</span>
                            </div>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    </div>
                </div>
            </div>

            <!-- Manager Activity Report -->
            <div class="mt-4">
                <h3 class="text-sm font-medium text-gray-700 mb-2">Manager Activity</h3>
                <div class="dataTable-container">
                    <table id="managerActivityTable" class="display">
                        <thead>
                            <tr>
                                <th>Manager</th>
                                <th>Parking</th>
                                <th>Plate Number</th>
                                <th>Car Type</th>
                                <th>Slot</th>
                                <th>Entry Time</th>
                                <th>Exit Time</th>
                                <th>Fee (FRCS)</th>
                                <th>Paid</th>
                                <th>Payment Ref</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($manager_activity as $activity): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($activity['username']); ?></td>
                                <td><?php echo htmlspecialchars($activity['parking_name'] . ' (ID: ' . $activity['parking_id'] . ')'); ?></td>
                                <td><?php echo htmlspecialchars($activity['plate_number']); ?></td>
                                <td><?php echo htmlspecialchars($activity['car_type']); ?></td>
                                <td><?php echo htmlspecialchars($activity['slot_id']); ?></td>
                                <td><?php echo htmlspecialchars($activity['entry_time']); ?></td>
                                <td><?php echo htmlspecialchars($activity['exit_time'] ?? 'N/A'); ?></td>
                                <td><?php echo number_format($activity['fee'], 2); ?></td>
                                <td><?php echo $activity['paid'] ? 'Yes' : 'No'; ?></td>
                                <td><?php echo htmlspecialchars($activity['payment_ref'] ?? 'N/A'); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Parking Status Report -->
            <div class="mt-4">
                <h3 class="text-sm font-medium text-gray-700 mb-2">Parking Status</h3>
                <div class="dataTable-container">
                    <table id="parkingStatusTable" class="display">
                        <thead>
                            <tr>
                                <th>Parking Name</th>
                                <th>Location</th>
                                <th>Total Slots</th>
                                <th>Occupied Slots</th>
                                <th>Available Slots</th>
                                <th>Daily Income (FRCS)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($parking_status as $status): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($status['parking_name'] . ' (ID: ' . $status['parking_id'] . ')'); ?></td>
                                <td><?php echo htmlspecialchars($status['location']); ?></td>
                                <td><?php echo htmlspecialchars($status['total_slots']); ?></td>
                                <td><?php echo htmlspecialchars($status['occupied_slots']); ?></td>
                                <td><?php echo htmlspecialchars($status['available_slots']); ?></td>
                                <td><?php echo number_format($status['daily_income'], 2); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($action === 'parking' && $selected_parking_id): ?>
        <!-- Parking Management -->
        <div class="card p-4">
            <h2 class="text-base font-semibold mb-3 text-gray-800">Parking Operations</h2>

            <!-- Paid Transactions -->
            <div class="mb-4">
                <h3 class="text-sm font-medium text-gray-700 mb-2">Paid Transactions (Today)</h3>
                <div class="dataTable-container">
                    <table id="paidTransactionsTable" class="display">
                        <thead>
                            <tr>
                                <?php if ($_SESSION['role'] !== 'ParkingManager'): ?>
                                <th>Parking ID</th>
                                <?php endif; ?>
                                <th>Plate Number</th>
                                <th>Car Type</th>
                                <th>Slot</th>
                                <th>Entry Time</th>
                                <th>Exit Time</th>
                                <th>Fee (FRCS)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($paid_transactions as $transaction): ?>
                            <tr>
                                <?php if ($_SESSION['role'] !== 'ParkingManager'): ?>
                                <td><?php echo htmlspecialchars($transaction['parking_id']); ?></td>
                                <?php endif; ?>
                                <td><?php echo htmlspecialchars($transaction['plate_number']); ?></td>
                                <td><?php echo htmlspecialchars($transaction['car_type']); ?></td>
                                <td><?php echo htmlspecialchars($transaction['slot_id']); ?></td>
                                <td><?php echo htmlspecialchars($transaction['entry_time']); ?></td>
                                <td><?php echo htmlspecialchars($transaction['exit_time']); ?></td>
                                <td><?php echo number_format($transaction['fee'], 2); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Unpaid Parked Vehicles -->
            <div class="mb-4">
                <h3 class="text-sm font-medium text-gray-700 mb-2">Currently Parked (Unpaid)</h3>
                <div class="dataTable-container">
                    <table id="unpaidParkedTable" class="display">
                        <thead>
                            <tr>
                                <?php if ($_SESSION['role'] !== 'ParkingManager'): ?>
                                <th>Parking ID</th>
                                <?php endif; ?>
                                <th>Slot</th>
                                <th>Plate Number</th>
                                <th>Car Type</th>
                                <th>Entry Time</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($unpaid_parked as $parked): ?>
                            <tr>
                                <?php if ($_SESSION['role'] !== 'ParkingManager'): ?>
                                <td><?php echo htmlspecialchars($parked['parking_id']); ?></td>
                                <?php endif; ?>
                                <td><?php echo htmlspecialchars($parked['slot_id']); ?></td>
                                <td><?php echo htmlspecialchars($parked['plate_number']); ?></td>
                                <td><?php echo htmlspecialchars($parked['car_type']); ?></td>
                                <td><?php echo htmlspecialchars($parked['entry_time']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Pending Payments -->
            <div>
                <h3 class="text-sm font-medium text-gray-700 mb-2">Pending Payments</h3>
                <div class="dataTable-container">
                    <table id="pendingPaymentsTable" class="display">
                        <thead>
                            <tr>
                                <?php if ($_SESSION['role'] !== 'ParkingManager'): ?>
                                <th>Parking ID</th>
                                <?php endif; ?>
                                <th>Plate Number</th>
                                <th>Car Type</th>
                                <th>Slot</th>
                                <th>Entry Time</th>
                                <th>Exit Time</th>
                                <th>Fee (FRCS)</th>
                                <th>Payment Ref</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pending_payments as $pending): ?>
                            <tr>
                                <?php if ($_SESSION['role'] !== 'ParkingManager'): ?>
                                <td><?php echo htmlspecialchars($pending['parking_id']); ?></td>
                                <?php endif; ?>
                                <td><?php echo htmlspecialchars($pending['plate_number']); ?></td>
                                <td><?php echo htmlspecialchars($pending['car_type']); ?></td>
                                <td><?php echo htmlspecialchars($pending['slot_id']); ?></td>
                                <td><?php echo htmlspecialchars($pending['entry_time']); ?></td>
                                <td><?php echo htmlspecialchars($pending['exit_time']); ?></td>
                                <td><?php echo number_format($pending['fee'], 2); ?></td>
                                <td><?php echo htmlspecialchars($pending['payment_ref']); ?></td>
                                <td>
                                    <form method="POST" action="" class="inline">
                                        <input type="hidden" name="payment_ref" value="<?php echo htmlspecialchars($pending['payment_ref']); ?>">
                                        <input type="hidden" name="parking_id" value="<?php echo htmlspecialchars($pending['parking_id'] ?? $selected_parking_id); ?>">
                                        <button type="submit" name="check_payment" class="pending-payment-btn">
                                            <i class="fas fa-sync-alt mr-1"></i> Check Payment
                                        </button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($action === 'management' && in_array($_SESSION['role'], ['SuperAdmin', 'Admin'])): ?>
        <!-- Management Section -->
        <div class="card p-4">
            <h2 class="text-base font-semibold mb-3 text-gray-800">System Management</h2>

            <!-- Create Parking -->
            <div class="mb-4">
                <h3 class="text-sm font-medium text-gray-700 mb-2">Create New Parking</h3>
                <form method="POST" action="">
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1">Parking Name</label>
                            <input type="text" name="parking_name" required class="form-control">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1">Location</label>
                            <input type="text" name="parking_location" required class="form-control">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1">Total Slots</label>
                            <input type="number" name="total_slots" required min="1" class="form-control">
                        </div>
                    </div>
                    <button type="submit" name="create_parking" class="btn-primary mt-2">
                        <i class="fas fa-plus mr-1"></i> Create Parking
                    </button>
                </form>
            </div>

            <!-- Assign Manager -->
            <div class="mb-4">
                <h3 class="text-sm font-medium text-gray-700 mb-2">Assign Manager to Parking</h3>
                <form method="POST" action="">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1">Select Manager</label>
                            <select name="manager_id" required class="form-control">
                                <option value="">Select a Manager</option>
                                <?php foreach ($managers as $manager): ?>
                                <option value="<?php echo $manager['id']; ?>"><?php echo htmlspecialchars($manager['username']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1">Select Parking</label>
                            <select name="parking_id" required class="form-control">
                                <option value="">Select a Parking</option>
                                <?php foreach ($parkings as $parking): ?>
                                <option value="<?php echo $parking['parking_id']; ?>"><?php echo htmlspecialchars($parking['name'] . ' (' . $parking['location'] . ')'); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <button type="submit" name="assign_manager" class="btn-primary mt-2">
                        <i class="fas fa-user-plus mr-1"></i> Assign Manager
                    </button>
                </form>
            </div>

            <!-- Register User -->
            <div>
                <h3 class="text-sm font-medium text-gray-700 mb-2">Register New User</h3>
                <form method="POST" action="">
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1">Username</label>
                            <input type="text" name="username" required class="form-control">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1">Password</label>
                            <input type="password" name="password" required class="form-control">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1">Role</label>
                            <select name="role" required class="form-control">
                                <?php if ($_SESSION['role'] === 'SuperAdmin'): ?>
                                <option value="Admin">Admin</option>
                                <?php endif; ?>
                                <option value="ParkingManager">Parking Manager</option>
                            </select>
                        </div>
                    </div>
                    <button type="submit" name="signup" class="btn-primary mt-2">
                        <i class="fas fa-user-plus mr-1"></i> Register User
                    </button>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($action === 'login'): ?>
        <!-- Login Form -->
        <div class="max-w-sm mx-auto mt-10">
            <div class="card p-6">
                <h2 class="text-lg font-bold text-center mb-4 text-gray-800">Login</h2>
                <form method="POST" action="">
                    <div class="mb-4">
                        <label class="block text-xs font-medium text-gray-700 mb-1">Username</label>
                        <input type="text" name="username" required class="form-control">
                    </div>
                    <div class="mb-4">
                        <label class="block text-xs font-medium text-gray-700 mb-1">Password</label>
                        <input type="password" name="password" required class="form-control">
                    </div>
                    <button type="submit" name="login" class="btn-primary w-full">
                        <i class="fas fa-sign-in-alt mr-1"></i> Login
                    </button>
                </form>
                <?php if ($_SESSION['role'] === 'SuperAdmin'): ?>
                <div class="text-center mt-3">
                    <a href="?action=signup" class="text-xs text-blue-600 hover:underline">Register a new user</a>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($action === 'signup' && $_SESSION['role'] === 'SuperAdmin'): ?>
        <!-- Signup Form -->
        <div class="max-w-sm mx-auto mt-10">
            <div class="card p-6">
                <h2 class="text-lg font-bold text-center mb-4 text-gray-800">Register User</h2>
                <form method="POST" action="">
                    <div class="mb-4">
                        <label class="block text-xs font-medium text-gray-700 mb-1">Username</label>
                        <input type="text" name="username" required class="form-control">
                    </div>
                    <div class="mb-4">
                        <label class="block text-xs font-medium text-gray-700 mb-1">Password</label>
                        <input type="password" name="password" required class="form-control">
                    </div>
                    <div class="mb-4">
                        <label class="block text-xs font-medium text-gray-700 mb-1">Role</label>
                        <select name="role" required class="form-control">
                            <option value="Admin">Admin</option>
                            <option value="ParkingManager">Parking Manager</option>
                        </select>
                    </div>
                    <button type="submit" name="signup" class="btn-primary w-full">
                        <i class="fas fa-user-plus mr-1"></i> Register
                    </button>
                </form>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Notifications -->
    <?php if ($message): ?>
    <div class="notification success show"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
    <div class="notification error show"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
</div>

<?php else: ?>
<!-- Login/Signup for Non-Logged-In Users -->
<div class="max-w-sm mx-auto mt-10">
    <?php if ($action === 'login'): ?>
    <div class="card p-6">
        <h2 class="text-lg font-bold text-center mb-4 text-gray-800">Login</h2>
        <form method="POST" action="">
            <div class="mb-4">
                <label class="block text-xs font-medium text-gray-700 mb-1">Username</label>
                <input type="text" name="username" required class="form-control">
            </div>
            <div class="mb-4">
                <label class="block text-xs font-medium text-gray-700 mb-1">Password</label>
                <input type="password" name="password" required class="form-control">
            </div>
            <button type="submit" name="login" class="btn-primary w-full">
                <i class="fas fa-sign-in-alt mr-1"></i> Login
            </button>
        </form>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Scripts -->
<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.tailwindcss.min.js"></script>
<script>
$(document).ready(function() {
    $('#managerActivityTable').DataTable({
        pageLength: 5,
        lengthMenu: [5, 10, 25, 50],
        order: [[6, 'desc']],
        columnDefs: [
            { orderable: false, targets: [8, 9] }
        ]
    });
    
    $('#parkingStatusTable').DataTable({
        pageLength: 5,
        lengthMenu: [5, 10, 25, 50],
        order: [[0, 'asc']]
    });
    
    $('#paidTransactionsTable').DataTable({
        pageLength: 5,
        lengthMenu: [5, 10, 25, 50],
        order: [[<?php echo $_SESSION['role'] === 'ParkingManager' ? 4 : 5; ?>, 'desc']]
    });
    
    $('#unpaidParkedTable').DataTable({
        pageLength: 5,
        lengthMenu: [5, 10, 25, 50],
        order: [[<?php echo $_SESSION['role'] === 'ParkingManager' ? 0 : 1; ?>, 'asc']]
    });
    
    $('#pendingPaymentsTable').DataTable({
        pageLength: 5,
        lengthMenu: [5, 10, 25, 50],
        order: [[<?php echo $_SESSION['role'] === 'ParkingManager' ? 4 : 5; ?>, 'desc']],
        columnDefs: [
            { orderable: false, targets: [<?php echo $_SESSION['role'] === 'ParkingManager' ? 7 : 8; ?>] }
        ]
    });

    // Auto-hide notifications after 5 seconds
    setTimeout(function() {
        $('.notification').removeClass('show');
    }, 5000);
});
</script>
</body>
</html>
<?php
$conn->close();
?>
