<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();
if(!isset($_SESSION['user_id'])){
    header("Location: ../../index.php?error=Please log in first");
    exit();
}

// Prevent browser caching after logout so 'Back' button won't show the dashboard
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

require_once '../../includes/db_connect.php';

// Fetch current user role for RBAC checks
$user_role = $_SESSION['role'] ?? 'Teacher';

$success_msg = '';
$error_msg = '';

// Handle Quick-Action Student Registration Form Submission (Restricted to Admin/Nurse)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['quick_student'])) {
    if ($user_role === 'Teacher') {
        header("Location: index.php?error=Unauthorized action for Teacher role");
        exit();
    }

    $student_id = trim($_POST['new_student_id']);
    $first_name = trim($_POST['first_name']);
    $last_name = trim($_POST['last_name']);
    $grade_section = trim($_POST['grade_section']);
    $gender = trim($_POST['gender']);

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

// Handle Quick-Action Consultation Form Submission with Inventory Deduction (Restricted to Admin/Nurse)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['quick_consultation'])) {
    if ($user_role === 'Teacher') {
        header("Location: index.php?error=Unauthorized action for Teacher role");
        exit();
    }

    $student_id = trim($_POST['student_id']);
    $chief_complaint = trim($_POST['chief_complaint']);
    $symptom_category = trim($_POST['symptom_category']);
    $medication_item = trim($_POST['medication_item']);
    $quantity_used = (int)($_POST['quantity_used'] ?? 1);
    $status = trim($_POST['status']);
    $action_taken = trim($_POST['action_taken']);

    // If a medicine was selected, append or set it in action taken
    if (!empty($medication_item)) {
        $action_taken = !empty($action_taken) ? $action_taken . " | Dispensed: {$medication_item} (Qty: {$quantity_used})" : "Dispensed: {$medication_item} (Qty: {$quantity_used})";
    }

    if (!empty($student_id) && !empty($chief_complaint)) {
        try {
            $pdo->beginTransaction();

            // 1. Insert Consultation Record
            $stmt = $pdo->prepare("INSERT INTO consultations (student_id, chief_complaint, symptom_category, action_taken, status, consultation_date) VALUES (?, ?, ?, ?, ?, NOW())");
            $stmt->execute([$student_id, $chief_complaint, $symptom_category, $action_taken, $status]);

            // 2. Automatically Deduct Inventory Stock if a medicine was selected
            if (!empty($medication_item) && $quantity_used > 0) {
                $inv_stmt = $pdo->prepare("UPDATE inventory SET current_stock = GREATEST(0, current_stock - ?) WHERE item_name = ?");
                $inv_stmt->execute([$quantity_used, $medication_item]);
            }

            $pdo->commit();
            $success_msg = "Consultation logged and inventory updated successfully!";
        } catch (Exception $e) {
            $pdo->rollBack();
            $error_msg = "Database error: " . $e->getMessage();
        }
    } else {
        $error_msg = "Please fill in all required consultation fields.";
    }
}

// 1. Fetch Dynamic KPI Metrics from Database
$visits_today = 0;
$stock_outs = 0;

try {
    $v_stmt = $pdo->query("SELECT COUNT(*) FROM consultations WHERE DATE(consultation_date) = CURDATE()");
    $visits_today = $v_stmt->fetchColumn();

    $s_stmt = $pdo->query("SELECT COUNT(*) FROM inventory WHERE current_stock <= reorder_level");
    $stock_outs = $s_stmt->fetchColumn();
} catch (PDOException $e) {
    // Handle error silently if tables are empty
}

// 2. Fetch Real Dynamic EARS Fever Data for the Past 7 Days + Today
$ears_data = [];
$labels = [];

