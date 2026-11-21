<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../index.php?error=Please log in first");
    exit();
}

// Prevent browser caching after logout so 'Back' button won't show consultations
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

require_once '../../includes/db_connect.php';

// Fetch current user role and username for RBAC and audit trail
$performed_by = $_SESSION['username'] ?? 'Staff';
try {
    $role_stmt = $pdo->prepare("SELECT role, username FROM users WHERE user_id = ?");
    $role_stmt->execute([$_SESSION['user_id']]);
    $user_row = $role_stmt->fetch(PDO::FETCH_ASSOC);
    $user_role = $user_row['role'] ?? ($_SESSION['role'] ?? 'Nurse');
    if (!empty($user_row['username'])) {
        $performed_by = $user_row['username'];
    }
} catch (PDOException $e) {
    $user_role = $_SESSION['role'] ?? 'Nurse';
}

/**
 * Automatically compute and update the daily consumption rate for an inventory item
 * based on recent consultation dispensing history for Holt's Trend model.
 */
function updateInventoryConsumptionRate($pdo, $item_id) {
    $sql = "SELECT SUM(c.quantity_used) as total_dispensed, 
                   DATEDIFF(MAX(c.consultation_date), MIN(c.consultation_date)) as day_span
            FROM consultations c 
            WHERE c.inventory_item_id = ? AND c.consultation_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$item_id]);
    $data = $stmt->fetch(PDO::FETCH_ASSOC);

    $total_dispensed = $data['total_dispensed'] ?? 0;
    
    $daily_rate = 1.00;
    if ($total_dispensed > 0) {
        $daily_rate = round($total_dispensed / 30, 2); 
    }

    $update = $pdo->prepare("UPDATE inventory SET daily_consumption_rate = ? WHERE item_id = ?");
    $update->execute([$daily_rate, $item_id]);
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

    if ($inventory_item_id && $quantity_used <= 0) {
        $error_msg = "Quantity dispensed must be at least 1.";
    } else {
        try {
            $pdo->beginTransaction();

            $locked_item = null;
            $previous_stock = 0;
            $new_stock = 0;

            // Lock the inventory row first to prevent race conditions / negative stock
            if ($inventory_item_id && $quantity_used > 0) {
                $lock_stmt = $pdo->prepare("SELECT item_id, item_name, current_stock FROM inventory WHERE item_id = ? FOR UPDATE");
                $lock_stmt->execute([$inventory_item_id]);
                $locked_item = $lock_stmt->fetch(PDO::FETCH_ASSOC);

                if (!$locked_item) {
                    throw new Exception("Selected medicine/supply item was not found in inventory.");
                }

                $previous_stock = (int)$locked_item['current_stock'];
                if ($previous_stock < $quantity_used) {
                    throw new Exception("Insufficient stock for {$locked_item['item_name']}. Available: {$previous_stock} pcs, Requested: {$quantity_used} pcs.");
                }

                $new_stock = $previous_stock - $quantity_used;

                $update_inv = $pdo->prepare("UPDATE inventory SET current_stock = ? WHERE item_id = ?");
                $update_inv->execute([$new_stock, $inventory_item_id]);
            }

            // 1. Insert consultation log
            $stmt = $pdo->prepare("INSERT INTO consultations (student_id, chief_complaint, symptom_category, action_taken, status, inventory_item_id, quantity_used) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$student_id, $chief_complaint, $symptom_category, $action_taken, $status, $inventory_item_id, $quantity_used]);

            // 2. Record audit trail & recalculate Holt's daily consumption rate if dispensed
            if ($locked_item && $quantity_used > 0) {
                $audit_stmt = $pdo->prepare("
                    INSERT INTO inventory_audit 
                        (item_id, item_name, action_type, quantity, previous_stock, new_stock, reason_or_remarks, performed_by) 
                    VALUES (?, ?, 'Dispensed', ?, ?, ?, ?, ?)
                ");
                $remarks = "Consultation for Student ID: {$student_id} | Complaint: {$chief_complaint}";
                $audit_stmt->execute([
                    $locked_item['item_id'],
                    $locked_item['item_name'],
                    $quantity_used,
                    $previous_stock,
                    $new_stock,
                    $remarks,
                    $performed_by
                ]);

                updateInventoryConsumptionRate($pdo, $inventory_item_id);
            }

            $pdo->commit();
            $success_msg = "Consultation successfully logged, inventory updated safely, and consumption rate recalculated!";
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error_msg = $e->getMessage();
        }
    }
}

// Report Filter Parameters
$date_from = trim($_GET['date_from'] ?? '');
$date_to = trim($_GET['date_to'] ?? '');
$filter_category = trim($_GET['symptom_category'] ?? '');
$filter_status = trim($_GET['status'] ?? '');
$filter_search = trim($_GET['q'] ?? '');

$consultations = [];
$query_error = '';
try {
    $sql = "
        SELECT c.*, s.first_name, s.last_name, s.grade_section 
        FROM consultations c
        JOIN students s ON c.student_id = s.student_id
        WHERE 1=1
    ";
    $params = [];

    if ($date_from !== '') {
        $sql .= " AND DATE(c.consultation_date) >= ?";
        $params[] = $date_from;
    }
    if ($date_to !== '') {
        $sql .= " AND DATE(c.consultation_date) <= ?";
        $params[] = $date_to;
    }
    if ($filter_category !== '') {
        $sql .= " AND c.symptom_category = ?";
        $params[] = $filter_category;
    }
    if ($filter_status !== '') {
        $sql .= " AND c.status = ?";
        $params[] = $filter_status;
    }
    if ($filter_search !== '') {
        $sql .= " AND (s.first_name LIKE ? OR s.last_name LIKE ? OR CONCAT(s.first_name, ' ', s.last_name) LIKE ? OR s.grade_section LIKE ? OR c.chief_complaint LIKE ?)";
        $like = '%' . $filter_search . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }

    $sql .= " ORDER BY c.consultation_date DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $consultations = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $query_error = "Error loading consultation records.";
}

$has_active_filters = ($date_from !== '' || $date_to !== '' || $filter_category !== '' || $filter_status !== '' || $filter_search !== '');
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
        
        .sidebar { width: 250px; height: 100vh; background-color: #1a252f; color: #ecf0f1; position: fixed; padding-top: 20px; top: 0; left: 0; z-index: 1000; }
        .sidebar .brand { font-size: 1.5rem; font-weight: bold; text-align: center; margin-bottom: 30px; color: #3498db; }
        .sidebar a { padding: 15px 25px; text-decoration: none; font-size: 1.1rem; color: #bdc3c7; display: block; transition: 0.3s; }
        .sidebar a:hover, .sidebar a.active { color: #ffffff; background-color: #2c3e50; border-left: 4px solid #3498db; }
        .sidebar a i { margin-right: 10px; width: 20px; text-align: center; }
        
        .main-content { margin-left: 250px; padding: 45px; width: calc(100% - 250px); }
        .card { border: none; border-radius: 20px; box-shadow: 0 8px 25px rgba(111, 66, 193, 0.07); background-color: #ffffff; }
        
        .bg-gradient-purple {
            background: linear-gradient(135deg, #6f42c1 0%, #4e73df 100%);
            color: white;
            border-radius: 20px;
        }

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

        .print-report-header { display: none; }

        @media print {
            .sidebar, .top-navbar, .no-print, .btn, .alert { display: none !important; }
            .main-content { margin-left: 0 !important; width: 100% !important; padding: 15px !important; }
            .card { box-shadow: none !important; border: 1px solid #dee2e6 !important; }
            .print-report-header { display: block !important; margin-bottom: 20px; border-bottom: 2px solid #2d3748; padding-bottom: 12px; }
            .table { border-collapse: collapse !important; border-spacing: 0 !important; }
            .table tbody tr { box-shadow: none !important; }
            .table tbody td { border: 1px solid #dee2e6 !important; border-radius: 0 !important; padding: 10px !important; }
        }
    </style>
</head>
<body>

    <?php include '../../includes/sidebar.php'; ?>

    <div class="main-content">
        <?php include '../../includes/topbar.php'; ?>

        <!-- Print-Only Official Report Header -->
        <div class="print-report-header">
            <h3 class="fw-bold mb-1">MedFlow — Clinic Consultations Report</h3>
            <p class="text-muted small mb-0">
                Generated on: <?php echo date('F d, Y h:i A'); ?> | Total Consultations: <strong><?php echo count($consultations); ?></strong>
                <?php if ($date_from !== '' || $date_to !== ''): ?> | Date Range: <strong><?php echo htmlspecialchars(($date_from ?: 'Start') . ' to ' . ($date_to ?: 'Present')); ?></strong><?php endif; ?>
                <?php if ($filter_category !== ''): ?> | Category: <strong><?php echo htmlspecialchars($filter_category); ?></strong><?php endif; ?>
                <?php if ($filter_status !== ''): ?> | Status: <strong><?php echo htmlspecialchars($filter_status); ?></strong><?php endif; ?>
            </p>
        </div>

        <div class="card bg-gradient-purple p-5 mb-5 shadow-sm mt-3">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
                <div>
                    <h2 class="fw-bold mb-2 text-white"><i class="fa-solid fa-stethoscope me-3"></i> Clinic Consultations Log</h2>
                    <p class="text-white-50 fs-6 mb-0">Record student visits, filter reports by date or symptom, and safely deduct dispensed inventory.</p>
                </div>
                
                <div class="d-flex flex-wrap gap-2 no-print">
                    <button type="button" class="btn btn-outline-light fw-bold px-4 py-3 shadow-sm rounded-pill text-nowrap" onclick="window.print();">
                        <i class="fa-solid fa-file-pdf me-2"></i> Generate / Print Report
                    </button>
                    <?php if ($user_role === 'Admin' || $user_role === 'Nurse'): ?>
                        <a href="export.php" class="btn btn-outline-light fw-bold px-4 py-3 shadow-sm rounded-pill text-nowrap">
                            <i class="fa-solid fa-file-arrow-down me-2"></i> Export CSV
                        </a>
                        <button class="btn btn-light fw-bold px-4 py-3 shadow text-primary rounded-pill text-nowrap" data-bs-toggle="modal" data-bs-target="#addConsultationModal">
                            <i class="fa-solid fa-plus me-2"></i> Log New Visit
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php if (isset($success_msg)): ?>
            <div class="alert alert-success alert-dismissible fade show rounded-4 p-4 mb-4 shadow-sm border-0 no-print" role="alert">
                <i class="fa-solid fa-circle-check me-2"></i> <?php echo htmlspecialchars($success_msg); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if (isset($error_msg)): ?>
            <div class="alert alert-danger alert-dismissible fade show rounded-4 p-4 mb-4 shadow-sm border-0 no-print" role="alert">
                <i class="fa-solid fa-triangle-exclamation me-2"></i> <?php echo htmlspecialchars($error_msg); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Filter & Report Criteria Card -->
        <div class="card p-4 mb-4 no-print">
            <form method="GET" action="" class="row g-3 align-items-end">
                <div class="col-md-2">
                    <label class="form-label small fw-bold text-muted text-uppercase">Date From</label>
                    <input type="date" name="date_from" class="form-control" value="<?php echo htmlspecialchars($date_from); ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold text-muted text-uppercase">Date To</label>
                    <input type="date" name="date_to" class="form-control" value="<?php echo htmlspecialchars($date_to); ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold text-muted text-uppercase">Symptom Category</label>
                    <select name="symptom_category" class="form-select">
                        <option value="">All Categories</option>
                        <?php
                        $categories = ['Fever', 'Cough & Cold', 'Headache', 'Stomachache', 'Injury', 'Other'];
                        foreach ($categories as $cat) {
                            $sel = $filter_category === $cat ? 'selected' : '';
                            echo "<option value=\"" . htmlspecialchars($cat) . "\" {$sel}>" . htmlspecialchars($cat) . "</option>";
                        }
                        ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold text-muted text-uppercase">Status</label>
                    <select name="status" class="form-select">
                        <option value="">All Statuses</option>
                        <?php
                        $statuses = ['Cleared', 'Under Observation', 'Excused to Rest at Home', 'Referred to Hospital / Clinic'];
                        foreach ($statuses as $st) {
                            $sel = $filter_status === $st ? 'selected' : '';
                            echo "<option value=\"" . htmlspecialchars($st) . "\" {$sel}>" . htmlspecialchars($st) . "</option>";
                        }
                        ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold text-muted text-uppercase">Search</label>
                    <input type="text" name="q" class="form-control" value="<?php echo htmlspecialchars($filter_search); ?>" placeholder="Student or complaint...">
                </div>
                <div class="col-md-2 d-flex gap-2">
                    <button type="submit" class="btn btn-primary rounded-pill px-3 py-2 fw-semibold flex-grow-1" style="background: linear-gradient(135deg, #6f42c1 0%, #4e73df 100%); border: none;">
                        <i class="fa-solid fa-filter me-1"></i> Filter
                    </button>
                    <?php if ($has_active_filters): ?>
                        <a href="index.php" class="btn btn-light rounded-pill px-3 py-2 fw-semibold" title="Reset Filters">
                            <i class="fa-solid fa-rotate-left"></i>
                        </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- Consultations Data Table -->
        <div class="card p-4">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <span class="text-muted small fw-semibold">
                    Showing <strong><?php echo count($consultations); ?></strong> consultation log<?php echo count($consultations) === 1 ? '' : 's'; ?>
                    <?php if ($has_active_filters): ?>(Filtered Report View)<?php endif; ?>
                </span>
            </div>

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
                                <th class="text-end pe-4 no-print">Actions</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        if ($query_error !== '') {
                            $colspan = ($user_role === 'Admin' || $user_role === 'Nurse') ? 8 : 7;
                            echo "<tr><td colspan='{$colspan}' class='text-center text-danger py-4'>" . htmlspecialchars($query_error) . "</td></tr>";
                        } elseif (count($consultations) > 0) {
                            foreach ($consultations as $row) {
                                $date = date('M d, Y h:i A', strtotime($row['consultation_date']));
                                
                                $badgeClass = 'bg-secondary';
                                if ($row['status'] == 'Cleared') $badgeClass = 'bg-success bg-opacity-10 text-success border border-success-subtle px-3 py-2 rounded-pill';
                                elseif ($row['status'] == 'Under Observation') $badgeClass = 'bg-warning bg-opacity-10 text-warning border border-warning-subtle px-3 py-2 rounded-pill';
                                elseif ($row['status'] == 'Excused to Rest at Home') $badgeClass = 'bg-danger bg-opacity-10 text-danger border border-danger-subtle px-3 py-2 rounded-pill';
                                elseif ($row['status'] == 'Referred to Hospital / Clinic') $badgeClass = 'bg-primary bg-opacity-10 text-primary border border-primary-subtle px-3 py-2 rounded-pill';
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
                                    echo "<td class='text-end pe-4 no-print'>
                                            <div class='d-flex justify-content-end gap-2'>
                                                <a href='edit.php?id=" . (int)$row['consultation_id'] . "' class='btn btn-sm btn-action-edit px-3 py-2' title='Edit Consultation'>
                                                    <i class='fa-solid fa-pen-to-square'></i>
                                                </a>
                                                <a href='delete.php?id=" . (int)$row['consultation_id'] . "' class='btn btn-sm btn-action-delete px-3 py-2' title='Delete Consultation' onclick='return confirm(\"Are you sure you want to delete this consultation log?\");'>
                                                    <i class='fa-solid fa-trash'></i>
                                                </a>
                                            </div>
                                          </td>";
                                }
                                echo "</tr>";
                            }
                        } else {
                            $colspan = ($user_role === 'Admin' || $user_role === 'Nurse') ? 8 : 7;
                            echo "<tr><td colspan='{$colspan}' class='text-center py-5 text-muted'><i class='fa-solid fa-folder-open fa-3x mb-3 text-secondary opacity-25'></i><p class='mb-0 fw-medium'>No matching consultation logs found.</p></td></tr>";
                        }
                        ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>

    <!-- LOG CONSULTATION MODAL WITH TYPE-TO-SEARCH DATALISTS -->
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
                            <label class="form-label text-muted small fw-bold text-uppercase">Search & Select Student</label>
                            <input class="form-control" list="student_list" name="student_id" placeholder="Type student name or ID..." required>
                            <datalist id="student_list">
                                <?php
                                $students = $pdo->query("SELECT student_id, first_name, last_name, grade_section FROM students ORDER BY last_name ASC");
                                while($s = $students->fetch(PDO::FETCH_ASSOC)) {
                                    echo "<option value='" . htmlspecialchars($s['student_id']) . "'>" . htmlspecialchars($s['last_name'] . ", " . $s['first_name'] . " (" . $s['grade_section'] . ")") . "</option>";
                                }
                                ?>
                            </datalist>
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
                        
                        <div class="row mb-3">
                            <div class="col-md-8">
                                <label class="form-label text-muted small fw-bold text-uppercase">Dispense Medicine / Supply (Optional)</label>
                                <input class="form-control" list="inventory_list" name="inventory_item_id" placeholder="Type medicine name...">
                                <datalist id="inventory_list">
                                    <?php
                                    $inv_list = $pdo->query("SELECT item_id, item_name, current_stock FROM inventory ORDER BY item_name ASC");
                                    while($item = $inv_list->fetch(PDO::FETCH_ASSOC)) {
                                        echo "<option value='" . (int)$item['item_id'] . "'>" . htmlspecialchars($item['item_name']) . " (Stock: " . (int)$item['current_stock'] . ")</option>";
                                    }
                                    ?>
                                </datalist>
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
                                <option value="Excused to Rest at Home">Excused to Rest at Home</option>
                                <option value="Referred to Hospital / Clinic">Referred to Hospital / Clinic</option>
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

    <?php include '../../includes/footer.php'; ?>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
</body>
</html>