<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();
if(!isset($_SESSION['user_id'])){
    header("Location: ../../index.php?error=Please log in first");
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

// Handle Form Submission for Logging a New Consultation & Auto-Deducting Inventory (Admin / Nurse Only)
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_consultation'])) {
    if ($user_role !== 'Admin' && $user_role !== 'Nurse') {
        header("Location: index.php?error=Unauthorized action.");
        exit();
    }

    $student_id = trim($_POST['student_id']);
    $chief_complaint = trim($_POST['chief_complaint']);
    $symptom_category = trim($_POST['symptom_category']);
    $action_taken = trim($_POST['action_taken']);
    $status = trim($_POST['status']);
    $inventory_item_id = !empty($_POST['inventory_item_id']) ? (int)$_POST['inventory_item_id'] : null;
    $quantity_used = !empty($_POST['quantity_used']) ? (int)$_POST['quantity_used'] : 0;

    try {
        $pdo->beginTransaction();

        // 1. Insert consultation log
        $stmt = $pdo->prepare("INSERT INTO consultations (student_id, chief_complaint, symptom_category, action_taken, status) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$student_id, $chief_complaint, $symptom_category, $action_taken, $status]);

        // 2. If medicine was dispensed, automatically deduct from inventory
        if ($inventory_item_id && $quantity_used > 0) {
            $update_inv = $pdo->prepare("UPDATE inventory SET current_stock = GREATEST(0, current_stock - ?) WHERE item_id = ?");
            $update_inv->execute([$quantity_used, $inventory_item_id]);
        }

        $pdo->commit();
        $success_msg = "Consultation successfully logged and inventory updated!";
    } catch (PDOException $e) {
        $pdo->rollBack();
        $error_msg = "Error: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Consultations - MedFlow</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background-color: #f4f5fa; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; overflow-x: hidden; }
        
        /* Sidebar Styles */
        .sidebar { width: 250px; height: 100vh; background-color: #1a252f; color: #ecf0f1; position: fixed; padding-top: 20px; top: 0; left: 0; z-index: 1000; }
        .sidebar .brand { font-size: 1.5rem; font-weight: bold; text-align: center; margin-bottom: 30px; color: #3498db; }
        .sidebar a { padding: 15px 25px; text-decoration: none; font-size: 1.1rem; color: #bdc3c7; display: block; transition: 0.3s; }
        .sidebar a:hover, .sidebar a.active { color: #ffffff; background-color: #2c3e50; border-left: 4px solid #3498db; }
        .sidebar a i { margin-right: 10px; width: 20px; text-align: center; }
        
        /* Spacious Main Content Styles */
        .main-content { margin-left: 250px; padding: 45px; width: calc(100% - 250px); }
        
        .card { border: none; border-radius: 20px; box-shadow: 0 8px 25px rgba(111, 66, 193, 0.07); background-color: #ffffff; }
        
        /* Purple Gradient Banner */
        .bg-gradient-purple {
            background: linear-gradient(135deg, #6f42c1 0%, #4e73df 100%);
            color: white;
            border-radius: 20px;
        }

        /* Spacious Table Styling */
        .table {
            border-collapse: separate;
            border-spacing: 0 12px;
            margin-top: -10px;
        }
        .table thead th {
            background-color: transparent;
            color: #6c757d;
            font-weight: 700;
            text-transform: uppercase;
            font-size: 0.8rem;
            letter-spacing: 1px;
            border-bottom: 2px solid #eaedf2;
            padding: 15px 20px;
        }
        .table tbody tr {
            background-color: #ffffff;
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.02);
        }
        .table tbody td {
            padding: 20px;
            color: #2d3748;
            vertical-align: middle;
            font-size: 0.95rem;
            border-top: 1px solid #f1f3f8;
            border-bottom: 1px solid #f1f3f8;
        }
        .table tbody td:first-child {
            border-left: 1px solid #f1f3f8;
            border-top-left-radius: 14px;
            border-bottom-left-radius: 14px;
        }
        .table tbody td:last-child {
            border-right: 1px solid #f1f3f8;
            border-top-right-radius: 14px;
            border-bottom-right-radius: 14px;
        }

        /* Action Buttons */
        .btn-action-edit { background-color: #fefcbf; color: #d69e2e; border: none; border-radius: 10px; transition: all 0.2s; }
        .btn-action-edit:hover { background-color: #d69e2e; color: white; }
        
        .btn-action-delete { background-color: #fff5f5; color: #e53e3e; border: none; border-radius: 10px; transition: all 0.2s; }
        .btn-action-delete:hover { background-color: #e53e3e; color: white; }

        .form-control, .form-select {
            border-radius: 12px;
            padding: 12px 16px;
            border: 1px solid #e2e8f0;
            background-color: #f8fafc;
        }
        .form-control:focus, .form-select:focus {
            background-color: #ffffff;
            border-color: #6f42c1;
            box-shadow: 0 0 0 4px rgba(111, 66, 193, 0.1);
        }
    </style>
</head>
<body>

    <!-- INJECT SIDEBAR -->
    <?php include '../../includes/sidebar.php'; ?>

    <div class="main-content">
        <!-- INJECT TOPBAR -->
        <?php include '../../includes/topbar.php'; ?>

        <div class="card bg-gradient-purple p-5 mb-5 shadow-sm mt-3">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2 class="fw-bold mb-2 text-white"><i class="fa-solid fa-stethoscope me-3"></i> Clinic Consultations Log</h2>
                    <p class="text-white-50 fs-6 mb-0">Record student visits, symptoms, and automatically deduct dispensed medicines from inventory.</p>
                </div>
                
                <div class="d-flex gap-2">
                    <?php if ($user_role === 'Admin' || $user_role === 'Nurse'): ?>
                        <a href="export.php" class="btn btn-outline-light fw-bold px-4 py-3 shadow-sm rounded-pill">
                            <i class="fa-solid fa-file-arrow-down me-2"></i> Export CSV
                        </a>
                        <button class="btn btn-light fw-bold px-4 py-3 shadow text-primary rounded-pill" data-bs-toggle="modal" data-bs-target="#addConsultationModal">
                            <i class="fa-solid fa-plus me-2"></i> Log New Visit
                        </button>
                    <?php else: ?>
                        <span class="badge bg-light text-dark px-4 py-3 rounded-pill fw-semibold shadow-sm">
                            <i class="fa-solid fa-eye me-2 text-primary"></i> View-Only Access
                        </span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php if(isset($success_msg)): ?>
            <div class="alert alert-success alert-dismissible fade show rounded-4 p-4 mb-4 shadow-sm border-0" role="alert">
                <i class="fa-solid fa-circle-check me-2"></i> <?php echo $success_msg; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if(isset($error_msg)): ?>
            <div class="alert alert-danger alert-dismissible fade show rounded-4 p-4 mb-4 shadow-sm border-0" role="alert">
                <i class="fa-solid fa-triangle-exclamation me-2"></i> <?php echo $error_msg; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Consultations Data Table -->
        <div class="card p-4">
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead>
                        <tr>
                            <th class="ps-4">Date & Time</th>
                            <th>Student Name</th>
                            <th>Grade / Section</th>
                            <th>Chief Complaint</th>
                            <th>Category</th>
                            <th>Action Taken</th>
                            <th>Status</th>
                            <?php if ($user_role === 'Admin' || $user_role === 'Nurse'): ?>
                                <th class="text-end pe-4">Actions</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $stmt = $pdo->query("
                            SELECT c.*, s.first_name, s.last_name, s.grade_section 
                            FROM consultations c
                            JOIN students s ON c.student_id = s.student_id
                            ORDER BY c.consultation_date DESC
                        ");
                        if ($stmt->rowCount() > 0) {
                            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                                $date = date('M d, Y h:i A', strtotime($row['consultation_date']));
                                
                                $badgeClass = 'bg-secondary';
                                if ($row['status'] == 'Cleared') $badgeClass = 'bg-success bg-opacity-10 text-success border border-success-subtle px-3 py-2 rounded-pill';
                                elseif ($row['status'] == 'Under Observation') $badgeClass = 'bg-warning bg-opacity-10 text-warning border border-warning-subtle px-3 py-2 rounded-pill';
                                elseif ($row['status'] == 'Sent Home') $badgeClass = 'bg-danger bg-opacity-10 text-danger border border-danger-subtle px-3 py-2 rounded-pill';
                                else $badgeClass = 'bg-secondary bg-opacity-10 text-secondary px-3 py-2 rounded-pill';

                                echo "<tr>";
                                echo "<td class='ps-4'><span class='fw-semibold text-dark'>{$date}</span></td>";
                                echo "<td><strong>" . htmlspecialchars($row['first_name'] . ' ' . $row['last_name']) . "</strong></td>";
                                echo "<td><span class='badge bg-info bg-opacity-10 text-info px-3 py-2 rounded-pill'>" . htmlspecialchars($row['grade_section']) . "</span></td>";
                                echo "<td>" . htmlspecialchars($row['chief_complaint']) . "</td>";
                                echo "<td><span class='badge bg-light text-dark border px-2 py-1'>" . htmlspecialchars($row['symptom_category']) . "</span></td>";
                                echo "<td>" . htmlspecialchars($row['action_taken']) . "</td>";
                                echo "<td><span class='badge {$badgeClass} fw-semibold'>" . htmlspecialchars($row['status']) . "</span></td>";
                                
                                if ($user_role === 'Admin' || $user_role === 'Nurse') {
                                    echo "<td class='text-end pe-4'>
                                            <div class='d-flex justify-content-end gap-2'>
                                                <a href='edit.php?id=" . $row['consultation_id'] . "' class='btn btn-sm btn-action-edit px-3 py-2' title='Edit Consultation'>
                                                    <i class='fa-solid fa-pen-to-square'></i>
                                                </a>
                                                <a href='delete.php?id=" . $row['consultation_id'] . "' class='btn btn-sm btn-action-delete px-3 py-2' title='Delete Consultation' onclick='return confirm(\"Are you sure you want to delete this consultation log?\");'>
                                                    <i class='fa-solid fa-trash'></i>
                                                </a>
                                            </div>
                                          </td>";
                                }
                                echo "</tr>";
                            }
                        } else {
                            $colspan = ($user_role === 'Admin' || $user_role === 'Nurse') ? 8 : 7;
                            echo "<tr><td colspan='{$colspan}' class='text-center py-5 text-muted'><i class='fa-solid fa-folder-open fa-3x mb-3 text-secondary opacity-25'></i><p class='mb-0 fw-medium'>No consultation logs found.</p></td></tr>";
                        }
                        ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>

    <!-- LOG CONSULTATION MODAL WITH INVENTORY AUTO-DEDUCTION (Admin / Nurse Only) -->
    <?php if ($user_role === 'Admin' || $user_role === 'Nurse'): ?>
    <div class="modal fade" id="addConsultationModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg rounded-4">
                <div class="modal-header border-0 text-white rounded-top-4 p-4" style="background: linear-gradient(135deg, #6f42c1 0%, #4e73df 100%);">
                    <h5 class="modal-title fw-bold"><i class="fa-solid fa-notes-medical me-2"></i> Log New Clinic Visit</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form action="" method="POST">
                    <div class="modal-body p-4">
                        <div class="mb-3">
                            <label class="form-label text-muted small fw-bold text-uppercase">Select Student</label>
                            <select name="student_id" class="form-select" required>
                                <option value="">-- Choose Student --</option>
                                <?php
                                $students = $pdo->query("SELECT student_id, first_name, last_name, grade_section FROM students ORDER BY last_name ASC");
                                while($s = $students->fetch(PDO::FETCH_ASSOC)) {
                                    echo "<option value='{$s['student_id']}'>{$s['last_name']}, {$s['first_name']} ({$s['grade_section']})</option>";
                                }
                                ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label text-muted small fw-bold text-uppercase">Chief Complaint / Symptoms</label>
                            <input type="text" name="chief_complaint" class="form-control" placeholder="e.g., Fever (38.5°C) and body malaise" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label text-muted small fw-bold text-uppercase">Symptom Category (For EARS Surveillance)</label>
                            <select name="symptom_category" class="form-select" required>
                                <option value="Fever">Fever</option>
                                <option value="Cough & Cold">Cough & Cold</option>
                                <option value="Headache">Headache</option>
                                <option value="Stomachache">Stomachache / GI</option>
                                <option value="Injury">Injury / Wound</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label text-muted small fw-bold text-uppercase">Action Taken / Treatment Given</label>
                            <input type="text" name="action_taken" class="form-control" placeholder="e.g., Given Paracetamol 500mg, advised rest" required>
                        </div>
                        
                        <!-- Automated Inventory Deduction Fields -->
                        <div class="row mb-3">
                            <div class="col-md-8">
                                <label class="form-label text-muted small fw-bold text-uppercase">Dispense Medicine (Optional)</label>
                                <select name="inventory_item_id" class="form-select">
                                    <option value="">-- No Medicine Dispensed --</option>
                                    <?php
                                    $inv_list = $pdo->query("SELECT item_id, item_name, current_stock FROM inventory ORDER BY item_name ASC");
                                    while($item = $inv_list->fetch(PDO::FETCH_ASSOC)) {
                                        echo "<option value='{$item['item_id']}'>{$item['item_name']} (Stock: {$item['current_stock']})</option>";
                                    }
                                    ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label text-muted small fw-bold text-uppercase">Quantity</label>
                                <input type="number" name="quantity_used" class="form-control" value="1" min="1">
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label text-muted small fw-bold text-uppercase">Status</label>
                            <select name="status" class="form-select" required>
                                <option value="Cleared">Cleared (Back to Class)</option>
                                <option value="Under Observation">Under Observation</option>
                                <option value="Sent Home">Sent Home</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer border-0 bg-light rounded-bottom-4 p-3">
                        <button type="button" class="btn btn-secondary btn-sm px-4 rounded-pill" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="add_consultation" class="btn btn-primary btn-sm px-4 rounded-pill fw-semibold" style="background: linear-gradient(135deg, #6f42c1 0%, #4e73df 100%); border: none;">Save & Deduct Stock</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- INJECT FOOTER -->
    <?php include '../../includes/footer.php'; ?>
</body>
</html>