<?php
session_start();
if(!isset($_SESSION['user_id'])){
    header("Location: ../../index.php?error=Please log in first");
    exit();
}
require_once '../../includes/db_connect.php';

$student_id = $_GET['id'] ?? '';

if (!empty($student_id)) {
    try {
        $stmt = $pdo->prepare("DELETE FROM students WHERE student_id = ?");
        $stmt->execute([$student_id]);
        
        header("Location: index.php?success=Student record deleted successfully!");
        exit();
    } catch (PDOException $e) {
        header("Location: index.php?error=Failed to delete student record.");
        exit();
    }
} else {
    header("Location: index.php?error=Invalid student ID");
    exit();
}