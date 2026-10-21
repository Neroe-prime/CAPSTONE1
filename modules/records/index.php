<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();
if(!isset($_SESSION['user_id'])){
    header("Location: ../../index.php?error=Please log in first");
    exit();
}
require_once '../../includes/db_connect.php';

// Handle Form Submission for Adding a New Student
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_student'])) {
    $student_id = trim($_POST['student_id']);
    $first_name = trim($_POST['first_name']);
    $last_name = trim($_POST['last_name']);
    $grade_section = trim($_POST['grade_section']);
    $medical_history = trim($_POST['medical_history']);

    try {
        $stmt = $pdo->prepare("INSERT INTO students (student_id, first_name, last_name, grade_section, medical_history) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$student_id, $first_name, $last_name, $grade_section, $medical_history]);
        $success_msg = "Student successfully added!";
    } catch (PDOException $e) {
        $error_msg = "Error: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Records - MedFlow</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background-color: #f0f4f8; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; overflow-x: hidden; }
        
        /* Sidebar Styles */
        .sidebar { width: 250px; height: 100vh; background-color: #1a252f; color: #ecf0f1; position: fixed; padding-top: 20px; top: 0; left: 0; z-index: 1000; }
        .sidebar .brand { font-size: 1.5rem; font-weight: bold; text-align: center; margin-bottom: 30px; color: #3498db; }
        .sidebar a { padding: 15px 25px; text-decoration: none; font-size: 1.1rem; color: #bdc3c7; display: block; transition: 0.3s; }
        .sidebar a:hover, .sidebar a.active { color: #ffffff; background-color: #2c3e50; border-left: 4px solid #3498db; }
        .sidebar a i { margin-right: 10px; width: 20px; text-align: center; }
        
        /* Main Content Styles */
        .main-content { margin-left: 250px; padding: 20px; width: calc(100% - 250px); }
        .top-navbar { background-color: #ffffff; border-radius: 8px; padding: 10px 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.04); margin-bottom: 24px; }
        .card { border: none; border-radius: 10px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); }
    </style>
</head>
<body>

    <!-- INJECT SIDEBAR -->
    <?php include '../../includes/sidebar.php'; ?>

    <div class="main-content">
        <!-- INJECT TOPBAR -->
        <?php include '../../includes/topbar.php'; ?>

        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4 class="fw-bold m-0">Student Health Records</h4>
            <button class="btn btn-primary fw-semibold" data-bs-toggle="modal" data-bs-target="#addStudentModal">
                <i class="fa-solid fa-plus me-2"></i> Register Student
            </button>
        </div>

        <?php if(isset($success_msg)): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fa-solid fa-check-circle me-2"></i> <?php echo $success_msg; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if(isset($error_msg)): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fa-solid fa-triangle-exclamation me-2"></i> <?php echo $error_msg; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Student Data Table -->
        <div class="card p-4">
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Student ID</th>
                            <th>Full Name</th>
                            <th>Grade / Section</th>
                            <th>Medical History / Allergies</th>
                            <th>Date Registered</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $stmt = $pdo->query("SELECT * FROM students ORDER BY created_at DESC");
                        if ($stmt->rowCount() > 0) {
                            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                                $date = date('M d, Y', strtotime($row['created_at']));
                                echo "<tr>";
                                echo "<td><span class='badge bg-light text-dark border'>{$row['student_id']}</span></td>";
                                echo "<td><strong>" . htmlspecialchars($row['first_name'] . ' ' . $row['last_name']) . "</strong></td>";
                                echo "<td>" . htmlspecialchars($row['grade_section']) . "</td>";
                                echo "<td><small class='text-muted'>" . htmlspecialchars($row['medical_history'] ? $row['medical_history'] : 'None') . "</small></td>";
                                echo "<td>{$date}</td>";
                                echo "<td>
                                        <button class='btn btn-sm btn-outline-primary'><i class='fa-solid fa-eye'></i> View</button>
                                      </td>";
                                echo "</tr>";
                            }
                        } else {
                            echo "<tr><td colspan='6' class='text-center py-4 text-muted'>No students registered yet.</td></tr>";
                        }
                        ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>

    <!-- ADD STUDENT MODAL -->
    <div class="modal fade" id="addStudentModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header border-0">
                    <h5 class="modal-title fw-bold">Register New Student</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form action="" method="POST">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Student ID (e.g., 2026-001)</label>
                            <input type="text" name="student_id" class="form-control" required>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-semibold">First Name</label>
                                <input type="text" name="first_name" class="form-control" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-semibold">Last Name</label>
                                <input type="text" name="last_name" class="form-control" required>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Grade & Section</label>
                            <input type="text" name="grade_section" class="form-control" placeholder="e.g., Grade 10 - Rizal" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Medical History / Allergies</label>
                            <textarea name="medical_history" class="form-control" rows="3" placeholder="Asthma, allergic to penicillin, etc. (Leave blank if none)"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer border-0">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="add_student" class="btn btn-primary">Save Profile</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- INJECT FOOTER -->
    <?php include '../../includes/footer.php'; ?>
</body>
</html>