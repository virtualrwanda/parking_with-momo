-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Generation Time: Oct 30, 2025 at 03:40 AM
-- Server version: 11.4.8-MariaDB-cll-lve-log
-- PHP Version: 8.3.26

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `masacxpy_parking`
--

DELIMITER $$
--
-- Procedures
--
CREATE DEFINER=`root`@`localhost` PROCEDURE `ExitVehicle` (IN `p_plate_number` VARCHAR(20), IN `p_parking_id` INT, IN `p_user_id` INT)   BEGIN
    DECLARE v_slot_id INT;
    DECLARE v_fee DECIMAL(10,2);
    DECLARE v_entry_time DATETIME;
    DECLARE v_car_type VARCHAR(50);
    DECLARE v_rate_per_minute DECIMAL(10,4);

    -- Start transaction
    START TRANSACTION;

    -- Get current rate per minute
    SELECT rate_per_minute INTO v_rate_per_minute
    FROM parking_config
    WHERE parking_id = p_parking_id
    ORDER BY updated_at DESC
    LIMIT 1;

    -- Find the occupied slot
    SELECT slot_id, entry_time, car_type 
    INTO v_slot_id, v_entry_time, v_car_type
    FROM slots 
    WHERE plate_number = p_plate_number AND status = 'Occupied' AND parking_id = p_parking_id
    LIMIT 1;

    IF v_slot_id IS NULL THEN
        ROLLBACK;
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Vehicle not found in any slot in this parking.';
    ELSE
        -- Calculate fee: rate_per_minute * minutes, minimum 100 FRCS
        SET v_fee = GREATEST(100.00, CEIL(TIMESTAMPDIFF(MINUTE, v_entry_time, NOW()) * v_rate_per_minute));

        -- Update slots
        UPDATE slots 
        SET status = 'Available', 
            plate_number = NULL, 
            car_type = NULL, 
            entry_time = NULL
        WHERE slot_id = v_slot_id AND parking_id = p_parking_id;

        -- Update parking_logs
        UPDATE parking_logs
        SET exit_time = NOW(),
            fee = v_fee,
            paid = FALSE,
            managed_by = p_user_id
        WHERE plate_number = p_plate_number 
        AND parking_id = p_parking_id 
        AND slot_id = v_slot_id 
        AND entry_time = v_entry_time;

        -- Commit transaction
        COMMIT;

        -- Return fee and log ID
        SELECT v_fee AS fee, (SELECT id FROM parking_logs WHERE plate_number = p_plate_number AND parking_id = p_parking_id AND slot_id = v_slot_id AND entry_time = v_entry_time) AS log_id;
    END IF;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `ParkVehicle` (IN `p_plate_number` VARCHAR(20), IN `p_car_type` VARCHAR(50), IN `p_parking_id` INT, IN `p_slot_id` INT, IN `p_user_id` INT)   BEGIN
    DECLARE v_slot_id INT;
    DECLARE v_available_slot INT;

    -- Start transaction
    START TRANSACTION;

    -- Check if vehicle is already parked in the specified parking
    IF EXISTS (SELECT 1 FROM slots WHERE plate_number = p_plate_number AND status = 'Occupied' AND parking_id = p_parking_id) THEN
        ROLLBACK;
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Vehicle is already parked in this parking.';
    END IF;

    -- Find an available slot
    IF p_slot_id IS NULL THEN
        SELECT slot_id INTO v_available_slot
        FROM slots
        WHERE status = 'Available' AND parking_id = p_parking_id
        LIMIT 1;
    ELSE
        SET v_available_slot = p_slot_id;
    END IF;

    -- Verify slot availability
    IF v_available_slot IS NULL THEN
        ROLLBACK;
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'No available slots or specified slot is invalid.';
    END IF;

    IF EXISTS (SELECT 1 FROM slots WHERE slot_id = v_available_slot AND parking_id = p_parking_id AND status = 'Occupied') THEN
        ROLLBACK;
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Selected slot is already occupied.';
    END IF;

    -- Update slot
    UPDATE slots
    SET status = 'Occupied',
        plate_number = p_plate_number,
        car_type = p_car_type,
        entry_time = NOW()
    WHERE slot_id = v_available_slot AND parking_id = p_parking_id;

    -- Log the parking action
    INSERT INTO parking_logs (parking_id, plate_number, car_type, slot_id, entry_time, managed_by)
    VALUES (p_parking_id, p_plate_number, p_car_type, v_available_slot, NOW(), p_user_id);

    -- Commit transaction
    COMMIT;

    -- Return assigned slot
    SELECT v_available_slot AS slot_id;
