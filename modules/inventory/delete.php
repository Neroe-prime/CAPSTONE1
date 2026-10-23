<?php
session_start();
if(!isset($_SESSION['user_id'])){
    header("Location: ../../index.php?error=Please log in first");
    exit();
}
require_once '../../includes/db_connect.php';

$item_id = $_GET['id'] ?? '';

if (!empty($item_id)) {
    try {
        $stmt = $pdo->prepare("DELETE FROM inventory WHERE item_id = ?");
        $stmt->execute([$item_id]);
        
        header("Location: index.php?success=Inventory item deleted successfully!");
        exit();
    } catch (PDOException $e) {
        header("Location: index.php?error=Failed to delete inventory item.");
        exit();
    }
} else {
    header("Location: index.php?error=Invalid item ID");
    exit();
}