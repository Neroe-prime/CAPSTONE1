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
    $category = trim($_POST['category'] ?? 'Medicine');
    $current_stock = !empty($_POST['current_stock']) ? (int)$_POST['current_stock'] : 0;
    $unit = trim($_POST['unit'] ?? 'Pieces');

    if (empty($item_name)) {
        $error_msg = "Item name is required.";
    } else {
        try {
            $stmt = $pdo->prepare("INSERT INTO inventory (item_name, category, current_stock, unit) VALUES (?, ?, ?, ?)");
            $stmt->execute([$item_name, $category, $current_stock, $unit]);
            
            header("Location: index.php?success=Inventory item added successfully!");
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
                    <p class="text-white-50 fs-6 mb-0">Register new medicine or medical supply into clinic stock.</p>
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
                    <label class="form-label text-muted small fw-bold text-uppercase">Item Name</label>
                    <input type="text" name="item_name" class="form-control" placeholder="e.g., Paracetamol 500mg, Mefenamic Acid, Bandages" required>
                </div>

                <div class="row mb-4">
                    <div class="col-md-6">
                        <label class="form-label text-muted small fw-bold text-uppercase">Category</label>
                        <select name="category" class="form-select" required>
                            <option value="Medicine">Medicine</option>
                            <option value="Supply">Medical Supply</option>
                            <option value="Equipment">Equipment</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label text-muted small fw-bold text-uppercase">Unit</label>
                        <select name="unit" class="form-select" required>
                            <option value="Pieces">Pieces / Tablets</option>
                            <option value="Bottles">Bottles</option>
                            <option value="Boxes">Boxes</option>
                            <option value="Packs">Packs</option>
                        </select>
                    </div>
                </div>

                <div class="mb-4">
                    <label class="form-label text-muted small fw-bold text-uppercase">Initial Stock Quantity</label>
                    <input type="number" name="current_stock" class="form-control" value="50" min="0" required>
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