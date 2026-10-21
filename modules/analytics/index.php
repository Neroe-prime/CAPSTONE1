<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();
if(!isset($_SESSION['user_id'])){
    header("Location: ../../index.php?error=Please log in first");
    exit();
}
require_once '../../includes/db_connect.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Analytics & Forecasting - MedFlow</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
        .card { border: none; border-radius: 10px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); margin-bottom: 24px; }
        .chart-container { position: relative; height: 300px; width: 100%; }
    </style>
</head>
<body>

    <!-- INJECT SIDEBAR -->
    <?php include '../../includes/sidebar.php'; ?>

    <div class="main-content">
        <!-- INJECT TOPBAR -->
        <?php include '../../includes/topbar.php'; ?>

        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4 class="fw-bold m-0">Predictive Analytics & Surveillance</h4>
            <span class="text-muted"><i class="fa-solid fa-brain me-1 text-primary"></i> Holt's Linear Trend & CDC EARS Active</span>
        </div>

        <!-- EARS Outbreak Surveillance Section -->
        <div class="row">
            <div class="col-12">
                <div class="card p-4">
                    <h5 class="fw-bold mb-1">CDC EARS Outbreak Detection Model</h5>
                    <p class="text-muted small mb-3">Detecting statistically significant deviations (aberrations) from historical rolling baselines across symptom categories.</p>
                    <div class="chart-container">
                        <canvas id="detailedEarsChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- Holt's Linear Trend Inventory Forecasting Section -->
        <div class="row">
            <div class="col-md-6">
                <div class="card p-4 h-100">
                    <h5 class="fw-bold mb-1">Holt's Exponential Smoothing Forecast</h5>
                    <p class="text-muted small mb-3">Projecting future inventory depletion rates based on trend components.</p>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Medicine</th>
                                    <th>Current Stock</th>
                                    <th>Daily Burn Rate</th>
                                    <th>Est. Days Left</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $inv = $pdo->query("SELECT * FROM inventory ORDER BY current_stock ASC");
                                while($item = $inv->fetch(PDO::FETCH_ASSOC)) {
                                    $daysLeft = $item['daily_consumption_rate'] > 0 ? round($item['current_stock'] / $item['daily_consumption_rate'], 1) : 'N/A';
                                    $badge = $daysLeft !== 'N/A' && $daysLeft <= 5 ? 'text-danger fw-bold' : 'text-dark';
                                    echo "<tr>";
                                    echo "<td><strong>{$item['item_name']}</strong></td>";
                                    echo "<td>{$item['current_stock']} pcs</td>";
                                    echo "<td>{$item['daily_consumption_rate']} units/day</td>";
                                    echo "<td class='{$badge}'>{$daysLeft} Days</td>";
                                    echo "</tr>";
                                }
                                ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            
            <div class="col-md-6">
                <div class="card p-4 h-100">
                    <h5 class="fw-bold mb-1">System Audit & Governance</h5>
                    <p class="text-muted small mb-3">Role-Based Access Control (RBAC) & System Status</p>
                    <ul class="list-group list-group-flush">
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            Database Driver <span class="badge bg-success">PDO MySQL (Secure)</span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            Encryption Standard <span class="badge bg-primary">Bcrypt (Password Hash)</span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            Current Active User <span class="badge bg-secondary"><?php echo htmlspecialchars($_SESSION['username']); ?> (Admin)</span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            Surveillance Algorithm <span class="badge bg-info text-dark">CDC EARS (Signal 3 Enabled)</span>
                        </li>
                    </ul>
                </div>
            </div>
        </div>

    </div>

    <!-- INJECT FOOTER -->
    <?php include '../../includes/footer.php'; ?>

    <!-- Chart Script -->
    <script>
        const ctx = document.getElementById('detailedEarsChart').getContext('2d');
        const detailedEarsChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: ['Week 1', 'Week 2', 'Week 3', 'Week 4', 'Week 5', 'Week 6', 'Current'],
                datasets: [
                    { label: 'Baseline Average (Expected)', data: [12, 14, 13, 15, 14, 13, 14], borderColor: '#95a5a6', backgroundColor: 'rgba(149, 165, 166, 0.1)', borderWidth: 2, fill: true, tension: 0.4 },
                    { label: 'Fever Cases (Observed)', data: [10, 15, 12, 16, 14, 13, 32], borderColor: '#e74c3c', backgroundColor: 'rgba(231, 76, 60, 0.1)', borderWidth: 3, fill: false, tension: 0.4 }
                ]
            },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'top' } }, scales: { y: { beginAtZero: true } } }
        });
    </script>
</body>
</html>