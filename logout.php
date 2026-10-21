<?php
session_start();

// Unset all session variables
$_SESSION = array();

// Destroy the session
session_destroy();

// Redirect back to login page with a success message
header("Location: index.php?success=You have been successfully logged out");
exit();
?>