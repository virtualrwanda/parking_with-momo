<?php
require_once 'config.php';

class TransactionSystem {
    private $db;

    public function __construct() {
        $database = new Database();
        $this->db = $database->connect();
    }

    public function getTransactions() {
        $stmt = $this->db->query("SELECT * FROM transactions ORDER BY transaction_time DESC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function calculateIncome($period) {
        $now = new DateTime();
        $start = clone $now;

        if ($period === 'daily') {
            $start->setTime(0, 0, 0);
        } elseif ($period === 'weekly') {
            $start->modify('monday this week')->setTime(0, 0, 0);
        } elseif ($period === 'monthly') {
            $start->modify('first day of this month')->setTime(0, 0, 0);
        } else {
            return 0;
        }

        $stmt = $this->db->prepare("SELECT SUM(amount) as total FROM transactions WHERE transaction_time >= :start AND transaction_time <= :end AND success = TRUE");
        $stmt->execute(['start' => $start->format('Y-m-d H:i:s'), 'end' => $now->format('Y-m-d H:i:s')]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result['total'] ?: 0;
    }
}
?>