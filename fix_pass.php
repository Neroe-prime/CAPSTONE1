<?php
require 'includes/db_connect.php';

// Generate a real encrypted hash for 'admin123'
$password = 'admin123';
$hash = password_hash($password, PASSWORD_DEFAULT);

// Update the admin account in the database
$stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE username = 'admin'");
$stmt->execute([$hash]);

echo "Success! The admin password is now properly encrypted. Go back and log in with admin123.";
?>