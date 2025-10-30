<?php
require_once 'config.php';

class Auth {
    private $db;

    public function __construct() {
        $database = new Database();
        $this->db = $database->connect();
    }

    public function login($username, $password) {
        $stmt = $this->db->prepare("SELECT * FROM users WHERE username = :username");
        $stmt->execute(['username' => $username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];
            return true;
        }
        return false;
    }

    public function logout() {
        session_destroy();
        header("Location: /");
        exit;
    }

    public function getCurrentUser() {
        if (isset($_SESSION['user_id'])) {
            return ['id' => $_SESSION['user_id'], 'username' => $_SESSION['username'], 'role' => $_SESSION['role']];
        }
        return null;
    }

    public function isRole($role) {
        return isset($_SESSION['role']) && $_SESSION['role'] === $role;
    }
}
?>