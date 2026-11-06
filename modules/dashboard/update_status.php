<?php
session_start();
require_once '../../includes/db_connect.php';

if(isset($_SESSION['user_id']) && isset($_GET['id']) && isset($_GET['status'])) {
    $id = $_GET['id'];
    $status = $_GET['status'];
    
    $allowed_statuses = ['Cleared', 'Under Observation', 'Sent Home'];
    
    if(in_array($status, $allowed_statuses)) {
        try {
            $stmt = $pdo->prepare("UPDATE consultations SET status = ? WHERE consultation_id = ?");
            $stmt->execute([$status, $id]);
        } catch (PDOException $e) {
            
        }
    }
}

header("Location: index.php");
exit();
?>