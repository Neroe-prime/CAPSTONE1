<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../index.php");
    exit();
}
require_once '../../includes/db_connect.php';

// Fetch current user role for RBAC
try {
    $role_stmt = $pdo->prepare("SELECT role FROM users WHERE user_id = ?");
    $role_stmt->execute([$_SESSION['user_id']]);
    $user_role = $role_stmt->fetchColumn();
} catch (PDOException $e) {
    $user_role = 'Teacher';
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
        body { background-color: #f4f5fa; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; overflow-x: hidden; }
        .sidebar { width: 250px; height: 100vh; background-color: #1a252f; color: #ecf0f1; position: fixed; padding-top: 20px; top: 0; left: 0; z-index: 1000; }
        .sidebar .brand { font-size: 1.5rem; font-weight: bold; text-align: center; margin-bottom: 30px; color: #3498db; }
        .sidebar a { padding: 15px 25px; text-decoration: none; font-size: 1.1rem; color: #bdc3c7; display: block; transition: 0.3s; }
        .sidebar a:hover, .sidebar a.active { color: #ffffff; background-color: #2c3e50; border-left: 4px solid #3498db; }
        .main-content { margin-left: 250px; padding: 45px; width: calc(100% - 250px); }
        .card { border: none; border-radius: 20px; box-shadow: 0 8px 25px rgba(111, 66, 193, 0.07); background-color: #ffffff; }
        .bg-gradient-purple { background: linear-gradient(135deg, #6f42c1 0%, #4e73df 100%); color: white; border-radius: 20px; }
        .table { border-collapse: separate; border-spacing: 0 10px; margin-top: -10px; }
        .table thead th { background-color: transparent; color: #6c757d; font-weight: 700; text-transform: uppercase; font-size: 0.75rem; letter-spacing: 1px; border-bottom: 2px solid #eaedf2; padding: 15px 20px; }
        .table tbody tr { background-color: #ffffff; box-shadow: 0 3px 10px rgba(0, 0, 0, 0.02); }
        .table tbody td { padding: 18px 20px; color: #2d3748; vertical-align: middle; font-size: 0.95rem; border-top: 1px solid #f1f3f8; border-bottom: 1px solid #f1f3f8; }
        .table tbody td:first-child { border-left: 1px solid #f1f3f8; border-top-left-radius: 12px; border-bottom-left-radius: 12px; }
        .table tbody td:last-child { border-right: 1px solid #f1f3f8; border-top-right-radius: 12px; border-bottom-right-radius: 12px; }
        
        .btn-action-view { background-color: #ebf8ff; color: #3182ce; border: none; border-radius: 10px; transition: all 0.2s; }
        .btn-action-view:hover { background-color: #3182ce; color: white; }
        .btn-action-edit { background-color: #fefcbf; color: #d69e2e; border: none; border-radius: 10px; transition: all 0.2s; }
        .btn-action-edit:hover { background-color: #d69e2e; color: white; }
        .btn-action-delete { background-color: #fff5f5; color: #e53e3e; border: none; border-radius: 10px; transition: all 0.2s; }
        .btn-action-delete:hover { background-color: #e53e3e; color: white; }
    </style>
</head>
<body>
    <?php include '../../includes/sidebar.php'; ?>
    <div class="main-content">
        <?php include '../../includes/topbar.php'; ?>

        <!-- Header Banner -->
        <div class="card bg-gradient-purple p-5 mb-5 shadow-sm mt-3">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2 class="fw-bold mb-2 text-white"><i class="fa-solid fa-user-injured me-3"></i> Student Health Records</h2>
                    <p class="text-white-50 fs-6 mb-0">Manage registered student profiles, medical histories, and clinical records smoothly.</p>
                </div>
                <?php if ($user_role === 'Admin' || $user_role === 'Nurse'): ?>
                    <a href="register.php" class="btn btn-light fw-bold px-4 py-3 shadow text-primary rounded-pill">
                        <i class="fa-solid fa-user-plus me-2"></i> Register Student
                    </a>
                <?php else: ?>
                    <span class="badge bg-light text-dark px-4 py-3 rounded-pill fw-semibold shadow-sm">
                        <i class="fa-solid fa-eye me-2 text-primary"></i> View-Only Access
                    </span>
                <?php endif; ?>
            </div>
        </div>

        <?php if (isset($_GET['success'])): ?>
            <div class="alert alert-success shadow-sm border-0 rounded-4 p-4 mb-4">
                <i class="fa-solid fa-circle-check me-2"></i> <?php echo htmlspecialchars($_GET['success']); ?>
            </div>
        <?php endif; ?>

        <?php if (isset($_GET['error'])): ?>
            <div class="alert alert-danger shadow-sm border-0 rounded-4 p-4 mb-4">
                <i class="fa-solid fa-triangle-exclamation me-2"></i> <?php echo htmlspecialchars($_GET['error']); ?>
            </div>
        <?php endif; ?>

        <!-- Students Table Card -->
        <div class="card p-4">
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead>
                        <tr>
                            <th class="ps-4">Student ID</th>
                            <th>Full Name</th>
                            <th>Grade / Section</th>
                            <th>Medical History / Allergies</th>
                            <th>Date Registered</th>
                            <th class="text-end pe-4">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        try {
                            $stmt = $pdo->query("SELECT * FROM students ORDER BY student_id DESC");
                            if ($stmt->rowCount() > 0) {
                                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                                    $reg_date = !empty($row['created_at']) ? date('M d, Y', strtotime($row['created_at'])) : 'Oct 22, 2026';
                                    
                                    echo "<tr>";
                                    echo "<td class='ps-4'><span class='badge bg-purple bg-opacity-10 text-purple border px-3 py-2 rounded-pill fw-semibold' style='background-color: #f3f0ff !important; color: #6f42c1 !important;'>#" . htmlspecialchars($row['student_id']) . "</span></td>";
                                    echo "<td><strong class='text-dark fs-6'>" . htmlspecialchars($row['first_name'] . ' ' . $row['last_name']) . "</strong></td>";
                                    echo "<td><span class='badge bg-info bg-opacity-10 text-info px-3 py-2 rounded-pill'>" . htmlspecialchars($row['grade_section']) . "</span></td>";
                                    echo "<td><span class='badge bg-danger bg-opacity-10 text-danger px-3 py-2 rounded-pill'>" . htmlspecialchars($row['medical_history'] ?: 'None') . "</span></td>";
                                    echo "<td class='text-muted'>" . $reg_date . "</td>";
                                    
                                    echo "<td class='text-end pe-4'>
                                            <div class='d-flex justify-content-end gap-2'>
                                                <a href='view.php?id=" . $row['student_id'] . "' class='btn btn-sm btn-action-view px-3 py-2' title='View Profile'>
                                                    <i class='fa-solid fa-eye'></i>
                                                </a>";
                                                
                                    if ($user_role === 'Admin' || $user_role === 'Nurse') {
                                        echo "<a href='edit.php?id=" . $row['student_id'] . "' class='btn btn-sm btn-action-edit px-3 py-2' title='Edit Record'>
                                                <i class='fa-solid fa-pen-to-square'></i>
                                              </a>
                                              <a href='delete.php?id=" . $row['student_id'] . "' class='btn btn-sm btn-action-delete px-3 py-2' title='Delete Record' onclick='return confirm(\"Are you sure you want to delete this student record?\");'>
                                                <i class='fa-solid fa-trash'></i>
                                              </a>";
                                    }
                                    
                                    echo "</div>
                                         </td>";
                                    echo "</tr>";
                                }
                            } else {
                                $colspan = ($user_role === 'Admin' || $user_role === 'Nurse') ? 6 : 5;
                                echo "<tr><td colspan='{$colspan}' class='text-center text-muted py-5'>
                                        <i class='fa-solid fa-user-slash fa-3x mb-3 text-secondary opacity-25'></i>
                                        <p class='mb-0 fw-medium'>No student health records found.</p>
                                      </td></tr>";
                            }
                        } catch (PDOException $e) {
                            echo "<tr><td colspan='6' class='text-center text-danger py-4'>Error loading student records.</td></tr>";
                        }
                        ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php include '../../includes/footer.php'; ?>
</body>
</html>