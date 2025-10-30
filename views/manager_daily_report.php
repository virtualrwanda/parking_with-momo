<?php
// manager_dashboard.php

session_start();
require 'db_connect.php';

// Check if the user is logged in and is a manager.
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'manager') {
    header("Location: login.php");
    exit();
}

// Function to fetch all parked vehicles.
function getParkedVehicles($conn) {
    try {
        $stmt = $conn->prepare("SELECT id, vehicle_plate, slot_number, parking_area, entry_time FROM parking_logs WHERE exit_time IS NULL ORDER BY entry_time DESC");
        $stmt->execute();
        return $stmt->get_result();
    } catch (Exception $e) {
        // Log the error for debugging purposes.
        error_log("Error fetching parked vehicles: " . $e->getMessage());
        return false;
    }
}

// Function to get the status message from the URL.
function getStatusMessage() {
    if (isset($_GET['status'])) {
        return htmlspecialchars(urldecode($_GET['status']));
    }
    return null;
}

$status_message = getStatusMessage();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manager Dashboard - Umuhanda Parking</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background-color: #f4f7f9;
        }
        .sidebar {
            transition: transform 0.3s ease-in-out;
        }
        .sidebar.open {
            transform: translateX(0);
        }
        @media (max-width: 767px) {
            .sidebar {
                transform: translateX(-100%);
            }
        }
    </style>
