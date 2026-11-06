    <?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../index.php?error=Please log in first");
    exit();
}

require_once '../../includes/db_connect.php';

$error_msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $item_name = trim($_POST['item_name'] ?? '');
    $batch_number = trim($_POST['batch_number'] ?? '');
    $initial_stock = intval($_POST['initial_stock'] ?? 0);
    $current_stock = intval($_POST['current_stock'] ?? 0);
    $expiration_date = $_POST['expiration_date'] ?? null;
    $reorder_level = intval($_POST['reorder_level'] ?? 10);
    $daily_consumption = floatval($_POST['daily_consumption'] ?? 0.00);

    if (empty($item_name) || empty($batch_number) || empty($expiration_date)) {
        $error_msg = "Item name, batch number, and expiration date are required.";
    } else {
        try {
            $stmt = $pdo->prepare("INSERT INTO inventory (item_name, batch_number, current_stock, initial_stock, expiration_date, reorder_level, daily_consumption) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$item_name, $batch_number, $current_stock, $initial_stock, $expiration_date, $reorder_level, $daily_consumption]);
            
            header("Location: index.php?success=Inventory item added successfully");
            exit();
        } catch (PDOException $e) {
            $error_msg = "Database error: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Inventory Item - MedFlow</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    
    <style>
        body { background-color: #f8f9fa; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .sidebar { background-color: #1a222c; min-height: 100vh; color: #fff; }
        .sidebar .nav-link { color: #a0aec0; padding: 12px 20px; margin-bottom: 5px; border-radius: 8px; }
        .sidebar .nav-link:hover, .sidebar .nav-link.active { background-color: #2d3748; color: #fff; }
        .card-custom { border-radius: 15px; border: none; box-shadow: 0 4px 6px rgba(0,0,0,0.05); }
        .btn-purple { background-color: #6366f1; color: white; border: none; }
        .btn-purple:hover { background-color: #4f46e5; color: white; }
    </style>
</head>
<body>

<div class="container-fluid">
    <div class="row">
        <!-- Sidebar -->
        <div class="col-md-2 sidebar p-3">
            <h4 class="text-info fw-bold mb-4 mt-2"><i class="bi bi-heart-pulse-fill"></i> MedFlow</h4>
            <ul class="nav flex-column">
                <li class="nav-item"><a class="nav-link" href="../dashboard/index.php"><i class="bi bi-house-door me-2"></i> Dashboard</a></li>
                <li class="nav-item"><a class="nav-link" href="../records/index.php"><i class="bi bi-people me-2"></i> Student Records</a></li>
                <li class="nav-item"><a class="nav-link" href="../consultations/index.php"><i class="bi bi-stethoscope me-2"></i> Consultations</a></li>
                <li class="nav-item"><a class="nav-link active" href="../inventory/index.php"><i class="bi bi-box-seam me-2"></i> Inventory</a></li>
                <li class="nav-item"><a class="nav-link" href="../analytics/index.php"><i class="bi bi-graph-up me-2"></i> Analytics</a></li>
                <li class="nav-item"><a class="nav-link" href="../settings/index.php"><i class="bi bi-gear me-2"></i> Settings</a></li>
            </ul>
        </div>

        <!-- Main Content -->
        <div class="col-md-10 p-4">
            <div class="row justify-content-center">
                <div class="col-md-7">
                    <div class="card card-custom p-4 mt-3">
                        <div class="card-body">
                            <h3 class="fw-bold mb-4"><i class="bi bi-plus-circle text-primary me-2"></i> Add New Inventory Item</h3>
                            
                            <?php if (!empty($error_msg)): ?>
                                <div class="alert alert-danger rounded-3 mb-4 small"><i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo htmlspecialchars($error_msg); ?></div>
                            <?php endif; ?>

                            <form action="" method="POST">
                                <!-- Item Name -->
                                <div class="mb-3">
                                    <label for="item_name" class="form-label fw-bold text-muted small">ITEM NAME</label>
                                    <input type="text" class="form-control py-2" id="item_name" name="item_name" placeholder="e.g., Paracetamol 500mg" required>
                                </div>

                                <!-- Batch Number -->
                                <div class="mb-3">
                                    <label for="batch_number" class="form-label fw-bold text-muted small">BATCH NUMBER</label>
                                    <input type="text" class="form-control py-2" id="batch_number" name="batch_number" placeholder="e.g., BN-2026-001" required>
                                </div>

                                <!-- Stock Quantities -->
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="initial_stock" class="form-label fw-bold text-muted small">INITIAL STOCK</label>
                                        <input type="number" class="form-control py-2" id="initial_stock" name="initial_stock" min="0" value="100" required>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label for="current_stock" class="form-label fw-bold text-muted small">CURRENT STOCK</label>
                                        <input type="number" class="form-control py-2" id="current_stock" name="current_stock" min="0" value="100" required>
                                    </div>
                                </div>

                                <!-- Expiration Date -->
                                <div class="mb-3">
                                    <label for="expiration_date" class="form-label fw-bold text-muted small">EXPIRATION DATE</label>
                                    <input type="date" class="form-control py-2" id="expiration_date" name="expiration_date" required>
                                </div>

                                <!-- Reorder Level & Daily Consumption -->
                                <div class="row">
                                    <div class="col-md-6 mb-4">
                                        <label for="reorder_level" class="form-label fw-bold text-muted small">REORDER LEVEL</label>
                                        <input type="number" class="form-control py-2" id="reorder_level" name="reorder_level" min="0" value="10">
                                    </div>
                                    <div class="col-md-6 mb-4">
                                        <label for="daily_consumption" class="form-label fw-bold text-muted small">DAILY CONSUMPTION</label>
                                        <input type="number" step="0.01" class="form-control py-2" id="daily_consumption" name="daily_consumption" min="0" value="2.00">
                                    </div>
                                </div>

                                <!-- Buttons -->
                                <div class="d-flex justify-content-end gap-2">
                                    <a href="index.php" class="btn btn-light fw-bold px-4">Cancel</a>
                                    <button type="submit" class="btn btn-purple fw-bold px-4">Save Item</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>