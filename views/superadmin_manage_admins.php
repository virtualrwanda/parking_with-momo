<?php
// views/superadmin_manage_admins.php
// This is included in dashboard.php.

$message = '';

// Handle POST requests for creating, editing, and deleting users
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action == 'create_user') {
        $username = trim($_POST['username']);
        $password = password_hash(trim($_POST['password']), PASSWORD_DEFAULT);
        $role = trim($_POST['role']);
        
        try {
            $stmt = $conn->prepare("INSERT INTO users (username, password, role, created_by) VALUES (:username, :password, :role, :created_by)");
            $stmt->bindParam(':username', $username);
            $stmt->bindParam(':password', $password);
            $stmt->bindParam(':role', $role);
            $stmt->bindParam(':created_by', $_SESSION['user_id']);
            $stmt->execute();
            $message = "User '{$username}' created successfully.";
        } catch (PDOException $e) {
            $message = "Error creating user: " . $e->getMessage();
        }
    } elseif ($action == 'edit_user') {
        $user_id = $_POST['user_id'];
        $role = trim($_POST['role']);

        try {
            $stmt = $conn->prepare("UPDATE users SET role = :role WHERE id = :user_id");
            $stmt->bindParam(':role', $role);
            $stmt->bindParam(':user_id', $user_id);
            $stmt->execute();
            $message = "User ID {$user_id} updated successfully.";
        } catch (PDOException $e) {
            $message = "Error updating user: " . $e->getMessage();
        }
    } elseif ($action == 'delete_user') {
        $user_id = $_POST['user_id'];

        try {
            if ($user_id == $_SESSION['user_id']) {
                $message = "Cannot delete your own account.";
            } else {
                // Delete user's assignments first to avoid foreign key constraints
                $conn->prepare("DELETE FROM manager_assignments WHERE user_id = ?")->execute([$user_id]);
                $stmt = $conn->prepare("DELETE FROM users WHERE id = :user_id");
                $stmt->bindParam(':user_id', $user_id);
                $stmt->execute();
                $message = "User ID {$user_id} deleted successfully.";
            }
        } catch (PDOException $e) {
            $message = "Error deleting user: " . $e->getMessage();
        }
    }
    // Redirect to prevent form resubmission on refresh
    header("Location: dashboard.php?page=manage_users&status=" . urlencode($message));
    exit();
}

// Fetch all users for display (excluding SuperAdmins)
try {
    $stmt = $conn->prepare("SELECT id, username, role, created_by FROM users WHERE role IN ('Admin', 'ParkingManager')");
    $stmt->execute();
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $users = [];
    $message = "Error fetching users: " . $e->getMessage();
}

// Display message if available (e.g., from redirection)
if (isset($_GET['status'])) {
    $message = $_GET['status'];
}
?>

<div class="space-y-6">
    <?php if (!empty($message)): ?>
        <div class="bg-blue-100 border-l-4 border-blue-500 text-blue-700 p-4" role="alert">
            <p><?= htmlspecialchars($message) ?></p>
        </div>
    <?php endif; ?>

    <div class="bg-white p-6 rounded-lg shadow-md">
        <h2 class="text-xl font-bold mb-4">Create New User</h2>
        <form action="dashboard.php?page=manage_users" method="POST" class="space-y-4">
            <input type="hidden" name="action" value="create_user">
            <div>
                <label for="username" class="block text-sm font-medium text-gray-700">Username</label>
                <input type="text" id="username" name="username" required class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md">
            </div>
            <div>
                <label for="password" class="block text-sm font-medium text-gray-700">Password</label>
                <input type="password" id="password" name="password" required class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md">
            </div>
            <div>
                <label for="role" class="block text-sm font-medium text-gray-700">Role</label>
                <select id="role" name="role" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md">
                    <option value="Admin">Admin</option>
                    <option value="ParkingManager">ParkingManager</option>
                </select>
            </div>
            <button type="submit" class="w-full py-2 px-4 rounded-md shadow-sm text-white bg-green-600 hover:bg-green-700">Create User</button>
        </form>
    </div>

    <div class="bg-white p-6 rounded-lg shadow-md">
        <h2 class="text-xl font-bold mb-4">Manage Existing Users</h2>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="py-3 px-6 text-left text-xs font-medium text-gray-500 uppercase">ID</th>
                        <th class="py-3 px-6 text-left text-xs font-medium text-gray-500 uppercase">Username</th>
                        <th class="py-3 px-6 text-left text-xs font-medium text-gray-500 uppercase">Role</th>
                        <th class="py-3 px-6 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php foreach ($users as $user): ?>
                        <tr>
                            <td class="py-4 px-6 whitespace-nowrap"><?= htmlspecialchars($user['id']) ?></td>
                            <td class="py-4 px-6 whitespace-nowrap"><?= htmlspecialchars($user['username']) ?></td>
                            <td class="py-4 px-6 whitespace-nowrap">
                                <form action="dashboard.php?page=manage_users" method="POST" class="flex items-center space-x-2">
                                    <input type="hidden" name="action" value="edit_user">
                                    <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                    <select name="role" class="py-1 px-2 border border-gray-300 rounded-md text-sm">
                                        <option value="Admin" <?= $user['role'] == 'Admin' ? 'selected' : '' ?>>Admin</option>
                                        <option value="ParkingManager" <?= $user['role'] == 'ParkingManager' ? 'selected' : '' ?>>ParkingManager</option>
                                    </select>
                                    <button type="submit" class="text-indigo-600 hover:text-indigo-900 text-sm font-medium">Update</button>
                                </form>
                            </td>
                            <td class="py-4 px-6 whitespace-nowrap">
                                <form action="dashboard.php?page=manage_users" method="POST" onsubmit="return confirm('Are you sure you want to delete this user?');">
                                    <input type="hidden" name="action" value="delete_user">
                                    <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
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