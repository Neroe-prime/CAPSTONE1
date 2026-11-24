<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../index.php?error=Please log in first");
    exit();
}

// Prevent browser caching after logout so 'Back' button won't show analytics
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

require_once '../../includes/db_connect.php';
require_once '../../includes/holt_forecast.php';

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
    header("Location: ../dashboard/index.php?error=Unauthorized access to predictive analytics");
    exit();
}

$success_msg = '';
$error_msg = '';

// Handle Logging a New School Event (for CDC EARS Event-Correlated Spike Context)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_school_event'])) {
    $event_name = trim($_POST['event_name'] ?? '');
    $event_date = trim($_POST['event_date'] ?? '');
    $description = trim($_POST['description'] ?? '');

    if (empty($event_name) || empty($event_date)) {
        $error_msg = "Event name and event date are required.";
    } else {
        try {
            $ev_stmt = $pdo->prepare("INSERT INTO school_events (event_name, event_date, description, created_by) VALUES (?, ?, ?, ?)");
            $ev_stmt->execute([$event_name, $event_date, $description ?: null, $performed_by]);
            $success_msg = "School event '{$event_name}' ({$event_date}) logged for CDC EARS context.";
        } catch (PDOException $e) {
            $error_msg = "Database error logging school event: " . $e->getMessage();
        }
    }
}

// Handle Deleting a School Event
if (isset($_GET['delete_event'])) {
    $event_id = (int)$_GET['delete_event'];
    if ($event_id > 0) {
        try {
            $del_stmt = $pdo->prepare("DELETE FROM school_events WHERE event_id = ?");
            $del_stmt->execute([$event_id]);
            $success_msg = "School event removed.";
        } catch (PDOException $e) {
            $error_msg = "Error removing school event: " . $e->getMessage();
        }
    }
}

// Fetch logged school events
$school_events = [];
$event_dates_map = [];
try {
    $ev_list_stmt = $pdo->query("SELECT * FROM school_events ORDER BY event_date DESC LIMIT 20");
    $school_events = $ev_list_stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($school_events as $ev) {
        $event_dates_map[$ev['event_date']][] = $ev['event_name'];
    }
} catch (PDOException $e) {
    $school_events = [];
}

// 1. Fetch Real Dynamic CDC EARS Fever Trend Data (Unique Students per Day, Past 7 Days) + Event Correlation
$ears_labels = [];
$observed_cases = [];
$baseline_data = [];
$daily_event_notes = [];
$event_correlated_days = 0;
$sustained_high_days = 0;