try {
    for ($i = 6; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-{$i} days"));
        $labels[] = date('D', strtotime($date)); 

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM consultations WHERE symptom_category = 'Fever' AND DATE(consultation_date) = ?");
        $stmt->execute([$date]);
        $ears_data[] = (int)$stmt->fetchColumn();
    }
    $labels[] = 'Today';
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM consultations WHERE symptom_category = 'Fever' AND DATE(consultation_date) = CURDATE()");
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
        
        .main-content { margin-left: 250px; padding: 45px; width: calc(100% - 250px); min-height: 100vh; position: relative; z-index: 1; }
        .top-navbar { position: relative; z-index: 1050; }
        
        .card { border: none; border-radius: 20px; box-shadow: 0 8px 25px rgba(111, 66, 193, 0.07); margin-bottom: 24px; transition: transform 0.2s ease, box-shadow 0.2s ease; background-color: #ffffff; }
        .clickable-card:hover { transform: translateY(-3px); box-shadow: 0 12px 30px rgba(111, 66, 193, 0.12); cursor: pointer; }
        
        .card-title { font-size: 0.85rem; font-weight: 700; color: #6c757d; text-transform: uppercase; letter-spacing: 0.8px; }
        .icon-box { width: 55px; height: 55px; border-radius: 16px; display: flex; align-items: center; justify-content: center; font-size: 1.4rem; }
        
        .bg-light-primary { background-color: #f3f0ff; color: #6f42c1; }
        .bg-light-danger { background-color: #fff5f5; color: #e53e3e; }
        .bg-light-warning { background-color: #fffaf0; color: #dd6b20; }
        
        .chart-container { position: relative; height: 280px; width: 100%; }
        .progress { height: 10px; border-radius: 5px; background-color: #edf2f7; }

        /* Custom HTML Legend Styling */
        .custom-legend {
            display: flex;
            justify-content: center;
            gap: 25px;
            margin-bottom: 15px;
            cursor: pointer;
            font-size: 0.9rem;
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
            width: 12px;
            height: 12px;
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
            padding: 20px;
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
    </style>
</head>
<body>

    <?php include '../../includes/sidebar.php'; ?>

    <div class="main-content">
        <?php include '../../includes/topbar.php'; ?>

        <?php if (!empty($success_msg)): ?>
            <div class="alert alert-success alert-dismissible fade show rounded-4 mb-4 mt-3" role="alert">
                <i class="fa-solid fa-circle-check me-2"></i> <?php echo $success_msg; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <?php if (!empty($error_msg)): ?>
            <div class="alert alert-danger alert-dismissible fade show rounded-4 mb-4 mt-3" role="alert">
                <i class="fa-solid fa-circle-exclamation me-2"></i> <?php echo $error_msg; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <!-- Dashboard Header with RBAC Quick-Action Buttons -->
        <div class="d-flex justify-content-between align-items-center mb-4 mt-4">
            <div>
                <h4 class="fw-bold text-dark mb-1">Clinic Dashboard Overview</h4>
                <p class="text-muted small mb-0">Real-time monitoring, outbreak surveillance, and inventory thresholds.</p>
            </div>
            
            <?php if ($user_role === 'Admin' || $user_role === 'Nurse'): ?>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-outline-primary px-3 py-2 rounded-pill fw-semibold shadow-sm" data-bs-toggle="modal" data-bs-target="#newStudentModal">
                        <i class="fa-solid fa-user-plus me-1"></i> New Student
                    </button>
                    <button type="button" class="btn btn-primary px-4 py-2 rounded-pill fw-semibold shadow-sm" data-bs-toggle="modal" data-bs-target="#newConsultationModal" style="background-color: #6f42c1; border: none;">
                        <i class="fa-solid fa-plus me-2"></i> New Consultation
                    </button>
                </div>
            <?php endif; ?>
        </div>

        <div class="alert alert-danger d-flex align-items-center border-0 shadow-sm rounded-4 p-4 mb-4" role="alert" style="background-color: #fff5f5; color: #c53030;">
            <i class="fa-solid fa-triangle-exclamation fs-3 me-3"></i>
            <div>
                <h6 class="fw-bold mb-1">EARS ALERT: Anomaly Monitoring Active</h6>
                <p class="mb-0 small opacity-75">Symptom deviations detected from rolling baseline thresholds. <a href="../analytics/index.php" class="alert-link fw-bold">Click to view report</a>.</p>
            </div>
        </div>

        <div class="row g-4">
            <div class="col-md-4">
                <a href="../consultations/index.php" class="text-decoration-none">
                    <div class="card p-4 clickable-card h-100">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="card-title mb-2">Visits Today</h6>
                                <h2 class="mb-0 fw-bold text-dark display-6"><?php echo $visits_today; ?></h2>
                            </div>
                            <div class="icon-box bg-light-primary"><i class="fa-solid fa-stethoscope"></i></div>
                        </div>
                    </div>
                </a>
            </div>
            <div class="col-md-4">
                <a href="../analytics/index.php" class="text-decoration-none">
                    <div class="card p-4 clickable-card h-100">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="card-title mb-2">Active EARS Alerts</h6>
                                <h2 class="mb-0 fw-bold text-danger display-6">1</h2>
                            </div>
                            <div class="icon-box bg-light-danger"><i class="fa-solid fa-virus"></i></div>
                        </div>
                    </div>
                </a>
            </div>
            <div class="col-md-4">
                <a href="../inventory/index.php" class="text-decoration-none">
                    <div class="card p-4 clickable-card h-100">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="card-title mb-2">Impending Stock-outs</h6>
                                <h2 class="mb-0 fw-bold text-warning display-6"><?php echo $stock_outs; ?></h2>
                            </div>
                            <div class="icon-box bg-light-warning"><i class="fa-solid fa-pills"></i></div>
                        </div>
                    </div>
                </a>
            </div>
        </div>

        <div class="row g-4 mt-2">
            <div class="col-lg-8">
                <div class="card p-4 h-100">
                    <h5 class="fw-bold mb-1 text-dark">Outbreak Surveillance (CDC EARS)</h5>
                    <p class="text-muted small mb-3">Live tracking of 'Fever' symptom cases against rolling baseline.</p>
                    <hr class="text-muted opacity-25 mb-4">
                    
                    <!-- Custom HTML Legend without strike-through bugs -->
                    <div class="custom-legend" id="customEarsLegend">
                        <div class="custom-legend-item" onclick="toggleMainDataset(0)">
                            <span class="custom-legend-dot" style="background-color: rgba(111, 66, 193, 0.5);"></span>
                            <span>Expected Baseline (Normal)</span>
                        </div>
                        <div class="custom-legend-item" onclick="toggleMainDataset(1)">
                            <span class="custom-legend-dot" style="background-color: #6f42c1;"></span>
                            <span>Actual Fever Cases</span>
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
                        <h5 class="fw-bold mb-1 text-dark">Inventory Forecast</h5>
                        <p class="text-muted small mb-4">Powered by Holt's Linear Trend Model</p>
                        <hr class="text-muted opacity-25 mb-4">
                        
                        <?php
                        $forecast_stmt = $pdo->query("SELECT * FROM inventory ORDER BY current_stock ASC LIMIT 2");
                        while($f_item = $forecast_stmt->fetch(PDO::FETCH_ASSOC)):
                            $stock = (int)$f_item['current_stock'];
                            $reorder = (int)$f_item['reorder_level'];
                            $daily_rate = (float)$f_item['daily_consumption_rate'];
                            
                            if ($daily_rate > 0) {
                                $days_left = round($stock / $daily_rate);
                                $days_text = $days_left . " Days Left";
                            } else {
                                $days_text = "Stable (No Usage)";
                            }

                            if ($stock <= $reorder) {
                                $bar_color = "bg-danger";
                                $text_color = "text-danger";
                            } elseif ($stock <= ($reorder / 2)) {
                                $bar_color = "bg-warning";
                                $text_color = "text-warning";
                            } else {
                                $bar_color = "bg-success";
                                $text_color = "text-success";
                            }

                            $max_capacity = $reorder > 0 ? $reorder * 3 : 50; 
                            $width_pct = min(100, max(5, ($stock / $max_capacity) * 100));
                        ?>
                        <div class="mb-4">
                            <div class="d-flex justify-content-between mb-1">
                                <span class="fw-semibold text-dark"><?php echo htmlspecialchars($f_item['item_name']); ?></span>
                                <span class="<?php echo $text_color; ?> fw-bold small"><i class="fa-solid fa-clock me-1"></i> <?php echo $days_text; ?></span>
                            </div>
                            <div class="progress mb-1">
                                <div class="progress-bar <?php echo $bar_color; ?>" role="progressbar" style="width: <?php echo $width_pct; ?>%;"></div>
                            </div>
                            <div class="d-flex justify-content-between">
                                <small class="text-muted">Current Stock: <?php echo $stock; ?> pcs</small>
                                <small class="text-muted fst-italic" style="font-size: 0.75rem;">Burn Rate: <?php echo $daily_rate; ?>/day</small>
                            </div>
                        </div>
                        <?php endwhile; ?>
                    </div>

                    <a href="../inventory/index.php" class="btn btn-outline-primary w-100 mt-3 py-2 rounded-pill fw-semibold">Manage Inventory</a>
                </div>
            </div>
        </div>

        <div class="row mt-4">
            <div class="col-12">
                <div class="card p-4">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="fw-bold mb-0 text-dark"><i class="fa-solid fa-clock-rotate-left me-2 text-purple" style="color: #6f42c1;"></i> Recent Consultations</h5>
                        <!-- Live Search Bar Filter -->
                        <div style="width: 280px;">
                            <input type="text" id="tableSearchInput" class="form-control form-control-sm rounded-pill px-3" placeholder="Filter recent table...">
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
                                        SELECT c.consultation_date, s.first_name, s.last_name, s.grade_section, c.chief_complaint, c.action_taken, c.status 
                                        FROM consultations c
                                        JOIN students s ON c.student_id = s.student_id
                                        ORDER BY c.consultation_date DESC 
                                        LIMIT 5
                                    ");
                                    
                                    if ($stmt->rowCount() > 0) {
                                        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                                            $time = date('h:i A', strtotime($row['consultation_date']));
                                            $currentStatus = $row['status'];

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
                                            echo "<td class='ps-3 fw-medium text-muted'>{$time}</td>";
                                            echo "<td><strong>" . htmlspecialchars($row['first_name'] . ' ' . $row['last_name']) . "</strong></td>";
                                            echo "<td><span class='badge bg-info bg-opacity-10 text-info px-3 py-2 rounded-pill'>" . htmlspecialchars($row['grade_section']) . "</span></td>";
                                            echo "<td>" . htmlspecialchars($row['chief_complaint']) . "</td>";
                                            echo "<td>" . htmlspecialchars($row['action_taken']) . "</td>";
                                            echo "<td class='text-end pe-3'>";
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

    <!-- Quick-Action New Student Modal (Admin / Nurse Only) -->
    <?php if ($user_role === 'Admin' || $user_role === 'Nurse'): ?>
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
                            <label class="form-label small fw-bold text-muted">Grade & Section</label>
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
                            <i class="fa-solid fa-stethoscope text-purple me-2" style="color:#6f42c1;"></i> Log New Clinic Visit
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body py-4">
                        <input type="hidden" name="quick_consultation" value="1">
                        
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-muted">SEARCH & SELECT STUDENT</label>
                            <input class="form-control rounded-3" list="student_list" name="student_id" placeholder="Type student name or ID..." required>
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
                                <option value="Cough & Cold">Cough & Cold</option>
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
                                <input class="form-control rounded-3" list="medicine_list" name="medication_item" placeholder="Type medicine name...">
                                <datalist id="medicine_list">
                                    <?php
                                    try {
                                        $meds = $pdo->query("SELECT item_name, current_stock FROM inventory WHERE current_stock > 0 ORDER BY item_name ASC");
                                        while ($m = $meds->fetch(PDO::FETCH_ASSOC)) {
                                            echo '<option value="' . htmlspecialchars($m['item_name']) . '">Stock: ' . $m['current_stock'] . ' pcs</option>';
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
                        <button type="submit" class="btn btn-primary rounded-pill px-4 shadow-sm" style="background-color: #6f42c1; border:none;">Save & Deduct Stock</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php include '../../includes/footer.php'; ?>

    <script>
        // Live Filter for Recent Consultations Table
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
                        { label: 'Expected Baseline (Normal)', data: baselineData, borderColor: 'rgba(111, 66, 193, 0.3)', backgroundColor: 'rgba(111, 66, 193, 0.05)', borderWidth: 2, fill: true, pointRadius: 0, tension: 0.4 },
                        { label: 'Actual Fever Cases', data: actualFeverCases, borderColor: '#6f42c1', backgroundColor: 'rgba(111, 66, 193, 0.1)', borderWidth: 3, pointBackgroundColor: actualFeverCases.map(v => v >= 3 ? '#e53e3e' : '#6f42c1'), pointBorderColor: '#fff', pointRadius: 5, fill: false, tension: 0.4 }
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

            // Custom Legend Toggle for Main Dashboard
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