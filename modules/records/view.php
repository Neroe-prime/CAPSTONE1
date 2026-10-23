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

$student_id = $_GET['id'] ?? '';
if (empty($student_id)) {
    header("Location: index.php?error=Invalid student ID");
    exit();
}

// Handle quick log visit submission inside profile (Admin / Nurse Only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['log_visit'])) {
    if ($user_role !== 'Admin' && $user_role !== 'Nurse') {
        header("Location: view.php?id=$student_id&error=Unauthorized action.");
        exit();
    }

    $chief_complaint = trim($_POST['chief_complaint'] ?? '');
    $symptom_category = trim($_POST['symptom_category'] ?? '');
    $action_taken = trim($_POST['action_taken'] ?? '');
    $status = trim($_POST['status'] ?? 'Cleared');
    $inventory_item_id = !empty($_POST['inventory_item_id']) ? (int)$_POST['inventory_item_id'] : null;
    $quantity_used = !empty($_POST['quantity_used']) ? (int)$_POST['quantity_used'] : 0;

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("INSERT INTO consultations (student_id, chief_complaint, symptom_category, action_taken, status) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$student_id, $chief_complaint, $symptom_category, $action_taken, $status]);

        if ($inventory_item_id && $quantity_used > 0) {
            $update_inv = $pdo->prepare("UPDATE inventory SET current_stock = GREATEST(0, current_stock - ?) WHERE item_id = ?");
            $update_inv->execute([$quantity_used, $inventory_item_id]);
        }

        $pdo->commit();
        header("Location: view.php?id=$student_id&success=Clinic visit logged successfully!");
        exit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        $error_msg = "Error logging visit: " . $e->getMessage();
    }
}

