<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();
if(!isset($_SESSION['user_id'])){
    header("Location: ../../index.php?error=Please log in first");
    exit();
}
require_once '../../includes/db_connect.php';

$student_id = $_GET['id'] ?? '';
if (empty($student_id)) {
    header("Location: index.php?error=Invalid student ID");
    exit();
}

// Handle form submission for update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $grade_section = trim($_POST['grade_section'] ?? '');
    $medical_history = trim($_POST['medical_history'] ?? '');
    $allergies = trim($_POST['allergies'] ?? '');

    try {
        $update_stmt = $pdo->prepare("UPDATE students SET first_name = ?, last_name = ?, grade_section = ?, medical_history = ?, allergies = ? WHERE student_id = ?");
        $update_stmt->execute([$first_name, $last_name, $grade_section, $medical_history, $allergies, $student_id]);
        
        header("Location: index.php?success=Student record updated successfully!");
        exit();
    } catch (PDOException $e) {
        $error_msg = "Database error while updating record.";
    }
}

// Fetch student info
try {
    $stmt = $pdo->prepare("SELECT * FROM students WHERE student_id = ?");
    $stmt->execute([$student_id]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$student) {
        header("Location: index.php?error=Student not found");
        exit();
    }
} catch (PDOException $e) {
    header("Location: index.php?error=Database error");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Student - MedFlow</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background-color: #f0f4f8; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; overflow-x: hidden; }
        .sidebar { width: 250px; height: 100vh; background-color: #1a252f; color: #ecf0f1; position: fixed; padding-top: 20px; top: 0; left: 0; z-index: 1000; }
        .sidebar .brand { font-size: 1.5rem; font-weight: bold; text-align: center; margin-bottom: 30px; color: #3498db; }
        .sidebar a { padding: 15px 25px; text-decoration: none; font-size: 1.1rem; color: #bdc3c7; display: block; transition: 0.3s; }
        .sidebar a:hover, .sidebar a.active { color: #ffffff; background-color: #2c3e50; border-left: 4px solid #3498db; }
        .sidebar a i { margin-right: 10px; width: 20px; text-align: center; }
        .main-content { margin-left: 250px; padding: 20px; width: calc(100% - 250px); }
        .card { border: none; border-radius: 10px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); }
    </style>
</head>
<body>
    <?php include '../../includes/sidebar.php'; ?>
    <div class="main-content">
        <?php include '../../includes/topbar.php'; ?>
        
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4 class="fw-bold mb-0">Edit Student Record</h4>
            <a href="index.php" class="btn btn-secondary btn-sm">
                <i class="fa-solid fa-arrow-left me-1"></i> Back to Records
            </a>
        </div>

        <?php if (isset($error_msg)): ?>
            <div class="alert alert-danger shadow-sm border-0"><?php echo htmlspecialchars($error_msg); ?></div>
        <?php endif; ?>

        <div class="card p-4">
            <form action="edit.php?id=<?php echo urlencode($student_id); ?>" method="POST">
                <div class="row mb-3">
                    <div class="col-md-4">
                        <label class="form-label text-muted small fw-semibold">Student ID</label>
                        <input type="text" class="form-control bg-light" value="<?php echo htmlspecialchars($student['student_id']); ?>" readonly>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label text-muted small fw-semibold">First Name</label>
                        <input type="text" name="first_name" class="form-control" value="<?php echo htmlspecialchars($student['first_name']); ?>" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label text-muted small fw-semibold">Last Name</label>
                        <input type="text" name="last_name" class="form-control" value="<?php echo htmlspecialchars($student['last_name']); ?>" required>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label text-muted small fw-semibold">Grade & Section</label>
                    <input type="text" name="grade_section" class="form-control" value="<?php echo htmlspecialchars($student['grade_section']); ?>" required>
                </div>

                <div class="mb-3">
                    <label class="form-label text-muted small fw-semibold">Medical History</label>
                    <textarea name="medical_history" class="form-control" rows="2"><?php echo htmlspecialchars($student['medical_history'] ?? ''); ?></textarea>
                </div>

                <div class="mb-4">
                    <label class="form-label text-muted small fw-semibold">Allergies</label>
                    <textarea name="allergies" class="form-control" rows="2"><?php echo htmlspecialchars($student['allergies'] ?? ''); ?></textarea>
                </div>

                <button type="submit" class="btn btn-primary px-4">
                    <i class="fa-solid fa-floppy-disk me-2"></i> Update Record
                </button>
            </form>
        </div>
    </div>
    <?php include '../../includes/footer.php'; ?>
</body>
</html>