</head>
<body class="flex flex-col md:flex-row min-h-screen">

    <!-- Sidebar -->
    <div class="sidebar fixed top-0 left-0 h-full w-64 bg-gray-800 text-white p-6 z-50 md:relative md:transform-none" id="sidebar">
        <div class="flex justify-between items-center mb-8">
            <h1 class="text-2xl font-bold">Manager Panel</h1>
            <button class="md:hidden text-white" id="close-sidebar">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <nav>
            <ul class="space-y-4">
                <li>
                    <a href="dashboard.php?page=home" class="flex items-center space-x-2 p-3 rounded-lg hover:bg-gray-700 transition-colors duration-200 <?php echo (!isset($_GET['page']) || $_GET['page'] == 'home') ? 'bg-gray-700' : ''; ?>">
                        <i class="fas fa-car"></i>
                        <span>Manage Parking</span>
                    </a>
                </li>
                <li>
                    <a href="logout.php" class="flex items-center space-x-2 p-3 rounded-lg hover:bg-red-600 transition-colors duration-200 mt-4">
                        <i class="fas fa-sign-out-alt"></i>
                        <span>Logout</span>
                    </a>
                </li>
            </ul>
        </nav>
    </div>

    <!-- Main Content -->
    <div class="flex-1 p-8 md:ml-64">
        <!-- Header for mobile -->
        <div class="flex justify-between items-center md:hidden mb-6">
            <button class="text-gray-800" id="open-sidebar">
                <i class="fas fa-bars text-2xl"></i>
            </button>
            <h1 class="text-2xl font-bold">Dashboard</h1>
        </div>

        <!-- Status message display -->
        <?php if ($status_message): ?>
            <div class="bg-blue-100 border border-blue-400 text-blue-700 px-4 py-3 rounded-md shadow-md mb-6" role="alert">
                <p class="font-bold">Status:</p>
                <p class="text-sm"><?php echo $status_message; ?></p>
            </div>
        <?php endif; ?>

        <!-- Main content section based on page -->
        <?php
        $page = $_GET['page'] ?? 'home';
        if ($page === 'home'):
        ?>
            <h2 class="text-3xl font-bold text-gray-800 mb-6">Currently Parked Vehicles</h2>
            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="overflow-x-auto">
                    <?php
                    $vehicles = getParkedVehicles($conn);
                    if ($vehicles && $vehicles->num_rows > 0):
                    ?>
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead>
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Vehicle Plate</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Slot</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Entry Time</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php while ($row = $vehicles->fetch_assoc()): ?>
                                <tr>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900"><?php echo htmlspecialchars($row['vehicle_plate']); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo htmlspecialchars($row['slot_number'] . ' (' . $row['parking_area'] . ')'); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo date('Y-m-d H:i:s', strtotime($row['entry_time'])); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <button class="bg-blue-500 hover:bg-blue-600 text-white font-bold py-2 px-4 rounded-md transition-colors duration-200 exit-vehicle-btn" data-log-id="<?php echo $row['id']; ?>" data-vehicle-plate="<?php echo htmlspecialchars($row['vehicle_plate']); ?>">
                                            Exit
                                        </button>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <p class="text-center text-gray-500">No vehicles are currently parked.</p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Modal for Exit and Payment -->
            <div id="exit-modal" class="fixed inset-0 bg-gray-600 bg-opacity-50 flex items-center justify-center hidden">
                <div class="bg-white rounded-lg shadow-xl p-6 w-11/12 md:w-1/2 lg:w-1/3">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="text-xl font-bold text-gray-800">Exit Vehicle</h3>
                        <button id="close-modal" class="text-gray-400 hover:text-gray-600 transition-colors duration-200">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>

                    <!-- Exit and Payment Form -->
                    <form id="payment-form" action="process_momo_payment.php" method="POST" class="space-y-4">
                        <div class="rounded-lg bg-gray-100 p-4">
                            <h4 class="font-bold mb-2">Vehicle Details:</h4>
                            <p><strong>Plate:</strong> <span id="modal-vehicle-plate"></span></p>
                            <p><strong>Fee:</strong> FRW <span id="modal-amount"></span></p>
                        </div>
                        <p class="text-gray-600 font-medium">Please enter the phone number for MoMo payment:</p>
                        <div>
                            <label for="phone_number" class="sr-only">Phone Number</label>
                            <input type="tel" name="phone_number" id="phone_number" placeholder="e.g., 078xxxxxxx" pattern="^07[0-9]{8}$" required class="w-full px-4 py-3 rounded-md border-2 border-gray-300 focus:outline-none focus:ring-2 focus:ring-blue-500 transition-colors duration-200">
                        </div>
                        <input type="hidden" name="log_id" id="modal-log-id">
                        <input type="hidden" name="amount" id="modal-amount-input">
                        <button type="submit" class="w-full bg-green-500 hover:bg-green-600 text-white font-bold py-3 px-4 rounded-md transition-colors duration-200">
                            Pay with MoMo
                        </button>
                    </form>
                </div>
            </div>

        <?php endif; ?>
    </div>

    <script>
        // Sidebar toggle for mobile
        const sidebar = document.getElementById('sidebar');
        const openSidebarBtn = document.getElementById('open-sidebar');
        const closeSidebarBtn = document.getElementById('close-sidebar');

        openSidebarBtn.addEventListener('click', () => {
            sidebar.classList.add('open');
        });

        closeSidebarBtn.addEventListener('click', () => {
            sidebar.classList.remove('open');
        });

        // Modal for exit and payment
        const exitButtons = document.querySelectorAll('.exit-vehicle-btn');
        const exitModal = document.getElementById('exit-modal');
        const closeModalBtn = document.getElementById('close-modal');
        const modalVehiclePlate = document.getElementById('modal-vehicle-plate');
        const modalAmount = document.getElementById('modal-amount');
        const modalLogIdInput = document.getElementById('modal-log-id');
        const modalAmountInput = document.getElementById('modal-amount-input');

        exitButtons.forEach(button => {
            button.addEventListener('click', async (e) => {
                const logId = e.target.getAttribute('data-log-id');
                const vehiclePlate = e.target.getAttribute('data-vehicle-plate');

                // Make a request to get the parking fee
                const response = await fetch(`get_parking_fee.php?log_id=${logId}`);
                const data = await response.json();

                if (data.fee !== undefined) {
                    modalVehiclePlate.textContent = vehiclePlate;
                    modalAmount.textContent = data.fee;
                    modalLogIdInput.value = logId;
                    modalAmountInput.value = data.fee;
                    exitModal.classList.remove('hidden');
                } else {
                    alert('Error calculating fee: ' + data.error);
                }
            });
        });

        closeModalBtn.addEventListener('click', () => {
            exitModal.classList.add('hidden');
        });

        // Close modal when clicking outside
        exitModal.addEventListener('click', (e) => {
            if (e.target === exitModal) {
                exitModal.classList.add('hidden');
            }
        });
    </script>
</body>
</html>
