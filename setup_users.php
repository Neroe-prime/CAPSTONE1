<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once 'includes/db_connect.php';

try {
    // 1. Remove old test accounts if they got stuck
    $pdo->query("DELETE FROM users WHERE username IN ('sysadmin', 'headnurse', 'mr_cruz')");

    // 2. Safely hash 'password123'
    $hashed_password = password_hash('password123', PASSWORD_DEFAULT);

    // 3. Insert the three accounts with exact roles
    $stmt = $pdo->prepare("INSERT INTO users (username, password_hash, role) VALUES (?, ?, ?)");
    $stmt->execute(['sysadmin', $hashed_password, 'Admin']);
    $stmt->execute(['headnurse', $hashed_password, 'Nurse']);
    $stmt->execute(['mr_cruz', $hashed_password, 'Teacher']);

    echo "<h3 style='color: green; font-family: sans-serif;'>Success! The 3 test accounts are ready.</h3>";
    echo "<p style='font-family: sans-serif;'>You can now go back to the login page and use <strong>password123</strong> for sysadmin, headnurse, or mr_cruz.</p>";
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>