-- --------------------------------------------------------
-- Host:                         127.0.0.1
-- Server version:               8.4.3 - MySQL Community Server - GPL
-- Server OS:                    Win64
-- HeidiSQL Version:             12.8.0.6908
-- --------------------------------------------------------

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET NAMES utf8 */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;


-- Dumping database structure for medflow_db
CREATE DATABASE IF NOT EXISTS `medflow_db` /*!40100 DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci */ /*!80016 DEFAULT ENCRYPTION='N' */;
USE `medflow_db`;

-- Dumping structure for table medflow_db.consultations
CREATE TABLE IF NOT EXISTS `consultations` (
  `consultation_id` int NOT NULL AUTO_INCREMENT,
  `consultation_date` datetime DEFAULT CURRENT_TIMESTAMP,
  `student_id` varchar(50) NOT NULL,
  `symptom_category` varchar(50) DEFAULT NULL,
  `chief_complaint` text,
  `action_taken` text,
  `status` varchar(50) DEFAULT NULL,
  PRIMARY KEY (`consultation_id`),
  KEY `consultations_ibfk_1` (`student_id`),
  CONSTRAINT `consultations_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Dumping data for table medflow_db.consultations: ~5 rows (approximately)
INSERT INTO `consultations` (`consultation_id`, `consultation_date`, `student_id`, `symptom_category`, `chief_complaint`, `action_taken`, `status`) VALUES
	(1, '2026-10-22 20:02:23', '2026-0110', 'Other', 'Acid Reflux', 'Gaviscon', 'Cleared'),
	(2, '2026-10-22 20:02:45', '2026-0110', 'Other', 'Acid Reflux', 'Gaviscon Double Action', 'Under Observation'),
	(3, '2026-10-22 20:15:24', '2026-0110', 'Other', 'Acid Reflux', 'Gaviscon Double Action', 'Under Observation'),
	(4, '2026-10-22 21:15:58', '2026-0111', 'Other', 'dizziness/acid reflux', 'Rest for a little while', 'Cleared'),
	(5, '2026-10-23 14:05:22', '2026-1215', 'Cough & Cold', 'cough', 'Paracetamol', 'Cleared');

-- Dumping structure for table medflow_db.inventory
CREATE TABLE IF NOT EXISTS `inventory` (
  `item_id` int NOT NULL AUTO_INCREMENT,
  `item_name` varchar(100) NOT NULL,
  `batch_number` varchar(100) DEFAULT NULL,
  `current_stock` int NOT NULL DEFAULT '0',
  `initial_stock` int DEFAULT '0',
  `expiration_date` date DEFAULT NULL,
  `reorder_level` int NOT NULL DEFAULT '0',
  `daily_consumption_rate` decimal(5,2) DEFAULT '0.00',
  PRIMARY KEY (`item_id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Dumping data for table medflow_db.inventory: ~3 rows (approximately)
INSERT INTO `inventory` (`item_id`, `item_name`, `batch_number`, `current_stock`, `initial_stock`, `expiration_date`, `reorder_level`, `daily_consumption_rate`) VALUES
	(1, 'Gaviscon Double Action', NULL, 48, 0, NULL, 15, 0.00),
	(2, 'cetirizine 10mg', NULL, 50, 0, NULL, 20, 1.00),
	(3, 'hh', '4525', 50, 50, '2026-11-29', 15, 1.00);

-- Dumping structure for table medflow_db.students
CREATE TABLE IF NOT EXISTS `students` (
  `student_id` varchar(50) NOT NULL,
  `first_name` varchar(50) DEFAULT NULL,
  `last_name` varchar(50) DEFAULT NULL,
  `grade_section` varchar(50) DEFAULT NULL,
  `medical_history` text,
  `allergies` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`student_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Dumping data for table medflow_db.students: ~7 rows (approximately)
INSERT INTO `students` (`student_id`, `first_name`, `last_name`, `grade_section`, `medical_history`, `allergies`, `created_at`) VALUES
	('1', 'qwe', 'qwe', 'BSIT 401B', 'none', NULL, '2026-10-24 10:04:10'),
	('2', 'asd', 'asd', 'HM 201A', 'none', NULL, '2026-10-24 10:04:31'),
	('2026-0110', 'Neroe', 'Arrachrine', 'BSIT 401B', 'Acid Reflux', '', '2026-10-22 10:56:40'),
	('2026-0111', 'Neroe', 'Su', 'HM 201A', 'Lactose Intolerancec', NULL, '2026-10-22 13:14:22'),
	('2026-1215', 'Jamilla', 'Manalili', 'BSIT 301A', 'Lactose Intolerance', NULL, '2026-10-23 06:04:06'),
	('3', 'zxc', 'zxc', 'BSIT 101A', 'Asthma', NULL, '2026-10-24 10:05:11'),
	('4', 'wer', 'wer', 'BSBA 101D', '', NULL, '2026-10-24 10:05:38');

-- Dumping structure for table medflow_db.users
CREATE TABLE IF NOT EXISTS `users` (
  `user_id` int NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('Admin','Nurse','Teacher') NOT NULL DEFAULT 'Teacher',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Dumping data for table medflow_db.users: ~4 rows (approximately)
INSERT INTO `users` (`user_id`, `username`, `password_hash`, `role`, `created_at`) VALUES
	(1, 'sysadmin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Admin', '2026-10-22 07:50:34'),
	(2, 'headnurse', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Nurse', '2026-10-22 07:50:34'),
	(3, 'mr_cruz', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Teacher', '2026-10-22 07:50:34'),
	(5, 'nurse jeean', '$2y$10$TyKa4W8IHpZeZQ4UnNNEj.P7puHp0ou95jhnUkn2K.cnG6TDPOv.a', 'Nurse', '2026-10-25 11:52:37');

/*!40103 SET TIME_ZONE=IFNULL(@OLD_TIME_ZONE, 'system') */;
/*!40101 SET SQL_MODE=IFNULL(@OLD_SQL_MODE, '') */;
/*!40014 SET FOREIGN_KEY_CHECKS=IFNULL(@OLD_FOREIGN_KEY_CHECKS, 1) */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40111 SET SQL_NOTES=IFNULL(@OLD_SQL_NOTES, 1) */;
