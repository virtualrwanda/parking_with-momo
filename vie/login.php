<?php
define('BASE_PATH', ''); // Set to '/parking_system' if in subdirectory
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Parking Management System</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link href="<?php echo BASE_PATH; ?>/styles.css" rel="stylesheet">
</head>
<body class="bg-gray-100">
    <nav class="bg-blue-600 p-4 text-white">
        <div class="container mx-auto">
            <h1 class="text-2xl font-bold">Parking Management System</h1>
        </div>
    </nav>
    <div class="container mx-auto mt-8">
        <div class="max-w-md mx-auto bg-white p-6 rounded-lg shadow-md">
            <h2 class="text-xl font-bold mb-4">Login</h2>
            <form method="POST" action="<?php echo BASE_PATH; ?>/login">
                <div class="mb-4">
                    <label class="block text-gray-700">Username</label>
                    <input type="text" name="username" class="w-full p-2 border rounded" required>
                </div>
                <div class="mb-4">
                    <label class="block text-gray-700">Password</label>
                    <input type="password" name="password" class="w-full p-2 border rounded" required>
                </div>
                <button type="submit" name="login" class="bg-blue-600 text-white p-2 rounded hover:bg-blue-700">Login</button>
            </form>
            <?php if (isset($error)): ?>
                <p class="text-red-500 mt-4"><?php echo htmlspecialchars($error); ?></p>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>