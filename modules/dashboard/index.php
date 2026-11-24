<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../index.php?error=Please log in first");
    exit();
}

// Prevent browser caching after logout so 'Back' button won't show the dashboard
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

require_once '../../includes/db_connect.php';
require_once '../../includes/holt_forecast.php';

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
    header("Location: ../../index.php?error=Unauthorized session role");
    exit();
}

/**
 * Automatically compute and update the daily consumption rate for an inventory item
 * based on recent consultation dispensing history for Holt's Trend model.
 */
function updateDashboardConsumptionRate($pdo, $item_id) {
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

// Handle Quick-Action Student Registration Form Submission (Admin / Nurse)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['quick_student'])) {
    $student_id = trim($_POST['new_student_id'] ?? '');
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $grade_section = trim($_POST['grade_section'] ?? '');
    $gender = trim($_POST['gender'] ?? 'Male');

    if (!empty($student_id) && !empty($first_name) && !empty($last_name)) {
        try {
            $stmt = $pdo->prepare("INSERT INTO students (student_id, first_name, last_name, grade_section, gender) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$student_id, $first_name, $last_name, $grade_section, $gender]);
            $success_msg = "Student profile registered successfully!";
        } catch (PDOException $e) {
            $error_msg = "Database error (Student ID might already exist): " . $e->getMessage();
        }
    } else {
        $error_msg = "Please fill in all required student fields.";
    }
}

// Handle Quick-Action Consultation Form Submission with FOR UPDATE Concurrency Lock, Same-Day Revisit & Audit Log
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['quick_consultation'])) {
    $student_id = trim($_POST['student_id'] ?? '');
    $chief_complaint = trim($_POST['chief_complaint'] ?? '');
    $symptom_category = trim($_POST['symptom_category'] ?? 'Other');
    $medication_item = trim($_POST['medication_item'] ?? '');
    $quantity_used = max(1, (int)($_POST['quantity_used'] ?? 1));
    $status = trim($_POST['status'] ?? 'Under Observation');
    $action_taken = trim($_POST['action_taken'] ?? '');
    $confirm_duplicate_med = isset($_POST['confirm_duplicate_med']) && $_POST['confirm_duplicate_med'] === '1';

    if (!empty($student_id) && !empty($chief_complaint)) {
        try {
            $prior_stmt = $pdo->prepare("
                SELECT consultation_id, consultation_date, chief_complaint, action_taken, inventory_item_id, status
                FROM consultations
                WHERE student_id = ? AND DATE(consultation_date) = CURDATE()
                ORDER BY consultation_date DESC
            ");
            $prior_stmt->execute([$student_id]);
            $same_day_prior = $prior_stmt->fetchAll(PDO::FETCH_ASSOC);
            $is_same_day_revisit = count($same_day_prior) > 0;

            if (!empty($medication_item) && $is_same_day_revisit && !$confirm_duplicate_med) {
                $item_lookup = $pdo->prepare("SELECT item_id FROM inventory WHERE item_name = ? LIMIT 1");
                $item_lookup->execute([$medication_item]);
                $target_item_id = $item_lookup->fetchColumn();

                foreach ($same_day_prior as $prev_c) {
                    $matched_by_id = ($target_item_id && (int)$prev_c['inventory_item_id'] === (int)$target_item_id);
                    $matched_by_text = (stripos((string)$prev_c['action_taken'], $medication_item) !== false);
                    if ($matched_by_id || $matched_by_text) {
                        $last_med_time = date('h:i A', strtotime($prev_c['consultation_date']));
                        throw new Exception("Duplicate Same-Day Medication Guard: '{$medication_item}' was already recorded today at {$last_med_time}. Confirm duplicate medication if clinically authorized.");
                    }
                }
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
                $remarks = "Quick Consultation (Dashboard) for Student ID: {$student_id}{$revisit_prefix} | Complaint: {$chief_complaint}";
                $audit_stmt->execute([
                    $dispensed_item_id,
                    $locked_item['item_name'],
                    $logged_qty,
                    $previous_stock,
                    $new_stock,
                    $remarks,
                    $performed_by
                ]);

                updateDashboardConsumptionRate($pdo, $dispensed_item_id);
            }

            $pdo->commit();
            $success_msg = $is_same_day_revisit
                ? "Same-day revisit consultation logged and linked to today's earlier visit!"
                : "Consultation logged and inventory updated safely!";
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error_msg = $e->getMessage();
        }
    } else {
        $error_msg = "Please fill in all required consultation fields.";
    }
}

// Preload today's consultations by student_id for live Same-Day Revisit Alert in quick modal
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

// Compute Holt's Linear Trend forecasts for all inventory items
$all_forecasts = getAllHoltInventoryForecasts($pdo);
$dashboard_forecasts = array_slice($all_forecasts, 0, 2);

// 1. Fetch Dynamic KPI Metrics & DISTINCT-Student Event-Aware EARS Alert Count
$visits_today = 0;
$unique_students_today = 0;
$revisits_today = 0;
$stock_outs = 0;
$active_ears_count = 0;
$event_linked_count = 0;

try {
    $v_stmt = $pdo->query("SELECT COUNT(*) FROM consultations WHERE DATE(consultation_date) = CURDATE()");
    $visits_today = (int)$v_stmt->fetchColumn();

    $u_td_stmt = $pdo->query("SELECT COUNT(DISTINCT student_id) FROM consultations WHERE DATE(consultation_date) = CURDATE()");
    $unique_students_today = (int)$u_td_stmt->fetchColumn();
    $revisits_today = max(0, $visits_today - $unique_students_today);

    foreach ($all_forecasts as $fc) {
        if ($fc['is_watchlist']) {
            $stock_outs++;
        }
    }

    $recent_events = [];
    try {
        $ev_q = $pdo->query("SELECT event_date FROM school_events WHERE event_date >= DATE_SUB(CURDATE(), INTERVAL 8 DAY)");
        $recent_events = $ev_q->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) {
        $recent_events = [];
    }

    $alert_q = $pdo->query("
        SELECT symptom_category, COUNT(DISTINCT student_id) as unique_students
        FROM consultations
        WHERE consultation_date >= NOW() - INTERVAL 7 DAY
        GROUP BY symptom_category
        HAVING unique_students >= 3
    ");
    foreach ($alert_q->fetchAll(PDO::FETCH_ASSOC) as $a_row) {
        if ($a_row['symptom_category'] === 'Fever' && !empty($recent_events)) {
            $event_linked_count++;
        } else {
            $active_ears_count++;
        }
    }
} catch (PDOException $e) {
    // Handle error silently if tables are empty
}

// 2. Fetch Real Dynamic EARS Fever Data (Unique Students per Day) for Past 7 Days + Today
$ears_data = [];
$labels = [];

try {
    for ($i = 6; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-{$i} days"));
        $labels[] = date('D', strtotime($date)); 

        $stmt = $pdo->prepare("SELECT COUNT(DISTINCT student_id) FROM consultations WHERE symptom_category = 'Fever' AND DATE(consultation_date) = ?");
        $stmt->execute([$date]);
        $ears_data[] = (int)$stmt->fetchColumn();
    }
    $labels[] = 'Today';
    $stmt = $pdo->prepare("SELECT COUNT(DISTINCT student_id) FROM consultations WHERE symptom_category = 'Fever' AND DATE(consultation_date) = CURDATE()");
    $stmt->execute();
    $ears_data[] = (int)$stmt->fetchColumn();
} catch (PDOException $e) {
    $ears_data = [0, 0, 0, 0, 0, 0, 0, 0];
    $labels = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun', 'Today'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MedFlow Dashboard</title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <style>
        body { background-color: #f4f5fa; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; overflow-x: hidden; }
        .sidebar { width: 250px; height: 100vh; background-color: #1a252f; color: #ecf0f1; position: fixed; padding-top: 20px; top: 0; left: 0; z-index: 1000; }
        .sidebar .brand { font-size: 1.5rem; font-weight: bold; text-align: center; margin-bottom: 30px; color: #3498db; }
        .sidebar a { padding: 15px 25px; text-decoration: none; font-size: 1.1rem; color: #bdc3c7; display: block; transition: 0.3s; }
        .sidebar a:hover, .sidebar a.active { color: #ffffff; background-color: #2c3e50; border-left: 4px solid #3498db; }
        .sidebar a i { margin-right: 10px; width: 20px; text-align: center; }
        
        .main-content { margin-left: 250px; padding: 40px 45px; width: calc(100% - 250px); min-height: 100vh; position: relative; z-index: 1; }
        .top-navbar { position: relative; z-index: 1050; }
        
        .card { border: none; border-radius: 20px; box-shadow: 0 8px 25px rgba(111, 66, 193, 0.06); margin-bottom: 24px; transition: transform 0.2s ease, box-shadow 0.2s ease; background-color: #ffffff; }
        .clickable-card:hover { transform: translateY(-3px); box-shadow: 0 12px 30px rgba(111, 66, 193, 0.12); cursor: pointer; }
        
        .card-title { font-size: 0.78rem; font-weight: 700; color: #6c757d; text-transform: uppercase; letter-spacing: 0.8px; }
        .kpi-sub { font-size: 0.78rem; color: #718096; margin-top: 6px; display: block; }
        .icon-box { width: 52px; height: 52px; border-radius: 15px; display: flex; align-items: center; justify-content: center; font-size: 1.35rem; flex-shrink: 0; }
        
        .bg-light-primary { background-color: #f3f0ff; color: #6f42c1; }
        .bg-light-danger { background-color: #fff5f5; color: #e53e3e; }
        .bg-light-warning { background-color: #fffaf0; color: #dd6b20; }
        
        .chart-container { position: relative; height: 280px; width: 100%; }
        .progress { height: 9px; border-radius: 999px; background-color: #edf2f7; }

        .custom-legend {
            display: flex;
            justify-content: center;
            gap: 24px;
            margin-bottom: 14px;
            cursor: pointer;
            font-size: 0.88rem;
            font-weight: 600;
            color: #4a5568;
            user-select: none;
        }
        .custom-legend-item {
            display: flex;
            align-items: center;
            gap: 8px;
            transition: opacity 0.2s ease;
        }
        .custom-legend-item.disabled {
            opacity: 0.4;
        }
        .custom-legend-dot {
            width: 11px;
            height: 11px;
            border-radius: 50%;
            display: inline-block;
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
            font-size: 0.74rem;
            letter-spacing: 0.9px;
            border-bottom: 2px solid #eaedf2;
            padding: 13px 18px;
        }
        .table tbody tr {
            background-color: #ffffff;
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.02);
        }
        .table tbody td {
            padding: 18px;
            color: #2d3748;
            vertical-align: middle;
            font-size: 0.93rem;
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
    </style>
</head>
<body>

    <?php include '../../includes/sidebar.php'; ?>

    <div class="main-content">
        <?php include '../../includes/topbar.php'; ?>

        <?php if (!empty($success_msg)): ?>
            <div class="alert alert-success alert-dismissible fade show border-0 shadow-sm rounded-4 p-3 mb-4 mt-3" role="alert" style="background-color: #f0fff4; color: #276749;">
                <i class="fa-solid fa-circle-check me-2"></i> <?php echo htmlspecialchars($success_msg); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <?php if (!empty($error_msg)): ?>
            <div class="alert alert-danger alert-dismissible fade show border-0 shadow-sm rounded-4 p-3 mb-4 mt-3" role="alert" style="background-color: #fff5f5; color: #c53030;">
                <i class="fa-solid fa-circle-exclamation me-2"></i> <?php echo htmlspecialchars($error_msg); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <!-- Header & Quick Actions -->
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 mt-3 gap-3">
            <div>
                <h4 class="fw-bold text-dark mb-1">Clinic Command Dashboard</h4>
                <p class="text-muted small mb-0">Same-day triage, event-aware CDC EARS surveillance, Holt stock forecasting, and clinic reports.</p>
            </div>
            
            <div class="d-flex flex-wrap align-items-center gap-2">
                <a href="../inventory/index.php" class="btn btn-white bg-white border text-dark px-3 py-2 rounded-pill fw-semibold small shadow-sm text-nowrap">
                    <i class="fa-solid fa-clipboard-list me-1 text-secondary"></i> Audit Trail
                </a>
                <a href="../analytics/index.php" class="btn btn-white bg-white border text-dark px-3 py-2 rounded-pill fw-semibold small shadow-sm text-nowrap">
                    <i class="fa-solid fa-file-lines me-1 text-primary"></i> Reports
                </a>
                <button type="button" class="btn btn-outline-primary px-3 py-2 rounded-pill fw-semibold small shadow-sm text-nowrap" data-bs-toggle="modal" data-bs-target="#newStudentModal">
                    <i class="fa-solid fa-user-plus me-1"></i> New Student
                </button>
                <button type="button" class="btn btn-primary px-4 py-2 rounded-pill fw-semibold small shadow-sm text-nowrap" data-bs-toggle="modal" data-bs-target="#newConsultationModal" style="background: linear-gradient(135deg, #6f42c1 0%, #4e73df 100%); border: none;">
                    <i class="fa-solid fa-plus me-1"></i> New Consultation
                </button>
            </div>
        </div>

        <!-- Status Banner -->
        <?php if ($active_ears_count > 0 || $stock_outs > 0): ?>
            <div class="alert alert-danger d-flex flex-wrap justify-content-between align-items-center border-0 shadow-sm rounded-4 p-3 px-4 mb-4 gap-2" role="alert" style="background-color: #fff5f5; color: #c53030;">
                <div class="d-flex align-items-center">
                    <i class="fa-solid fa-triangle-exclamation fs-4 me-3"></i>
                    <div>
                        <span class="fw-bold">Clinic Watch:</span>
                        <span>
                            <?php echo $active_ears_count; ?> active EARS alert<?php echo $active_ears_count === 1 ? '' : 's'; ?>
                            <?php if ($event_linked_count > 0): ?> (<?php echo $event_linked_count; ?> event-correlated)<?php endif; ?>
                            &bull; <strong><?php echo $stock_outs; ?></strong> watchlist stock item<?php echo $stock_outs === 1 ? '' : 's'; ?>.
                        </span>
                    </div>
                </div>
                <a href="../analytics/index.php" class="btn btn-sm btn-danger rounded-pill px-3 fw-semibold text-nowrap">Inspect Alerts</a>
            </div>
        <?php else: ?>
            <div class="alert alert-success d-flex flex-wrap justify-content-between align-items-center border-0 shadow-sm rounded-4 p-3 px-4 mb-4 gap-2" role="alert" style="background-color: #f0fff4; color: #276749;">
                <div class="d-flex align-items-center">
                    <i class="fa-solid fa-circle-check fs-4 me-3"></i>
                    <div>
                        <span class="fw-bold">All Systems Normal:</span>
                        <span class="small">No active EARS outbreak aberrations and no items on the stock-out watchlist.</span>
                    </div>
                </div>
                <a href="../analytics/index.php" class="btn btn-sm btn-outline-success rounded-pill px-3 fw-semibold text-nowrap">View Analytics</a>
            </div>
        <?php endif; ?>

        <!-- Top KPI Row -->
        <div class="row g-4">
            <div class="col-md-4">
                <a href="../consultations/index.php" class="text-decoration-none">
                    <div class="card p-4 clickable-card h-100">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <h6 class="card-title mb-2">Visits Today</h6>
                                <h2 class="mb-0 fw-bold text-dark display-6"><?php echo $visits_today; ?></h2>
                                <span class="kpi-sub"><?php echo $unique_students_today; ?> unique student<?php echo $unique_students_today === 1 ? '' : 's'; ?> &bull; <?php echo $revisits_today; ?> revisit<?php echo $revisits_today === 1 ? '' : 's'; ?></span>
                            </div>
                            <div class="icon-box bg-light-primary"><i class="fa-solid fa-stethoscope"></i></div>
                        </div>
                    </div>
                </a>
            </div>
            <div class="col-md-4">
                <a href="../analytics/index.php" class="text-decoration-none">
                    <div class="card p-4 clickable-card h-100">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <h6 class="card-title mb-2">Active EARS Alerts</h6>
                                <h2 class="mb-0 fw-bold text-danger display-6"><?php echo $active_ears_count; ?></h2>
                                <span class="kpi-sub">Distinct students &bull; <?php echo $event_linked_count; ?> event-linked</span>
                            </div>
                            <div class="icon-box bg-light-danger"><i class="fa-solid fa-virus"></i></div>
                        </div>
                    </div>
                </a>
            </div>
            <div class="col-md-4">
                <a href="../inventory/index.php" class="text-decoration-none">
                    <div class="card p-4 clickable-card h-100">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <h6 class="card-title mb-2">Impending Stock-outs</h6>
                                <h2 class="mb-0 fw-bold text-warning display-6"><?php echo $stock_outs; ?></h2>
                                <span class="kpi-sub">Holt 7d horizon &amp; reorder watchlist</span>
                            </div>
                            <div class="icon-box bg-light-warning"><i class="fa-solid fa-pills"></i></div>
                        </div>
                    </div>
                </a>
            </div>
        </div>

        <!-- Chart & Holt Forecast Row -->
        <div class="row g-4 mt-1">
            <div class="col-lg-8">
                <div class="card p-4 h-100">
                    <div class="d-flex flex-wrap justify-content-between align-items-center mb-2 gap-2">
                        <div>
                            <h5 class="fw-bold mb-1 text-dark">Outbreak Surveillance (CDC EARS)</h5>
                            <p class="text-muted small mb-0">Unique daily Fever students vs. rolling baseline.</p>
                        </div>
                        <span class="badge bg-light text-dark border rounded-pill px-3 py-2 small">7-Day + Today</span>
                    </div>
                    <hr class="text-muted opacity-25 my-3">
                    
                    <div class="custom-legend" id="customEarsLegend">
                        <div class="custom-legend-item" onclick="toggleMainDataset(0)">
                            <span class="custom-legend-dot" style="background-color: rgba(111, 66, 193, 0.5);"></span>
                            <span>Expected Baseline</span>
                        </div>
                        <div class="custom-legend-item" onclick="toggleMainDataset(1)">
                            <span class="custom-legend-dot" style="background-color: #6f42c1;"></span>
                            <span>Unique Fever Cases</span>
                        </div>
                    </div>

                    <div class="chart-container">
                        <canvas id="earsChart"></canvas>
                    </div>
                </div>
            </div>
            <div class="col-lg-4">
                <div class="card p-4 h-100 d-flex flex-column justify-content-between">
                    <div>
                        <h5 class="fw-bold mb-1 text-dark">Holt Linear Trend Forecast</h5>
                        <p class="text-muted small mb-3">Level ($L_t$) + Trend ($T_t$) 7-day projection</p>
                        <hr class="text-muted opacity-25 my-3">
                        
                        <?php foreach ($dashboard_forecasts as $fc):
                            $stock = (int)$fc['current_stock'];
                            $reorder = (int)$fc['reorder_level'];
                            $level = (float)$fc['level'];
                            $trend = (float)$fc['trend'];
                            $f7_demand = (float)$fc['forecast_7d_demand'];
                            $proj_7d = (int)$fc['projected_stock_7d'];
                            $days_left = $fc['days_until_depletion'];
                            $has_usage = (bool)$fc['has_usage'];
                            $status_label = $fc['stock_status'];

                            if (!$has_usage) {
                                $horizon_text = "No Recent Dispensing";
                                $horizon_color = "text-secondary";
                            } elseif ($days_left !== null) {
                                $horizon_text = "~{$days_left}d to Deplete";
                                $horizon_color = ($days_left <= 7) ? "text-danger" : "text-primary";
                            } else {
                                $horizon_text = "Proj. 7d Left: {$proj_7d} pcs";
                                $horizon_color = "text-primary";
                            }

                            if ($stock <= 0) {
                                $bar_color = "bg-dark";
                            } elseif ($stock <= $reorder) {
                                $bar_color = "bg-danger";
                            } else {
                                $bar_color = "bg-success";
                            }

                            $max_capacity = $reorder > 0 ? $reorder * 3 : 50; 
                            $width_pct = min(100, max(5, ($stock / $max_capacity) * 100));
                            $trend_sign = $trend > 0 ? '+' : '';
                        ?>
                        <div class="mb-3 pb-3 border-bottom border-light">
                            <div class="d-flex justify-content-between align-items-start gap-2 mb-1">
                                <span class="fw-semibold text-dark"><?php echo htmlspecialchars($fc['item_name']); ?></span>
                                <span class="<?php echo $horizon_color; ?> fw-bold small text-nowrap">
                                    <i class="fa-solid <?php echo $has_usage ? 'fa-chart-line' : 'fa-minus-circle'; ?> me-1"></i><?php echo htmlspecialchars($horizon_text); ?>
                                </span>
                            </div>
                            <div class="progress mb-2">
                                <div class="progress-bar <?php echo $bar_color; ?>" role="progressbar" style="width: <?php echo $width_pct; ?>%;"></div>
                            </div>
                            <div class="d-flex justify-content-between align-items-center small">
                                <span class="text-muted">Now: <strong><?php echo $stock; ?></strong> pcs &bull; <strong><?php echo htmlspecialchars($status_label); ?></strong></span>
                                <span class="text-muted">7d Need: <strong><?php echo $f7_demand; ?></strong> pcs</span>
                            </div>
                            <div class="text-muted mt-1" style="font-size: 0.74rem;">
                                Level: <?php echo number_format($level, 2); ?>/d &bull; Trend: <?php echo $trend_sign . number_format($trend, 2); ?>/d (<?php echo htmlspecialchars($fc['trend_direction']); ?>)
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <a href="../analytics/index.php" class="btn btn-outline-primary w-100 mt-2 py-2 rounded-pill fw-semibold small">Full Holt Forecast &amp; Watchlist</a>
                </div>
            </div>
        </div>

        <!-- Recent Consultations Table -->
        <div class="row mt-2">
            <div class="col-12">
                <div class="card p-4">
                    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
                        <h5 class="fw-bold mb-0 text-dark"><i class="fa-solid fa-clock-rotate-left me-2" style="color: #6f42c1;"></i> Recent Consultations</h5>
                        <div class="d-flex align-items-center gap-2">
                            <input type="text" id="tableSearchInput" class="form-control form-control-sm rounded-pill px-3" style="width: 240px;" placeholder="Filter recent visits...">
                            <a href="../consultations/index.php" class="btn btn-sm btn-light border rounded-pill px-3 fw-semibold">View All</a>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="table align-middle mb-0" id="consultationsTable">
                            <thead>
                                <tr>
                                    <th class="ps-3">Time</th>
                                    <th>Student Name</th>
                                    <th>Grade / Section</th>
                                    <th>Chief Complaint</th>
                                    <th>Action Taken</th>
                                    <th class="text-end pe-3">Status Progression</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                try {
                                    $stmt = $pdo->query("
                                        SELECT c.student_id, c.consultation_date, s.first_name, s.last_name, s.grade_section, c.chief_complaint, c.action_taken, c.status 
                                        FROM consultations c
                                        JOIN students s ON c.student_id = s.student_id
                                        ORDER BY c.consultation_date DESC 
                                        LIMIT 5
                                    ");
                                    
                                    if ($stmt->rowCount() > 0) {
                                        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                                            $time = date('h:i A', strtotime($row['consultation_date']));
                                            $currentStatus = $row['status'];
                                            $sid = $row['student_id'];
                                            $isRevisit = isset($today_visits_by_student[$sid]) && count($today_visits_by_student[$sid]) > 1 && date('Y-m-d', strtotime($row['consultation_date'])) === date('Y-m-d');

                                            if ($currentStatus == 'Cleared') {
                                                $startText = 'Under Observation';
                                                $endBadge = '<span class="badge bg-success bg-opacity-10 text-success border border-success-subtle px-3 py-2 rounded-pill fw-semibold">Cleared</span>';
                                            } elseif ($currentStatus == 'Excused to Rest at Home') {
                                                $startText = 'Under Observation';
                                                $endBadge = '<span class="badge bg-danger bg-opacity-10 text-danger border border-danger-subtle px-3 py-2 rounded-pill fw-semibold">Excused to Rest at Home</span>';
                                            } elseif ($currentStatus == 'Referred to Hospital / Clinic') {
                                                $startText = 'Under Observation';
                                                $endBadge = '<span class="badge bg-primary bg-opacity-10 text-primary border border-primary-subtle px-3 py-2 rounded-pill fw-semibold">Referred to Hospital</span>';
                                            } else {
                                                $startText = 'Logged';
                                                $endBadge = '<span class="badge bg-warning bg-opacity-10 text-warning border border-warning-subtle px-3 py-2 rounded-pill fw-semibold">Under Observation</span>';
                                            }

                                            echo "<tr>";
                                            echo "<td class='ps-3 fw-medium text-muted text-nowrap'>{$time}</td>";
                                            echo "<td><strong>" . htmlspecialchars($row['first_name'] . ' ' . $row['last_name']) . "</strong>";
                                            if ($isRevisit) {
                                                echo "<div><span class='badge bg-info bg-opacity-15 text-dark border border-info-subtle rounded-pill px-2 py-1 mt-1' style='font-size:0.7rem;'><i class='fa-solid fa-rotate-right me-1 text-info'></i>Same-Day Revisit</span></div>";
                                            }
                                            echo "</td>";
                                            echo "<td><span class='badge bg-info bg-opacity-10 text-info px-3 py-2 rounded-pill'>" . htmlspecialchars($row['grade_section']) . "</span></td>";
                                            echo "<td>" . htmlspecialchars($row['chief_complaint']) . "</td>";
                                            echo "<td>" . htmlspecialchars($row['action_taken']) . "</td>";
                                            echo "<td class='text-end pe-3 text-nowrap'>";
                                            echo "  <span class='text-muted small fw-medium'>" . $startText . "</span>";
                                            echo "  <i class='fa-solid fa-arrow-right mx-2 text-muted opacity-50 small'></i>";
                                            echo "  " . $endBadge;
                                            echo "</td>";
                                            echo "</tr>";
                                        }
                                    } else {
                                        echo "<tr><td colspan='6' class='text-center text-muted py-5'><i class='fa-solid fa-notes-medical fa-3x mb-3 text-secondary opacity-25'></i><p class='mb-0 fw-medium'>No recent consultations found.</p></td></tr>";
                                    }
                                } catch (PDOException $e) {
                                    echo "<tr><td colspan='6' class='text-center text-danger py-4'>Error loading data.</td></tr>";
                                }
                                ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Quick-Action New Student Modal -->
    <div class="modal fade" id="newStudentModal" tabindex="-1" aria-labelledby="newStudentModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 rounded-4 shadow-lg">
                <form method="POST">
                    <div class="modal-header border-0 pb-0">
                        <h5 class="modal-title fw-bold text-dark" id="newStudentModalLabel"><i class="fa-solid fa-user-plus text-primary me-2"></i> Register New Student</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body py-4">
                        <input type="hidden" name="quick_student" value="1">
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-muted">Student ID / Number</label>
                            <input type="text" name="new_student_id" class="form-control rounded-3" placeholder="e.g., 2026-10045" required>
                        </div>
                        <div class="row g-2 mb-3">
                            <div class="col-md-6">
                                <label class="form-label small fw-bold text-muted">First Name</label>
                                <input type="text" name="first_name" class="form-control rounded-3" placeholder="First name" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold text-muted">Last Name</label>
                                <input type="text" name="last_name" class="form-control rounded-3" placeholder="Last name" required>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-muted">Grade &amp; Section</label>
                            <input type="text" name="grade_section" class="form-control rounded-3" placeholder="e.g., BSIT 301A" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-muted">Gender</label>
                            <select name="gender" class="form-select rounded-3">
                                <option value="Male">Male</option>
                                <option value="Female">Female</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer border-0 pt-0">
                        <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary rounded-pill px-4 shadow-sm">Register Student</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Quick-Action New Consultation Modal -->
    <div class="modal fade" id="newConsultationModal" tabindex="-1" aria-labelledby="newConsultationModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 rounded-4 shadow-lg">
                <form method="POST">
                    <div class="modal-header border-0 pb-0">
                        <h5 class="modal-title fw-bold text-dark" id="newConsultationModalLabel">
                            <i class="fa-solid fa-stethoscope me-2" style="color:#6f42c1;"></i> Log New Clinic Visit
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body py-4">
                        <input type="hidden" name="quick_consultation" value="1">

                        <div id="dashSameDayBanner" class="alert alert-warning border-0 rounded-3 d-none mb-3" style="background-color: #fffaf0; color: #744210;">
                            <div class="fw-bold small mb-1"><i class="fa-solid fa-clock-rotate-left me-1"></i> Same-Day Revisit Detected Today</div>
                            <div id="dashSameDayDetails" class="small"></div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-muted">SEARCH &amp; SELECT STUDENT</label>
                            <input class="form-control rounded-3" list="student_list" id="dashStudentInput" name="student_id" placeholder="Type student name or ID..." required oninput="checkDashSameDayRevisit()">
                            <datalist id="student_list">
                                <?php
                                try {
                                    $students_query = $pdo->query("SELECT student_id, first_name, last_name, grade_section FROM students ORDER BY last_name ASC");
                                    while ($s = $students_query->fetch(PDO::FETCH_ASSOC)) {
                                        echo '<option value="' . htmlspecialchars($s['student_id']) . '">' . htmlspecialchars($s['last_name'] . ', ' . $s['first_name'] . ' (' . $s['grade_section'] . ')') . '</option>';
                                    }
                                } catch (Exception $e) {}
                                ?>
                            </datalist>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-muted">SYMPTOM CATEGORY (FOR EARS SURVEILLANCE)</label>
                            <select name="symptom_category" class="form-select rounded-3" required>
                                <option value="Fever">Fever (Triggers EARS)</option>
                                <option value="Cough & Cold">Cough &amp; Cold</option>
                                <option value="Headache / Dizziness">Headache / Dizziness</option>
                                <option value="Stomachache">Stomachache / Acid Reflux</option>
                                <option value="Injury / Wound">Injury / Wound</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-muted">CHIEF COMPLAINT / SYMPTOMS</label>
                            <textarea name="chief_complaint" class="form-control rounded-3" rows="2" placeholder="e.g., Fever (38.5°C) and body malaise" required></textarea>
                        </div>
                        
                        <div class="row g-2 mb-3">
                            <div class="col-md-8">
                                <label class="form-label small fw-bold text-muted">DISPENSE MEDICINE / SUPPLY (OPTIONAL)</label>
                                <input class="form-control rounded-3" list="medicine_list" id="dashMedInput" name="medication_item" placeholder="Type medicine name..." oninput="checkDashSameDayRevisit()">
                                <datalist id="medicine_list">
                                    <?php
                                    try {
                                        $meds = $pdo->query("SELECT item_name, current_stock FROM inventory WHERE current_stock > 0 ORDER BY item_name ASC");
                                        while ($m = $meds->fetch(PDO::FETCH_ASSOC)) {
                                            echo '<option value="' . htmlspecialchars($m['item_name']) . '">Stock: ' . (int)$m['current_stock'] . ' pcs</option>';
                                        }
                                    } catch (Exception $e) {}
                                    ?>
                                </datalist>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small fw-bold text-muted">QUANTITY</label>
                                <input type="number" name="quantity_used" class="form-control rounded-3" value="1" min="1">
                            </div>
                        </div>

                        <div id="dashDupMedBox" class="form-check bg-danger bg-opacity-10 border border-danger-subtle rounded-3 p-2 mb-3 d-none">
                            <input class="form-check-input ms-0 me-2" type="checkbox" name="confirm_duplicate_med" value="1" id="dashConfirmDupMed">
                            <label class="form-check-label small fw-semibold text-danger" for="dashConfirmDupMed" id="dashDupMedLabel">
                                Confirm duplicate same-day medication if clinically authorized.
                            </label>
                        </div>

                        <div class="mb-3">
                            <label class="form-label small fw-bold text-muted">ACTION TAKEN / TREATMENT GIVEN</label>
                            <input type="text" name="action_taken" class="form-control rounded-3" placeholder="e.g., Given Paracetamol 500mg, advised rest">
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-muted">STATUS</label>
                            <select name="status" class="form-select rounded-3">
                                <option value="Under Observation">Under Observation</option>
                                <option value="Cleared">Cleared (Back to Class)</option>
                                <option value="Excused to Rest at Home">Excused to Rest at Home</option>
                                <option value="Referred to Hospital / Clinic">Referred to Hospital / Clinic</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer border-0 pt-0">
                        <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary rounded-pill px-4 shadow-sm" style="background-color: #6f42c1; border:none;">Save &amp; Deduct Stock</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php include '../../includes/footer.php'; ?>

    <script>
        const dashTodayVisits = <?php echo json_encode($today_visits_by_student); ?>;

        function checkDashSameDayRevisit() {
            const sid = (document.getElementById('dashStudentInput')?.value || '').trim();
            const med = (document.getElementById('dashMedInput')?.value || '').trim().toLowerCase();
            const banner = document.getElementById('dashSameDayBanner');
            const details = document.getElementById('dashSameDayDetails');
            const dupBox = document.getElementById('dashDupMedBox');
            const dupLabel = document.getElementById('dashDupMedLabel');
            const visits = dashTodayVisits[sid] || [];

            if (banner && details) {
                if (visits.length > 0) {
                    banner.classList.remove('d-none');
                    details.innerHTML = visits.map(v => {
                        const medInfo = v.medicine ? ` &bull; Med: <strong>${escapeDashHtml(v.medicine)}</strong>` : '';
                        return `<div><strong>${escapeDashHtml(v.time)}</strong>: ${escapeDashHtml(v.complaint)}${medInfo} (${escapeDashHtml(v.status)})</div>`;
                    }).join('');
                } else {
                    banner.classList.add('d-none');
                    details.innerHTML = '';
                }
            }

            if (dupBox && dupLabel && med !== '' && visits.length > 0) {
                const matchedVisit = visits.find(v =>
                    (v.medicine && v.medicine.toLowerCase().includes(med)) ||
                    (v.action && v.action.toLowerCase().includes(med))
                );
                if (matchedVisit) {
                    dupBox.classList.remove('d-none');
                    dupLabel.textContent = `Safety Guard: "${document.getElementById('dashMedInput').value.trim()}" was already given today at ${matchedVisit.time}. Confirm only if clinically authorized.`;
                } else {
                    dupBox.classList.add('d-none');
                }
            } else if (dupBox) {
                dupBox.classList.add('d-none');
            }
        }

        function escapeDashHtml(str) {
            return String(str || '').replace(/[&<>"']/g, m => ({
                '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
            }[m]));
        }

        document.getElementById('tableSearchInput')?.addEventListener('keyup', function() {
            let filter = this.value.toLowerCase();
            let rows = document.querySelectorAll('#consultationsTable tbody tr');
            rows.forEach(row => {
                let text = row.textContent.toLowerCase();
                row.style.display = text.includes(filter) ? '' : 'none';
            });
        });

        const canvas = document.getElementById('earsChart');
        if (canvas) {
            const ctx = canvas.getContext('2d');
            
            const chartLabels = <?php echo json_encode($labels); ?>;
            const actualFeverCases = <?php echo json_encode($ears_data); ?>;
            const baselineData = actualFeverCases.map(val => Math.max(1, val > 2 ? val / 2 : val));

            const earsChart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: chartLabels,
                    datasets: [
                        { label: 'Expected Baseline', data: baselineData, borderColor: 'rgba(111, 66, 193, 0.3)', backgroundColor: 'rgba(111, 66, 193, 0.05)', borderWidth: 2, fill: true, pointRadius: 0, tension: 0.4 },
                        { label: 'Unique Fever Cases', data: actualFeverCases, borderColor: '#6f42c1', backgroundColor: 'rgba(111, 66, 193, 0.1)', borderWidth: 3, pointBackgroundColor: actualFeverCases.map(v => v >= 3 ? '#e53e3e' : '#6f42c1'), pointBorderColor: '#fff', pointRadius: 5, fill: false, tension: 0.4 }
                    ]
                },
                options: { 
                    responsive: true, 
                    maintainAspectRatio: false, 
                    plugins: { 
                        legend: { display: false } 
                    }, 
                    scales: { 
                        y: { beginAtZero: true, grid: { borderDash: [5, 5], color: '#e9ecef' } }, 
                        x: { grid: { display: false } } 
                    } 
                }
            });

            window.toggleMainDataset = function(index) {
                const meta = earsChart.getDatasetMeta(index);
                meta.hidden = meta.hidden === null ? !earsChart.data.datasets[index].hidden : null;
                earsChart.update();

                const items = document.querySelectorAll('#customEarsLegend .custom-legend-item');
                const dot = items[index].querySelector('.custom-legend-dot');
                if (meta.hidden) {
                    items[index].classList.add('disabled');
                    dot.style.backgroundColor = 'transparent';
                    dot.style.border = '2px solid #adb5bd';
                } else {
                    items[index].classList.remove('disabled');
                    dot.style.backgroundColor = index === 0 ? 'rgba(111, 66, 193, 0.5)' : '#6f42c1';
                    dot.style.border = 'none';
                }
            };
        }
    </script>
</body>
</html>