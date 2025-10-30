<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transactions</title>
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
        <h1 class="text-2xl font-bold mb-4">Transaction History</h1>
        <a href="/dashboard" class="bg-blue-600 text-white p-2 rounded hover:bg-blue-700 mb-4 inline-block">Back to Dashboard</a>

        <!-- Income Summary -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-8">
            <div class="bg-white p-4 rounded-lg shadow-md">
                <h3 class="font-bold">Daily Income</h3>
                <p class="text-2xl">$<?php echo number_format($daily_income, 2); ?></p>
            </div>
            <div class="bg-white p-4 rounded-lg shadow-md">
                <h3 class="font-bold">Weekly Income</h3>
                <p class="text-2xl">$<?php echo number_format($weekly_income, 2); ?></p>
            </div>
            <div class="bg-white p-4 rounded-lg shadow-md">
                <h3 class="font-bold">Monthly Income</h3>
                <p class="text-2xl">$<?php echo number_format($monthly_income, 2); ?></p>
            </div>
        </div>

        <!-- Transaction Table -->
        <div class="bg-white p-6 rounded-lg shadow-md">
            <h2 class="text-xl font-bold mb-4">Transactions</h2>
            <table class="w-full border-collapse">
                <thead>
                    <tr class="bg-gray-200">
                        <th class="p-2 border">Plate Number</th>
                        <th class="p-2 border">Amount</th>
                        <th class="p-2 border">Payment Method</th>
                        <th class="p-2 border">Time</th>
                        <th class="p-2 border">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($transactions as $t): ?>
                        <tr>
                            <td class="p-2 border"><?php echo htmlspecialchars($t['plate_number']); ?></td>
                            <td class="p-2 border">$<?php echo number_format($t['amount'], 2); ?></td>
                            <td class="p-2 border"><?php echo $t['payment_method']; ?></td>
                            <td class="p-2 border"><?php echo $t['transaction_time']; ?></td>
                            <td class="p-2 border"><?php echo $t['success'] ? 'Success' : 'Failed'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>