<?php
// views/admin_reports.php
// This is included in dashboard.php.

try {
    // Example: Fetch daily income
    $daily_income_stmt = $conn->query("SELECT * FROM daily_income ORDER BY transaction_date DESC LIMIT 7");
    $daily_income_data = $daily_income_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Example: Fetch weekly income
    $weekly_income_stmt = $conn->query("SELECT * FROM weekly_income ORDER BY transaction_week DESC LIMIT 4");
    $weekly_income_data = $weekly_income_stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    echo "<div class='bg-red-100 p-4 rounded-md text-red-800'>Database Error: " . htmlspecialchars($e->getMessage()) . "</div>";
    exit();
}
?>

<div class="space-y-6">
    <div class="bg-white p-6 rounded-lg shadow-md">
        <h2 class="text-2xl font-bold mb-4 text-gray-800">Financial Reports</h2>
        <p class="text-lg text-gray-600 mb-6">View detailed income reports by day and week.</p>

        <h3 class="text-xl font-semibold mb-2 text-gray-800">Daily Income (Last 7 Days)</h3>
        <div class="overflow-x-auto mb-6">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="py-3 px-6 text-left text-xs font-medium text-gray-500 uppercase">Parking Lot ID</th>
                        <th class="py-3 px-6 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                        <th class="py-3 px-6 text-left text-xs font-medium text-gray-500 uppercase">Total Income (FRW)</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php foreach ($daily_income_data as $row): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="py-4 px-6 whitespace-nowrap"><?= htmlspecialchars($row['parking_id']) ?></td>
                            <td class="py-4 px-6 whitespace-nowrap"><?= htmlspecialchars($row['transaction_date']) ?></td>
                            <td class="py-4 px-6 whitespace-nowrap">FRW <?= number_format($row['total_income'], 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <h3 class="text-xl font-semibold mb-2 text-gray-800">Weekly Income (Last 4 Weeks)</h3>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="py-3 px-6 text-left text-xs font-medium text-gray-500 uppercase">Parking Lot ID</th>
                        <th class="py-3 px-6 text-left text-xs font-medium text-gray-500 uppercase">Week</th>
                        <th class="py-3 px-6 text-left text-xs font-medium text-gray-500 uppercase">Total Income (FRW)</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php foreach ($weekly_income_data as $row): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="py-4 px-6 whitespace-nowrap"><?= htmlspecialchars($row['parking_id']) ?></td>
                            <td class="py-4 px-6 whitespace-nowrap"><?= htmlspecialchars($row['transaction_week']) ?></td>
                            <td class="py-4 px-6 whitespace-nowrap">FRW <?= number_format($row['total_income'], 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>