// Fetch student details
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
    <title>Student Profile - MedFlow</title>
    
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

        .table {
            border-collapse: separate;
            border-spacing: 0 10px;
            margin-top: -10px;
        }
        .table thead th {
            background-color: transparent;
            color: #6c757d;
            font-weight: 700;
            text-transform: uppercase;
            font-size: 0.75rem;
            letter-spacing: 1px;
            border-bottom: 2px solid #eaedf2;
            padding: 15px 20px;
        }
        .table tbody tr {
            background-color: #ffffff;
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.02);
        }
        .table tbody td {
            padding: 18px 20px;
            color: #2d3748;
            vertical-align: middle;
            font-size: 0.95rem;
            border-top: 1px solid #f1f3f8;
            border-bottom: 1px solid #f1f3f8;
        }
        .table tbody td:first-child {
            border-left: 1px solid #f1f3f8;
            border-top-left-radius: 12px;
            border-bottom-left-radius: 12px;
        }
        .table tbody td:last-child {
            border-right: 1px solid #f1f3f8;
            border-top-right-radius: 12px;
            border-bottom-right-radius: 12px;
        }

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

    <?php include '../../includes/sidebar.php'; ?>

    <div class="main-content">
        
        <?php include '../../includes/topbar.php'; ?>

        <!-- Purple Gradient Header Banner -->
        <div class="card bg-gradient-purple p-5 mb-5 shadow-sm mt-3">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <span class="badge bg-light text-purple px-3 py-1 rounded-pill fw-semibold mb-2" style="color: #6f42c1 !important;">ID: 2026-0<?php echo str_pad($student['student_id'], 3, '0', STR_PAD_LEFT); ?></span>
                    <h2 class="fw-bold mb-1 text-white"><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></h2>
                    <p class="text-white-50 fs-6 mb-0"><i class="fa-solid fa-graduation-cap me-2"></i> <?php echo htmlspecialchars($student['grade_section']); ?></p>
                </div>
                <a href="index.php" class="btn btn-light fw-bold px-4 py-3 shadow text-primary rounded-pill">
                    <i class="fa-solid fa-arrow-left me-2"></i> Back to Records
                </a>
            </div>
        </div>

        <?php if (isset($_GET['success'])): ?>
            <div class="alert alert-success shadow-sm border-0 rounded-4 p-4 mb-4"><i class="fa-solid fa-circle-check me-2"></i> <?php echo htmlspecialchars($_GET['success']); ?></div>
        <?php endif; ?>

        <?php if (isset($error_msg) || isset($_GET['error'])): ?>
            <div class="alert alert-danger shadow-sm border-0 rounded-4 p-4 mb-4"><i class="fa-solid fa-triangle-exclamation me-2"></i> <?php echo htmlspecialchars($error_msg ?? $_GET['error']); ?></div>
        <?php endif; ?>

        <div class="row g-4">
            <!-- Left Column: Medical Profile -->
            <div class="col-lg-4">
                <div class="card p-4 h-100">
                    <h5 class="fw-bold text-dark mb-4"><i class="fa-solid fa-id-card me-2 text-purple" style="color: #6f42c1;"></i> Medical Profile</h5>
                    
                    <div class="mb-4">
                        <label class="form-label text-muted small fw-bold text-uppercase">Grade & Section</label>
                        <p class="fw-semibold text-dark fs-6 mb-0"><?php echo htmlspecialchars($student['grade_section']); ?></p>
                    </div>

                    <div class="mb-4">
                        <label class="form-label text-muted small fw-bold text-uppercase">Medical History</label>
                        <p class="text-danger fw-semibold mb-0"><?php echo htmlspecialchars($student['medical_history'] ?: 'None recorded'); ?></p>
                    </div>

                    <div class="mb-4">
                        <label class="form-label text-muted small fw-bold text-uppercase">Allergies</label>
                        <p class="text-muted fw-semibold mb-0">None recorded</p>
                    </div>

                    <div class="mt-auto pt-3 border-top">
                        <span class="text-muted small">Date Registered: <?php echo !empty($student['created_at']) ? date('F d, Y h:i A', strtotime($student['created_at'])) : 'October 22, 2026'; ?></span>
                    </div>
                </div>
            </div>

            <!-- Right Column: Clinic Visit History -->
            <div class="col-lg-8">
                <div class="card p-4 h-100">
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <div>
                            <h5 class="fw-bold text-dark mb-1"><i class="fa-solid fa-notes-medical me-2 text-primary"></i> Clinic Visit History</h5>
                            <p class="text-muted small mb-0">Recorded consultations and treatment logs for this student.</p>
                        </div>
                        
                        <!-- RBAC: Only Admin or Nurse can log visits from profile -->
                        <?php if ($user_role === 'Admin' || $user_role === 'Nurse'): ?>
                            <button class="btn btn-primary btn-sm px-4 py-2 rounded-pill fw-semibold shadow-sm" style="background: linear-gradient(135deg, #6f42c1 0%, #4e73df 100%); border: none;" data-bs-toggle="modal" data-bs-target="#logVisitModal">
                                <i class="fa-solid fa-plus me-1"></i> Log Visit
                            </button>
                        <?php endif; ?>
                    </div>

                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
                            <thead>
                                <tr>
                                    <th class="ps-3">Visit Date</th>
                                    <th>Chief Complaint</th>
                                    <th>Treatment Given</th>
                                    <th class="text-end pe-3">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                try {
                                    $v_stmt = $pdo->prepare("SELECT * FROM consultations WHERE student_id = ? ORDER BY consultation_date DESC");
                                    $v_stmt->execute([$student_id]);
                                    
                                    if ($v_stmt->rowCount() > 0) {
                                        while ($visit = $v_stmt->fetch(PDO::FETCH_ASSOC)) {
                                            $v_date = date('M d, Y h:i A', strtotime($visit['consultation_date']));
                                            $status = $visit['status'];
                                            
                                            $badgeClass = 'bg-secondary bg-opacity-10 text-secondary';
                                            if ($status == 'Cleared') $badgeClass = 'bg-success bg-opacity-10 text-success border border-success-subtle';
                                            elseif ($status == 'Under Observation') $badgeClass = 'bg-warning bg-opacity-10 text-warning border border-warning-subtle';
                                            elseif ($status == 'Sent Home') $badgeClass = 'bg-danger bg-opacity-10 text-danger border border-danger-subtle';

                                            echo "<tr>";
                                            echo "<td class='ps-3 fw-semibold text-dark'>" . $v_date . "</td>";
                                            echo "<td>" . htmlspecialchars($visit['chief_complaint']) . "</td>";
                                            echo "<td>" . htmlspecialchars($visit['action_taken']) . "</td>";
                                            echo "<td class='text-end pe-3'><span class='badge {$badgeClass} px-3 py-2 rounded-pill fw-semibold'>" . htmlspecialchars($status) . "</span></td>";
                                            echo "</tr>";
                                        }
                                    } else {
                                        echo "<tr><td colspan='4' class='text-center text-muted py-5'>
                                                <i class='fa-solid fa-folder-open fa-3x mb-3 text-secondary opacity-25'></i>
                                                <p class='mb-0 fw-medium'>No clinic visits recorded for this student yet.</p>
                                              </td></tr>";
                                    }
                                } catch (PDOException $e) {
                                    echo "<tr><td colspan='4' class='text-center text-danger py-4'>Error loading visit history.</td></tr>";
                                }
                                ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Log Visit Modal (Admin / Nurse Only) -->
    <?php if ($user_role === 'Admin' || $user_role === 'Nurse'): ?>
    <div class="modal fade" id="logVisitModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg rounded-4">
                <div class="modal-header border-0 text-white rounded-top-4 p-4" style="background: linear-gradient(135deg, #6f42c1 0%, #4e73df 100%);">
                    <h5 class="modal-title fw-bold"><i class="fa-solid fa-notes-medical me-2"></i> Log Visit for <?php echo htmlspecialchars($student['first_name']); ?></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form action="" method="POST">
                    <div class="modal-body p-4">
                        <div class="mb-3">
                            <label class="form-label text-muted small fw-bold text-uppercase">Chief Complaint / Symptoms</label>
                            <input type="text" name="chief_complaint" class="form-control" placeholder="e.g., Fever and headache" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label text-muted small fw-bold text-uppercase">Symptom Category</label>
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
                            <input type="text" name="action_taken" class="form-control" placeholder="e.g., Given Paracetamol 500mg" required>
                        </div>
                        
                        <!-- Inventory Deduction -->
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
                        <button type="submit" name="log_visit" class="btn btn-primary btn-sm px-4 rounded-pill fw-semibold" style="background: linear-gradient(135deg, #6f42c1 0%, #4e73df 100%); border: none;">Save & Deduct Stock</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php include '../../includes/footer.php'; ?>
</body>
</html>