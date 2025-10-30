<?php
// dashboard.php
session_start();
require 'db_connect.php';

// Check if the user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$role = $_SESSION['role'];
$username = $_SESSION['username'];
$page = $_GET['page'] ?? 'home';

// A map to link URL pages to file paths
$page_map = [
    'SuperAdmin' => [
        'home' => 'superadmin_dashboard.php',
        'manage_users' => 'superadmin_manage_users.php',
        'reports' => 'admin_reports.php',
    ],
    'Admin' => [
        'home' => 'admin_dashboard.php',
        'manage_parkings' => 'admin_manage_parkings.php',
        'manage_managers' => 'admin_manage_managers.php',
        'view_activity' => 'admin_view_activity.php',
        'reports' => 'admin_reports.php',
    ],
    'ParkingManager' => [
        'home' => 'manager_dashboard.php',
        'daily_report' => 'manager_daily_report.php',
    ],
];

// Check if the requested page is valid for the user's role
if (!isset($page_map[$role][$page])) {
    header("Location: dashboard.php?page=home");
    exit();
}

$content_file = 'views/' . $page_map[$role][$page];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 font-sans min-h-screen flex flex-col">

    <!-- Header with Hamburger Menu -->
    <header class="bg-white shadow-sm flex justify-between items-center p-4 md:p-6 sticky top-0 z-20">
        <div class="flex items-center space-x-4">
            <!-- Hamburger Menu Button (visible on mobile) -->
            <button id="menu-toggle" class="md:hidden text-gray-600 focus:outline-none" aria-label="Toggle sidebar">
                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16m-7 6h7" />
                </svg>
            </button>
            <h1 class="text-lg sm:text-xl font-semibold text-gray-800">
                <?= ucfirst(str_replace(['_', '-'], ' ', $page)) ?>
            </h1>
        </div>
        <div class="flex items-center space-x-4">
            <span class="text-gray-600 text-sm sm:text-base">Welcome, <strong><?= htmlspecialchars($username) ?></strong></span>
            <a href="logout.php" class="py-2 px-4 rounded-md shadow-sm text-sm font-medium text-white bg-red-600 hover:bg-red-700">Logout</a>
        </div>
    </header>

    <div class="flex flex-1">
        <!-- Sidebar -->
        <div id="sidebar" class="bg-gray-800 text-white w-full md:w-64 space-y-6 py-7 px-4 absolute md:static inset-y-0 left-0 transform -translate-x-full md:translate-x-0 transition-transform duration-200 ease-in-out z-10">
            <a href="dashboard.php" class="text-white flex items-center space-x-2 px-4">
                <svg class="h-8 w-8 text-indigo-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path>
                </svg>
                <span class="text-xl sm:text-2xl font-extrabold">Parking App</span>
            </a>

            <nav class="space-y-2">
                <a href="dashboard.php?page=home" class="block py-3 px-4 rounded transition duration-200 hover:bg-gray-700 text-sm sm:text-base">
                    Dashboard
                </a>
                <?php if ($role == 'SuperAdmin'): ?>
                    <a href="dashboard.php?page=manage_users" class="block py-3 px-4 rounded transition duration-200 hover:bg-gray-700 text-sm sm:text-base">
                        Manage Users
                    </a>
                    <a href="dashboard.php?page=reports" class="block py-3 px-4 rounded transition duration-200 hover:bg-gray-700 text-sm sm:text-base">
                        Reports
                    </a>
                <?php endif; ?>
                <?php if ($role == 'Admin'): ?>
                    <a href="dashboard.php?page=manage_parkings" class="block py-3 px-4 rounded transition duration-200 hover:bg-gray-700 text-sm sm:text-base">
                        Manage Parkings
                    </a>
                    <a href="dashboard.php?page=manage_managers" class="block py-3 px-4 rounded transition duration-200 hover:bg-gray-700 text-sm sm:text-base">
                        Manage Managers
                    </a>
                    <a href="dashboard.php?page=view_activity" class="block py-3 px-4 rounded transition duration-200 hover:bg-gray-700 text-sm sm:text-base">
                        View All Activity
                    </a>
                    <a href="dashboard.php?page=reports" class="block py-3 px-4 rounded transition duration-200 hover:bg-gray-700 text-sm sm:text-base">
                        Reports
                    </a>
                <?php endif; ?>
                <?php if ($role == 'ParkingManager'): ?>
                    <a href="dashboard.php?page=daily_report" class="block py-3 px-4 rounded transition duration-200 hover:bg-gray-700 text-sm sm:text-base">
                        Daily Report
                    </a>
                <?php endif; ?>
            </nav>
        </div>

        <!-- Main Content -->
        <main class="flex-1 overflow-y-auto p-4 sm:p-6">
            <div class="max-w-7xl mx-auto">
                <?php
                    if (file_exists($content_file)) {
                        include $content_file;
                    } else {
                        echo "<div class='bg-red-100 p-4 rounded-md text-red-800'>Page not found.</div>";
                    }
                ?>
            </div>
        </main>
    </div>

    <!-- JavaScript for Sidebar Toggle -->
    <script>
        const menuToggle = document.getElementById('menu-toggle');
        const sidebar = document.getElementById('sidebar');

        menuToggle.addEventListener('click', () => {
            sidebar.classList.toggle('-translate-x-full');
        });

        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', (event) => {
            if (!sidebar.contains(event.target) && !menuToggle.contains(event.target) && !sidebar.classList.contains('-translate-x-full')) {
                sidebar.classList.add('-translate-x-full');
            }
        });
    </script>
</body>
</html>