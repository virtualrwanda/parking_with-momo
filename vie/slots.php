<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Slots</title>
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
        <h1 class="text-2xl font-bold mb-4">Parking Slots</h1>
        <a href="/dashboard" class="bg-blue-600 text-white p-2 rounded hover:bg-blue-700 mb-4 inline-block">Back to Dashboard</a>
        
        <?php if (isset($message)): ?>
            <p class="<?php echo strpos($message, 'successfully') !== false ? 'text-green-500' : 'text-red-500'; ?> mb-4"><?php echo htmlspecialchars($message); ?></p>
        <?php endif; ?>
        
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <!-- Park Vehicle Form -->
            <div class="bg-white p-6 rounded-lg shadow-md">
                <h2 class="text-xl font-bold mb-4">Park Vehicle</h2>
                <form method="POST" action="/slots/park">
                    <div class="mb-4">
                        <label class="block text-gray-700">Plate Number</label>
                        <input type="text" name="plate" class="w-full p-2 border rounded" required>
                    </div>
                    <div class="mb-4">
                        <label class="block text-gray-700">Car Type</label>
                        <select name="car_type" class="w-full p-2 border rounded" required>
                            <option value="Sedan">Sedan</option>
                            <option value="SUV">SUV</option>
                            <option value="Truck">Truck</option>
                            <option value="Motorcycle">Motorcycle</option>
                        </select>
                    </div>
                    <div class="mb-4">
                        <label class="block text-gray-700">Slot ID (Optional)</label>
                        <input type="number" name="slot_id" class="w-full p-2 border rounded">
                    </div>
                    <button type="submit" name="park" class="bg-blue-600 text-white p-2 rounded hover:bg-blue-700">Park</button>
                </form>
            </div>

            <!-- Exit Vehicle Form -->
            <div class="bg-white p-6 rounded-lg shadow-md">
                <h2 class="text-xl font-bold mb-4">Exit Vehicle</h2>
                <form method="POST" action="/slots/exit">
                    <div class="mb-4">
                        <label class="block text-gray-700">Plate Number</label>
                        <input type="text" name="plate" class="w-full p-2 border rounded" required>
                    </div>
                    <button type="submit" name="exit" class="bg-blue-600 text-white p-2 rounded hover:bg-blue-700">Exit</button>
                </form>
            </div>
        </div>

        <!-- Slot Status -->
        <h2 class="text-xl font-bold mt-8 mb-4">Slot Status</h2>
        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4">
            <?php foreach ($slots as $slot): ?>
                <div class="bg-white p-4 rounded-lg shadow-md <?php echo $slot['status'] === 'Available' ? 'border-green-500' : 'border-red-500'; ?> border-2">
                    <h3 class="font-bold">Slot <?php echo $slot['slot_id']; ?></h3>
                    <p>Status: <span class="<?php echo $slot['status'] === 'Available' ? 'text-green-500' : 'text-red-500'; ?>"><?php echo $slot['status']; ?></span></p>
                    <p>Plate: <?php echo $slot['plate_number'] ?: 'N/A'; ?></p>
                    <p>Car Type: <?php echo $slot['car_type'] ?: 'N/A'; ?></p>
                    <p>Entry Time: <?php echo $slot['entry_time'] ?: 'N/A'; ?></p>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</body>
</html>