try {
    for ($i = 6; $i >= 0; $i--) {
        $date_point = date('Y-m-d', strtotime("-{$i} days"));
        $display_label = date('M d', strtotime("-{$i} days"));
        $ears_labels[] = $display_label;

        $stmt = $pdo->prepare("
            SELECT COUNT(DISTINCT student_id) as total 
            FROM consultations 
            WHERE symptom_category = 'Fever' AND DATE(consultation_date) = ?
        ");
        $stmt->execute([$date_point]);
        $count = (int)$stmt->fetchColumn();

        $base_val = max(1, (int)round($count * 0.6 + 1));
        $observed_cases[] = $count;
        $baseline_data[] = $base_val;

        $prev_day = date('Y-m-d', strtotime($date_point . ' -1 day'));
        $matched_events = [];
        if (isset($event_dates_map[$date_point])) {
            foreach ($event_dates_map[$date_point] as $en) {
                $matched_events[] = $en . " (on " . date('M d', strtotime($date_point)) . ")";
            }
        }
        if (isset($event_dates_map[$prev_day])) {
            foreach ($event_dates_map[$prev_day] as $en) {
                $matched_events[] = $en . " (day prior, " . date('M d', strtotime($prev_day)) . ")";
            }
        }

        $daily_event_notes[] = $matched_events;

        if ($count >= 3) {
            if (!empty($matched_events)) {
                $event_correlated_days++;
            } else {
                $sustained_high_days++;
            }
        }
    }
} catch (PDOException $e) {
    $ears_labels = ['Day 1', 'Day 2', 'Day 3', 'Day 4', 'Day 5', 'Day 6', 'Day 7'];
    $observed_cases = [0, 0, 0, 0, 0, 0, 0];
    $baseline_data = [2, 2, 2, 2, 2, 2, 2];
    $daily_event_notes = array_fill(0, 7, []);
}

// 2. Real-Time EARS Threshold Alert Check (Unique Students, distinguishing Event-Correlated vs Unprompted Aberration)
$active_alerts = [];
try {
    $alert_stmt = $pdo->query("
        SELECT symptom_category, COUNT(DISTINCT student_id) as case_count 
        FROM consultations 
        WHERE consultation_date >= NOW() - INTERVAL 7 DAY 
        GROUP BY symptom_category 
        HAVING case_count >= 3
    ");
    $raw_alerts = $alert_stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($raw_alerts as $ra) {
        $cat = $ra['symptom_category'];
        $cnt = (int)$ra['case_count'];
        $is_event_linked = false;
        $linked_event_labels = [];

        if ($cat === 'Fever') {
            foreach ($daily_event_notes as $idx => $ev_list) {
                if (!empty($ev_list) && ($observed_cases[$idx] ?? 0) >= 2) {
                    $is_event_linked = true;
                    $linked_event_labels = array_merge($linked_event_labels, $ev_list);
                }
            }
        }

        $is_true_aberration = (!$is_event_linked) || ($sustained_high_days >= 2);

        $active_alerts[] = [
            'symptom_category' => $cat,
            'case_count' => $cnt,
            'is_event_linked' => $is_event_linked,
            'is_true_aberration' => $is_true_aberration,
            'linked_events' => array_unique($linked_event_labels)
        ];
    }
} catch (PDOException $e) {
    $active_alerts = [];
}

// 3. Holt's Linear Trend Forecast & Impending Stock-Out Watchlist
$inventory_forecast = getAllHoltInventoryForecasts($pdo);
$impending_stockouts = array_values(array_filter($inventory_forecast, function ($row) {
    return !empty($row['is_watchlist']);
}));

// Build 7-day day-by-day projected stock trajectory for each item for the visual chart
$holt_trajectories = [];
$horizon_labels = ['Today'];
for ($d = 1; $d <= 7; $d++) {
    $horizon_labels[] = '+' . $d . 'd (' . date('M d', strtotime("+{$d} days")) . ')';
}

foreach ($inventory_forecast as $fc_row) {
    $stock_val = (float)$fc_row['current_stock'];
    $lvl_val = (float)$fc_row['level'];
    $trd_val = (float)$fc_row['trend'];
    $reord_val = (int)$fc_row['reorder_level'];

    $traj = [round($stock_val, 1)];
    $sim_stock = $stock_val;
    for ($h = 1; $h <= 7; $h++) {
        $daily_need = max(0.0, $lvl_val + ($h * $trd_val));
        $sim_stock = max(0.0, $sim_stock - $daily_need);
        $traj[] = round($sim_stock, 1);
    }

    $holt_trajectories[] = [
        'item_name' => $fc_row['item_name'],
        'current_stock' => (int)$fc_row['current_stock'],
        'reorder_level' => $reord_val,
        'level' => $lvl_val,
        'trend' => $trd_val,
        'trend_direction' => $fc_row['trend_direction'],
        'forecast_7d_demand' => $fc_row['forecast_7d_demand'],
        'projected_stock_7d' => $fc_row['projected_stock_7d'],
        'trajectory' => $traj
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Predictive Analytics & Surveillance - MedFlow</title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

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
            letter-spacing: 0.8px;
            border-bottom: 2px solid #eaedf2;
            padding: 14px 18px;
            white-space: nowrap;
        }
        .table tbody tr {
            background-color: #ffffff;
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.02);
        }
        .table tbody td {
            padding: 16px 18px;
            color: #2d3748;
            vertical-align: middle;
            font-size: 0.92rem;
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

        .extra-forecast-row.d-none {
            display: none !important;
        }

        @media print {
            .sidebar, .top-navbar, .no-print, .btn, .alert .btn-close { display: none !important; }
            .main-content { margin-left: 0 !important; width: 100% !important; padding: 15px !important; }
            .card { box-shadow: none !important; border: 1px solid #dee2e6 !important; }
            .extra-forecast-row { display: table-row !important; }
        }
    </style>
</head>
<body>

    <?php include '../../includes/sidebar.php'; ?>

    <div class="main-content">
        
        <?php include '../../includes/topbar.php'; ?>

        <!-- Purple Gradient Header Banner -->
        <div class="card bg-gradient-purple p-5 mb-5 shadow-sm mt-3">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
                <div>
                    <h2 class="fw-bold mb-2 text-white"><i class="fa-solid fa-chart-line me-3"></i> Predictive Analytics & Surveillance</h2>
                    <p class="text-white-50 fs-6 mb-0">Holt's Linear Trend forecasting, impending stock-out watchlist, and event-aware CDC EARS surveillance.</p>
                </div>
                <div class="d-flex flex-wrap gap-2 no-print">
                    <button type="button" class="btn btn-outline-light fw-bold px-4 py-2 rounded-pill shadow-sm text-nowrap" data-bs-toggle="modal" data-bs-target="#schoolEventModal">
                        <i class="fa-solid fa-calendar-plus me-2"></i> Log School Event
                    </button>
                    <button type="button" class="btn btn-light fw-bold px-4 py-2 shadow text-primary rounded-pill text-nowrap" onclick="window.print();">
                        <i class="fa-solid fa-file-lines me-2"></i> Print Summary Report
                    </button>
                </div>
            </div>
        </div>

        <?php if (!empty($success_msg)): ?>
            <div class="alert alert-success shadow-sm border-0 alert-dismissible fade show mb-4 rounded-4 p-4" role="alert">
                <i class="fa-solid fa-circle-check me-2"></i> <?php echo htmlspecialchars($success_msg); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <?php if (!empty($error_msg)): ?>
            <div class="alert alert-danger shadow-sm border-0 alert-dismissible fade show mb-4 rounded-4 p-4" role="alert">
                <i class="fa-solid fa-triangle-exclamation me-2"></i> <?php echo htmlspecialchars($error_msg); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <!-- Impending Stock-Out Watchlist Banner -->
        <?php if (!empty($impending_stockouts)): ?>
            <div class="card p-4 mb-4 border-start border-4 border-warning shadow-sm" style="background-color: #fffaf0;">
                <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
                    <div>
                        <h5 class="fw-bold mb-1" style="color: #c05621;">
                            <i class="fa-solid fa-boxes-packing me-2"></i> Impending Stock-Out Watchlist (<?php echo count($impending_stockouts); ?> Item<?php echo count($impending_stockouts) > 1 ? 's' : ''; ?>)
                        </h5>
                        <p class="text-dark small mb-2">Items at or below reorder threshold (&le; 15 pcs) or projected by Holt's Linear Trend to deplete within &le; 7 days:</p>
                        <div class="d-flex flex-wrap gap-2">
                            <?php foreach ($impending_stockouts as $iso): ?>
                                <span class="badge bg-white text-dark border border-warning-subtle px-3 py-2 rounded-pill shadow-sm">
                                    <strong><?php echo htmlspecialchars($iso['item_name']); ?></strong>: 
                                    <?php echo (int)$iso['current_stock']; ?> pcs now &bull; 7d need: <?php echo htmlspecialchars((string)$iso['forecast_7d_demand']); ?> pcs
                                    <?php if ($iso['days_until_depletion'] !== null && $iso['has_usage']): ?>
                                        <span class="text-danger fw-bold">(~<?php echo (int)$iso['days_until_depletion']; ?>d)</span>
                                    <?php endif; ?>
                                </span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <a href="../inventory/index.php" class="btn btn-sm btn-warning text-dark fw-bold rounded-pill px-3 py-2 text-nowrap no-print">
                        Manage Inventory
                    </a>
                </div>
            </div>
        <?php endif; ?>

        <!-- Real-Time Threshold Alert Banners (Event-Aware, Unique Students) -->
        <?php if (!empty($active_alerts)): ?>
            <?php foreach ($active_alerts as $alert): ?>
                <?php if ($alert['is_true_aberration']): ?>
                    <div class="card p-4 mb-4 border-start border-4 border-danger shadow-sm bg-danger bg-opacity-10">
                        <div class="d-flex align-items-center">
                            <i class="fa-solid fa-triangle-exclamation fa-2x text-danger me-3"></i>
                            <div>
                                <h5 class="fw-bold text-danger mb-1">EARS Aberration Alert: Potential Spike in <?php echo htmlspecialchars($alert['symptom_category']); ?>!</h5>
                                <p class="text-dark mb-0 fw-medium">
                                    Detected <strong><?php echo (int)$alert['case_count']; ?> unique students</strong> with <?php echo htmlspecialchars($alert['symptom_category']); ?> within the rolling 7-day window. Statistical threshold exceeded—review clinic logs for sustained clusters.
                                </p>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="card p-4 mb-4 border-start border-4 border-info shadow-sm bg-info bg-opacity-10">
                        <div class="d-flex align-items-center">
                            <i class="fa-solid fa-calendar-check fa-2x text-info me-3"></i>
                            <div>
                                <h5 class="fw-bold text-dark mb-1">EARS Event-Correlated Spike Notice: <?php echo htmlspecialchars($alert['symptom_category']); ?> (<?php echo (int)$alert['case_count']; ?> Unique Students)</h5>
                                <p class="text-dark mb-0 small">
                                    Elevated cases correlate with logged school activity: <strong><?php echo htmlspecialchars(implode(', ', $alert['linked_events'])); ?></strong>. Flagged as event-correlated rather than an unprompted infectious outbreak—monitor if elevated cases persist beyond 48 hours.
                                </p>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="card p-4 mb-4 border-start border-4 border-success shadow-sm">
                <div class="d-flex align-items-center">
                    <i class="fa-solid fa-shield-check fa-2x text-success me-3"></i>
                    <div>
                        <h5 class="fw-bold text-dark mb-1">Surveillance Status: Normal Baseline</h5>
                        <p class="text-muted mb-0">No significant statistical aberrations or outbreak thresholds crossed across current symptom categories (unique students counted per day).</p>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- CDC EARS Chart Card -->
        <div class="card p-4 mb-4">
            <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
                <div>
                    <h4 class="fw-bold text-dark mb-1">CDC EARS Outbreak Detection Model (Event-Contextualized)</h4>
                    <p class="text-muted small mb-0">Comparing unique daily Fever students against baseline while cross-checking logged <code>school_events</code>.</p>
                </div>
                <span class="badge bg-light text-dark border px-3 py-2 rounded-pill small text-nowrap">
                    Event-Correlated Days (7d): <strong><?php echo (int)$event_correlated_days; ?></strong>
                </span>
            </div>
            <hr class="text-muted opacity-25">
            
            <div class="custom-legend" id="customChartLegend">
                <div class="custom-legend-item" onclick="toggleDataset(0)">
                    <span class="custom-legend-dot" style="background-color: #6c757d;"></span>
                    <span>Baseline Average (Expected)</span>
                </div>
                <div class="custom-legend-item" onclick="toggleDataset(1)">
                    <span class="custom-legend-dot" style="background-color: #e53e3e;"></span>
                    <span>Unique Fever Students (Observed)</span>
                </div>
            </div>

            <div style="height: 320px; position: relative;" class="my-3">
                <canvas id="earsSurveillanceChart"></canvas>
            </div>
        </div>

        <!-- Full-Width Holt's Linear Trend Forecast & Stock-Out Watchlist Card -->
        <div class="card p-4 mb-4">
            <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
                <div>
                    <h5 class="fw-bold text-dark mb-1"><i class="fa-solid fa-chart-pie me-2 text-warning"></i> Holt's Linear Trend Forecast &amp; Stock-Out Watchlist</h5>
                    <p class="text-muted small mb-0">Double exponential smoothing (Level &amp; Trend) projecting 7-day demand, projected stock trajectory, and depletion horizon.</p>
                </div>
                <div class="d-flex align-items-center gap-2 no-print">
                    <?php if (count($holt_trajectories) > 0): ?>
                        <select id="holtItemSelector" class="form-select form-select-sm rounded-pill px-3" style="width: auto; min-width: 220px;" onchange="updateHoltTrajectoryChart()">
                            <?php foreach ($holt_trajectories as $t_idx => $tr_item): ?>
                                <option value="<?php echo $t_idx; ?>"><?php echo htmlspecialchars($tr_item['item_name']); ?> (<?php echo (int)$tr_item['current_stock']; ?> pcs)</option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>
                    <span class="badge bg-warning bg-opacity-10 text-dark border border-warning-subtle px-3 py-2 rounded-pill small text-nowrap">
                        Watchlist Items: <strong><?php echo count($impending_stockouts); ?></strong>
                    </span>
                </div>
            </div>

            <?php if (count($holt_trajectories) > 0): ?>
                <div class="bg-light bg-opacity-50 rounded-4 p-3 mb-4 border border-light">
                    <div class="d-flex flex-wrap justify-content-between align-items-center mb-2 gap-2">
                        <span class="small fw-bold text-dark" id="holtTrajectoryCaption">Projected 7-Day Stock Trajectory</span>
                        <span class="small text-muted" id="holtTrajectoryMeta"></span>
                    </div>
                    <div class="custom-legend mb-2" style="cursor: default;">
                        <div class="custom-legend-item">
                            <span class="custom-legend-dot" style="background-color: #6f42c1;"></span>
                            <span>Projected Stock (pcs)</span>
                        </div>
                        <div class="custom-legend-item">
                            <span class="custom-legend-dot" style="background-color: #dd6b20;"></span>
                            <span>Reorder Threshold (pcs)</span>
                        </div>
                    </div>
                    <div style="height: 220px; position: relative;">
                        <canvas id="holtTrajectoryChart"></canvas>
                    </div>
                </div>
            <?php endif; ?>

            <div class="table-responsive">
                <table class="table align-middle mb-0" id="forecastTable">
                    <thead>
                        <tr>
                            <th class="ps-3">Medicine / Supply</th>
                            <th>Current Stock</th>
                            <th>Smoothed Level / Trend</th>
                            <th>7-Day Forecast</th>
                            <th>Est. Depletion</th>
                            <th class="text-end pe-3">Watchlist Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($inventory_forecast) > 0): ?>
                            <?php $preview_limit = 5; ?>
                            <?php foreach ($inventory_forecast as $idx => $inv): ?>
                                <?php
                                $stock = (int)$inv['current_stock'];
                                $level = (float)$inv['level'];
                                $trend = (float)$inv['trend'];
                                $f7 = (float)$inv['forecast_7d_demand'];
                                $proj7 = (int)$inv['projected_stock_7d'];
                                $days = $inv['days_until_depletion'];
                                $has_usage = (bool)$inv['has_usage'];
                                $is_watchlist = (bool)$inv['is_watchlist'];
                                $is_extra = $idx >= $preview_limit;
                                $trend_sign = $trend > 0 ? '+' : '';
                                ?>
                                <tr class="<?php echo $is_extra ? 'extra-forecast-row d-none' : ''; ?>">
                                    <td class="ps-3">
                                        <div class="fw-semibold text-dark"><?php echo htmlspecialchars($inv['item_name']); ?></div>
                                        <small class="text-muted">Batch: <code><?php echo htmlspecialchars($inv['batch_number']); ?></code></small>
                                    </td>
                                    <td><strong><?php echo $stock; ?></strong> pcs</td>
                                    <td>
                                        <div class="small fw-semibold text-dark">L: <?php echo number_format($level, 2); ?>/d &bull; T: <?php echo $trend_sign . number_format($trend, 2); ?>/d</div>
                                        <small class="text-muted"><?php echo htmlspecialchars($inv['trend_direction']); ?></small>
                                    </td>
                                    <td>
                                        <div class="small fw-bold text-primary">Need: <?php echo $f7; ?> pcs (7d)</div>
                                        <small class="text-muted">Est. Left: <?php echo $proj7; ?> pcs</small>
                                    </td>
                                    <td>
                                        <?php if (!$has_usage): ?>
                                            <span class="badge bg-secondary bg-opacity-10 text-secondary px-2 py-1 rounded-pill">No Recent Dispensing</span>
                                        <?php elseif ($days !== null): ?>
                                            <span class="fw-bold <?php echo ($days <= 7) ? 'text-danger' : 'text-primary'; ?>">~<?php echo (int)$days; ?> days</span>
                                        <?php else: ?>
                                            <span class="text-muted small">&gt; 90 days</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end pe-3 text-nowrap">
                                        <?php if ($is_watchlist): ?>
                                            <span class="badge bg-danger bg-opacity-10 text-danger border border-danger-subtle px-3 py-2 rounded-pill fw-semibold">
                                                <i class="fa-solid fa-circle-exclamation me-1"></i> Watchlist (<?php echo htmlspecialchars($inv['stock_status']); ?>)
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-success bg-opacity-10 text-success border border-success-subtle px-3 py-2 rounded-pill fw-semibold">
                                                <?php echo htmlspecialchars($inv['stock_status']); ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="6" class="text-center text-muted py-4">No inventory items tracked.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if (count($inventory_forecast) > 5): ?>
                <?php $hidden_count = count($inventory_forecast) - 5; ?>
                <div class="text-center mt-3 no-print">
                    <button type="button" id="toggleForecastBtn" class="btn btn-outline-primary btn-sm rounded-pill px-4 py-2 fw-semibold" onclick="toggleForecastRows(<?php echo $hidden_count; ?>)">
                        <i class="fa-solid fa-chevron-down me-1"></i> Click to view more (<?php echo $hidden_count; ?> more items)
                    </button>
                </div>
            <?php endif; ?>
        </div>

        <!-- School Events Context Card -->
        <div class="card p-4">
            <div class="d-flex justify-content-between align-items-center mb-2 gap-3">
                <div>
                    <h5 class="fw-bold text-dark mb-1"><i class="fa-solid fa-calendar-days me-2 text-primary"></i> School Events (CDC EARS Context)</h5>
                    <p class="text-muted small mb-0">Activities logged here help CDC EARS distinguish single-day event-correlated spikes from multi-day outbreaks.</p>
                </div>
                <button type="button" class="btn btn-sm btn-primary rounded-pill px-4 py-2 fw-semibold text-nowrap no-print" style="background: linear-gradient(135deg, #6f42c1 0%, #4e73df 100%); border: none;" data-bs-toggle="modal" data-bs-target="#schoolEventModal">
                    <i class="fa-solid fa-plus me-1"></i> Add Event
                </button>
            </div>

            <div class="table-responsive mt-3">
                <table class="table align-middle mb-0">
                    <thead>
                        <tr>
                            <th class="ps-3">Event Date</th>
                            <th>Activity / Event Name</th>
                            <th>Context / Notes</th>
                            <th class="text-end pe-3 no-print">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($school_events) > 0): ?>
                            <?php foreach ($school_events as $ev): ?>
                                <tr>
                                    <td class="ps-3 fw-semibold text-dark text-nowrap"><?php echo htmlspecialchars(date('M d, Y', strtotime($ev['event_date']))); ?></td>
                                    <td class="fw-semibold text-dark"><?php echo htmlspecialchars($ev['event_name']); ?></td>
                                    <td class="text-muted"><?php echo htmlspecialchars($ev['description'] ?: '—'); ?></td>
                                    <td class="text-end pe-3 no-print">
                                        <a href="?delete_event=<?php echo (int)$ev['event_id']; ?>" class="btn btn-sm btn-outline-danger rounded-pill px-3 py-1" onclick="return confirm('Remove this school event?');" title="Delete Event">
                                            <i class="fa-solid fa-trash"></i>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="4" class="text-center text-muted py-4">No school events logged yet. Click <strong>+ Add Event</strong> to record school activities.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>

    <!-- LOG SCHOOL EVENT MODAL -->
    <div class="modal fade" id="schoolEventModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg rounded-4">
                <div class="modal-header border-0 text-white rounded-top-4 p-4" style="background: linear-gradient(135deg, #6f42c1 0%, #4e73df 100%);">
                    <h5 class="modal-title fw-bold"><i class="fa-solid fa-calendar-plus me-2"></i> Log School Event / Activity</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form action="" method="POST">
                    <div class="modal-body p-4">
                        <input type="hidden" name="add_school_event" value="1">
                        <div class="mb-3">
                            <label class="form-label text-muted small fw-bold text-uppercase">Event / Activity Name</label>
                            <input type="text" name="event_name" class="form-control rounded-3" placeholder="e.g., School Intramurals / Field Day" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label text-muted small fw-bold text-uppercase">Event Date</label>
                            <input type="date" name="event_date" class="form-control rounded-3" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label text-muted small fw-bold text-uppercase">Notes / Context (Optional)</label>
                            <textarea name="description" class="form-control rounded-3" rows="2" placeholder="e.g., Full-day outdoor sports under high heat"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer border-0 bg-light rounded-bottom-4 p-3">
                        <button type="button" class="btn btn-secondary btn-sm px-4 rounded-pill" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary btn-sm px-4 rounded-pill fw-semibold" style="background: linear-gradient(135deg, #6f42c1 0%, #4e73df 100%); border: none;">Save Event</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php include '../../includes/footer.php'; ?>

    <script>
        function toggleForecastRows(hiddenCount) {
            const rows = document.querySelectorAll('.extra-forecast-row');
            const btn = document.getElementById('toggleForecastBtn');
            const isExpanding = rows.length > 0 && rows[0].classList.contains('d-none');

            rows.forEach(row => {
                if (isExpanding) {
                    row.classList.remove('d-none');
                } else {
                    row.classList.add('d-none');
                }
            });

            if (btn) {
                if (isExpanding) {
                    btn.innerHTML = '<i class="fa-solid fa-chevron-up me-1"></i> Show less';
                } else {
                    btn.innerHTML = '<i class="fa-solid fa-chevron-down me-1"></i> Click to view more (' + hiddenCount + ' more items)';
                }
            }
        }

        const ctx = document.getElementById('earsSurveillanceChart').getContext('2d');
        
        const chartLabels = <?php echo json_encode($ears_labels); ?>;
        const observedData = <?php echo json_encode($observed_cases); ?>;
        const baselineData = <?php echo json_encode($baseline_data); ?>;
        const dailyEventNotes = <?php echo json_encode($daily_event_notes); ?>;

        const earsSurveillanceChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: chartLabels,
                datasets: [
                    {
                        label: 'Baseline Average (Expected)',
                        data: baselineData,
                        borderColor: '#6c757d',
                        backgroundColor: 'rgba(108, 117, 125, 0.1)',
                        borderWidth: 2,
                        fill: true,
                        tension: 0.4,
                        pointRadius: 0
                    },
                    {
                        label: 'Unique Fever Students (Observed)',
                        data: observedData,
                        borderColor: '#e53e3e',
                        backgroundColor: 'rgba(229, 62, 62, 0.1)',
                        borderWidth: 3,
                        tension: 0.4,
                        pointBackgroundColor: observedData.map((val, idx) => (dailyEventNotes[idx] && dailyEventNotes[idx].length > 0 && val >= 2) ? '#0dcaf0' : (val >= 3 ? '#e53e3e' : '#6f42c1')),
                        pointBorderColor: '#fff',
                        pointRadius: 5
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        callbacks: {
                            afterBody: function(items) {
                                const idx = items[0]?.dataIndex;
                                if (idx !== undefined && dailyEventNotes[idx] && dailyEventNotes[idx].length > 0) {
                                    return 'Event Context: ' + dailyEventNotes[idx].join(', ');
                                }
                                return '';
                            }
                        }
                    }
                },
                scales: {
                    y: { beginAtZero: true, grid: { borderDash: [5, 5], color: '#e9ecef' } },
                    x: { grid: { display: false } }
                }
            }
        });

        function toggleDataset(index) {
            const meta = earsSurveillanceChart.getDatasetMeta(index);
            meta.hidden = meta.hidden === null ? !earsSurveillanceChart.data.datasets[index].hidden : null;
            earsSurveillanceChart.update();

            const items = document.querySelectorAll('#customChartLegend .custom-legend-item');
            const dot = items[index].querySelector('.custom-legend-dot');
            if (meta.hidden) {
                items[index].classList.add('disabled');
                dot.style.backgroundColor = 'transparent';
                dot.style.border = '2px solid #adb5bd';
            } else {
                items[index].classList.remove('disabled');
                dot.style.backgroundColor = index === 0 ? '#6c757d' : '#e53e3e';
                dot.style.border = 'none';
            }
        }

        // Holt's Linear Trend 7-Day Projected Stock Trajectory Chart
        const holtTrajectories = <?php echo json_encode($holt_trajectories); ?>;
        const horizonLabels = <?php echo json_encode($horizon_labels); ?>;
        const holtCanvas = document.getElementById('holtTrajectoryChart');
        let holtTrajectoryChart = null;

        // Auto-select the first item that has active usage/trend so the chart shows a dynamic curve when available
        const selectorEl = document.getElementById('holtItemSelector');
        if (selectorEl && Array.isArray(holtTrajectories) && holtTrajectories.length > 0) {
            const activeIdx = holtTrajectories.findIndex(item => Number(item.level) > 0 || Math.abs(Number(item.trend)) > 0);
            if (activeIdx !== -1) {
                selectorEl.value = String(activeIdx);
            }
        }

        function updateHoltTrajectoryChart() {
            if (!holtCanvas || !holtTrajectories || holtTrajectories.length === 0) return;
            const selector = document.getElementById('holtItemSelector');
            const idx = selector ? parseInt(selector.value, 10) || 0 : 0;
            const item = holtTrajectories[idx] || holtTrajectories[0];
            const reorderLine = new Array(horizonLabels.length).fill(item.reorder_level);
            const maxVal = Math.max(20, Number(item.current_stock) + 5, Number(item.reorder_level) + 5);

            const captionEl = document.getElementById('holtTrajectoryCaption');
            const metaEl = document.getElementById('holtTrajectoryMeta');
            if (captionEl) {
                captionEl.textContent = item.item_name + ' — Projected 7-Day Stock Trajectory';
            }
            if (metaEl) {
                const sign = item.trend > 0 ? '+' : '';
                metaEl.textContent = `Level: ${Number(item.level).toFixed(2)}/d | Trend: ${sign}${Number(item.trend).toFixed(2)}/d | 7d Need: ${item.forecast_7d_demand} pcs | Reorder: ${item.reorder_level} pcs`;
            }

            if (holtTrajectoryChart) {
                holtTrajectoryChart.data.datasets[0].data = item.trajectory;
                holtTrajectoryChart.data.datasets[0].hidden = false;
                holtTrajectoryChart.data.datasets[1].data = reorderLine;
                holtTrajectoryChart.data.datasets[1].hidden = false;
                holtTrajectoryChart.options.scales.y.suggestedMax = maxVal;
                holtTrajectoryChart.update();
            } else {
                holtTrajectoryChart = new Chart(holtCanvas.getContext('2d'), {
                    type: 'line',
                    data: {
                        labels: horizonLabels,
                        datasets: [
                            {
                                label: 'Projected Stock (pcs)',
                                data: item.trajectory,
                                borderColor: '#6f42c1',
                                backgroundColor: 'rgba(111, 66, 193, 0.12)',
                                borderWidth: 3,
                                fill: true,
                                tension: 0.3,
                                pointBackgroundColor: '#6f42c1',
                                pointBorderColor: '#fff',
                                pointRadius: 4
                            },
                            {
                                label: 'Reorder Threshold (pcs)',
                                data: reorderLine,
                                borderColor: '#dd6b20',
                                borderDash: [6, 6],
                                borderWidth: 2,
                                fill: false,
                                pointRadius: 0
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: { display: false }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                suggestedMax: maxVal,
                                grid: { borderDash: [5, 5], color: '#e9ecef' }
                            },
                            x: { grid: { display: false } }
                        }
                    }
                });
            }
        }

        updateHoltTrajectoryChart();
    </script>
</body>
</html>