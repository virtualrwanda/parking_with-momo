<?php
// views/manager_dashboard.php
// This is included in dashboard.php.

$user_id = $_SESSION['user_id'];
$message = '';
$payment_info = null; // Variable to hold payment details after exit

// Get the parking_id assigned to this manager
try {
    $stmt = $conn->prepare("SELECT ma.parking_id, p.name AS parking_name FROM manager_assignments ma JOIN parkings p ON ma.parking_id = p.parking_id WHERE user_id = :user_id");
    $stmt->bindParam(':user_id', $user_id);
    $stmt->execute();
    $assignment = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$assignment) {
        throw new Exception("You are not assigned to a parking lot.");
    }
    $parking_id = $assignment['parking_id'];
} catch (Exception $e) {
    echo "<div class='bg-red-100 p-4 rounded-md text-red-800'>Error: " . htmlspecialchars($e->getMessage()) . "</div>";
    exit();
}

// Handle POST requests for parking/exiting/paying
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action == 'park') {
        $plate_number = trim($_POST['plate_number']);
        $car_type = trim($_POST['car_type']);
        if (empty($plate_number)) {
            $message = "Plate number is required.";
        } else {
            try {
                $stmt = $conn->prepare("CALL ParkVehicle(:plate_number, :car_type, :parking_id, NULL, :user_id)");
                $stmt->bindParam(':plate_number', $plate_number);
                $stmt->bindParam(':car_type', $car_type);
                $stmt->bindParam(':parking_id', $parking_id);
                $stmt->bindParam(':user_id', $user_id);
                $stmt->execute();
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                $message = "Vehicle with plate number '{$plate_number}' parked successfully in slot " . htmlspecialchars($result['slot_id']) . ".";
            } catch (PDOException $e) {
                $message = "Error: " . $e->getMessage();
            }
        }
    } elseif ($action == 'exit') {
        $plate_number = trim($_POST['plate_number']);
        if (empty($plate_number)) {
            $message = "Plate number is required.";
        } else {
            try {
                // Call stored procedure to calculate fee
                $stmt = $conn->prepare("CALL ExitVehicle(:plate_number, :parking_id, :user_id)");
                $stmt->bindParam(':plate_number', $plate_number);
                $stmt->bindParam(':parking_id', $parking_id);
                $stmt->bindParam(':user_id', $user_id);
                $stmt->execute();
                $result = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($result['fee'] !== null) {
                    $payment_info = [
                        'log_id' => $result['log_id'],
                        'fee' => $result['fee'],
                        'plate_number' => $plate_number
                    ];
                    $message = "Fee calculated for '{$plate_number}'. Please proceed with payment.";
                } else {
                    $message = "Error: Vehicle not found or already exited.";
                }
            } catch (PDOException $e) {
                $message = "Error: " . $e->getMessage();
            }
        }
    }
}
?>

<div class="bg-white p-8 rounded-lg shadow-md w-full max-w-md mx-auto">
    <h2 class="text-2xl font-bold mb-6 text-center text-gray-800">Parking Manager Portal</h2>
    <p class="text-gray-600 text-center mb-4">Managing **<?= htmlspecialchars($assignment['parking_name']) ?>**</p>

    <?php if (!empty($message)): ?>
        <div class="bg-blue-100 border-l-4 border-blue-500 text-blue-700 p-4 mb-4" role="alert">
            <p class="font-bold">Status</p>
            <p><?= htmlspecialchars($message) ?></p>
        </div>
    <?php endif; ?>

    <form action="dashboard.php?page=home" method="POST" class="space-y-4">
        <div>
            <label for="plate_number" class="block text-sm font-medium text-gray-700">Plate Number</label>
            <input type="text" id="plate_number" name="plate_number" required
                   class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm">
        </div>
        <div>
            <label for="car_type" class="block text-sm font-medium text-gray-700">Car Type</label>
            <select id="car_type" name="car_type" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm">
                <option value="taxi">Taxi</option>
                <option value="hilux">Hilux</option>
                <option value="ikamyo">Ikamyo</option>
                <option value="dyna_Daihatsu">Dyna/Daihatsu</option>
                <option value="lifani">Lifani</option>
            </select>
        </div>
        <div class="flex space-x-4">
            <button type="submit" name="action" value="park"
                    class="flex-1 py-2 px-4 rounded-md shadow-sm text-sm font-medium text-white bg-green-600 hover:bg-green-700">
                Park Vehicle
            </button>
            <button type="submit" name="action" value="exit"
                    class="flex-1 py-2 px-4 rounded-md shadow-sm text-sm font-medium text-white bg-red-600 hover:bg-red-700">
                Exit Vehicle
            </button>
        </div>
    </form>

    <?php if ($payment_info): ?>
        <hr class="my-6">
        <div class="bg-gray-50 p-6 rounded-lg">
            <h3 class="text-xl font-bold mb-4">Payment Details</h3>
            <p class="text-gray-700 mb-2">**Plate Number:** <?= htmlspecialchars($payment_info['plate_number']) ?></p>
            <p class="text-gray-700 mb-4">**Total Fee:** <span class="text-green-600 font-bold">FRW <?= number_format($payment_info['fee'], 2) ?></span></p>

            <form action="process_momo_payment.php" method="POST" class="space-y-4">
                <input type="hidden" name="log_id" value="<?= htmlspecialchars($payment_info['log_id']) ?>">
                <input type="hidden" name="amount" value="<?= htmlspecialchars($payment_info['fee']) ?>">
                <div>
                    <label for="phone_number" class="block text-sm font-medium text-gray-700">Payer's Phone Number (MTN MoMo)</label>
                    <input type="tel" id="phone_number" name="phone_number" required
                           class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm">
                </div>
                <button type="submit" class="w-full py-2 px-4 rounded-md shadow-sm text-sm font-medium text-white bg-orange-500 hover:bg-orange-600">
                    Pay with MoMo
                </button>
            </form>
        </div>
    <?php endif; ?>
</div>