END$$

DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `config_logs`
--

CREATE TABLE `config_logs` (
  `id` int(11) NOT NULL,
  `parking_id` int(11) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `old_rate` decimal(10,4) DEFAULT NULL,
  `new_rate` decimal(10,4) DEFAULT NULL,
  `change_time` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `config_logs`
--

INSERT INTO `config_logs` (`id`, `parking_id`, `user_id`, `old_rate`, `new_rate`, `change_time`) VALUES
(1, 1, 5, 1.6667, 1122.0000, '2025-09-14 21:50:53'),
(2, 1, 5, 1.6667, 200.0000, '2025-09-14 21:52:11'),
(3, 1, 2, 1.6667, 4667.0000, '2025-09-15 13:05:35');

-- --------------------------------------------------------

--
-- Stand-in structure for view `daily_income`
-- (See below for the actual view)
--
CREATE TABLE `daily_income` (
`parking_id` int(11)
,`transaction_date` date
,`total_income` decimal(32,2)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `manager_activity`
-- (See below for the actual view)
--
CREATE TABLE `manager_activity` (
`user_id` int(11)
,`username` varchar(50)
,`parking_id` int(11)
,`parking_name` varchar(100)
,`log_id` int(11)
,`plate_number` varchar(20)
,`car_type` varchar(50)
,`slot_id` int(11)
,`entry_time` datetime
,`exit_time` datetime
,`fee` decimal(10,2)
,`paid` tinyint(1)
,`payment_ref` varchar(255)
);

-- --------------------------------------------------------

--
-- Table structure for table `manager_assignments`
--

CREATE TABLE `manager_assignments` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `parking_id` int(11) DEFAULT NULL,
  `assigned_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `manager_assignments`
--

INSERT INTO `manager_assignments` (`id`, `user_id`, `parking_id`, `assigned_at`) VALUES
(1, 3, 2, '2025-09-15 13:13:28'),
(2, 4, 3, '2025-09-16 13:48:49');

-- --------------------------------------------------------

--
-- Stand-in structure for view `monthly_income`
-- (See below for the actual view)
--
CREATE TABLE `monthly_income` (
`parking_id` int(11)
,`transaction_month` varchar(7)
,`total_income` decimal(32,2)
);

-- --------------------------------------------------------

--
-- Table structure for table `parkings`
--

CREATE TABLE `parkings` (
  `parking_id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `location` varchar(255) NOT NULL,
  `total_slots` int(11) NOT NULL CHECK (`total_slots` > 0),
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `parkings`
--

INSERT INTO `parkings` (`parking_id`, `name`, `location`, `total_slots`, `created_by`, `created_at`) VALUES
(1, 'Main Parking', 'Downtown Kigali', 50, 1, '2025-09-14 21:39:32'),
(2, 'KIGALI PARKING', 'KIAGLI', 40, 2, '2025-09-15 13:12:30'),
(3, 'gasanze_park', 'gasanze', 12, 2, '2025-09-16 13:47:18');

-- --------------------------------------------------------

--
-- Table structure for table `parking_config`
--

CREATE TABLE `parking_config` (
  `id` int(11) NOT NULL,
  `parking_id` int(11) DEFAULT NULL,
  `rate_per_minute` decimal(10,4) NOT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_by` int(11) DEFAULT NULL
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `parking_config`
--

INSERT INTO `parking_config` (`id`, `parking_id`, `rate_per_minute`, `updated_at`, `updated_by`) VALUES
(1, 1, 1.6667, '2025-09-14 21:39:32', 1),
(2, 1, 1122.0000, '2025-09-14 21:50:53', 5),
(3, 1, 200.0000, '2025-09-14 21:52:11', 5),
(4, 1, 4667.0000, '2025-09-15 13:05:35', 2),
(5, 2, 1.6667, '2025-09-15 13:12:30', 2),
(6, 3, 1.6667, '2025-09-16 13:47:18', 2);

-- --------------------------------------------------------

--
-- Table structure for table `parking_logs`
--

CREATE TABLE `parking_logs` (
  `id` int(11) NOT NULL,
  `parking_id` int(11) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `plate_number` varchar(20) NOT NULL,
  `car_type` varchar(50) DEFAULT NULL,
  `slot_id` int(11) DEFAULT NULL,
  `entry_time` datetime DEFAULT NULL,
  `exit_time` datetime DEFAULT NULL,
  `fee` decimal(10,2) DEFAULT NULL,
  `paid` tinyint(1) DEFAULT 0,
  `payment_ref` varchar(255) DEFAULT NULL,
  `managed_by` int(11) DEFAULT NULL,
  `payment_method` varchar(50) DEFAULT 'Cash'
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `parking_logs`
--

INSERT INTO `parking_logs` (`id`, `parking_id`, `user_id`, `plate_number`, `car_type`, `slot_id`, `entry_time`, `exit_time`, `fee`, `paid`, `payment_ref`, `managed_by`, `payment_method`) VALUES
(1, 1, NULL, '0', '0', 1, '2025-09-14 21:50:41', NULL, NULL, 0, NULL, 5, 'Cash'),
(2, 2, NULL, '0', '0', 1, '2025-09-15 14:08:08', NULL, NULL, 0, NULL, 3, 'Cash'),
(3, 2, NULL, '35435', '0', 2, '2025-09-15 14:30:15', '2025-09-15 14:31:01', 100.00, 0, '275bbf55-d55e-4f4c-ad4a-87f298fca936', 3, 'Cash'),
(4, 1, NULL, '344', '0', 2, '2025-09-16 13:37:04', '2025-09-16 13:38:44', 4667.00, 0, 'ec9dc7e3-33ff-48e9-8538-63f8a794b729', 2, 'Cash'),
(5, 2, NULL, 'RG00AR', 'Motorcycle', 2, '2025-09-18 06:32:47', '2025-09-18 06:33:07', 100.00, 0, NULL, 3, 'Cash'),
(6, 3, NULL, 'RAA005k', 'taxi', 1, '2025-09-18 08:29:25', '2025-09-18 09:42:42', 122.00, 0, NULL, 4, 'Cash'),
(7, 3, NULL, 'RD008F', 'taxi', 2, '2025-09-18 09:11:36', NULL, NULL, 0, NULL, 4, 'Cash'),
(8, 3, NULL, 'RG00A5', 'ikamyo', 3, '2025-09-18 09:42:30', '2025-09-18 09:45:44', 100.00, 0, NULL, 4, 'Cash'),
(9, 3, NULL, 'RG00AR', 'taxi', 1, '2025-09-18 10:00:34', '2025-09-18 10:00:55', 100.00, 0, NULL, 4, 'Cash'),
(10, 3, NULL, 'RG00A5', 'hilux', 3, '2025-09-18 10:00:47', '2025-09-18 10:37:47', 100.00, 0, 'e2b18065-f7e1-446d-b46d-2c930dc4d03e', 4, 'Cash'),
(11, 3, NULL, 'RG00AR', 'taxi', 1, '2025-09-18 10:07:08', '2025-09-18 10:07:22', 100.00, 0, NULL, 4, 'Cash'),
(12, 3, NULL, 'RG00AR', 'taxi', 1, '2025-09-18 10:12:59', '2025-09-18 10:13:09', 100.00, 0, NULL, 4, 'Cash'),
(13, 3, NULL, 'RG00AR', 'taxi', 1, '2025-09-18 10:16:25', '2025-09-18 10:16:33', 100.00, 0, NULL, 4, 'Cash'),
(14, 3, NULL, 'RG00AR', 'taxi', 1, '2025-09-18 10:26:15', '2025-09-18 10:26:24', 100.00, 0, NULL, 4, 'Cash'),
(15, 3, NULL, 'RG00AR', 'ikamyo', 1, '2025-09-18 10:28:29', '2025-09-18 10:28:37', 100.00, 0, NULL, 4, 'Cash'),
(16, 3, NULL, 'RG00AR', 'taxi', 1, '2025-09-18 10:34:19', '2025-09-18 10:34:27', 100.00, 0, NULL, 4, 'Cash'),
(17, 3, NULL, 'RD007F', 'ikamyo', 1, '2025-09-19 09:32:32', '2025-09-19 09:32:38', 100.00, 0, '90353553-f7b5-4de1-a800-c79d00356ff0', 4, 'Cash'),
(18, 2, NULL, 'R00G', 'taxi', 2, '2025-09-19 10:45:08', NULL, NULL, 0, NULL, 3, 'Cash'),
(19, 2, NULL, 'RD008F', 'taxi', 3, '2025-09-19 11:15:05', '2025-09-19 11:15:11', 100.00, 0, 'd18b6b82-21a2-4cde-aa2d-d4eca8a07b1f', 3, 'Cash'),
(20, 2, NULL, 'RD008F', 'taxi', 3, '2025-09-19 11:15:53', '2025-09-19 11:16:13', 100.00, 0, 'd9942927-a536-427c-be84-548344ebffcd', 3, 'Cash'),
(21, 3, NULL, 'RG00A5', 'taxi', 1, '2025-09-19 11:31:34', '2025-09-19 11:34:26', 100.00, 0, '2451a5f9-dd2a-42e3-ab7c-24df90276f58', 4, 'Cash'),
(22, 3, NULL, 'RAA005k', 'taxi', 3, '2025-09-19 11:31:42', '2025-09-19 11:32:56', 100.00, 0, '12bace86-bd57-43ea-a89b-e951982547b1', 4, 'Cash'),
(23, 3, NULL, 'RAA376R', 'taxi', 4, '2025-09-19 11:32:06', NULL, NULL, 0, NULL, 4, 'Cash'),
(24, 3, NULL, 'RG00AR', 'taxi', 1, '2025-09-19 11:38:37', '2025-09-19 11:38:44', 100.00, 0, 'c95bab6c-c331-40b2-ae3a-fc165b8e0d9a', 4, 'Cash'),
(25, 3, NULL, 'RG00AR', 'taxi', 1, '2025-09-19 11:47:05', '2025-09-19 11:47:13', 100.00, 0, '893ea644-8e53-4019-9f7a-30e061570b11', 4, 'Cash'),
(26, 2, NULL, 'RG005A', 'taxi', 3, '2025-09-19 11:52:17', '2025-09-19 11:53:33', 100.00, 0, 'e421adab-7bff-434b-ab4d-25308516f9ba', 3, 'Cash'),
(27, 2, NULL, 'RAA376R', 'taxi', 4, '2025-09-19 11:52:31', NULL, NULL, 0, NULL, 3, 'Cash');

-- --------------------------------------------------------

--
-- Stand-in structure for view `parking_status`
-- (See below for the actual view)
--
CREATE TABLE `parking_status` (
`parking_id` int(11)
,`parking_name` varchar(100)
,`location` varchar(255)
,`total_slots` int(11)
,`occupied_slots` bigint(21)
,`available_slots` bigint(21)
,`daily_income` decimal(32,2)
);

-- --------------------------------------------------------

--
-- Table structure for table `slots`
--

CREATE TABLE `slots` (
  `slot_id` int(11) NOT NULL,
  `parking_id` int(11) NOT NULL,
  `status` enum('Available','Occupied') DEFAULT 'Available',
  `plate_number` varchar(20) DEFAULT NULL,
  `car_type` varchar(50) DEFAULT NULL,
  `entry_time` datetime DEFAULT NULL
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `slots`
--

INSERT INTO `slots` (`slot_id`, `parking_id`, `status`, `plate_number`, `car_type`, `entry_time`) VALUES
(1, 1, 'Occupied', '0', '0', '2025-09-14 21:50:41'),
(11, 1, 'Available', NULL, NULL, NULL),
(21, 1, 'Available', NULL, NULL, NULL),
(31, 1, 'Available', NULL, NULL, NULL),
(41, 1, 'Available', NULL, NULL, NULL),
(2, 1, 'Available', NULL, NULL, NULL),
(12, 1, 'Available', NULL, NULL, NULL),
(22, 1, 'Available', NULL, NULL, NULL),
(32, 1, 'Available', NULL, NULL, NULL),
(42, 1, 'Available', NULL, NULL, NULL),
(3, 1, 'Available', NULL, NULL, NULL),
(13, 1, 'Available', NULL, NULL, NULL),
(23, 1, 'Available', NULL, NULL, NULL),
(33, 1, 'Available', NULL, NULL, NULL),
(43, 1, 'Available', NULL, NULL, NULL),
(4, 1, 'Available', NULL, NULL, NULL),
(14, 1, 'Available', NULL, NULL, NULL),
(24, 1, 'Available', NULL, NULL, NULL),
(34, 1, 'Available', NULL, NULL, NULL),
(44, 1, 'Available', NULL, NULL, NULL),
(5, 1, 'Available', NULL, NULL, NULL),
(15, 1, 'Available', NULL, NULL, NULL),
(25, 1, 'Available', NULL, NULL, NULL),
(35, 1, 'Available', NULL, NULL, NULL),
(45, 1, 'Available', NULL, NULL, NULL),
(6, 1, 'Available', NULL, NULL, NULL),
(16, 1, 'Available', NULL, NULL, NULL),
(26, 1, 'Available', NULL, NULL, NULL),
(36, 1, 'Available', NULL, NULL, NULL),
(46, 1, 'Available', NULL, NULL, NULL),
(7, 1, 'Available', NULL, NULL, NULL),
(17, 1, 'Available', NULL, NULL, NULL),
(27, 1, 'Available', NULL, NULL, NULL),
(37, 1, 'Available', NULL, NULL, NULL),
(47, 1, 'Available', NULL, NULL, NULL),
(8, 1, 'Available', NULL, NULL, NULL),
(18, 1, 'Available', NULL, NULL, NULL),
(28, 1, 'Available', NULL, NULL, NULL),
(38, 1, 'Available', NULL, NULL, NULL),
(48, 1, 'Available', NULL, NULL, NULL),
(9, 1, 'Available', NULL, NULL, NULL),
(19, 1, 'Available', NULL, NULL, NULL),
(29, 1, 'Available', NULL, NULL, NULL),
(39, 1, 'Available', NULL, NULL, NULL),
(49, 1, 'Available', NULL, NULL, NULL),
(10, 1, 'Available', NULL, NULL, NULL),
(20, 1, 'Available', NULL, NULL, NULL),
(30, 1, 'Available', NULL, NULL, NULL),
(40, 1, 'Available', NULL, NULL, NULL),
(50, 1, 'Available', NULL, NULL, NULL),
(1, 2, 'Occupied', '0', '0', '2025-09-15 14:08:08'),
(2, 2, 'Occupied', 'R00G', 'taxi', '2025-09-19 10:45:08'),
(3, 2, 'Available', NULL, NULL, NULL),
(4, 2, 'Occupied', 'RAA376R', 'taxi', '2025-09-19 11:52:31'),
(5, 2, 'Available', NULL, NULL, NULL),
(6, 2, 'Available', NULL, NULL, NULL),
(7, 2, 'Available', NULL, NULL, NULL),
(8, 2, 'Available', NULL, NULL, NULL),
(9, 2, 'Available', NULL, NULL, NULL),
(10, 2, 'Available', NULL, NULL, NULL),
(11, 2, 'Available', NULL, NULL, NULL),
(12, 2, 'Available', NULL, NULL, NULL),
(13, 2, 'Available', NULL, NULL, NULL),
(14, 2, 'Available', NULL, NULL, NULL),
(15, 2, 'Available', NULL, NULL, NULL),
(16, 2, 'Available', NULL, NULL, NULL),
(17, 2, 'Available', NULL, NULL, NULL),
(18, 2, 'Available', NULL, NULL, NULL),
(19, 2, 'Available', NULL, NULL, NULL),
(20, 2, 'Available', NULL, NULL, NULL),
(21, 2, 'Available', NULL, NULL, NULL),
(22, 2, 'Available', NULL, NULL, NULL),
(23, 2, 'Available', NULL, NULL, NULL),
(24, 2, 'Available', NULL, NULL, NULL),
(25, 2, 'Available', NULL, NULL, NULL),
(26, 2, 'Available', NULL, NULL, NULL),
(27, 2, 'Available', NULL, NULL, NULL),
(28, 2, 'Available', NULL, NULL, NULL),
(29, 2, 'Available', NULL, NULL, NULL),
(30, 2, 'Available', NULL, NULL, NULL),
(31, 2, 'Available', NULL, NULL, NULL),
(32, 2, 'Available', NULL, NULL, NULL),
(33, 2, 'Available', NULL, NULL, NULL),
(34, 2, 'Available', NULL, NULL, NULL),
(35, 2, 'Available', NULL, NULL, NULL),
(36, 2, 'Available', NULL, NULL, NULL),
(37, 2, 'Available', NULL, NULL, NULL),
(38, 2, 'Available', NULL, NULL, NULL),
(39, 2, 'Available', NULL, NULL, NULL),
(40, 2, 'Available', NULL, NULL, NULL),
(1, 3, 'Available', NULL, NULL, NULL),
(2, 3, 'Occupied', 'RD008F', 'taxi', '2025-09-18 09:11:36'),
(3, 3, 'Available', NULL, NULL, NULL),
(4, 3, 'Occupied', 'RAA376R', 'taxi', '2025-09-19 11:32:06'),
(5, 3, 'Available', NULL, NULL, NULL),
(6, 3, 'Available', NULL, NULL, NULL),
(7, 3, 'Available', NULL, NULL, NULL),
(8, 3, 'Available', NULL, NULL, NULL),
(9, 3, 'Available', NULL, NULL, NULL),
(10, 3, 'Available', NULL, NULL, NULL),
(11, 3, 'Available', NULL, NULL, NULL),
(12, 3, 'Available', NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('Admin','SuperAdmin','ParkingManager') NOT NULL,
  `created_by` int(11) DEFAULT NULL
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `password`, `role`, `created_by`) VALUES
(1, 'superadmin', '$2y$10$dhqO2iR5oC744LrPVTT/OO/cmDjZ2zzNuKOJuJnIfboT.k62ijTK.', 'SuperAdmin', NULL),
(2, 'access', '$2y$10$fzDzecbMVV/Y.A5cIhBkiOuGWWXxmmkVfiCRdNMjosHL9Tcbyb9FW', 'Admin', 1),
(3, 'manager', '$2y$10$KnpWaQPQ6xBGuq0Q9vsJRevF.CaHQlbh6C7FEIEKMCQUArgIp7g.K', 'ParkingManager', 2),
(4, 'Butata', '$2y$10$pfsGrQceeVe9uWgTVOYnsuaHquJwD9yvcEJxXnGH60IQDo99WNqDy', 'ParkingManager', 2);

-- --------------------------------------------------------

--
-- Stand-in structure for view `weekly_income`
-- (See below for the actual view)
--
CREATE TABLE `weekly_income` (
`parking_id` int(11)
,`transaction_week` varchar(7)
,`total_income` decimal(32,2)
);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `config_logs`
--
ALTER TABLE `config_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `parking_id` (`parking_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `manager_assignments`
--
ALTER TABLE `manager_assignments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_assignment` (`user_id`,`parking_id`),
  ADD KEY `parking_id` (`parking_id`);

--
-- Indexes for table `parkings`
--
ALTER TABLE `parkings`
  ADD PRIMARY KEY (`parking_id`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `parking_config`
--
ALTER TABLE `parking_config`
  ADD PRIMARY KEY (`id`),
  ADD KEY `parking_id` (`parking_id`),
  ADD KEY `updated_by` (`updated_by`);

--
-- Indexes for table `parking_logs`
--
ALTER TABLE `parking_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `parking_id` (`parking_id`),
  ADD KEY `slot_id` (`slot_id`,`parking_id`),
  ADD KEY `managed_by` (`managed_by`),
  ADD KEY `idx_plate_exit` (`plate_number`,`exit_time`),
  ADD KEY `idx_parking_logs_plate_exit` (`plate_number`,`exit_time`,`parking_id`);

--
-- Indexes for table `slots`
--
ALTER TABLE `slots`
  ADD PRIMARY KEY (`slot_id`,`parking_id`),
  ADD KEY `parking_id` (`parking_id`),
  ADD KEY `idx_slots_plate_status` (`plate_number`,`status`,`parking_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD KEY `created_by` (`created_by`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `config_logs`
--
ALTER TABLE `config_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `manager_assignments`
--
ALTER TABLE `manager_assignments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `parkings`
--
ALTER TABLE `parkings`
  MODIFY `parking_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `parking_config`
--
ALTER TABLE `parking_config`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `parking_logs`
--
ALTER TABLE `parking_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=28;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

-- --------------------------------------------------------

--
-- Structure for view `daily_income`
--
DROP TABLE IF EXISTS `daily_income`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `daily_income`  AS SELECT `parking_logs`.`parking_id` AS `parking_id`, cast(`parking_logs`.`exit_time` as date) AS `transaction_date`, sum(`parking_logs`.`fee`) AS `total_income` FROM `parking_logs` WHERE `parking_logs`.`paid` = 1 GROUP BY `parking_logs`.`parking_id`, cast(`parking_logs`.`exit_time` as date) ;

-- --------------------------------------------------------

--
-- Structure for view `manager_activity`
--
DROP TABLE IF EXISTS `manager_activity`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `manager_activity`  AS SELECT `u`.`id` AS `user_id`, `u`.`username` AS `username`, `p`.`parking_id` AS `parking_id`, `p`.`name` AS `parking_name`, `pl`.`id` AS `log_id`, `pl`.`plate_number` AS `plate_number`, `pl`.`car_type` AS `car_type`, `pl`.`slot_id` AS `slot_id`, `pl`.`entry_time` AS `entry_time`, `pl`.`exit_time` AS `exit_time`, `pl`.`fee` AS `fee`, `pl`.`paid` AS `paid`, `pl`.`payment_ref` AS `payment_ref` FROM ((`parking_logs` `pl` join `users` `u` on(`pl`.`managed_by` = `u`.`id`)) join `parkings` `p` on(`pl`.`parking_id` = `p`.`parking_id`)) WHERE `u`.`role` = 'ParkingManager' ;

-- --------------------------------------------------------

--
-- Structure for view `monthly_income`
--
DROP TABLE IF EXISTS `monthly_income`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `monthly_income`  AS SELECT `parking_logs`.`parking_id` AS `parking_id`, date_format(`parking_logs`.`exit_time`,'%Y-%m') AS `transaction_month`, sum(`parking_logs`.`fee`) AS `total_income` FROM `parking_logs` WHERE `parking_logs`.`paid` = 1 GROUP BY `parking_logs`.`parking_id`, date_format(`parking_logs`.`exit_time`,'%Y-%m') ;

-- --------------------------------------------------------

--
-- Structure for view `parking_status`
--
DROP TABLE IF EXISTS `parking_status`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `parking_status`  AS SELECT `p`.`parking_id` AS `parking_id`, `p`.`name` AS `parking_name`, `p`.`location` AS `location`, `p`.`total_slots` AS `total_slots`, count(case when `s`.`status` = 'Occupied' then 1 end) AS `occupied_slots`, count(case when `s`.`status` = 'Available' then 1 end) AS `available_slots`, coalesce(sum(case when `pl`.`paid` = 1 and cast(`pl`.`exit_time` as date) = curdate() then `pl`.`fee` end),0) AS `daily_income` FROM ((`parkings` `p` left join `slots` `s` on(`p`.`parking_id` = `s`.`parking_id`)) left join `parking_logs` `pl` on(`p`.`parking_id` = `pl`.`parking_id`)) GROUP BY `p`.`parking_id`, `p`.`name`, `p`.`location`, `p`.`total_slots` ;

-- --------------------------------------------------------

--
-- Structure for view `weekly_income`
--
DROP TABLE IF EXISTS `weekly_income`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `weekly_income`  AS SELECT `parking_logs`.`parking_id` AS `parking_id`, date_format(`parking_logs`.`exit_time`,'%Y-%U') AS `transaction_week`, sum(`parking_logs`.`fee`) AS `total_income` FROM `parking_logs` WHERE `parking_logs`.`paid` = 1 GROUP BY `parking_logs`.`parking_id`, date_format(`parking_logs`.`exit_time`,'%Y-%U') ;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
