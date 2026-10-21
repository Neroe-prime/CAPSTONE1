<?php
$host = 'localhost';
$dbname = 'medflow_db';
$username = 'root'; // Default XAMPP username
$password = '';     // Default XAMPP password is empty

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    // Set the PDO error mode to exception so we can catch errors easily
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    // Uncomment the line below just for testing, then delete it later
    // echo "Connected successfully to MedFlow Database!"; 
} catch(PDOException $e) {
    die("Database Connection failed: " . $e->getMessage());
}
?>