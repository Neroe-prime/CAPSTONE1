<?php
session_start();
if(!isset($_SESSION['user_id'])){
    header("Location: ../../index.php?error=Please log in first");
    exit();
}
require_once '../../includes/db_connect.php';

$consultation_id = $_GET['id'] ?? '';

if (!empty($consultation_id)) {
    try {
        $stmt = $pdo->prepare("DELETE FROM consultations WHERE consultation_id = ?");
        $stmt->execute([$consultation_id]);
        
        header("Location: index.php?success=Consultation log deleted successfully!");
        exit();
    } catch (PDOException $e) {
        header("Location: index.php?error=Failed to delete consultation log.");
        exit();
    }
} else {
    header("Location: index.php?error=Invalid consultation ID");
    exit();
}