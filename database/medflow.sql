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
  `inventory_item_id` int DEFAULT NULL,
  `quantity_used` int DEFAULT '0',
  PRIMARY KEY (`consultation_id`),
  KEY `consultations_ibfk_1` (`student_id`),
  CONSTRAINT `consultations_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=23 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Dumping data for table medflow_db.consultations: ~13 rows (approximately)
INSERT INTO `consultations` (`consultation_id`, `consultation_date`, `student_id`, `symptom_category`, `chief_complaint`, `action_taken`, `status`, `inventory_item_id`, `quantity_used`) VALUES
	(10, '2026-11-11 20:58:47', '04-22-3939', 'Other', 'Acid Reflux', 'given a 2 gaviscon', 'Cleared', 61, 1),
	(11, '2026-11-10 22:39:04', '04-22-3901', 'Fever', 'High fever (38.8°C) and chills', 'Given Paracetamol 500mg, advised rest in clinic', 'Cleared', 1, 2),
	(12, '2026-11-10 22:39:04', '04-22-3902', 'Headache', 'Severe headache and dizziness', 'Given Mefenamic Acid, allowed to lie down', 'Cleared', 2, 1),
	(13, '2026-11-09 22:39:04', '04-22-3906', 'Cough & Cold', 'Persistent cough with runny nose', 'Given Cetirizine and warm water, sent back to class', 'Cleared', 3, 1),
	(14, '2026-11-09 22:39:04', '04-22-3903', 'Headache', 'Migraine attack during class', 'Given Paracetamol, rested in quiet room', 'Cleared', 1, 1),
	(15, '2026-11-07 22:39:04', '04-22-3905', 'Other', 'Anxiety attack, hyperventilating', 'Guided breathing exercises, counseled by nurse', 'Cleared', NULL, 0),
	(16, '2026-11-06 22:39:04', '04-22-3907', 'Stomachache', 'Stomach cramps and nausea', 'Given Antacids, advised hydration', 'Under Observation', 4, 1),
	(17, '2026-11-05 22:39:04', '04-22-3912', 'Cough & Cold', 'Asthma wheezing triggered by dust', 'Administered Nebulizer treatment', 'Under Observation', 5, 1),
	(18, '2026-11-04 22:39:04', '04-22-3917', 'Injury', 'Accidental skin scratch on playground', 'Cleaned wound with Betadine, applied sterile bandage', 'Cleared', 6, 1),
	(19, '2026-11-01 22:39:04', '04-22-3920', 'Fever', 'High fever and body malaise', 'Given Paracetamol 500mg, excused to rest at home', 'Excused to Rest at Home', 1, 2),
	(20, '2026-10-30 22:39:04', '04-22-3925', 'Stomachache', 'Minor stomach ache after lunch', 'Given Antacid tablet', 'Cleared', 4, 1),
	(21, '2026-10-27 22:39:04', '04-22-3933', 'Headache', 'Throbbing migraine', 'Given Mefenamic Acid', 'Cleared', 2, 1),
	(22, '2026-10-24 22:39:04', '04-22-3938', 'Fever', 'Fever and sore throat', 'Given Paracetamol and lozenges', 'Under Observation', 1, 2);

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
  `category` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`item_id`)
) ENGINE=InnoDB AUTO_INCREMENT=63 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Dumping data for table medflow_db.inventory: ~62 rows (approximately)
INSERT INTO `inventory` (`item_id`, `item_name`, `batch_number`, `current_stock`, `initial_stock`, `expiration_date`, `reorder_level`, `daily_consumption_rate`, `category`) VALUES
	(1, 'Paracetamol 500mg Tablet', 'BN-2026-001', 20, 20, '2028-01-25', 15, 0.00, 'Analgesic / Antipyretic'),
	(2, 'Ibuprofen 400mg Tablet', 'BN-2026-002', 50, 50, '2027-11-10', 15, 0.00, 'Analgesic / Antipyretic'),
	(3, 'Mefenamic Acid 500mg Tablet', 'BN-2026-003', 8, 8, '2028-06-30', 15, 0.00, 'Analgesic / Antipyretic'),
	(4, 'Aspirin 80mg Tablet', 'BN-2026-004', 100, 100, '2027-05-15', 15, 0.00, 'Analgesic / Antipyretic'),
	(5, 'Tramadol 50mg Capsule', 'BN-2026-005', 20, 20, '2027-08-20', 15, 0.00, 'Analgesic / Antipyretic'),
	(6, 'Amoxicillin 500mg Capsule', 'BN-7026-006', 80, 80, '2028-06-30', 15, 0.00, 'Antibiotic'),
	(7, 'Azithromycin 500mg Tablet', 'BN-2026-007', 20, 20, '2027-08-20', 15, 0.00, 'Antibiotic'),
	(8, 'Ciprofloxacin 500mg Tablet', 'BN-2026-008', 80, 80, '2026-12-31', 15, 0.00, 'Antibiotic'),
	(9, 'Cephalexin 500mg Capsule', 'BN-2026-009', 35, 35, '2027-05-15', 15, 0.00, 'Antibiotic'),
	(10, 'Doxycycline 100mg Capsule', 'BN-7026-010', 14, 14, '2026-12-31', 15, 0.00, 'Antibiotic'),
	(11, 'Co-Amoxiclav 625mg Tablet', 'BN-2026-011', 80, 80, '2028-01-25', 15, 0.00, 'Antibiotic'),
	(12, 'Metronidazole 500mg Tablet', 'BN-2026-012', 50, 50, '2027-05-15', 15, 0.00, 'Antibiotic'),
	(13, 'Erythromycin 500mg Tablet', 'BN-2026-013', 80, 80, '2027-08-20', 15, 0.00, 'Antibiotic'),
	(14, 'Clindamycin 300mg Capsule', 'BN-2026-014', 20, 20, '2028-06-30', 15, 0.00, 'Antibiotic'),
	(15, 'Nitrofurantoin 100mg Capsule', 'BN-2026-015', 65, 65, '2027-11-10', 15, 0.00, 'Antibiotic'),
	(16, 'Cetirizine 10mg Tablet', 'BN-2026-016', 20, 20, '2026-12-31', 15, 0.00, 'Antihistamine'),
	(17, 'Loratadine 10mg Tablet', 'BN-2026-017', 65, 65, '2027-11-10', 15, 0.00, 'Antihistamine'),
	(18, 'Diphenhydramine 50mg Capsule', 'BN-2026-018', 35, 35, '2027-05-15', 15, 0.00, 'Antihistamine'),
	(19, 'Chlorphenamine 4mg Tablet', 'BN-7026-019', 12, 12, '2028-06-30', 15, 0.00, 'Antihistamine'),
	(20, 'Phenylephrine / Paracetamol Tablet', 'BN-2026-020', 50, 50, '2028-06-30', 15, 0.00, 'Antihistamine'),
	(21, 'Losartan 50mg Tablet', 'BN-2026-021', 35, 35, '2027-11-10', 15, 0.00, 'Cardiovascular'),
	(22, 'Amlodipine 5mg Tablet', 'BN-2026-022', 65, 65, '2027-05-15', 15, 0.00, 'Cardiovascular'),
	(23, 'Metoprolol 50mg Tablet', 'BN-2026-023', 65, 65, '2026-12-31', 15, 0.00, 'Cardiovascular'),
	(24, 'Atorvastatin 20mg Tablet', 'BN-2026-024', 65, 65, '2028-06-30', 15, 0.00, 'Cardiovascular'),
	(25, 'Simvastatin 20mg Tablet', 'BN-2026-025', 50, 50, '2026-12-31', 15, 0.00, 'Cardiovascular'),
	(26, 'Captopril 25mg Tablet', 'BN-2026-026', 80, 80, '2027-05-15', 15, 0.00, 'Cardiovascular'),
	(27, 'Furosemide 40mg Tablet', 'BN-7026-027', 100, 100, '2026-12-31', 15, 0.00, 'Cardiovascular'),
	(28, 'Hydrochlorothiazide 25mg Tablet', 'BN-2026-028', 50, 50, '2026-12-31', 15, 0.00, 'Cardiovascular'),
	(29, 'Digoxin 0.25mg Tablet', 'BN-2026-029', 50, 50, '2028-06-30', 15, 0.00, 'Cardiovascular'),
	(30, 'Isosorbide Dinitrate 5mg Sublingual', 'BN-2026-030', 50, 50, '2026-12-31', 15, 0.00, 'Cardiovascular'),
	(31, 'Omeprazole 20mg Capsule', 'BN-2026-031', 35, 35, '2028-01-25', 15, 0.00, 'Gastrointestinal'),
	(32, 'Pantoprazole 40mg Tablet', 'BN-2026-032', 14, 14, '2026-12-31', 15, 0.00, 'Gastrointestinal'),
	(33, 'Ranitidine 150mg Tablet', 'BN-2026-033', 65, 65, '2027-05-15', 15, 0.00, 'Gastrointestinal'),
	(34, 'Metoclopramide 10mg Tablet', 'BN-2026-034', 35, 35, '2026-12-31', 15, 0.00, 'Gastrointestinal'),
	(35, 'Domperidone 10mg Tablet', 'BN-2026-035', 14, 14, '2027-11-10', 15, 0.00, 'Gastrointestinal'),
	(36, 'Aluminum/Magnesium Hydroxide Suspension 120ml', 'BN-2026-036', 50, 50, '2028-06-30', 15, 0.00, 'Gastrointestinal'),
	(37, 'Hyoscine N-Butylbromide 10mg Tablet', 'BN-2026-037', 80, 80, '2027-05-15', 15, 0.00, 'Gastrointestinal'),
	(38, 'Loperamide 2mg Capsule', 'BN-2026-038', 80, 80, '2028-01-25', 15, 0.00, 'Gastrointestinal'),
	(39, 'ORS (Oral Rehydration Salts) Sachet', 'BN-2026-039', 35, 35, '2027-05-15', 15, 0.00, 'Gastrointestinal'),
	(40, 'Lactulose Syrup 120ml', 'BN-7026-040', 100, 100, '2028-06-30', 15, 0.00, 'Gastrointestinal'),
	(41, 'Salbutamol 2mg Tablet', 'BN-2026-041', 8, 8, '2026-12-31', 15, 0.00, 'Respiratory'),
	(42, 'Salbutamol Inhaler 100mcg/dose', 'BN-2026-042', 12, 12, '2026-12-31', 15, 0.00, 'Respiratory'),
	(43, 'Erdosteine 300mg Capsule', 'BN-2026-043', 50, 50, '2028-01-25', 15, 0.00, 'Respiratory'),
	(44, 'Acetylcysteine 600mg Effervescent', 'BN-7026-044', 80, 80, '2026-12-31', 15, 0.00, 'Respiratory'),
	(45, 'Montelukast 10mg Tablet', 'BN-2026-045', 80, 80, '2028-06-30', 15, 0.00, 'Respiratory'),
	(46, 'Prednisone 10mg Tablet', 'BN-2026-046', 14, 14, '2028-06-30', 15, 0.00, 'Anti-inflammatory'),
	(47, 'Dexamethasone 0.5mg Tablet', 'BN-2026-047', 14, 14, '2027-05-15', 15, 0.00, 'Anti-inflammatory'),
	(48, 'Methylprednisolone 4mg Tablet', 'BN-7026-048', 100, 100, '2027-11-10', 15, 0.00, 'Anti-inflammatory'),
	(49, 'Mefenamic Acid Suspension 60ml', 'BN-2026-049', 14, 14, '2026-12-31', 15, 0.00, 'Anti-inflammatory'),
	(50, 'Paracetamol Syrup 250mg/5ml 60ml', 'BN-2026-050', 14, 14, '2027-08-20', 15, 0.00, 'Analgesic / Antipyretic'),
	(51, 'Metformin 500mg Tablet', 'BN-2026-051', 50, 50, '2027-11-10', 15, 0.00, 'Antidiabetic'),
	(52, 'Gliclazide 80mg Tablet', 'BN-7026-052', 12, 12, '2026-12-31', 15, 0.00, 'Antidiabetic'),
	(53, 'Glimepiride 2mg Tablet', 'BN-2026-053', 100, 100, '2028-01-25', 15, 0.00, 'Antidiabetic'),
	(54, 'Allopurinol 100mg Tablet', 'BN-2026-054', 50, 50, '2027-11-10', 15, 0.00, 'Antidiabetic'),
	(55, 'Colchicine 500mcg Tablet', 'BN-2026-055', 8, 8, '2027-05-15', 15, 0.00, 'Anti-inflammatory'),
	(56, 'Silver Sulfadiazine Cream 25g', 'BN-2026-056', 35, 35, '2028-06-30', 15, 0.00, 'Topical'),
	(57, 'Povidone Iodine 10% Solution 60ml', 'BN-2026-057', 50, 50, '2026-12-31', 15, 0.00, 'Topical'),
	(58, 'Hydrocortisone Cream 1% 15g', 'BN-2026-058', 35, 35, '2027-05-15', 15, 0.00, 'Topical'),
	(59, 'Diclofenac Sodium Gel 20g', 'BN-2026-059', 35, 35, '2027-11-10', 15, 0.00, 'Topical'),
	(60, 'Mupirocin Ointment 15g', 'BN-2026-060', 20, 20, '2027-11-10', 15, 0.00, 'Topical'),
	(61, 'Gaviscon Double Action Liquid 150ml', 'BN-2026-061', 49, 50, '2028-06-30', 15, 0.03, 'Gastrointestinal'),
	(62, 'Antacid Magnesium Hydroxide Tablet', 'BN-2026-062', 60, 60, '2027-12-31', 15, 0.00, 'Gastrointestinal');

-- Dumping structure for table medflow_db.inventory_audit
CREATE TABLE IF NOT EXISTS `inventory_audit` (
  `audit_id` int NOT NULL AUTO_INCREMENT,
  `item_id` int DEFAULT NULL,
  `item_name` varchar(255) NOT NULL,
  `action_type` enum('Dispensed','Restocked','Disposed/Thrown','Adjusted') NOT NULL,
  `quantity` int NOT NULL,
  `previous_stock` int NOT NULL,
  `new_stock` int NOT NULL,
  `reason_or_remarks` varchar(255) DEFAULT NULL,
  `performed_by` varchar(100) NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`audit_id`),
  KEY `idx_item_id` (`item_id`),
  KEY `idx_action_type` (`action_type`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Dumping data for table medflow_db.inventory_audit: ~0 rows (approximately)

-- Dumping structure for table medflow_db.school_events
CREATE TABLE IF NOT EXISTS `school_events` (
  `event_id` int NOT NULL AUTO_INCREMENT,
  `event_name` varchar(255) NOT NULL,
  `event_date` date NOT NULL,
  `description` text,
  `created_by` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`event_id`),
  KEY `idx_event_date` (`event_date`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Dumping data for table medflow_db.school_events: ~1 rows (approximately)
INSERT INTO `school_events` (`event_id`, `event_name`, `event_date`, `description`, `created_by`, `created_at`) VALUES
	(1, 'CIT WEEK', '2026-11-22', 'Full-day outdoor sports under high heat', 'headnurse', '2026-11-22 00:29:52');

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

-- Dumping data for table medflow_db.students: ~50 rows (approximately)
INSERT INTO `students` (`student_id`, `first_name`, `last_name`, `grade_section`, `medical_history`, `allergies`, `created_at`) VALUES
	('04-22-3901', 'Jannah', 'Valez', '301A', 'Allergic rhinitis', NULL, '2026-11-11 12:48:19'),
	('04-22-3902', 'Zyra', 'Vicente', '101A', 'Asthma', NULL, '2026-11-11 12:48:19'),
	('04-22-3903', 'Kennan Mateo', 'Neri', '201D', 'Migraine', NULL, '2026-11-11 12:48:19'),
	('04-22-3904', 'Victoria', 'Reotan', '401A', 'Atopic dermatitis', NULL, '2026-11-11 12:48:19'),
	('04-22-3905', 'Marjan', 'Medel', '201D', 'Anxiety disorder', NULL, '2026-11-11 12:48:19'),
	('04-22-3906', 'Janice', 'Manzala', '101B', 'Asthma', NULL, '2026-11-11 12:48:19'),
	('04-22-3907', 'Lyrainne', 'Aguila', '101D', 'Lactose intolerance', NULL, '2026-11-11 12:48:19'),
	('04-22-3908', 'Bridgette', 'Lopez', '301B', 'Anemia', NULL, '2026-11-11 12:48:19'),
	('04-22-3909', 'Anne Chrystal', 'Amion', '101C', 'Acid reflux', NULL, '2026-11-11 12:48:19'),
	('04-22-3910', 'Vrixz', 'Dulay', '101B', 'Depression', NULL, '2026-11-11 12:48:19'),
	('04-22-3911', 'Sarah', 'Saluang', '101C', 'Vision impairment', NULL, '2026-11-11 12:48:19'),
	('04-22-3912', 'Christian', 'Carpio', '401B', 'Asthma', NULL, '2026-11-11 12:48:19'),
	('04-22-3913', 'Yval', 'Balverde', '301A', 'Acid reflux', NULL, '2026-11-11 12:48:19'),
	('04-22-3914', 'Elisha', 'Manalo', '401A', 'Anemia', NULL, '2026-11-11 12:48:19'),
	('04-22-3915', 'Jeanne', 'Silva', '101C', 'Allergic rhinitis', NULL, '2026-11-11 12:48:19'),
	('04-22-3916', 'Karen', 'Panarigan', '301C', 'Acid reflux', NULL, '2026-11-11 12:48:19'),
	('04-22-3917', 'Jackie', 'Arevalo', '201C', 'Peanut allergy', NULL, '2026-11-11 12:48:19'),
	('04-22-3918', 'Sachi', 'Osunero', '401D', 'Learning disability', NULL, '2026-11-11 12:48:19'),
	('04-22-3919', 'April Mae', 'Osico', '301D', 'Asthma', NULL, '2026-11-11 12:48:19'),
	('04-22-3920', 'Samantha', 'Catubig', '301D', 'Allergic rhinitis', NULL, '2026-11-11 12:48:19'),
	('04-22-3921', 'Maki', 'Aguirre', '301C', 'Depression', NULL, '2026-11-11 12:48:19'),
	('04-22-3922', 'Gabriel', 'Buban', '201A', 'None', NULL, '2026-11-11 12:48:19'),
	('04-22-3923', 'Mark', 'Amburgo', '301D', 'None', NULL, '2026-11-11 12:48:19'),
	('04-22-3924', 'Mico', 'Agapito', '401C', 'None', NULL, '2026-11-11 12:48:19'),
	('04-22-3925', 'Loumierre', 'Castillo', '201B', 'None', NULL, '2026-11-11 12:48:19'),
	('04-22-3926', 'Jayr', 'Fernandez', '401B', 'None', NULL, '2026-11-11 12:48:19'),
	('04-22-3927', 'Justin', 'Manalalsal', '401D', 'Acid reflux', NULL, '2026-11-11 12:48:19'),
	('04-22-3928', 'Enrico', 'Mesias', '301B', 'None', NULL, '2026-11-11 12:48:19'),
	('04-22-3929', 'Dion', 'Rosales', '401C', 'Vision impairment', NULL, '2026-11-11 12:48:19'),
	('04-22-3930', 'Kenneth', 'Sanguid', '101D', 'Depression', NULL, '2026-11-11 12:48:19'),
	('04-22-3931', 'Louise', 'Lezvela', '201C', 'None', NULL, '2026-11-11 12:48:19'),
	('04-22-3932', 'Shana', 'Cruz', '101B', 'None', NULL, '2026-11-11 12:48:19'),
	('04-22-3933', 'Kenneth', 'Loyola', '201B', 'Migraine', NULL, '2026-11-11 12:48:19'),
	('04-22-3934', 'Francis', 'Berces', '201C', 'None', NULL, '2026-11-11 12:48:19'),
	('04-22-3935', 'Andrea', 'Payawal', '301A', 'Asthma', NULL, '2026-11-11 12:48:19'),
	('04-22-3936', 'Simon', 'Alonzo', '101A', 'None', NULL, '2026-11-11 12:48:19'),
	('04-22-3937', 'Christian', 'Janapin', '101D', 'None', NULL, '2026-11-11 12:48:19'),
	('04-22-3938', 'Carlo', 'Garcia', '201A', 'Allergic rhinitis', NULL, '2026-11-11 12:48:19'),
	('04-22-3939', 'Sophia', 'Alison', '201A', 'None', NULL, '2026-11-11 12:48:19'),
	('04-22-3940', 'Anne', 'Lavarias', '401D', 'None', NULL, '2026-11-11 12:48:19'),
	('04-22-3941', 'Jerald', 'Amor', '301C', 'None', NULL, '2026-11-11 12:48:19'),
	('04-22-3942', 'Nisha', 'Rino', '201B', 'None', NULL, '2026-11-11 12:48:19'),
	('04-22-3943', 'Mikaela', 'Villaran', '201A', 'None', NULL, '2026-11-11 12:48:19'),
	('04-22-3944', 'Ralph', 'Quinto', '301B', 'None', NULL, '2026-11-11 12:48:19'),
	('04-22-3945', 'Laurence', 'Frias', '101A', 'Migraine', NULL, '2026-11-11 12:48:19'),
	('04-22-3946', 'Sedsed', 'Tamano', '201D', 'None', NULL, '2026-11-11 12:48:19'),
	('04-22-3947', 'Sheena', 'Dela Cruz', '401C', 'Anemia', NULL, '2026-11-11 12:48:19'),
	('04-22-3948', 'Niv', 'Manabat', '401A', 'None', NULL, '2026-11-11 12:48:19'),
	('04-22-3949', 'John', 'Busil', '401B', 'None', NULL, '2026-11-11 12:48:19'),
	('04-22-3950', 'Xyro', 'Monggol', '401D', 'Anxiety disorder', NULL, '2026-11-11 12:48:19');

-- Dumping structure for table medflow_db.users
CREATE TABLE IF NOT EXISTS `users` (
  `user_id` int NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('Admin','Nurse','Teacher') NOT NULL DEFAULT 'Teacher',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Dumping data for table medflow_db.users: ~3 rows (approximately)
INSERT INTO `users` (`user_id`, `username`, `password_hash`, `role`, `created_at`) VALUES
	(1, 'sysadmin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Admin', '2026-10-22 07:50:34'),
	(2, 'headnurse', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Nurse', '2026-10-22 07:50:34'),
	(6, 'Nurse', '$2y$10$ZWtigU4fVY1qQHgn4WP5mud9FWckKumIhZTn6FTgciHqQFTkjujq.', 'Nurse', '2026-11-12 04:53:49');

/*!40103 SET TIME_ZONE=IFNULL(@OLD_TIME_ZONE, 'system') */;
/*!40101 SET SQL_MODE=IFNULL(@OLD_SQL_MODE, '') */;
/*!40014 SET FOREIGN_KEY_CHECKS=IFNULL(@OLD_FOREIGN_KEY_CHECKS, 1) */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40111 SET SQL_NOTES=IFNULL(@OLD_SQL_NOTES, 1) */;
