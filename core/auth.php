<?php
session_start();
require_once '../includes/db_connect.php'; // Pulls in your DB connection

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = trim($_POST['username']);
    $password = $_POST['password'];

    // Query the database for the user
    $stmt = $pdo->prepare("SELECT user_id, username, password_hash, role FROM users WHERE username = :username");
    $stmt->execute(['username' => $username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    // Verify the password
    if ($user && password_verify($password, $user['password_hash'])) {
        // Success! Set session variables
        $_SESSION['user_id'] = $user['user_id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role'] = $user['role'];
        
        header("Location: ../modules/dashboard/index.php");
        exit();
    } else {
        // Fail! Send back to login with an error message
        header("Location: ../index.php?error=Invalid username or password");
        exit();
    }
} else {
    header("Location: ../index.php");
    exit();
}
?>