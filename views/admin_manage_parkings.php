<?php
// views/admin_manage_parkings.php
// Handles CRUD for parking lots

$message = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action == 'create') {
        $name = trim($_POST['name']);
        $location = trim($_POST['location']);
        $total_slots = intval($_POST['total_slots']);

        try {
            $stmt = $conn->prepare("INSERT INTO parkings (name, location, total_slots) VALUES (?, ?, ?)");
            $stmt->execute([$name, $location, $total_slots]);
            $message = "Parking lot '{$name}' created successfully.";
        } catch (PDOException $e) {
            $message = "Error creating parking lot: " . $e->getMessage();
        }
    } elseif ($action == 'edit') {
        $id = intval($_POST['id']);
        $name = trim($_POST['name']);
        $location = trim($_POST['location']);
        $total_slots = intval($_POST['total_slots']);

        try {
            $stmt = $conn->prepare("UPDATE parkings SET name = ?, location = ?, total_slots = ? WHERE parking_id = ?");
            $stmt->execute([$name, $location, $total_slots, $id]);
            $message = "Parking lot ID {$id} updated successfully.";
        } catch (PDOException $e) {
            $message = "Error updating parking lot: " . $e->getMessage();
        }
    } elseif ($action == 'delete') {
        $id = intval($_POST['id']);
        try {
            $stmt = $conn->prepare("DELETE FROM parkings WHERE parking_id = ?");
            $stmt->execute([$id]);
            $message = "Parking lot ID {$id} deleted successfully.";
        } catch (PDOException $e) {
            $message = "Error deleting parking lot: " . $e->getMessage();
        }
    }
    // Redirect to prevent form resubmission
    header("Location: dashboard.php?page=manage_parkings&status=" . urlencode($message));
    exit();
}

// Fetch all parking lots to display
try {
    $parkings_stmt = $conn->query("SELECT * FROM parkings");
    $parkings = $parkings_stmt->fetchAll(PDO::FETCH_ASSOC);
    if (isset($_GET['status'])) {
        $message = $_GET['status'];
    }
} catch (PDOException $e) {
    $message = "Error fetching parking lots: " . $e->getMessage();
    $parkings = [];
}
?>

<div class="space-y-6">
    <?php if (!empty($message)): ?>
        <div class="bg-blue-100 border-l-4 border-blue-500 text-blue-700 p-4" role="alert">
            <p><?= htmlspecialchars($message) ?></p>
        </div>
    <?php endif; ?>

    <div class="bg-white p-6 rounded-lg shadow-md">
        <h2 class="text-xl font-bold mb-4">Create New Parking Lot</h2>
        <form action="dashboard.php?page=manage_parkings" method="POST" class="space-y-4">
            <input type="hidden" name="action" value="create">
            <div>
                <label for="name" class="block text-sm font-medium text-gray-700">Name</label>
                <input type="text" id="name" name="name" required class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md">
            </div>
            <div>
                <label for="location" class="block text-sm font-medium text-gray-700">Location</label>
                <input type="text" id="location" name="location" required class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md">
            </div>
            <div>
                <label for="total_slots" class="block text-sm font-medium text-gray-700">Total Slots</label>
                <input type="number" id="total_slots" name="total_slots" required class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md">
            </div>
            <button type="submit" class="w-full py-2 px-4 rounded-md shadow-sm text-white bg-green-600 hover:bg-green-700">Create Parking Lot</button>
        </form>
    </div>

    <div class="bg-white p-6 rounded-lg shadow-md">
        <h2 class="text-xl font-bold mb-4">Manage Existing Parking Lots</h2>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="py-3 px-6 text-left text-xs font-medium text-gray-500 uppercase">ID</th>
                        <th class="py-3 px-6 text-left text-xs font-medium text-gray-500 uppercase">Name</th>
                        <th class="py-3 px-6 text-left text-xs font-medium text-gray-500 uppercase">Location</th>
                        <th class="py-3 px-6 text-left text-xs font-medium text-gray-500 uppercase">Total Slots</th>
                        <th class="py-3 px-6 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php foreach ($parkings as $parking): ?>
                        <tr>
                            <td class="py-4 px-6"><?= htmlspecialchars($parking['parking_id']) ?></td>
                            <td class="py-4 px-6"><?= htmlspecialchars($parking['name']) ?></td>
                            <td class="py-4 px-6"><?= htmlspecialchars($parking['location']) ?></td>
                            <td class="py-4 px-6"><?= htmlspecialchars($parking['total_slots']) ?></td>
                            <td class="py-4 px-6 flex space-x-2">
                                <form action="dashboard.php?page=manage_parkings" method="POST">
                                    <input type="hidden" name="action" value="edit">
                                    <input type="hidden" name="id" value="<?= $parking['parking_id'] ?>">
                                    <input type="hidden" name="name" value="<?= htmlspecialchars($parking['name']) ?>">
                                    <input type="hidden" name="location" value="<?= htmlspecialchars($parking['location']) ?>">
                                    <input type="hidden" name="total_slots" value="<?= htmlspecialchars($parking['total_slots']) ?>">
                                    <button type="button" class="text-indigo-600 hover:text-indigo-900 text-sm font-medium" onclick="toggleEditForm(this)">Edit</button>
                                </form>
                                <form action="dashboard.php?page=manage_parkings" method="POST" onsubmit="return confirm('Are you sure you want to delete this parking lot?');">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= $parking['parking_id'] ?>">
                                    <button type="submit" class="text-red-600 hover:text-red-900 text-sm font-medium">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>