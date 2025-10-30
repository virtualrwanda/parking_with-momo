<?php
require_once 'config.php';

class ParkingSystem {
    private $db;
    private $fee_per_hour = [
        'Sedan' => 2.0,
        'SUV' => 3.0,
        'Truck' => 5.0,
        'Motorcycle' => 1.0
    ];

    public function __construct() {
        $database = new Database();
        $this->db = $database->connect();
    }

    /**
     * Retrieve all parking slots
     * @return array Array of slot details
     */
    public function getSlots() {
        $stmt = $this->db->query("SELECT * FROM slots ORDER BY slot_id");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Park a vehicle in a specified or available slot
     * @param string $plate Plate number of the vehicle
     * @param string $car_type Type of vehicle (Sedan, SUV, Truck, Motorcycle)
     * @param int|null $slot_id Specific slot ID or null for first available
     * @return array Result with success status and message
     */
    public function parkVehicle($plate, $car_type, $slot_id = null) {
        try {
            $stmt = $this->db->prepare("CALL ParkVehicle(:plate, :car_type, :slot_id)");
            $stmt->execute([
                'plate' => $plate,
                'car_type' => $car_type,
                'slot_id' => $slot_id
            ]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return [
                'success' => $result['message'] === 'Vehicle parked successfully',
                'message' => $result['message'],
                'slot_id' => $result['slot_id'] ?? null
            ];
        } catch (PDOException $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Exit a vehicle and process payment
     * @param string $plate Plate number of the vehicle
     * @return array Result with success status, fee, and message
     */
    public function exitVehicle($plate) {
        try {
            $stmt = $this->db->prepare("CALL ExitVehicle(:plate)");
            $stmt->execute(['plate' => $plate]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return [
                'success' => $result['message'] === 'Vehicle exited successfully',
                'fee' => $result['fee'] ?? null,
                'message' => $result['message']
            ];
        } catch (PDOException $e) {
            return [
                'success' => false,
                'fee' => null,
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Simulate MoMo payment (placeholder for real API integration)
     * @param string $plate Plate number
     * @param float $amount Amount to pay
     * @return bool Payment success status
     */
    private function simulateMoMoPayment($plate, $amount) {
        // Simulate MoMo API call (90% success rate for demo)
        return rand(0, 100) > 10;
    }
}
?>