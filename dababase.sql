-- Create Database
CREATE DATABASE IF NOT EXISTS parking_system
CHARACTER SET utf8mb4
COLLATE utf8mb4_unicode_ci;
USE parking_system;

-- Users Table
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    role ENUM('SuperAdmin', 'Admin', 'ParkingManager') NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_username (username)
) ENGINE=InnoDB;

-- Slots Table
CREATE TABLE slots (
    slot_id INT PRIMARY KEY,
    status ENUM('Available', 'Occupied') DEFAULT 'Available' NOT NULL,
    plate_number VARCHAR(20),
    car_type ENUM('Sedan', 'SUV', 'Truck', 'Motorcycle'),
    entry_time DATETIME,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_plate_number (plate_number),
    INDEX idx_status (status)
) ENGINE=InnoDB;

-- Transactions Table
CREATE TABLE transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    slot_id INT NOT NULL,
    plate_number VARCHAR(20) NOT NULL,
    amount DECIMAL(10, 2) NOT NULL,
    payment_method ENUM('MoMo') NOT NULL,
    transaction_time DATETIME NOT NULL,
    success BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (slot_id) REFERENCES slots(slot_id) ON DELETE RESTRICT,
    INDEX idx_transaction_time (transaction_time),
    INDEX idx_success (success)
) ENGINE=InnoDB;

-- Initialize Slots
INSERT INTO slots (slot_id, status) VALUES
(1, 'Available'), (2, 'Available'), (3, 'Available'), (4, 'Available'), (5, 'Available');

-- Initialize Default Users
-- Replace with hashed passwords generated via PHP
INSERT INTO users (username, password, role) VALUES
('superadmin', '$2y$10$yourhashedpasswordhere', 'SuperAdmin'),
('admin', '$2y$10$yourhashedpasswordhere', 'Admin'),
('manager', '$2y$10$yourhashedpasswordhere', 'ParkingManager');

-- Trigger for slots
DELIMITER //
CREATE TRIGGER slots_update_timestamp
BEFORE UPDATE ON slots
FOR EACH ROW
BEGIN
    SET NEW.updated_at = CURRENT_TIMESTAMP;
END;
//
DELIMITER ;

-- Views
CREATE VIEW daily_income AS
SELECT 
    DATE(transaction_time) AS transaction_date,
    SUM(amount) AS total_income
FROM transactions
WHERE success = TRUE
GROUP BY DATE(transaction_time);

CREATE VIEW weekly_income AS
SELECT 
    YEARWEEK(transaction_time, 1) AS transaction_week,
    SUM(amount) AS total_income
FROM transactions
WHERE success = TRUE
GROUP BY YEARWEEK(transaction_time, 1);

CREATE VIEW monthly_income AS
SELECT 
    DATE_FORMAT(transaction_time, '%Y-%m') AS transaction_month,
    SUM(amount) AS total_income
FROM transactions
WHERE success = TRUE
GROUP BY DATE_FORMAT(transaction_time, '%Y-%m');

-- Stored Procedures
DELIMITER //
CREATE PROCEDURE ParkVehicle(
    IN p_plate_number VARCHAR(20),
    IN p_car_type ENUM('Sedan', 'SUV', 'Truck', 'Motorcycle'),
    IN p_slot_id INT
)
BEGIN
    DECLARE slot_available INT;
    DECLARE target_slot_id INT;
    IF p_slot_id IS NULL THEN
        SELECT slot_id INTO target_slot_id 
        FROM slots 
        WHERE status = 'Available' 
        LIMIT 1;
    ELSE
        SET target_slot_id = p_slot_id;
    END IF;
    SELECT COUNT(*) INTO slot_available 
    FROM slots 
    WHERE slot_id = target_slot_id AND status = 'Available';
    IF slot_available = 1 THEN
        UPDATE slots 
        SET status = 'Occupied', 
            plate_number = p_plate_number, 
            car_type = p_car_type, 
            entry_time = NOW()
        WHERE slot_id = target_slot_id;
        SELECT 'Vehicle parked successfully' AS message, target_slot_id AS slot_id;
    ELSE
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Slot unavailable or invalid';
    END IF;
END //
DELIMITER ;

DELIMITER //
CREATE PROCEDURE ExitVehicle(
    IN p_plate_number VARCHAR(20)
)
BEGIN
    DECLARE v_slot_id INT;
    DECLARE v_car_type ENUM('Sedan', 'SUV', 'Truck', 'Motorcycle');
    DECLARE v_entry_time DATETIME;
    DECLARE v_amount DECIMAL(10,2);
    SELECT slot_id, car_type, entry_time 
    INTO v_slot_id, v_car_type, v_entry_time
    FROM slots 
    WHERE plate_number = p_plate_number AND status = 'Occupied';
    IF v_slot_id IS NOT NULL THEN
        SET v_amount = TIMESTAMPDIFF(HOUR, v_entry_time, NOW()) * 
            CASE v_car_type 
                WHEN 'Sedan' THEN 2.0
                WHEN 'SUV' THEN 3.0
                WHEN 'Truck' THEN 5.0
                WHEN 'Motorcycle' THEN 1.0
                ELSE 0
            END;
        UPDATE slots 
        SET status = 'Available', 
            plate_number = NULL, 
            car_type = NULL, 
            entry_time = NULL
        WHERE slot_id = v_slot_id;
        INSERT INTO transactions (slot_id, plate_number, amount, payment_method, transaction_time, success)
        VALUES (v_slot_id, p_plate_number, v_amount, 'MoMo', NOW(), TRUE);
        SELECT v_amount AS fee, 'Vehicle exited successfully' AS message;
    ELSE
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Vehicle not found';
    END IF;
END //
DELIMITER ;