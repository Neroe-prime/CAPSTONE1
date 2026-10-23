<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();
if(!isset($_SESSION['user_id'])){
    header("Location: ../../index.php?error=Please log in first");
    exit();
}
require_once '../../includes/db_connect.php';

// RBAC Check: Only Admin or Nurse can register students
try {
    $role_stmt = $pdo->prepare("SELECT role FROM users WHERE user_id = ?");
    $role_stmt->execute([$_SESSION['user_id']]);
    $user_role = $role_stmt->fetchColumn();
} catch (PDOException $e) {
    $user_role = 'Teacher';
}

if ($user_role !== 'Admin' && $user_role !== 'Nurse') {
    header("Location: index.php?error=Unauthorized action. Teachers have view-only access.");
    exit();
}

$error_msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $student_id = trim($_POST['student_id'] ?? '');
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $grade_section = trim($_POST['grade_section'] ?? '');
    $medical_history = trim($_POST['medical_history'] ?? '');

    if (empty($student_id) || empty($first_name) || empty($last_name) || empty($grade_section)) {
        $error_msg = "Please fill in all required fields.";
    } else {
        try {
            $stmt = $pdo->prepare("INSERT INTO students (student_id, first_name, last_name, grade_section, medical_history) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$student_id, $first_name, $last_name, $grade_section, $medical_history]);
            
            header("Location: index.php?success=Student registered successfully!");
            exit();
        } catch (PDOException $e) {
            $error_msg = "Database error or Student ID already exists.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register Student - MedFlow</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background-color: #f4f5fa; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; overflow-x: hidden; }
        .sidebar { width: 250px; height: 100vh; background-color: #1a252f; color: #ecf0f1; position: fixed; padding-top: 20px; top: 0; left: 0; z-index: 1000; }
        .sidebar .brand { font-size: 1.5rem; font-weight: bold; text-align: center; margin-bottom: 30px; color: #3498db; }
        .sidebar a { padding: 15px 25px; text-decoration: none; font-size: 1.1rem; color: #bdc3c7; display: block; transition: 0.3s; }
        .sidebar a:hover, .sidebar a.active { color: #ffffff; background-color: #2c3e50; border-left: 4px solid #3498db; }
        .main-content { margin-left: 250px; padding: 45px; width: calc(100% - 250px); }
        .card { border: none; border-radius: 20px; box-shadow: 0 8px 25px rgba(111, 66, 193, 0.07); background-color: #ffffff; }
        .bg-gradient-purple {
            background: linear-gradient(135deg, #6f42c1 0%, #4e73df 100%);
            color: white;
            border-radius: 20px;
        }
        .form-control, .form-select {
            border-radius: 12px;
            padding: 12px 16px;
            border: 1px solid #e2e8f0;
            background-color: #f8fafc;
        }
        .form-control:focus {
            background-color: #ffffff;
            border-color: #6f42c1;
            box-shadow: 0 0 0 4px rgba(111, 66, 193, 0.1);
        }
    </style>
</head>
<body>
    <?php include '../../includes/sidebar.php'; ?>
    <div class="main-content">
        <?php include '../../includes/topbar.php'; ?>
        
        <div class="card bg-gradient-purple p-5 mb-5 shadow-sm mt-3">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2 class="fw-bold mb-2 text-white"><i class="fa-solid fa-user-plus me-3"></i> Register New Student</h2>
                    <p class="text-white-50 fs-6 mb-0">Add student profile details, ID number, academic section, and initial medical history.</p>
                </div>
                <a href="index.php" class="btn btn-light fw-bold px-4 py-3 shadow text-primary rounded-pill">
                    <i class="fa-solid fa-arrow-left me-2"></i> Back to Records
                </a>
            </div>
        </div>

        <?php if (!empty($error_msg)): ?>
            <div class="alert alert-danger shadow-sm border-0 rounded-4 p-4 mb-4"><?php echo htmlspecialchars($error_msg); ?></div>
        <?php endif; ?>

        <div class="card p-5">
            <form action="" method="POST">
                <div class="mb-4">
                    <label class="form-label text-muted small fw-bold text-uppercase">Student ID Number</label>
                    <input type="text" name="student_id" class="form-control fw-semibold" placeholder="e.g., 2026-0110" required>
                </div>

                <div class="row mb-4">
                    <div class="col-md-6">
                        <label class="form-label text-muted small fw-bold text-uppercase">First Name</label>
                        <input type="text" name="first_name" class="form-control fw-semibold" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label text-muted small fw-bold text-uppercase">Last Name</label>
                        <input type="text" name="last_name" class="form-control fw-semibold" required>
                    </div>
                </div>

                <div class="mb-4">
                    <label class="form-label text-muted small fw-bold text-uppercase">Grade & Section</label>
                    <input type="text" name="grade_section" class="form-control fw-semibold" placeholder="e.g., BSIT 401B" required>
                </div>

                <div class="mb-5">
                    <label class="form-label text-muted small fw-bold text-uppercase">Medical History / Allergies</label>
                    <textarea name="medical_history" class="form-control fw-semibold" rows="3" placeholder="e.g., Asthma, Acid Reflux, Penicillin allergy"></textarea>
                </div>

                <div class="d-flex justify-content-end gap-3">
                    <a href="index.php" class="btn btn-light px-4 py-3 rounded-pill fw-semibold text-muted border">Cancel</a>
                    <button type="submit" class="btn btn-primary px-5 py-3 rounded-pill fw-bold shadow-sm" style="background: linear-gradient(135deg, #6f42c1 0%, #4e73df 100%); border: none;">
                        <i class="fa-solid fa-floppy-disk me-2"></i> Save Student Record
                    </button>
                </div>
            </form>
        </div>
    </div>
    <?php include '../../includes/footer.php'; ?>
</body>
</html>