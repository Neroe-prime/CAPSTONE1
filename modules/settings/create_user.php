<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../index.php?error=Please log in first");
    exit();
}

require_once '../../includes/db_connect.php';

// Ensure only an Admin can create accounts
if ($_SESSION['role'] !== 'Admin') {
    header("Location: index.php?error=Unauthorized action");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['new_username'] ?? '');
    $password = $_POST['new_password'] ?? '';
    $role = $_POST['new_role'] ?? 'Teacher';

    // 1. Validation
    if (empty($username) || empty($password) || empty($role)) {
        header("Location: index.php?error=All fields are required");
        exit();
    }

    try {
        // 2. Check if username already exists
        $check_stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
        $check_stmt->execute([$username]);
        if ($check_stmt->fetchColumn() > 0) {
            header("Location: index.php?error=Username already exists");
            exit();
        }

        // 3. Hash password and insert new user
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        $insert_stmt = $pdo->prepare("INSERT INTO users (username, password, role) VALUES (?, ?, ?)");
        $insert_stmt->execute([$username, $hashed_password, $role]);

        header("Location: index.php?success=User account created successfully");
        exit();

    } catch (PDOException $e) {
        header("Location: index.php?error=Database error while creating user");
        exit();
    }
} else {
    header("Location: index.php");
    exit();
}