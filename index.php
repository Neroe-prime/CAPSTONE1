<?php
session_start();
require_once 'includes/db_connect.php';

// If already logged in, send them straight to the dashboard
if(isset($_SESSION['user_id'])){
    header("Location: modules/dashboard/index.php");
    exit();
}

$error = '';

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['login'])) {
    $username = trim($_POST['username']);
    $password = $_POST['password'];

    try {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        // Verify password using the correct 'password_hash' column
        if ($user && password_verify($password, $user['password_hash'])) {
            // Set Session Variables using the correct 'user_id' column
            $_SESSION['user_id'] = $user['user_id']; 
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];

            header("Location: modules/dashboard/index.php");
            exit();
        } else {
            $error = "Invalid username or password.";
        }
    } catch (PDOException $e) {
        $error = "System error: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MedFlow - Login</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background-color: #1a252f; height: 100vh; display: flex; align-items: center; justify-content: center; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .login-card { background: #fff; padding: 40px; border-radius: 10px; box-shadow: 0 10px 25px rgba(0,0,0,0.2); width: 100%; max-width: 400px; }
        .brand { font-size: 2rem; font-weight: bold; text-align: center; margin-bottom: 30px; color: #3498db; }
    </style>
</head>
<body>

    <div class="login-card">
        <div class="brand">
            <i class="fa-solid fa-heart-pulse"></i> MedFlow
        </div>
        
        <?php if($error): ?>
            <div class="alert alert-danger py-2"><?php echo $error; ?></div>
        <?php endif; ?>
        <?php if(isset($_GET['error'])): ?>
            <div class="alert alert-warning py-2"><?php echo htmlspecialchars($_GET['error']); ?></div>
        <?php endif; ?>
        <?php if(isset($_GET['success'])): ?>
            <div class="alert alert-success py-2"><?php echo htmlspecialchars($_GET['success']); ?></div>
        <?php endif; ?>

        <form method="POST" action="">
            <div class="mb-3">
                <label class="form-label fw-semibold">Username</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="fa-solid fa-user"></i></span>
                    <input type="text" name="username" class="form-control" required>
                </div>
            </div>
            <div class="mb-4">
                <label class="form-label fw-semibold">Password</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="fa-solid fa-lock"></i></span>
                    <input type="password" name="password" class="form-control" required>
                </div>
            </div>
            <button type="submit" name="login" class="btn btn-primary w-100 fw-bold py-2">Sign In</button>
        </form>
    </div>

</body>
</html>