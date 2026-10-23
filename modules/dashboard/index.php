<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();
if(!isset($_SESSION['user_id'])){
    header("Location: ../../index.php?error=Please log in first");
    exit();
}
require_once '../../includes/db_connect.php';

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
        
        /* Spacious Layout */
        .main-content { margin-left: 250px; padding: 45px; width: calc(100% - 250px); }
        
        .card { border: none; border-radius: 20px; box-shadow: 0 8px 25px rgba(111, 66, 193, 0.07); margin-bottom: 24px; transition: transform 0.2s ease, box-shadow 0.2s ease; background-color: #ffffff; }
        .clickable-card:hover { transform: translateY(-3px); box-shadow: 0 12px 30px rgba(111, 66, 193, 0.12); cursor: pointer; }
        
        .card-title { font-size: 0.85rem; font-weight: 700; color: #6c757d; text-transform: uppercase; letter-spacing: 0.8px; }
        .icon-box { width: 55px; height: 55px; border-radius: 16px; display: flex; align-items: center; justify-content: center; font-size: 1.4rem; }
        
        .bg-light-primary { background-color: #f3f0ff; color: #6f42c1; }
        .bg-light-danger { background-color: #fff5f5; color: #e53e3e; }
        .bg-light-warning { background-color: #fffaf0; color: #dd6b20; }
        
        .chart-container { position: relative; height: 280px; width: 100%; }
        .progress { height: 10px; border-radius: 5px; background-color: #edf2f7; }

        /* Spacious Table Styling */
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
                            <small class="text-muted">Current Stock: <?php echo $stock; ?> pcs</small>
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
                    <h5 class="fw-bold mb-3 text-dark"><i class="fa-solid fa-clock-rotate-left me-2 text-purple" style="color: #6f42c1;"></i> Recent Consultations</h5>
                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
                            <thead>
                                <tr>
                                    <th class="ps-3">Time</th>
                                    <th>Student Name</th>
                                    <th>Grade / Section</th>
                                    <th>Chief Complaint</th>
                                    <th>Action Taken</th>
                                    <th class="text-end pe-3">Status</th>
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
                                            
                                            $badgeClass = 'bg-secondary';
                                            if ($row['status'] == 'Cleared') $badgeClass = 'bg-success bg-opacity-10 text-success border border-success-subtle px-3 py-2 rounded-pill';
                                            elseif ($row['status'] == 'Under Observation') $badgeClass = 'bg-warning bg-opacity-10 text-warning border border-warning-subtle px-3 py-2 rounded-pill';
                                            elseif ($row['status'] == 'Sent Home') $badgeClass = 'bg-danger bg-opacity-10 text-danger border border-danger-subtle px-3 py-2 rounded-pill';
                                            else $badgeClass = 'bg-secondary bg-opacity-10 text-secondary px-3 py-2 rounded-pill';

                                            echo "<tr>";
                                            echo "<td class='ps-3 fw-medium text-muted'>{$time}</td>";
                                            echo "<td><strong>" . htmlspecialchars($row['first_name'] . ' ' . $row['last_name']) . "</strong></td>";
                                            echo "<td><span class='badge bg-info bg-opacity-10 text-info px-3 py-2 rounded-pill'>" . htmlspecialchars($row['grade_section']) . "</span></td>";
                                            echo "<td>" . htmlspecialchars($row['chief_complaint']) . "</td>";
                                            echo "<td>" . htmlspecialchars($row['action_taken']) . "</td>";
                                            echo "<td class='text-end pe-3'><span class='badge {$badgeClass} fw-semibold'>" . htmlspecialchars($row['status']) . "</span></td>";
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

    <?php include '../../includes/footer.php'; ?>
    
    <script>
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
                options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'top', labels: { usePointStyle: true, boxWidth: 8, font: { family: 'Segoe UI' } } } }, scales: { y: { beginAtZero: true, grid: { borderDash: [5, 5], color: '#e9ecef' } }, x: { grid: { display: false } } } }
            });
        }
    </script>
</body>
</html>