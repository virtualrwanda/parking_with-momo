<?php
// views/admin_view_activity.php
// View all parking activity

$message = '';
$logs = [];
$filter_status = $_GET['status'] ?? 'all';

// Build the SQL query
$sql = "SELECT pl.*, u.username AS manager_username, p.name AS parking_name 
        FROM parking_logs pl 
        JOIN users u ON pl.user_id = u.id 
        JOIN parkings p ON pl.parking_id = p.parking_id";

if ($filter_status != 'all') {
    $where_clause = '';
    if ($filter_status == 'paid') {
        $where_clause = " WHERE pl.paid = 1";
    } elseif ($filter_status == 'unpaid') {
        $where_clause = " WHERE pl.paid = 0 AND pl.exit_time IS NOT NULL";
    } elseif ($filter_status == 'pending') {
        $where_clause = " WHERE pl.exit_time IS NULL";
    }
    $sql .= $where_clause;
}
$sql .= " ORDER BY pl.entry_time DESC";

try {
    $logs_stmt = $conn->query($sql);
    $logs = $logs_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $message = "Error fetching parking logs: " . $e->getMessage();
}
?>

<div class="space-y-6">
    <div class="bg-white p-6 rounded-lg shadow-md">
        <h2 class="text-2xl font-bold mb-4">Parking Activity Log</h2>
        <?php if (!empty($message)): ?>
            <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-4" role="alert">
                <p><?= htmlspecialchars($message) ?></p>
            </div>
        <?php endif; ?>

        <div class="mb-6 flex space-x-4">
            <a href="dashboard.php?page=view_activity&status=all" class="py-2 px-4 rounded-md text-sm font-medium <?= $filter_status == 'all' ? 'bg-indigo-600 text-white' : 'bg-gray-200 text-gray-800' ?>">All</a>
            <a href="dashboard.php?page=view_activity&status=paid" class="py-2 px-4 rounded-md text-sm font-medium <?= $filter_status == 'paid' ? 'bg-indigo-600 text-white' : 'bg-gray-200 text-gray-800' ?>">Paid</a>
            <a href="dashboard.php?page=view_activity&status=unpaid" class="py-2 px-4 rounded-md text-sm font-medium <?= $filter_status == 'unpaid' ? 'bg-indigo-600 text-white' : 'bg-gray-200 text-gray-800' ?>">Unpaid</a>
            <a href="dashboard.php?page=view_activity&status=pending" class="py-2 px-4 rounded-md text-sm font-medium <?= $filter_status == 'pending' ? 'bg-indigo-600 text-white' : 'bg-gray-200 text-gray-800' ?>">Pending</a>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="py-3 px-6 text-left text-xs font-medium text-gray-500 uppercase">Log ID</th>
                        <th class="py-3 px-6 text-left text-xs font-medium text-gray-500 uppercase">Plate Number</th>
                        <th class="py-3 px-6 text-left text-xs font-medium text-gray-500 uppercase">Parking Lot</th>
                        <th class="py-3 px-6 text-left text-xs font-medium text-gray-500 uppercase">Entry Time</th>
                        <th class="py-3 px-6 text-left text-xs font-medium text-gray-500 uppercase">Exit Time</th>
                        <th class="py-3 px-6 text-left text-xs font-medium text-gray-500 uppercase">Fee</th>
                        <th class="py-3 px-6 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                        <th class="py-3 px-6 text-left text-xs font-medium text-gray-500 uppercase">Manager</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php foreach ($logs as $log): ?>
                        <tr>
                            <td class="py-4 px-6"><?= htmlspecialchars($log['log_id']) ?></td>
                            <td class="py-4 px-6"><?= htmlspecialchars($log['plate_number']) ?></td>
                            <td class="py-4 px-6"><?= htmlspecialchars($log['parking_name']) ?></td>
                            <td class="py-4 px-6"><?= htmlspecialchars($log['entry_time']) ?></td>
                            <td class="py-4 px-6"><?= $log['exit_time'] ? htmlspecialchars($log['exit_time']) : 'N/A' ?></td>
                            <td class="py-4 px-6">FRW <?= $log['fee'] ? number_format($log['fee'], 2) : 'N/A' ?></td>
                            <td class="py-4 px-6">
                                <?php
                                    if ($log['paid'] == 1) {
                                        echo '<span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">Paid</span>';
                                    } elseif ($log['exit_time'] && $log['paid'] == 0) {
                                        echo '<span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-yellow-100 text-yellow-800">Unpaid</span>';
                                    } else {
                                        echo '<span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-gray-100 text-gray-800">Pending</span>';
                                    }
                                ?>
                            </td>
                            <td class="py-4 px-6"><?= htmlspecialchars($log['manager_username']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>