<?php
define('BASE_PATH', ''); // Set to '/parking_system' if in subdirectory
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link href="<?php echo BASE_PATH; ?>/styles.css" rel="stylesheet">
</head>
<body class="bg-gray-100">
    <nav class="bg-blue-600 p-4 text-white">
        <div class="container mx-auto flex justify-between">
            <h1 class="text-2xl font-bold">Parking Management System</h1>
            <div>
                <span>Welcome, <?php echo htmlspecialchars($user['username']); ?> (<?php echo $user['role']; ?>)</span>
                <a href="<?php echo BASE_PATH; ?>/logout" class="ml-4 underline">Logout</a>
            </div>
        </div>
    </nav>
    <div class="container mx-auto mt-8">
        <h1 class="text-2xl font-bold mb-4">Dashboard</h1>
        <div class="flex space-x-4">
            <a href="<?php echo BASE_PATH; ?>/slots" class="bg-blue-600 text-white p-3 rounded hover:bg-blue-700">Manage Slots</a>
            <a href="<?php echo BASE_PATH; ?>/transactions" class="bg-blue-600 text-white p-3 rounded hover:bg-blue-700">View Transactions</a>
            <?php if ($user['role'] === 'SuperAdmin' || $user['role'] === 'Admin'): ?>
                <a href="<?php echo BASE_PATH; ?>/users" class="bg-blue-600 text-white p-3 rounded hover:bg-blue-700">Manage Users</a>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>