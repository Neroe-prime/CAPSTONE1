<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();
if (!isset($_SESSION['user_id'])) {
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

// Restrict access to Admin and Nurse only
if ($user_role !== 'Admin' && $user_role !== 'Nurse') {
    header("Location: index.php?error=Unauthorized action.");
    exit();
}

$error_msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $item_name = trim($_POST['item_name'] ?? '');
    $batch_number = trim($_POST['batch_number'] ?? '');
    $current_stock = !empty($_POST['current_stock']) ? (int)$_POST['current_stock'] : 0;
    $initial_stock = $current_stock; // Initial stock matches starting current stock
    $expiration_date = $_POST['expiration_date'] ?? null;
    $reorder_level = 15; // Fixed system-wide policy threshold for reorder alerts
    $daily_consumption = 1.00; // Default baseline for forecasting

    if (empty($item_name) || empty($batch_number) || empty($expiration_date)) {
        $error_msg = "Item name, batch number, and expiration date are required.";
    } else {
        try {
            $stmt = $pdo->prepare("INSERT INTO inventory (item_name, batch_number, current_stock, initial_stock, expiration_date, reorder_level, daily_consumption_rate) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$item_name, $batch_number, $current_stock, $initial_stock, $expiration_date, $reorder_level, $daily_consumption]);
            
            header("Location: index.php?success=Inventory item added successfully with fixed alert policy!");
            exit();
        } catch (PDOException $e) {
            $error_msg = "Database Error: " . $e->getMessage();
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
        .bg-gradient-purple { background: linear-gradient(135deg, #6f42c1 0%, #4e73df 100%); color: white; border-radius: 20px; }
        .form-control, .form-select { border-radius: 12px; padding: 12px 16px; border: 1px solid #e2e8f0; background-color: #f8fafc; }
        .form-control:focus, .form-select:focus { background-color: #ffffff; border-color: #6f42c1; box-shadow: 0 0 0 4px rgba(111, 66, 193, 0.1); }
    </style>
</head>
<body>
    <?php include '../../includes/sidebar.php'; ?>
    <div class="main-content">
        <?php include '../../includes/topbar.php'; ?>

        <div class="card bg-gradient-purple p-5 mb-5 shadow-sm mt-3">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2 class="fw-bold mb-2 text-white"><i class="fa-solid fa-pills me-3"></i> Add Inventory Item</h2>
                    <p class="text-white-50 fs-6 mb-0">Register new medical stock with strict batch tracking and fixed alert policies.</p>
                </div>
                <a href="index.php" class="btn btn-outline-light fw-bold px-4 py-2 rounded-pill shadow-sm">
                    <i class="fa-solid fa-arrow-left me-2"></i> Back to Inventory
                </a>
            </div>
        </div>

        <?php if (!empty($error_msg)): ?>
            <div class="alert alert-danger border-0 rounded-4 p-4 shadow-sm mb-4">
                <i class="fa-solid fa-triangle-exclamation me-2"></i> <?php echo htmlspecialchars($error_msg); ?>
            </div>
        <?php endif; ?>

        <div class="card p-5 col-lg-8 mx-auto">
            <form action="" method="POST">
                <div class="mb-4">
                    <label class="form-label text-muted small fw-bold text-uppercase">Medicine / Supply Name</label>
                    <input type="text" name="item_name" class="form-control" placeholder="e.g., Paracetamol 500mg" required>
                </div>

                <div class="mb-4">
                    <label class="form-label text-muted small fw-bold text-uppercase">Batch Number</label>
                    <input type="text" name="batch_number" class="form-control" placeholder="e.g., BN-2026-001" required>
                </div>

                <div class="row mb-4">
                    <div class="col-md-6">
                        <label class="form-label text-muted small fw-bold text-uppercase">Initial / Current Stock Quantity</label>
                        <input type="number" name="current_stock" class="form-control" value="50" min="0" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label text-muted small fw-bold text-uppercase">Expiration Date</label>
                        <input type="date" name="expiration_date" class="form-control" required>
                    </div>
                </div>

                <!-- Fixed Reorder Level Policy (Non-Editable / Read-only) -->
                <div class="mb-4">
                    <label class="form-label text-muted small fw-bold text-uppercase">Critical Reorder Level (System Policy)</label>
                    <input type="number" class="form-control bg-light text-muted" value="15" readonly title="System reorder thresholds are fixed by institutional clinic policy and cannot be altered.">
                    <div class="form-text text-muted small mt-1"><i class="fa-solid fa-lock me-1"></i> Fixed rule: Alerts trigger automatically when stock falls below 15 units.</div>
                </div>

                <div class="d-flex justify-content-end gap-3 pt-3 border-top">
                    <a href="index.php" class="btn btn-light px-4 py-2 rounded-pill fw-semibold">Cancel</a>
                    <button type="submit" class="btn btn-primary px-5 py-2 rounded-pill fw-semibold shadow-sm" style="background: linear-gradient(135deg, #6f42c1 0%, #4e73df 100%); border: none;">
                        <i class="fa-solid fa-save me-2"></i> Save Item
                    </button>
                </div>
            </form>
        </div>
    </div>
    <?php include '../../includes/footer.php'; ?>
</body>
</html>