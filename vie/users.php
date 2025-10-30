<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Users</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link href="/styles.css" rel="stylesheet">
</head>
<body class="bg-gray-100">
    <nav class="bg-blue-600 p-4 text-white">
        <div class="container mx-auto flex justify-between">
            <h1 class="text-2xl font-bold">Parking Management System</h1>
            <div>
                <span>Welcome, <?php echo htmlspecialchars($user['username']); ?> (<?php echo $user['role']; ?>)</span>
                <a href="/logout" class="ml-4 underline">Logout</a>
            </div>
        </div>
    </nav>
    <div class="container mx-auto mt-8">
        <h1 class="text-2xl font-bold mb-4">Manage Users</h1>
        <a href="/dashboard" class="bg-blue-600 text-white p-2 rounded hover:bg-blue-700 mb-4 inline-block">Back to Dashboard</a>

        <?php if (isset($message)): ?>
            <p class="<?php echo strpos($message, 'successfully') !== false ? 'text-green-500' : 'text-red-500'; ?> mb-4"><?php echo htmlspecialchars($message); ?></p>
        <?php endif; ?>

        <!-- Add User Form -->
        <div class="bg-white p-6 rounded-lg shadow-md mb-8">
            <h2 class="text-xl font-bold mb-4">Add User</h2>
            <form method="POST" action="/users/add">
                <div class="mb-4">
                    <label class="block text-gray-700">Username</label>
                    <input type="text" name="username" class="w-full p-2 border rounded" required>
                </div>
                <div class="mb-4">
                    <label class="block text-gray-700">Password</label>
                    <input type="password" name="password" class="w-full p-2 border rounded" required>
                </div>
                <div class="mb-4">
                    <label class="block text-gray-700">Role</label>
                    <select name="role" class="w-full p-2 border rounded" required>
                        <option value="SuperAdmin">SuperAdmin</option>
                        <option value="Admin">Admin</option>
                        <option value="ParkingManager">ParkingManager</option>
                    </select>
                </div>
                <button type="submit" name="add_user" class="bg-blue-600 text-white p-2 rounded hover:bg-blue-700">Add User</button>
            </form>
        </div>

        <!-- User List -->
        <div class="bg-white p-6 rounded-lg shadow-md">
            <h2 class="text-xl font-bold mb-4">Users</h2>
            <table class="w-full border-collapse">
                <thead>
                    <tr class="bg-gray-200">
                        <th class="p-2 border">Username</th>
                        <th class="p-2 border">Role</th>
                        <?php if ($user['role'] === 'SuperAdmin'): ?>
                            <th class="p-2 border">Action</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $u): ?>
                        <tr>
                            <td class="p-2 border"><?php echo htmlspecialchars($u['username']); ?></td>
                            <td class="p-2 border"><?php echo $u['role']; ?></td>
                            <?php if ($user['role'] === 'SuperAdmin'): ?>
                                <td class="p-2 border">
                                    <?php if ($u['id'] != $user['id']): ?>
                                        <form method="POST" action="/users/delete" class="inline">
                                            <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                                            <button type="submit" name="delete_user" class="text-red-500 hover:underline">Delete</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>