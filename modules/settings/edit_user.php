<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../index.php?error=Please log in first");
    exit();
}

require_once '../../includes/db_connect.php';

// Ensure only an Admin can edit users
$role_stmt = $pdo->prepare("SELECT role FROM users WHERE user_id = ?");
$role_stmt->execute([$_SESSION['user_id']]);
if ($role_stmt->fetchColumn() !== 'Admin') {
    header("Location: index.php?error=Unauthorized action");
    exit();
}

$user_id = $_GET['id'] ?? null;
if (!$user_id) {
    header("Location: index.php?error=Invalid user ID");
    exit();
}

// Fetch target user details
$stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = ?");
$stmt->execute([$user_id]);
$target_user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$target_user) {
    header("Location: index.php?error=User not found");
    exit();
}

$error_msg = '';

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $role = trim($_POST['role'] ?? '');
    $new_password = $_POST['new_password'] ?? '';

    if (empty($username) || empty($role)) {
        $error_msg = "Username and role are required.";
    } else {
        try {
            if (!empty($new_password)) {
                $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
                $update = $pdo->prepare("UPDATE users SET username = ?, role = ?, password_hash = ? WHERE user_id = ?");
                $update->execute([$username, $role, $password_hash, $user_id]);
            } else {
                $update = $pdo->prepare("UPDATE users SET username = ?, role = ? WHERE user_id = ?");
                $update->execute([$username, $role, $user_id]);
            }
            header("Location: index.php?success=User updated successfully");
            exit();
        } catch (PDOException $e) {
            $error_msg = "Database error: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit User - MedFlow</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    
    <style>
        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .sidebar {
            background-color: #1a222c;
            min-height: 100vh;
            color: #fff;
        }
        .sidebar .nav-link {
            color: #a0aec0;
            padding: 12px 20px;
            margin-bottom: 5px;
            border-radius: 8px;
        }
        .sidebar .nav-link:hover, .sidebar .nav-link.active {
            background-color: #2d3748;
            color: #fff;
        }
        .card-custom {
            border-radius: 15px;
            border: none;
            box-shadow: 0 4px 6px rgba(0,0,0,0.05);
        }
        .btn-purple {
            background-color: #6366f1;
            color: white;
            border: none;
        }
        .btn-purple:hover {
            background-color: #4f46e5;
            color: white;
        }
    </style>
</head>
<body>

<div class="container-fluid">
    <div class="row">
        <!-- Sidebar -->
        <div class="col-md-2 sidebar p-3">
            <h4 class="text-info fw-bold mb-4 mt-2"><i class="bi bi-heart-pulse-fill"></i> MedFlow</h4>
            <ul class="nav flex-column">
                <li class="nav-item"><a class="nav-link" href="../dashboard/index.php"><i class="bi bi-house-door me-2"></i> Dashboard</a></li>
                <li class="nav-item"><a class="nav-link" href="../records/index.php"><i class="bi bi-people me-2"></i> Student Records</a></li>
                <li class="nav-item"><a class="nav-link" href="../consultations/index.php"><i class="bi bi-stethoscope me-2"></i> Consultations</a></li>
                <li class="nav-item"><a class="nav-link" href="../inventory/index.php"><i class="bi bi-box-seam me-2"></i> Inventory</a></li>
                <li class="nav-item"><a class="nav-link" href="../analytics/index.php"><i class="bi bi-graph-up me-2"></i> Analytics</a></li>
                <li class="nav-item"><a class="nav-link active" href="../settings/index.php"><i class="bi bi-gear me-2"></i> Settings</a></li>
            </ul>
        </div>

        <!-- Main Content -->
        <div class="col-md-10 p-4">
            <!-- Top Navbar Area -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div class="input-group w-50">
                    <span class="input-group-text bg-white border-end-0"><i class="bi bi-search"></i></span>
                    <input type="text" class="form-control border-start-0" placeholder="Search student ID or name...">
                </div>
                <div class="d-flex align-items-center">
                    <i class="bi bi-bell fs-5 me-4 text-muted"></i>
                    <div class="d-flex align-items-center">
                        <div class="bg-primary text-white rounded-circle d-flex justify-content-center align-items-center me-2" style="width: 35px; height: 35px;">SY</div>
                        <span class="fw-bold me-2">sysadmin</span>
                        <span class="badge bg-secondary">Admin</span>
                    </div>
                </div>
            </div>

            <!-- Error Notification Alert -->
            <?php if (!empty($error_msg)): ?>
                <div class="alert alert-danger rounded-4 mb-4 shadow-sm">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i> <?php echo htmlspecialchars($error_msg); ?>
                </div>
            <?php endif; ?>

            <!-- Edit User Form Card -->
            <div class="row justify-content-center">
                <div class="col-md-6">
                    <div class="card card-custom p-4 mt-3">
                        <div class="card-body">
                            <h3 class="fw-bold mb-4"><i class="bi bi-person-gear text-primary me-2"></i> Edit System User</h3>
                            
                            <form action="" method="POST">
                                <!-- Username -->
                                <div class="mb-4">
                                    <label for="username" class="form-label fw-bold text-muted" style="font-size: 0.85rem;">USERNAME</label>
                                    <input type="text" class="form-control py-2" id="username" name="username" value="<?php echo htmlspecialchars($target_user['username']); ?>" style="border-radius: 8px;" required>
                                </div>

                                <!-- Role -->
                                <div class="mb-4">
                                    <label for="role" class="form-label fw-bold text-muted" style="font-size: 0.85rem;">ROLE</label>
                                    <select class="form-select py-2" id="role" name="role" style="border-radius: 8px;" required>
                                        <option value="Admin" <?php if($target_user['role'] === 'Admin') echo 'selected'; ?>>Admin</option>
                                        <option value="Nurse" <?php if($target_user['role'] === 'Nurse') echo 'selected'; ?>>Nurse</option>
                                        <option value="Teacher" <?php if($target_user['role'] === 'Teacher') echo 'selected'; ?>>Teacher</option>
                                    </select>
                                </div>

                                <!-- Sleek Password with Floating Toggle -->
                                <div class="mb-5">
                                    <label for="new_password" class="form-label fw-bold text-muted" style="font-size: 0.85rem;">NEW PASSWORD (LEAVE BLANK TO KEEP CURRENT)</label>
                                    <div class="position-relative">
                                        <input type="password" class="form-control py-2" id="new_password" name="new_password" placeholder="••••••••" style="padding-right: 45px; border-radius: 8px;">
                                        <span class="position-absolute top-50 end-0 translate-middle-y me-3" id="togglePassword" style="cursor: pointer;">
                                            <i class="bi bi-eye text-muted fs-5" id="toggleIcon"></i>
                                        </span>
                                    </div>
                                </div>

                                <!-- Buttons -->
                                <div class="d-flex justify-content-end gap-2">
                                    <a href="index.php" class="btn btn-light fw-bold px-4" style="border-radius: 8px;">Cancel</a>
                                    <button type="submit" class="btn btn-purple fw-bold px-4" style="border-radius: 8px;">Update User</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<!-- Password Toggle Script -->
<script>
    const togglePassword = document.getElementById('togglePassword');
    const passwordInput = document.getElementById('new_password');
    const toggleIcon = document.getElementById('toggleIcon');

    togglePassword.addEventListener('click', function () {
        const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
        passwordInput.setAttribute('type', type);
        
        toggleIcon.classList.toggle('bi-eye');
        toggleIcon.classList.toggle('bi-eye-slash');
    });
</script>

</body>
</html>