<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../index.php");
    exit();
}
require_once '../../includes/db_connect.php';

$user_id = $_GET['id'] ?? null;
// Prevent admin from deleting their own active session account
if ($user_id && $user_id != $_SESSION['user_id']) {
    $stmt = $pdo->prepare("DELETE FROM users WHERE user_id = ?");
    $stmt->execute([$user_id]);
}
header("Location: index.php?success=User deleted successfully!");
exit();