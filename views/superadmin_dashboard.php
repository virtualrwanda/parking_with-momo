<?php
// views/superadmin_dashboard.php
// This is included in dashboard.php.

// Get basic stats for a quick overview
try {
    $total_parkings_stmt = $conn->query("SELECT COUNT(*) AS total_parkings FROM parkings");
    $total_parkings = $total_parkings_stmt->fetch(PDO::FETCH_ASSOC)['total_parkings'];

    $total_users_stmt = $conn->query("SELECT COUNT(*) AS total_users FROM users");
    $total_users = $total_users_stmt->fetch(PDO::FETCH_ASSOC)['total_users'];
    
    // Total income from all paid transactions
    $total_income_stmt = $conn->query("SELECT SUM(total_income) AS grand_total FROM daily_income");
    $total_income = $total_income_stmt->fetch(PDO::FETCH_ASSOC)['grand_total'] ?? 0;

} catch (PDOException $e) {
    echo "<div class='bg-red-100 p-4 rounded-md text-red-800'>Database Error: " . htmlspecialchars($e->getMessage()) . "</div>";
    exit();
}
?>

<div class="space-y-6">
    <div class="bg-white p-6 rounded-lg shadow-md">
        <h2 class="text-2xl font-bold mb-4 text-gray-800">SuperAdmin Dashboard</h2>
        <p class="text-lg text-gray-600">Full system control and overview.</p>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
        <div class="bg-white p-6 rounded-lg shadow-md text-center">
            <h3 class="text-lg font-semibold text-gray-700">Total Parking Lots</h3>
            <p class="text-4xl font-bold text-indigo-600 mt-2"><?= htmlspecialchars($total_parkings) ?></p>
        </div>
        
        <div class="bg-white p-6 rounded-lg shadow-md text-center">
            <h3 class="text-lg font-semibold text-gray-700">Total System Users</h3>
            <p class="text-4xl font-bold text-indigo-600 mt-2"><?= htmlspecialchars($total_users) ?></p>
        </div>
        
        <div class="bg-white p-6 rounded-lg shadow-md text-center">
            <h3 class="text-lg font-semibold text-gray-700">Total Income</h3>
            <p class="text-4xl font-bold text-green-600 mt-2">FRW <?= number_format($total_income, 2) ?></p>
        </div>
    </div>

    <div class="bg-white p-6 rounded-lg shadow-md">
        <h3 class="text-xl font-semibold mb-2 text-gray-800">Administrative Actions</h3>
        <div class="flex flex-wrap gap-4 mt-4">
            <a href="dashboard.php?page=manage_users" class="py-2 px-4 rounded-md shadow-sm text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700">
                Manage Users
            </a>
            <a href="dashboard.php?page=manage_parkings" class="py-2 px-4 rounded-md shadow-sm text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700">
                Manage Parking Lots
            </a>
            <a href="dashboard.php?page=config_rates" class="py-2 px-4 rounded-md shadow-sm text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700">
                Configure Rates
            </a>
        </div>
    </div>
</div>