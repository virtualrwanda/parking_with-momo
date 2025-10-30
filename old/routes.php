<?php
class Router {
    private $routes = [];
    private $base_path = ''; // Set to '/parking_system' if in subdirectory

    public function __construct($base_path = '') {
        $this->base_path = rtrim($base_path, '/');
    }

    public function get($path, $callback) {
        $this->routes['GET'][$path] = $callback;
    }

    public function post($path, $callback) {
        $this->routes['POST'][$path] = $callback;
    }

    public function dispatch($method, $uri) {
        // Strip base path and normalize URI
        $uri = parse_url($uri, PHP_URL_PATH);
        if ($this->base_path) {
            $uri = str_replace($this->base_path, '', $uri);
        }
        $uri = rtrim($uri, '/');
        $uri = $uri ?: '/';
        error_log("Requested URI: $uri, Method: $method");

        if (isset($this->routes[$method][$uri])) {
            return call_user_func($this->routes[$method][$uri]);
        } else {
            http_response_code(404);
            echo "404 Not Found: $uri";
            exit;
        }
    }
}

// Adjust base_path if app is in a subdirectory (e.g., /parking_system)
$router = new Router(''); // Set to '/parking_system' if needed

$router->get('/', function() {
    require_once 'auth.php';
    $auth = new Auth();
    $user = $auth->getCurrentUser();
    if ($user) {
        header("Location: /dashboard");
        exit;
    }
    include 'views/login.php';
});

$router->get('/login', function() {
    require_once 'auth.php';
    $auth = new Auth();
    $user = $auth->getCurrentUser();
    if ($user) {
        header("Location: /dashboard");
        exit;
    }
    include 'views/login.php';
});

$router->post('/login', function() {
    require_once 'auth.php';
    $auth = new Auth();
    if (isset($_POST['login'])) {
        if ($auth->login($_POST['username'], $_POST['password'])) {
            header("Location: /dashboard");
            exit;
        } else {
            $error = "Invalid credentials!";
            include 'views/login.php';
        }
    } else {
        include 'views/login.php';
    }
});

$router->get('/logout', function() {
    require_once 'auth.php';
    $auth = new Auth();
    $auth->logout();
});

$router->get('/dashboard', function() {
    require_once 'auth.php';
    $auth = new Auth();
    $user = $auth->getCurrentUser();
    if (!$user) {
        header("Location: /");
        exit;
    }
    include 'views/dashboard.php';
});

$router->get('/slots', function() {
    require_once 'auth.php';
    require_once 'parking.php';
    $auth = new Auth();
    $user = $auth->getCurrentUser();
    if (!$user) {
        header("Location: /");
        exit;
    }
    $parking = new ParkingSystem();
    $slots = $parking->getSlots();
    include 'views/slots.php';
});

$router->post('/slots/park', function() {
    require_once 'auth.php';
    require_once 'parking.php';
    $auth = new Auth();
    $user = $auth->getCurrentUser();
    if (!$user) {
        header("Location: /");
        exit;
    }
    $parking = new ParkingSystem();
    $message = '';
    if (isset($_POST['park'])) {
        $result = $parking->parkVehicle($_POST['plate'], $_POST['car_type'], $_POST['slot_id'] ?: null);
        $message = $result['message'];
    }
    $slots = $parking->getSlots();
    include 'views/slots.php';
});

$router->post('/slots/exit', function() {
    require_once 'auth.php';
    require_once 'parking.php';
    $auth = new Auth();
    $user = $auth->getCurrentUser();
    if (!$user) {
        header("Location: /");
        exit;
    }
    $parking = new ParkingSystem();
    $message = '';
    if (isset($_POST['exit'])) {
        $result = $parking->exitVehicle($_POST['plate']);
        $message = $result['success'] ? "Vehicle exited. Fee: $$result[fee]" : $result['message'];
    }
    $slots = $parking->getSlots();
    include 'views/slots.php';
});

$router->get('/transactions', function() {
    require_once 'auth.php';
    require_once 'transactions.php';
    $auth = new Auth();
    $user = $auth->getCurrentUser();
    if (!$user) {
        header("Location: /");
        exit;
    }
    $trans = new TransactionSystem();
    $transactions = $trans->getTransactions();
    $daily_income = $trans->calculateIncome('daily');
    $weekly_income = $trans->calculateIncome('weekly');
    $monthly_income = $trans->calculateIncome('monthly');
    include 'views/transactions.php';
});

$router->get('/users', function() {
    require_once 'auth.php';
    require_once 'users.php';
    $auth = new Auth();
    $user = $auth->getCurrentUser();
    if (!$user || !($user['role'] === 'SuperAdmin' || $user['role'] === 'Admin')) {
        header("Location: /dashboard");
        exit;
    }
    $um = new UserManagement();
    $users = $um->getUsers();
    include 'views/users.php';
});

$router->post('/users/add', function() {
    require_once 'auth.php';
    require_once 'users.php';
    $auth = new Auth();
    $user = $auth->getCurrentUser();
    if (!$user || !($user['role'] === 'SuperAdmin' || $user['role'] === 'Admin')) {
        header("Location: /dashboard");
        exit;
    }
    $um = new UserManagement();
    $message = '';
    if (isset($_POST['add_user'])) {
        if ($um->addUser($_POST['username'], $_POST['password'], $_POST['role'])) {
            $message = "User added successfully!";
        } else {
            $message = "Failed to add user!";
        }
    }
    $users = $um->getUsers();
    include 'views/users.php';
});

$router->post('/users/delete', function() {
    require_once 'auth.php';
    require_once 'users.php';
    $auth = new Auth();
    $user = $auth->getCurrentUser();
    if (!$user || $user['role'] !== 'SuperAdmin') {
        header("Location: /dashboard");
        exit;
    }
    $um = new UserManagement();
    $message = '';
    if (isset($_POST['delete_user'])) {
        if ($um->deleteUser($_POST['user_id'], $user['id'])) {
            $message = "User deleted successfully!";
        } else {
            $message = "Failed to delete user!";
        }
    }
    $users = $um->getUsers();
    include 'views/users.php';
});
?>