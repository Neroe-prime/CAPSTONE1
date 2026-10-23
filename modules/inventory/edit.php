<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();
if(!isset($_SESSION['user_id'])){
    header("Location: ../../index.php?error=Please log in first");
    exit();
}
require_once '../../includes/db_connect.php';

$item_id = $_GET['id'] ?? '';
if (empty($item_id)) {
    header("Location: index.php?error=Invalid item ID");
    exit();
}

// Handle form submission for update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $item_name = trim($_POST['item_name'] ?? '');
    $current_stock = (int)($_POST['current_stock'] ?? 0);
    $reorder_level = (int)($_POST['reorder_level'] ?? 0);
    $daily_consumption_rate = (float)($_POST['daily_consumption_rate'] ?? 0);

    try {
        $update_stmt = $pdo->prepare("UPDATE inventory SET item_name = ?, current_stock = ?, reorder_level = ?, daily_consumption_rate = ? WHERE item_id = ?");
        $update_stmt->execute([$item_name, $current_stock, $reorder_level, $daily_consumption_rate, $item_id]);
        
        header("Location: index.php?success=Inventory item updated successfully!");
        exit();
    } catch (PDOException $e) {
        $error_msg = "Database error while updating inventory item.";
    }
}

// Fetch item details
try {
    $stmt = $pdo->prepare("SELECT * FROM inventory WHERE item_id = ?");
    $stmt->execute([$item_id]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$item) {
        header("Location: index.php?error=Inventory item not found");
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
    <title>Edit Inventory Item - MedFlow</title>
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

        .form-control, .form-select {
            border-radius: 12px;
            padding: 12px 16px;
            border: 1px solid #e2e8f0;
            background-color: #f8fafc;
        }
        .form-control:focus {
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
        
        <div class="card bg-gradient-purple p-5 mb-5 shadow-sm mt-3">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2 class="fw-bold mb-2 text-white"><i class="fa-solid fa-pen-to-square me-3"></i> Edit Inventory Item</h2>
                    <p class="text-white-50 fs-6 mb-0">Update stock quantities, reorder thresholds, and consumption metrics.</p>
                </div>
                <a href="index.php" class="btn btn-light fw-bold px-4 py-3 shadow text-primary rounded-pill">
                    <i class="fa-solid fa-arrow-left me-2"></i> Back to Inventory
                </a>
            </div>
        </div>

        <?php if (isset($error_msg)): ?>
            <div class="alert alert-danger shadow-sm border-0 rounded-4 p-4 mb-4"><?php echo htmlspecialchars($error_msg); ?></div>
        <?php endif; ?>

        <div class="card p-5">
            <form action="edit.php?id=<?php echo $item_id; ?>" method="POST">
                <div class="mb-4">
                    <label class="form-label text-muted small fw-bold text-uppercase"><i class="fa-solid fa-pills me-2 text-purple" style="color: #6f42c1;"></i> Medicine / Supply Name</label>
                    <input type="text" name="item_name" class="form-control fw-semibold" value="<?php echo htmlspecialchars($item['item_name']); ?>" required>
                </div>

                <div class="row mb-4">
                    <div class="col-md-6">
                        <label class="form-label text-muted small fw-bold text-uppercase"><i class="fa-solid fa-boxes-stacked me-2 text-purple" style="color: #6f42c1;"></i> Current Stock</label>
                        <input type="number" name="current_stock" class="form-control fw-semibold" value="<?php echo (int)$item['current_stock']; ?>" min="0" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label text-muted small fw-bold text-uppercase"><i class="fa-solid fa-triangle-exclamation me-2 text-purple" style="color: #6f42c1;"></i> Critical Reorder Level</label>
                        <input type="number" name="reorder_level" class="form-control fw-semibold" value="<?php echo (int)$item['reorder_level']; ?>" min="0" required>
                    </div>
                </div>

                <div class="mb-5">
                    <label class="form-label text-muted small fw-bold text-uppercase"><i class="fa-solid fa-chart-line me-2 text-purple" style="color: #6f42c1;"></i> Daily Consumption Rate (Holt's Data)</label>
                    <input type="number" step="0.01" name="daily_consumption_rate" class="form-control fw-semibold" value="<?php echo htmlspecialchars($item['daily_consumption_rate'] ?? 0); ?>" min="0" required>
                </div>

                <div class="d-flex justify-content-end gap-3">
                    <a href="index.php" class="btn btn-light px-4 py-3 rounded-pill fw-semibold text-muted border">Cancel</a>
                    <button type="submit" class="btn btn-primary px-5 py-3 rounded-pill fw-bold shadow-sm" style="background: linear-gradient(135deg, #6f42c1 0%, #4e73df 100%); border: none;">
                        <i class="fa-solid fa-floppy-disk me-2"></i> Update Item
                    </button>
                </div>
            </form>
        </div>
    </div>
    <?php include '../../includes/footer.php'; ?>
</body>
</html>