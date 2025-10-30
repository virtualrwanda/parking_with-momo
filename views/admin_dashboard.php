<?php
// views/admin_dashboard.php
// This is included in dashboard.php.

try {
    // --- Get Vehicle Counts ---
    $daily_vehicles_stmt = $conn->query("SELECT COUNT(*) AS count FROM parking_logs WHERE DATE(entry_time) = CURDATE()");
    $daily_vehicles = $daily_vehicles_stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
    $monthly_vehicles_stmt = $conn->query("SELECT COUNT(*) AS count FROM parking_logs WHERE YEAR(entry_time) = YEAR(CURDATE()) AND MONTH(entry_time) = MONTH(CURDATE())");
    $monthly_vehicles = $monthly_vehicles_stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
    $annual_vehicles_stmt = $conn->query("SELECT COUNT(*) AS count FROM parking_logs WHERE YEAR(entry_time) = YEAR(CURDATE())");
    $annual_vehicles = $annual_vehicles_stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
    
    // --- Get Total Income ---
    $daily_income_stmt = $conn->query("SELECT SUM(fee) AS total FROM parking_logs WHERE DATE(exit_time) = CURDATE() AND paid = 1");
    $daily_income = $daily_income_stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
    $monthly_income_stmt = $conn->query("SELECT SUM(fee) AS total FROM parking_logs WHERE YEAR(exit_time) = YEAR(CURDATE()) AND MONTH(exit_time) = MONTH(CURDATE()) AND paid = 1");
    $monthly_income = $monthly_income_stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
    $annual_income_stmt = $conn->query("SELECT SUM(fee) AS total FROM parking_logs WHERE YEAR(exit_time) = YEAR(CURDATE()) AND paid = 1");
    $annual_income = $annual_income_stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
    
    // --- Data for Charts (Last 30 Days) ---
    $chart_data_stmt = $conn->query("
        SELECT 
            DATE(entry_time) AS day,
            COUNT(*) AS vehicles_count,
            SUM(CASE WHEN paid = 1 THEN fee ELSE 0 END) AS income
        FROM parking_logs
        WHERE entry_time >= CURDATE() - INTERVAL 30 DAY
        GROUP BY day
        ORDER BY day ASC
    ");
    $chart_data = $chart_data_stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    echo "<div class='bg-red-100 p-4 rounded-md text-red-800'>Database Error: " . htmlspecialchars($e->getMessage()) . "</div>";
    exit();
}

// Prepare data for Chart.js
$labels = json_encode(array_column($chart_data, 'day'));
$vehicle_data = json_encode(array_column($chart_data, 'vehicles_count'));
$income_data = json_encode(array_column($chart_data, 'income'));

?>

<div class="space-y-6">
    <div class="bg-white p-6 rounded-lg shadow-md">
        <h2 class="text-2xl font-bold text-gray-800">Admin Dashboard</h2>
        <p class="text-gray-600">Overview of system metrics.</p>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
        <div class="bg-white p-6 rounded-lg shadow-md">
            <h3 class="text-xl font-semibold text-gray-700 mb-4">Vehicles</h3>
            <div class="flex items-center justify-between mb-2">
                <span class="text-lg text-gray-500">Daily:</span>
                <span class="text-xl font-bold bg-indigo-100 text-indigo-800 px-3 py-1 rounded-full"><?= htmlspecialchars($daily_vehicles) ?></span>
            </div>
            <div class="flex items-center justify-between mb-2">
                <span class="text-lg text-gray-500">Monthly:</span>
                <span class="text-xl font-bold bg-indigo-100 text-indigo-800 px-3 py-1 rounded-full"><?= htmlspecialchars($monthly_vehicles) ?></span>
            </div>
            <div class="flex items-center justify-between">
                <span class="text-lg text-gray-500">Annual:</span>
                <span class="text-xl font-bold bg-indigo-100 text-indigo-800 px-3 py-1 rounded-full"><?= htmlspecialchars($annual_vehicles) ?></span>
            </div>
        </div>
        <div class="bg-white p-6 rounded-lg shadow-md">
            <h3 class="text-xl font-semibold text-gray-700 mb-4">Income</h3>
            <div class="flex items-center justify-between mb-2">
                <span class="text-lg text-gray-500">Daily:</span>
                <span class="text-xl font-bold text-green-800 px-3 py-1 rounded-full bg-green-100">FRW <?= number_format($daily_income, 2) ?></span>
            </div>
            <div class="flex items-center justify-between mb-2">
                <span class="text-lg text-gray-500">Monthly:</span>
                <span class="text-xl font-bold text-green-800 px-3 py-1 rounded-full bg-green-100">FRW <?= number_format($monthly_income, 2) ?></span>
            </div>
            <div class="flex items-center justify-between">
                <span class="text-lg text-gray-500">Annual:</span>
                <span class="text-xl font-bold text-green-800 px-3 py-1 rounded-full bg-green-100">FRW <?= number_format($annual_income, 2) ?></span>
            </div>
        </div>
    </div>
    
    <div class="bg-white p-6 rounded-lg shadow-md">
        <h3 class="text-xl font-semibold mb-4 text-gray-800">Last 30 Days Trends</h3>
        <canvas id="dailyTrendChart" class="w-full h-96"></canvas>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        const ctx = document.getElementById('dailyTrendChart');
        const labels = <?= $labels ?>;
        const vehicleData = <?= $vehicle_data ?>;
        const incomeData = <?= $income_data ?>;

        new Chart(ctx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Vehicles Parked',
                    data: vehicleData,
                    borderColor: 'rgb(75, 192, 192)',
                    tension: 0.1,
                    yAxisID: 'y'
                }, {
                    label: 'Total Income (FRW)',
                    data: incomeData,
                    borderColor: 'rgb(54, 162, 235)',
                    tension: 0.1,
                    yAxisID: 'y1'
                }]
            },
            options: {
                responsive: true,
                interaction: {
                    mode: 'index',
                    intersect: false,
                },
                scales: {
                    y: {
                        type: 'linear',
                        display: true,
                        position: 'left',
                        title: {
                            display: true,
                            text: 'Vehicles'
                        }
                    },
                    y1: {
                        type: 'linear',
                        display: true,
                        position: 'right',
                        title: {
                            display: true,
                            text: 'Income (FRW)'
                        },
                        grid: {
                            drawOnChartArea: false,
                        },
                    }
                }
            }
        });
    </script>
</div>