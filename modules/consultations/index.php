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

// Fetch current user role and username
$performed_by = $_SESSION['username'] ?? 'Staff';
try {
    $u_stmt = $pdo->prepare("SELECT role, username FROM users WHERE user_id = ?");
    $u_stmt->execute([$_SESSION['user_id']]);
    $u_row = $u_stmt->fetch(PDO::FETCH_ASSOC);
    $user_role = $u_row['role'] ?? ($_SESSION['role'] ?? 'Nurse');
    if (!empty($u_row['username'])) {
        $performed_by = $u_row['username'];
    }
} catch (PDOException $e) {
    $user_role = $_SESSION['role'] ?? 'Nurse';
}

if ($user_role !== 'Admin' && $user_role !== 'Nurse') {
    header("Location: ../dashboard/index.php?error=Unauthorized access to consultations");
    exit();
}

/**
 * Automatically compute and update the daily consumption rate for an inventory item
 * based on recent consultation dispensing history for Holt's Trend model.
 */
function updateConsumptionRate($pdo, $item_id) {
    $sql = "SELECT SUM(c.quantity_used) as total_dispensed
            FROM consultations c
            WHERE c.inventory_item_id = ? AND c.consultation_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$item_id]);
    $total_dispensed = (float)($stmt->fetchColumn() ?? 0);

    $daily_rate = 1.00;
    if ($total_dispensed > 0) {
        $daily_rate = round($total_dispensed / 30, 2);
    }

    $update = $pdo->prepare("UPDATE inventory SET daily_consumption_rate = ? WHERE item_id = ?");
    $update->execute([$daily_rate, $item_id]);
}

$success_msg = '';
$error_msg = '';

