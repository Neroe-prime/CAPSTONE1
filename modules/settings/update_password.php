<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../index.php?error=Please log in first");
    exit();
}

require_once '../../includes/db_connect.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_id = $_SESSION['user_id'];
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    // 1. Basic Validation
    if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
        header("Location: index.php?error=All fields are required");
        exit();
    }

    if ($new_password !== $confirm_password) {
        header("Location: index.php?error=New passwords do not match");
        exit();
    }

    try {
        // 2. Fetch the current hashed password from the database
        $stmt = $pdo->prepare("SELECT password FROM users WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        // 3. Verify the old password matches
        if (password_verify($current_password, $user['password'])) {
            
            // 4. Hash the new password and update the database
            $hashed_new_password = password_hash($new_password, PASSWORD_DEFAULT);
            $update_stmt = $pdo->prepare("UPDATE users SET password = ? WHERE user_id = ?");
            $update_stmt->execute([$hashed_new_password, $user_id]);

            header("Location: index.php?success=Password updated successfully");
            exit();
        } else {
            header("Location: index.php?error=Incorrect current password");
            exit();
        }
    } catch (PDOException $e) {
        header("Location: index.php?error=A database error occurred");
        exit();
    }
} else {
    header("Location: index.php");
    exit();
}