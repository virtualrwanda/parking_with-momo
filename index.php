<?php
// index.php
session_start();


// Check if the user is logged in and their role is set
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    // Redirect to the login page if not authenticated
    header("Location: login.php");
    exit();
}

// Check if the user has the correct role
if ($_SESSION['role'] != 'ParkingManager') {
    // Redirect to a different page or show an error
    header("Location: dashboard.php"); // Or wherever you handle other roles
    exit();
}

require 'db_connect.php';

// The rest of your parking manager logic goes here...
// Check if the user is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'ParkingManager') {
    // Redirect to login page if not logged in or not a manager
    header("Location: login.php");
    exit();
}

require 'db_connect.php';

// Use session variables for the current user and their assigned parking lot
$user_id = $_SESSION['user_id'];

// Get the parking_id assigned to this user from the manager_assignments table
try {
    $stmt = $conn->prepare("SELECT parking_id FROM manager_assignments WHERE user_id = :user_id");
    $stmt->bindParam(':user_id', $user_id);
    $stmt->execute();
    $assignment = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$assignment) {
        throw new Exception("You are not assigned to a parking lot.");
    }
    $parking_id = $assignment['parking_id'];

} catch (Exception $e) {
    // If no parking lot is assigned, log out and show an error
    session_destroy();
    header("Location: login.php?error=" . urlencode($e->getMessage()));
    exit();
}

$message = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $plate_number = trim($_POST['plate_number']);
    $action = $_POST['action'];

    if (empty($plate_number)) {
        $message = "Plate number is required.";
    } else {
        try {
            if ($action == 'park') {
                $car_type = $_POST['car_type'];
                $stmt = $conn->prepare("CALL ParkVehicle(:plate_number, :car_type, :parking_id, NULL, :user_id)");
                $stmt->bindParam(':plate_number', $plate_number);
                $stmt->bindParam(':car_type', $car_type);
                $stmt->bindParam(':parking_id', $parking_id);
                $stmt->bindParam(':user_id', $user_id);
                $stmt->execute();
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                $message = "Vehicle with plate number '{$plate_number}' parked successfully in slot " . htmlspecialchars($result['slot_id']) . ".";
            } elseif ($action == 'exit') {
                $stmt = $conn->prepare("CALL ExitVehicle(:plate_number, :parking_id, :user_id)");
                $stmt->bindParam(':plate_number', $plate_number);
                $stmt->bindParam(':parking_id', $parking_id);
                $stmt->bindParam(':user_id', $user_id);
                $stmt->execute();
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                $message = "Vehicle with plate number '{$plate_number}' exited successfully. Fee: FRW " . htmlspecialchars($result['fee']);
            }
        } catch (PDOException $e) {
            $message = "Error: " . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Parking Management</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 font-sans">
    <div class="p-6 text-right">
        <a href="logout.php" class="text-indigo-600 hover:text-indigo-900 font-medium">Logout</a>
    </div>
    <div class="min-h-screen flex items-center justify-center">
        <div class="bg-white p-8 rounded-lg shadow-md w-full max-w-md">
            <h1 class="text-2xl font-bold mb-6 text-center text-gray-800">Parking Management</h1>
            <h2 class="text-lg font-semibold mb-4 text-center text-gray-600">
                Logged in as: <?= htmlspecialchars($_SESSION['username']) ?>
            </h2>

            <?php if (!empty($message)): ?>
                <div class="bg-blue-100 border-l-4 border-blue-500 text-blue-700 p-4 mb-4" role="alert">
                    <p class="font-bold">Status</p>
                    <p><?= htmlspecialchars($message) ?></p>
                </div>
            <?php endif; ?>

            <form action="index.php" method="POST" class="space-y-4">
                <div>
                    <label for="plate_number" class="block text-sm font-medium text-gray-700">Plate Number</label>
                    <input type="text" id="plate_number" name="plate_number" required
                           class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm"
                           placeholder="e.g., RAF123E">
                </div>

                <div id="car_type_field">
                    <label for="car_type" class="block text-sm font-medium text-gray-700">Car Type</label>
                    <select id="car_type" name="car_type"
                            class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm">
                        <option value="Sedan">Sedan</option>
                        <option value="SUV">SUV</option>
                        <option value="Motorcycle">Motorcycle</option>
                        <option value="Truck">Truck</option>
                    </select>
                </div>

                <div class="flex space-x-4">
                    <button type="submit" name="action" value="park"
                            class="flex-1 py-2 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500">
                        Park Vehicle
                    </button>
                    <button type="submit" name="action" value="exit"
                            class="flex-1 py-2 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-red-600 hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500">
                        Exit Vehicle
                    </button>
                </div>
            </form>
        </div>
    </div>
</body>
</html>