// Handle Add New Consultation with FOR UPDATE Concurrency Lock, Same-Day Revisit & Duplicate Med Safety Check
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_consultation'])) {
    $student_id = trim($_POST['student_id'] ?? '');
    $chief_complaint = trim($_POST['chief_complaint'] ?? '');
    $symptom_category = trim($_POST['symptom_category'] ?? 'Other');
    $medication_item = trim($_POST['medication_item'] ?? '');
    $quantity_used = max(1, (int)($_POST['quantity_used'] ?? 1));
    $status = trim($_POST['status'] ?? 'Under Observation');
    $action_taken = trim($_POST['action_taken'] ?? '');
    $confirm_duplicate_med = isset($_POST['confirm_duplicate_med']) && $_POST['confirm_duplicate_med'] === '1';

    if (empty($student_id) || empty($chief_complaint)) {
        $error_msg = "Please fill in all required consultation fields.";
    } else {
        try {
            // Check earlier consultations for this student today
            $prior_stmt = $pdo->prepare("
                SELECT consultation_id, consultation_date, chief_complaint, action_taken, inventory_item_id, quantity_used, status
                FROM consultations
                WHERE student_id = ? AND DATE(consultation_date) = CURDATE()
                ORDER BY consultation_date DESC
            ");
            $prior_stmt->execute([$student_id]);
            $same_day_prior = $prior_stmt->fetchAll(PDO::FETCH_ASSOC);
            $is_same_day_revisit = count($same_day_prior) > 0;

            // Check if the same medication was already dispensed to this student today
            $same_med_today = false;
            $last_med_time = '';
            if (!empty($medication_item) && $is_same_day_revisit) {
                $item_lookup = $pdo->prepare("SELECT item_id FROM inventory WHERE item_name = ? LIMIT 1");
                $item_lookup->execute([$medication_item]);
                $target_item_id = $item_lookup->fetchColumn();

                foreach ($same_day_prior as $prev_c) {
                    $matched_by_id = ($target_item_id && (int)$prev_c['inventory_item_id'] === (int)$target_item_id);
                    $matched_by_text = (stripos((string)$prev_c['action_taken'], $medication_item) !== false);
                    if ($matched_by_id || $matched_by_text) {
                        $same_med_today = true;
                        $last_med_time = date('h:i A', strtotime($prev_c['consultation_date']));
                        break;
                    }
                }
            }

            if ($same_med_today && !$confirm_duplicate_med) {
                throw new Exception("Duplicate Same-Day Medication Guard: '{$medication_item}' was already recorded for this student today at {$last_med_time}. Check the 'Confirm duplicate same-day medication' box if clinically authorized, or choose a different treatment.");
            }

            if (!empty($medication_item)) {
                $dispense_note = "Dispensed: {$medication_item} (Qty: {$quantity_used})";
                $action_taken = !empty($action_taken) ? ($action_taken . " | " . $dispense_note) : $dispense_note;
            }

            if ($is_same_day_revisit) {
                $visit_num = count($same_day_prior) + 1;
                $revisit_tag = "[Same-Day Revisit #" . $visit_num . "]";
                if (stripos($action_taken, 'Same-Day Revisit') === false) {
                    $action_taken = !empty($action_taken) ? ($revisit_tag . " " . $action_taken) : $revisit_tag;
                }
            }

            $pdo->beginTransaction();

            $locked_item = null;
            $previous_stock = 0;
            $new_stock = 0;
            $dispensed_item_id = null;
            $logged_qty = 0;

            if (!empty($medication_item) && $quantity_used > 0) {
                $lock_stmt = $pdo->prepare("SELECT item_id, item_name, current_stock FROM inventory WHERE item_name = ? FOR UPDATE");
                $lock_stmt->execute([$medication_item]);
                $locked_item = $lock_stmt->fetch(PDO::FETCH_ASSOC);

                if (!$locked_item) {
                    throw new Exception("Selected medicine/supply '{$medication_item}' was not found in inventory.");
                }

                $previous_stock = (int)$locked_item['current_stock'];
                if ($previous_stock < $quantity_used) {
                    throw new Exception("Insufficient stock for {$locked_item['item_name']}. Available: {$previous_stock} pcs, Requested: {$quantity_used} pcs.");
                }

                $new_stock = $previous_stock - $quantity_used;
                $dispensed_item_id = (int)$locked_item['item_id'];
                $logged_qty = $quantity_used;

                $inv_stmt = $pdo->prepare("UPDATE inventory SET current_stock = ? WHERE item_id = ?");
                $inv_stmt->execute([$new_stock, $dispensed_item_id]);
            }

            $stmt = $pdo->prepare("INSERT INTO consultations (student_id, chief_complaint, symptom_category, action_taken, status, inventory_item_id, quantity_used, consultation_date) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
            $stmt->execute([$student_id, $chief_complaint, $symptom_category, $action_taken, $status, $dispensed_item_id, $logged_qty]);

            if ($locked_item && $logged_qty > 0) {
                $audit_stmt = $pdo->prepare("
                    INSERT INTO inventory_audit 
                        (item_id, item_name, action_type, quantity, previous_stock, new_stock, reason_or_remarks, performed_by) 
                    VALUES (?, ?, 'Dispensed', ?, ?, ?, ?, ?)
                ");
                $revisit_prefix = $is_same_day_revisit ? " [Same-Day Revisit]" : "";
                $remarks = "Consultation for Student ID: {$student_id}{$revisit_prefix} | Complaint: {$chief_complaint}";
                $audit_stmt->execute([
                    $dispensed_item_id,
                    $locked_item['item_name'],
                    $logged_qty,
                    $previous_stock,
                    $new_stock,
                    $remarks,
                    $performed_by
                ]);

                updateConsumptionRate($pdo, $dispensed_item_id);
            }

            $pdo->commit();
            $success_msg = $is_same_day_revisit
                ? "Same-day revisit consultation logged and linked to today's earlier visit timeline!"
                : "Consultation logged and inventory updated safely!";
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error_msg = $e->getMessage();
        }
    }
}

// Handle Status Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $consultation_id = (int)($_POST['consultation_id'] ?? 0);
    $new_status = trim($_POST['new_status'] ?? 'Under Observation');

    if ($consultation_id > 0) {
        try {
            $stmt = $pdo->prepare("UPDATE consultations SET status = ? WHERE consultation_id = ?");
            $stmt->execute([$new_status, $consultation_id]);
            $success_msg = "Consultation status updated to '{$new_status}'!";
        } catch (PDOException $e) {
            $error_msg = "Database error updating status: " . $e->getMessage();
        }
    }
}

// Preload today's consultations by student_id for live Same-Day Revisit Alert in modal
$today_visits_by_student = [];
try {
    $td_stmt = $pdo->query("
        SELECT c.student_id, c.consultation_date, c.chief_complaint, c.symptom_category, c.action_taken, c.status, inv.item_name
        FROM consultations c
        LEFT JOIN inventory inv ON c.inventory_item_id = inv.item_id
        WHERE DATE(c.consultation_date) = CURDATE()
        ORDER BY c.consultation_date DESC
    ");
    foreach ($td_stmt->fetchAll(PDO::FETCH_ASSOC) as $tv) {
        $sid = (string)$tv['student_id'];
        $today_visits_by_student[$sid][] = [
            'time' => date('h:i A', strtotime($tv['consultation_date'])),
            'complaint' => $tv['chief_complaint'],
            'category' => $tv['symptom_category'],
            'action' => $tv['action_taken'],
            'medicine' => $tv['item_name'] ?: '',
            'status' => $tv['status']
        ];
    }
} catch (PDOException $e) {
    $today_visits_by_student = [];
}

// Preload full consultation timeline per student for the View Mode modal
$student_history_map = [];
try {
    $hist_stmt = $pdo->query("
        SELECT c.consultation_id, c.student_id, c.consultation_date, c.chief_complaint, c.symptom_category, c.action_taken, c.status, c.quantity_used,
               s.first_name, s.last_name, s.grade_section, inv.item_name as med_name
        FROM consultations c
        JOIN students s ON c.student_id = s.student_id
        LEFT JOIN inventory inv ON c.inventory_item_id = inv.item_id
        ORDER BY c.consultation_date DESC
    ");
    foreach ($hist_stmt->fetchAll(PDO::FETCH_ASSOC) as $h_row) {
        $sid = (string)$h_row['student_id'];
        if (!isset($student_history_map[$sid])) {
            $student_history_map[$sid] = [
                'student_id' => $sid,
                'name' => trim($h_row['first_name'] . ' ' . $h_row['last_name']),
                'grade_section' => $h_row['grade_section'],
                'visits' => []
            ];
        }
        $student_history_map[$sid]['visits'][] = [
            'id' => (int)$h_row['consultation_id'],
            'date' => date('M d, Y', strtotime($h_row['consultation_date'])),
            'iso_date' => date('Y-m-d', strtotime($h_row['consultation_date'])),
            'time' => date('h:i A', strtotime($h_row['consultation_date'])),
            'category' => $h_row['symptom_category'],
            'complaint' => $h_row['chief_complaint'],
            'action' => $h_row['action_taken'],
            'medicine' => $h_row['med_name'] ?: '',
            'qty' => (int)($h_row['quantity_used'] ?? 0),
            'status' => $h_row['status']
        ];
    }
} catch (PDOException $e) {
    $student_history_map = [];
}

// Report & Filter Parameters
$filter_start_date = trim($_GET['start_date'] ?? '');
$filter_end_date = trim($_GET['end_date'] ?? '');
$filter_category = trim($_GET['symptom_category'] ?? '');
$filter_status = trim($_GET['status'] ?? '');
$report_mode = isset($_GET['report']) && $_GET['report'] === '1';

// Build filtered query
$where = [];
$params = [];

if ($filter_start_date !== '') {
    $where[] = "DATE(c.consultation_date) >= ?";
    $params[] = $filter_start_date;
}
if ($filter_end_date !== '') {
    $where[] = "DATE(c.consultation_date) <= ?";
    $params[] = $filter_end_date;
}
if ($filter_category !== '') {
    $where[] = "c.symptom_category = ?";
    $params[] = $filter_category;
}
if ($filter_status !== '') {
    $where[] = "c.status = ?";
    $params[] = $filter_status;
}

$sql = "
    SELECT c.*, s.first_name, s.last_name, s.grade_section, inv.item_name as med_name
    FROM consultations c
    JOIN students s ON c.student_id = s.student_id
    LEFT JOIN inventory inv ON c.inventory_item_id = inv.item_id
";
if (!empty($where)) {
    $sql .= " WHERE " . implode(" AND ", $where);
}
$sql .= " ORDER BY c.consultation_date DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$consultations_list = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Compute same-day visit counts per (student_id + date) so repeat same-day visits can be clearly badged
$daily_student_counts = [];
try {
    $cnt_stmt = $pdo->query("
        SELECT student_id, DATE(consultation_date) as v_date, COUNT(*) as v_cnt
        FROM consultations
        GROUP BY student_id, DATE(consultation_date)
    ");
    foreach ($cnt_stmt->fetchAll(PDO::FETCH_ASSOC) as $dc) {
        $daily_student_counts[$dc['student_id'] . '|' . $dc['v_date']] = (int)$dc['v_cnt'];
    }
} catch (PDOException $e) {
    $daily_student_counts = [];
}

// Report summary metrics
$total_filtered = count($consultations_list);
$revisit_filtered_count = 0;
$status_summary = [
    'Under Observation' => 0,
    'Cleared' => 0,
    'Excused to Rest at Home' => 0,
    'Referred to Hospital / Clinic' => 0,
];
foreach ($consultations_list as $c_row) {
    $st = $c_row['status'] ?? 'Under Observation';
    if (isset($status_summary[$st])) {
        $status_summary[$st]++;
    }
    $key = $c_row['student_id'] . '|' . date('Y-m-d', strtotime($c_row['consultation_date']));
    if (($daily_student_counts[$key] ?? 1) > 1) {
        $revisit_filtered_count++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Consultations & Triage - MedFlow</title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>
        body { background-color: #f4f5fa; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; overflow-x: hidden; }
        .sidebar { width: 250px; height: 100vh; background-color: #1a252f; color: #ecf0f1; position: fixed; padding-top: 20px; top: 0; left: 0; z-index: 1000; }
        .sidebar .brand { font-size: 1.5rem; font-weight: bold; text-align: center; margin-bottom: 30px; color: #3498db; }
        .sidebar a { padding: 15px 25px; text-decoration: none; font-size: 1.1rem; color: #bdc3c7; display: block; transition: 0.3s; }
        .sidebar a:hover, .sidebar a.active { color: #ffffff; background-color: #2c3e50; border-left: 4px solid #3498db; }
        
        .main-content { margin-left: 250px; padding: 32px 32px; width: calc(100% - 250px); }
        .card { border: none; border-radius: 20px; box-shadow: 0 8px 25px rgba(111, 66, 193, 0.07); background-color: #ffffff; }
        
        .bg-gradient-purple {
            background: linear-gradient(135deg, #6f42c1 0%, #4e73df 100%);
            color: white;
            border-radius: 20px;
        }

        .table {
            border-collapse: separate;
            border-spacing: 0 8px;
            margin-top: -8px;
            width: 100%;
        }
        .table thead th {
            background-color: transparent;
            color: #6c757d;
            font-weight: 700;
            text-transform: uppercase;
            font-size: 0.68rem;
            letter-spacing: 0.5px;
            border-bottom: 2px solid #eaedf2;
            padding: 10px 8px;
            white-space: nowrap;
        }
        .table tbody tr {
            background-color: #ffffff;
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.02);
        }
        .table tbody td {
            padding: 12px 8px;
            color: #2d3748;
            vertical-align: middle;
            font-size: 0.85rem;
            border-top: 1px solid #f1f3f8;
            border-bottom: 1px solid #f1f3f8;
        }
        .table tbody td:first-child {
            padding-left: 12px;
            border-left: 1px solid #f1f3f8;
            border-top-left-radius: 12px;
            border-bottom-left-radius: 12px;
        }
        .table tbody td:last-child {
            padding-right: 12px;
            border-right: 1px solid #f1f3f8;
            border-top-right-radius: 12px;
            border-bottom-right-radius: 12px;
        }

        .action-row-controls .btn-view-compact {
            padding: 4px 10px;
            font-size: 0.76rem;
            line-height: 1.25;
        }
        .action-row-controls .select-status-compact {
            padding: 4px 24px 4px 8px;
            font-size: 0.76rem;
            line-height: 1.25;
            height: auto;
            width: 128px;
        }
        .action-row-controls .btn-save-compact {
            padding: 4px 12px;
            font-size: 0.76rem;
            line-height: 1.25;
        }

        .print-only-header { display: none; }

        @media print {
            .sidebar, .top-navbar, .no-print, .btn, .alert .btn-close { display: none !important; }
            .main-content { margin-left: 0 !important; width: 100% !important; padding: 15px !important; }
            .card { box-shadow: none !important; border: 1px solid #dee2e6 !important; }
            .print-only-header { display: block !important; margin-bottom: 16px; }
        }
    </style>
</head>
<body>

    <?php include '../../includes/sidebar.php'; ?>

    <div class="main-content">
        <?php include '../../includes/topbar.php'; ?>

        <div class="print-only-header">
            <h3 class="fw-bold mb-1">MedFlow School Clinic — Consultations &amp; Triage Report</h3>
            <p class="text-muted small mb-0">
                Generated on <?php echo date('F d, Y h:i A'); ?>
                <?php if ($filter_start_date || $filter_end_date): ?>
                    | Date Range: <?php echo htmlspecialchars($filter_start_date ?: 'Beginning'); ?> to <?php echo htmlspecialchars($filter_end_date ?: 'Present'); ?>
                <?php endif; ?>
                <?php if ($filter_category): ?> | Symptom: <?php echo htmlspecialchars($filter_category); ?><?php endif; ?>
                <?php if ($filter_status): ?> | Status: <?php echo htmlspecialchars($filter_status); ?><?php endif; ?>
            </p>
            <hr>
        </div>

        <!-- Purple Gradient Header Banner -->
        <div class="card bg-gradient-purple p-4 p-md-5 mb-4 shadow-sm mt-3 no-print">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
                <div>
                    <h2 class="fw-bold mb-2 text-white"><i class="fa-solid fa-notes-medical me-3"></i> Consultations & Triage</h2>
                    <p class="text-white-50 fs-6 mb-0">Log student clinic visits, inspect student revisit timelines, and generate consultation reports.</p>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    <button type="button" class="btn btn-outline-light fw-bold px-4 py-2 rounded-pill shadow-sm text-nowrap" onclick="window.print();">
                        <i class="fa-solid fa-print me-2"></i> Generate / Print Report
                    </button>
                    <button type="button" class="btn btn-light fw-bold px-4 py-2 shadow text-primary rounded-pill text-nowrap" data-bs-toggle="modal" data-bs-target="#newConsultationModal">
                        <i class="fa-solid fa-plus me-2"></i> Log New Visit
                    </button>
                </div>
            </div>
        </div>

        <?php if (!empty($success_msg)): ?>
            <div class="alert alert-success alert-dismissible fade show shadow-sm border-0 rounded-4 p-4 mb-4" role="alert">
                <i class="fa-solid fa-circle-check me-2"></i> <?php echo htmlspecialchars($success_msg); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <?php if (!empty($error_msg)): ?>
            <div class="alert alert-danger alert-dismissible fade show shadow-sm border-0 rounded-4 p-4 mb-4" role="alert">
                <i class="fa-solid fa-triangle-exclamation me-2"></i> <?php echo htmlspecialchars($error_msg); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <!-- Report Summary Bar -->
        <div class="row g-3 mb-4 <?php echo $report_mode ? '' : 'no-print'; ?>">
            <div class="col-md-3">
                <div class="card p-3 h-100">
                    <div class="text-muted small fw-bold text-uppercase">Filtered Visits</div>
                    <div class="fs-3 fw-bold text-dark"><?php echo $total_filtered; ?></div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card p-3 h-100">
                    <div class="text-muted small fw-bold text-uppercase">Same-Day Revisits</div>
                    <div class="fs-3 fw-bold text-info"><?php echo $revisit_filtered_count; ?></div>
                    <div class="text-muted" style="font-size: 0.75rem;">Repeat visits on same date</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card p-3 h-100">
                    <div class="text-muted small fw-bold text-uppercase">Cleared / Rest at Home</div>
                    <div class="fs-3 fw-bold text-success"><?php echo $status_summary['Cleared'] + $status_summary['Excused to Rest at Home']; ?></div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card p-3 h-100">
                    <div class="text-muted small fw-bold text-uppercase">Referred / Observing</div>
                    <div class="fs-3 fw-bold text-warning"><?php echo $status_summary['Referred to Hospital / Clinic'] + $status_summary['Under Observation']; ?></div>
                </div>
            </div>
        </div>

        <!-- Filter & Report Card -->
        <div class="card p-4 mb-4 no-print">
            <form method="GET" action="" class="row g-3 align-items-end">
                <div class="col-md-2">
                    <label class="form-label small fw-bold text-muted text-uppercase">From Date</label>
                    <input type="date" name="start_date" class="form-control rounded-3" value="<?php echo htmlspecialchars($filter_start_date); ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold text-muted text-uppercase">To Date</label>
                    <input type="date" name="end_date" class="form-control rounded-3" value="<?php echo htmlspecialchars($filter_end_date); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-bold text-muted text-uppercase">Symptom Category</label>
                    <select name="symptom_category" class="form-select rounded-3">
                        <option value="">All Categories</option>
                        <option value="Fever" <?php echo $filter_category === 'Fever' ? 'selected' : ''; ?>>Fever</option>
                        <option value="Cough & Cold" <?php echo $filter_category === 'Cough & Cold' ? 'selected' : ''; ?>>Cough &amp; Cold</option>
                        <option value="Headache / Dizziness" <?php echo $filter_category === 'Headache / Dizziness' ? 'selected' : ''; ?>>Headache / Dizziness</option>
                        <option value="Stomachache" <?php echo $filter_category === 'Stomachache' ? 'selected' : ''; ?>>Stomachache</option>
                        <option value="Injury / Wound" <?php echo $filter_category === 'Injury / Wound' ? 'selected' : ''; ?>>Injury / Wound</option>
                        <option value="Other" <?php echo $filter_category === 'Other' ? 'selected' : ''; ?>>Other</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-bold text-muted text-uppercase">Status</label>
                    <select name="status" class="form-select rounded-3">
                        <option value="">All Statuses</option>
                        <option value="Under Observation" <?php echo $filter_status === 'Under Observation' ? 'selected' : ''; ?>>Under Observation</option>
                        <option value="Cleared" <?php echo $filter_status === 'Cleared' ? 'selected' : ''; ?>>Cleared</option>
                        <option value="Excused to Rest at Home" <?php echo $filter_status === 'Excused to Rest at Home' ? 'selected' : ''; ?>>Excused to Rest at Home</option>
                        <option value="Referred to Hospital / Clinic" <?php echo $filter_status === 'Referred to Hospital / Clinic' ? 'selected' : ''; ?>>Referred to Hospital / Clinic</option>
                    </select>
                </div>
                <div class="col-md-2 d-flex gap-2">
                    <button type="submit" class="btn btn-primary rounded-pill px-3 py-2 fw-semibold w-100">Filter</button>
                    <a href="index.php" class="btn btn-light rounded-pill px-3 py-2 fw-semibold">Reset</a>
                </div>
            </form>
        </div>

        <!-- Consultations Table -->
        <div class="card p-3 p-md-4">
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Date &amp; Time</th>
                            <th>Student</th>
                            <th>Category</th>
                            <th>Chief Complaint</th>
                            <th>Action Taken</th>
                            <th>Status</th>
                            <th class="text-end no-print">View / Update Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($total_filtered > 0): ?>
                            <?php foreach ($consultations_list as $row):
                                $v_date = date('Y-m-d', strtotime($row['consultation_date']));
                                $v_key = $row['student_id'] . '|' . $v_date;
                                $day_visit_total = $daily_student_counts[$v_key] ?? 1;
                                $student_visit_total = count($student_history_map[(string)$row['student_id']]['visits'] ?? []);
                            ?>
                                <tr>
                                    <td class="text-muted small text-nowrap">
                                        <div class="fw-semibold text-dark"><?php echo date('M d, Y', strtotime($row['consultation_date'])); ?></div>
                                        <div><?php echo date('h:i A', strtotime($row['consultation_date'])); ?></div>
                                    </td>
                                    <td class="text-nowrap">
                                        <div class="fw-bold text-dark"><?php echo htmlspecialchars($row['first_name'] . ' ' . $row['last_name']); ?></div>
                                        <small class="text-muted"><?php echo htmlspecialchars($row['student_id']); ?> &bull; <?php echo htmlspecialchars($row['grade_section']); ?></small>
                                        <?php if ($day_visit_total > 1): ?>
                                            <div class="mt-1">
                                                <span class="badge bg-info bg-opacity-15 text-dark border border-info-subtle rounded-pill px-2 py-0.5" style="font-size: 0.68rem;">
                                                    <i class="fa-solid fa-rotate-right me-1 text-info"></i> Revisit (<?php echo $day_visit_total; ?>x)
                                                </span>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-nowrap"><span class="badge bg-light text-dark border px-2 py-1 rounded-pill" style="font-size: 0.75rem;"><?php echo htmlspecialchars($row['symptom_category']); ?></span></td>
                                    <td><?php echo htmlspecialchars($row['chief_complaint']); ?></td>
                                    <td><?php echo htmlspecialchars($row['action_taken']); ?></td>
                                    <td class="text-nowrap"><span class="badge bg-primary bg-opacity-10 text-primary px-2 py-1 rounded-pill" style="font-size: 0.75rem;"><?php echo htmlspecialchars($row['status']); ?></span></td>
                                    <td class="text-end text-nowrap no-print action-row-controls">
                                        <div class="d-inline-flex align-items-center justify-content-end gap-1.5">
                                            <button type="button"
                                                    class="btn btn-outline-secondary rounded-pill fw-semibold text-nowrap btn-view-compact"
                                                    onclick="openStudentHistoryModal('<?php echo htmlspecialchars((string)$row['student_id'], ENT_QUOTES); ?>')"
                                                    title="View full visit history & same-day timeline">
                                                <i class="fa-solid fa-eye me-1"></i>View<?php if ($student_visit_total > 1): ?> (<?php echo $student_visit_total; ?>)<?php endif; ?>
                                            </button>
                                            <form method="POST" class="d-inline-flex align-items-center gap-1.5 mb-0">
                                                <input type="hidden" name="update_status" value="1">
                                                <input type="hidden" name="consultation_id" value="<?php echo (int)$row['consultation_id']; ?>">
                                                <select name="new_status" class="form-select rounded-3 select-status-compact">
                                                    <option value="Under Observation" <?php echo $row['status'] === 'Under Observation' ? 'selected' : ''; ?>>Under Observation</option>
                                                    <option value="Cleared" <?php echo $row['status'] === 'Cleared' ? 'selected' : ''; ?>>Cleared</option>
                                                    <option value="Excused to Rest at Home" <?php echo $row['status'] === 'Excused to Rest at Home' ? 'selected' : ''; ?>>Excused to Rest at Home</option>
                                                    <option value="Referred to Hospital / Clinic" <?php echo $row['status'] === 'Referred to Hospital / Clinic' ? 'selected' : ''; ?>>Referred to Hospital / Clinic</option>
                                                </select>
                                                <button type="submit" class="btn btn-primary rounded-pill fw-semibold text-nowrap btn-save-compact" style="background-color: #5a67d8; border: none;">Save</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="7" class="text-center text-muted py-5">No consultation records match the selected filters.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- STUDENT CONSULTATION HISTORY / VIEW MODE MODAL -->
    <div class="modal fade" id="studentHistoryModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content border-0 shadow-lg rounded-4">
                <div class="modal-header border-0 text-white rounded-top-4 p-4" style="background: linear-gradient(135deg, #6f42c1 0%, #4e73df 100%);">
                    <div>
                        <h5 class="modal-title fw-bold mb-1" id="historyStudentTitle"><i class="fa-solid fa-user-clock me-2"></i> Student Consultation Timeline</h5>
                        <div class="small text-white-50" id="historyStudentMeta"></div>
                    </div>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4" id="historyTimelineBody">
                    <!-- Populated via JS -->
                </div>
                <div class="modal-footer border-0 bg-light rounded-bottom-4 p-3">
                    <button type="button" class="btn btn-secondary btn-sm px-4 rounded-pill" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- LOG NEW CONSULTATION MODAL -->
    <div class="modal fade" id="newConsultationModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content border-0 shadow-lg rounded-4">
                <div class="modal-header border-0 text-white rounded-top-4 p-4" style="background: linear-gradient(135deg, #6f42c1 0%, #4e73df 100%);">
                    <h5 class="modal-title fw-bold"><i class="fa-solid fa-stethoscope me-2"></i> Log New Clinic Consultation</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" action="">
                    <div class="modal-body p-4">
                        <input type="hidden" name="add_consultation" value="1">

                        <!-- Live Same-Day Revisit Safety Banner -->
                        <div id="sameDayRevisitBanner" class="alert alert-warning border-0 rounded-3 d-none mb-3" style="background-color: #fffaf0; color: #744210;">
                            <div class="fw-bold mb-1"><i class="fa-solid fa-clock-rotate-left me-1"></i> Same-Day Revisit Detected Today</div>
                            <div id="sameDayRevisitDetails" class="small"></div>
                        </div>

                        <div class="row g-3 mb-3">
                            <div class="col-md-7">
                                <label class="form-label text-muted small fw-bold text-uppercase">Student ID / Name</label>
                                <input list="students_datalist" id="consultStudentInput" name="student_id" class="form-control rounded-3" placeholder="Select or type Student ID..." required oninput="checkSameDayRevisit()">
                                <datalist id="students_datalist">
                                    <?php
                                    try {
                                        $s_q = $pdo->query("SELECT student_id, first_name, last_name, grade_section FROM students ORDER BY last_name ASC");
                                        while ($st_row = $s_q->fetch(PDO::FETCH_ASSOC)) {
                                            echo '<option value="' . htmlspecialchars($st_row['student_id']) . '">' . htmlspecialchars($st_row['last_name'] . ', ' . $st_row['first_name'] . ' (' . $st_row['grade_section'] . ')') . '</option>';
                                        }
                                    } catch (PDOException $e) {}
                                    ?>
                                </datalist>
                            </div>
                            <div class="col-md-5">
                                <label class="form-label text-muted small fw-bold text-uppercase">Symptom Category</label>
                                <select name="symptom_category" class="form-select rounded-3" required>
                                    <option value="Fever">Fever</option>
                                    <option value="Cough & Cold">Cough &amp; Cold</option>
                                    <option value="Headache / Dizziness">Headache / Dizziness</option>
                                    <option value="Stomachache">Stomachache</option>
                                    <option value="Injury / Wound">Injury / Wound</option>
                                    <option value="Other">Other</option>
                                </select>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label text-muted small fw-bold text-uppercase">Chief Complaint / Symptoms</label>
                            <textarea name="chief_complaint" class="form-control rounded-3" rows="2" placeholder="Describe student symptoms or follow-up condition..." required></textarea>
                        </div>

                        <div class="row g-3 mb-3">
                            <div class="col-md-8">
                                <label class="form-label text-muted small fw-bold text-uppercase">Dispense Medicine / Supply (Optional)</label>
                                <input list="meds_datalist" id="consultMedInput" name="medication_item" class="form-control rounded-3" placeholder="Select medicine/supply..." oninput="checkSameDayRevisit()">
                                <datalist id="meds_datalist">
                                    <?php
                                    try {
                                        $m_q = $pdo->query("SELECT item_name, current_stock FROM inventory WHERE current_stock > 0 ORDER BY item_name ASC");
                                        while ($m_row = $m_q->fetch(PDO::FETCH_ASSOC)) {
                                            echo '<option value="' . htmlspecialchars($m_row['item_name']) . '">Available: ' . (int)$m_row['current_stock'] . ' pcs</option>';
                                        }
                                    } catch (PDOException $e) {}
                                    ?>
                                </datalist>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label text-muted small fw-bold text-uppercase">Quantity</label>
                                <input type="number" name="quantity_used" class="form-control rounded-3" value="1" min="1">
                            </div>
                        </div>

                        <div id="duplicateMedGuardBox" class="form-check bg-danger bg-opacity-10 border border-danger-subtle rounded-3 p-3 mb-3 d-none">
                            <input class="form-check-input ms-0 me-2" type="checkbox" name="confirm_duplicate_med" value="1" id="confirmDuplicateMed">
                            <label class="form-check-label small fw-semibold text-danger" for="confirmDuplicateMed" id="duplicateMedLabel">
                                Confirm duplicate same-day medication if clinically authorized.
                            </label>
                        </div>

                        <div class="row g-3">
                            <div class="col-md-7">
                                <label class="form-label text-muted small fw-bold text-uppercase">Action Taken / Notes</label>
                                <input type="text" name="action_taken" class="form-control rounded-3" placeholder="e.g., Given Paracetamol 500mg, advised rest in clinic">
                            </div>
                            <div class="col-md-5">
                                <label class="form-label text-muted small fw-bold text-uppercase">Disposition Status</label>
                                <select name="status" class="form-select rounded-3">
                                    <option value="Under Observation">Under Observation</option>
                                    <option value="Cleared">Cleared</option>
                                    <option value="Excused to Rest at Home">Excused to Rest at Home</option>
                                    <option value="Referred to Hospital / Clinic">Referred to Hospital / Clinic</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer border-0 bg-light rounded-bottom-4 p-3">
                        <button type="button" class="btn btn-secondary btn-sm px-4 rounded-pill" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary btn-sm px-4 rounded-pill fw-semibold" style="background: linear-gradient(135deg, #6f42c1 0%, #4e73df 100%); border: none;">Save Consultation</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php include '../../includes/footer.php'; ?>

    <script>
        const todayVisitsByStudent = <?php echo json_encode($today_visits_by_student); ?>;
        const studentHistoryMap = <?php echo json_encode($student_history_map); ?>;
        const dailyStudentCounts = <?php echo json_encode($daily_student_counts); ?>;

        function openStudentHistoryModal(studentId) {
            const profile = studentHistoryMap[String(studentId)];
            const titleEl = document.getElementById('historyStudentTitle');
            const metaEl = document.getElementById('historyStudentMeta');
            const bodyEl = document.getElementById('historyTimelineBody');

            if (!profile || !Array.isArray(profile.visits) || profile.visits.length === 0) {
                titleEl.textContent = 'Student Consultation Timeline';
                metaEl.textContent = 'Student ID: ' + studentId;
                bodyEl.innerHTML = '<div class="text-muted text-center py-4">No consultation history found for this student.</div>';
            } else {
                titleEl.innerHTML = '<i class="fa-solid fa-user-clock me-2"></i> ' + escapeHtml(profile.name);
                metaEl.textContent = 'ID: ' + profile.student_id + ' • ' + profile.grade_section + ' • ' + profile.visits.length + ' total visit(s)';

                bodyEl.innerHTML = profile.visits.map(v => {
                    const dayKey = profile.student_id + '|' + v.iso_date;
                    const sameDayCount = dailyStudentCounts[dayKey] || 1;
                    const revisitBadge = sameDayCount > 1
                        ? `<span class="badge bg-info bg-opacity-15 text-dark border border-info-subtle rounded-pill px-2 py-1 ms-2"><i class="fa-solid fa-rotate-right me-1 text-info"></i>Same-Day Revisit (${sameDayCount} visits on ${escapeHtml(v.date)})</span>`
                        : '';

                    return `
                        <div class="border rounded-4 p-3 mb-3 bg-light bg-opacity-50">
                            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                                <div>
                                    <strong class="text-dark">${escapeHtml(v.date)}</strong>
                                    <span class="text-muted small ms-1">${escapeHtml(v.time)}</span>
                                    ${revisitBadge}
                                </div>
                                <span class="badge bg-primary bg-opacity-10 text-primary rounded-pill px-3 py-1">${escapeHtml(v.status)}</span>
                            </div>
                            <div class="small mb-1"><strong>Category:</strong> ${escapeHtml(v.category)}</div>
                            <div class="small mb-1"><strong>Chief Complaint:</strong> ${escapeHtml(v.complaint)}</div>
                            <div class="small text-muted"><strong>Action Taken:</strong> ${escapeHtml(v.action || '—')}</div>
                        </div>
                    `;
                }).join('');
            }

            const modal = new bootstrap.Modal(document.getElementById('studentHistoryModal'));
            modal.show();
        }

        function checkSameDayRevisit() {
            const sidInput = document.getElementById('consultStudentInput');
            const medInput = document.getElementById('consultMedInput');
            const banner = document.getElementById('sameDayRevisitBanner');
            const details = document.getElementById('sameDayRevisitDetails');
            const dupBox = document.getElementById('duplicateMedGuardBox');
            const dupLabel = document.getElementById('duplicateMedLabel');

            const sid = (sidInput?.value || '').trim();
            const med = (medInput?.value || '').trim().toLowerCase();
            const visits = todayVisitsByStudent[sid] || [];

            if (!banner || !details) return;

            if (visits.length > 0) {
                banner.classList.remove('d-none');
                details.innerHTML = visits.map(v => {
                    const medInfo = v.medicine ? ` &bull; Med: <strong>${escapeHtml(v.medicine)}</strong>` : '';
                    return `<div class="mb-1"><strong>${escapeHtml(v.time)}</strong> (${escapeHtml(v.category)}): ${escapeHtml(v.complaint)}${medInfo} &bull; Status: <em>${escapeHtml(v.status)}</em></div>`;
                }).join('');
            } else {
                banner.classList.add('d-none');
                details.innerHTML = '';
            }

            if (dupBox && dupLabel && med !== '' && visits.length > 0) {
                const matchedVisit = visits.find(v =>
                    (v.medicine && v.medicine.toLowerCase().includes(med)) ||
                    (v.action && v.action.toLowerCase().includes(med))
                );
                if (matchedVisit) {
                    dupBox.classList.remove('d-none');
                    dupLabel.textContent = `Safety Guard: "${medInput.value.trim()}" was already given today at ${matchedVisit.time}. Check here only if a repeat same-day dose is clinically authorized.`;
                } else {
                    dupBox.classList.add('d-none');
                }
            } else if (dupBox) {
                dupBox.classList.add('d-none');
            }
        }

        function escapeHtml(str) {
            return String(str || '').replace(/[&<>"']/g, m => ({
                '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
            }[m]));
        }
    </script>
</body>
</html>