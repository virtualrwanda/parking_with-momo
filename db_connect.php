<?php
// db_connect.php

$servername = "localhost";
$username = "masacxpy_parking"; // Replace with your database username
$password = "masacxpy_parking"; // Replace with your database password
$dbname = "masacxpy_parking"; // Replace with your database name

try {
    $conn = new PDO("mysql:host=$servername;dbname=$dbname", $username, $password);
    // Set the PDO error mode to exception
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

// class Database {
//     private $host = 'localhost';
//     private $db_name = 'masacxpy_parking';
//     private $username = 'masacxpy_parking'; // Update with your MySQL username
//     private $password = 'masacxpy_parking'; // Update with your MySQL password
//     private $conn;

//     public function connect() {
//         $this->conn = null;
//         try {
//             $this->conn = new PDO("mysql:host=$this->host;dbname=$this->db_name", $this->username, $this->password);
//             $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
//         } catch (PDOException $e) {
//             echo "Connection Error: " . $e->getMessage();
//         }
//         return $this->conn;
//     }
// }
?>