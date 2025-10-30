<?php
// views/admin_manage_managers.php
// Handles CRUD and assignments for Parking Managers

$message = '';
$edit_manager = null;

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action == 'create_manager') {
        $username = trim($_POST['username']);
        $password = password_hash(trim($_POST['password']), PASSWORD_DEFAULT);
        $parking_id = intval($_POST['parking_id']);

        try {
            $stmt = $conn->prepare("INSERT INTO users (username, password, role) VALUES (?, ?, 'ParkingManager')");
            $stmt->execute([$username, $password]);
            $manager_id = $conn->lastInsertId();

            $assign_stmt = $conn->prepare("INSERT INTO manager_assignments (user_id, parking_id) VALUES (?, ?)");
            $assign_stmt->execute([$manager_id, $parking_id]);

            $message = "Parking Manager '{$username}' created and assigned successfully.";
        } catch (PDOException $e) {
            $message = "Error creating manager: " . $e->getMessage();
        }
    } elseif ($action == 'assign_manager') {
        $user_id = intval($_POST['user_id']);
        $parking_id = intval($_POST['parking_id']);
        
        try {
            // Delete old assignment if it exists
            $conn->prepare("DELETE FROM manager_assignments WHERE user_id = ?")->execute([$user_id]);
            // Insert new assignment
            $stmt = $conn->prepare("INSERT INTO manager_assignments (user_id, parking_id) VALUES (?, ?)");
            $stmt->execute([$user_id, $parking_id]);
            $message = "Manager ID {$user_id} assigned to Parking ID {$parking_id} successfully.";
        } catch (PDOException $e) {
            $message = "Error assigning manager: " . $e->getMessage();
        }
    } elseif ($action == 'delete_manager') {
        $user_id = intval($_POST['user_id']);
        try {
            // Delete assignment first due to foreign key constraint
            $conn->prepare("DELETE FROM manager_assignments WHERE user_id = ?")->execute([$user_id]);
            $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            $message = "Manager ID {$user_id} deleted successfully.";
        } catch (PDOException $e) {
            $message = "Error deleting manager: " . $e->getMessage();
        }
    }
    // Redirect to prevent form resubmission
    header("Location: dashboard.php?page=manage_managers&status=" . urlencode($message));
    exit();
}

// Fetch all managers and their assigned parking lots
try {
    $managers_stmt = $conn->query("SELECT u.id, u.username, ma.parking_id, p.name AS parking_name FROM users u LEFT JOIN manager_assignments ma ON u.id = ma.user_id LEFT JOIN parkings p ON ma.parking_id = p.parking_id WHERE u.role = 'ParkingManager'");
    $managers = $managers_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch all parking lots for the select dropdowns
    $parkings_stmt = $conn->query("SELECT parking_id, name FROM parkings");
    $parkings = $parkings_stmt->fetchAll(PDO::FETCH_ASSOC);

    if (isset($_GET['status'])) {
        $message = $_GET['status'];
    }
} catch (PDOException $e) {
    $message = "Error fetching data: " . $e->getMessage();
    $managers = [];
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
        <h2 class="text-xl font-bold mb-4">Create & Assign New Parking Manager</h2>
        <form action="dashboard.php?page=manage_managers" method="POST" class="space-y-4">
            <input type="hidden" name="action" value="create_manager">
            <div>
                <label for="username" class="block text-sm font-medium text-gray-700">Username</label>
                <input type="text" id="username" name="username" required class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md">
            </div>
            <div>
                <label for="password" class="block text-sm font-medium text-gray-700">Password</label>
                <input type="password" id="password" name="password" required class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md">
            </div>
            <div>
                <label for="parking_id" class="block text-sm font-medium text-gray-700">Assign Parking Lot</label>
                <select id="parking_id" name="parking_id" required class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md">
                    <?php foreach ($parkings as $parking): ?>
                        <option value="<?= htmlspecialchars($parking['parking_id']) ?>">
                            <?= htmlspecialchars($parking['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="w-full py-2 px-4 rounded-md shadow-sm text-white bg-green-600 hover:bg-green-700">Create & Assign</button>
        </form>
    </div>

    <div class="bg-white p-6 rounded-lg shadow-md">
        <h2 class="text-xl font-bold mb-4">Manage Existing Parking Managers</h2>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="py-3 px-6 text-left text-xs font-medium text-gray-500 uppercase">ID</th>
                        <th class="py-3 px-6 text-left text-xs font-medium text-gray-500 uppercase">Username</th>
                        <th class="py-3 px-6 text-left text-xs font-medium text-gray-500 uppercase">Assigned Parking</th>
                        <th class="py-3 px-6 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php foreach ($managers as $manager): ?>
                        <tr>
                            <td class="py-4 px-6"><?= htmlspecialchars($manager['id']) ?></td>
                            <td class="py-4 px-6"><?= htmlspecialchars($manager['username']) ?></td>
                            <td class="py-4 px-6">
                                <form action="dashboard.php?page=manage_managers" method="POST" class="flex items-center space-x-2">
                                    <input type="hidden" name="action" value="assign_manager">
                                    <input type="hidden" name="user_id" value="<?= $manager['id'] ?>">
                                    <select name="parking_id" class="py-1 px-2 border border-gray-300 rounded-md text-sm">
                                        <?php foreach ($parkings as $parking): ?>
                                            <option value="<?= htmlspecialchars($parking['parking_id']) ?>" <?= $manager['parking_id'] == $parking['parking_id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($parking['name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="submit" class="text-indigo-600 hover:text-indigo-900 text-sm font-medium">Assign</button>
                                </form>
                            </td>
                            <td class="py-4 px-6">
                                <form action="dashboard.php?page=manage_managers" method="POST" onsubmit="return confirm('Are you sure you want to delete this manager?');">
                                    <input type="hidden" name="action" value="delete_manager">
                                    <input type="hidden" name="user_id" value="<?= $manager['id'] ?>">
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