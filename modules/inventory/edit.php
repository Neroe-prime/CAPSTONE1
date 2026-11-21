<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../index.php?error=Please log in first");
    exit();
}

require_once '../../includes/db_connect.php';

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
    header("Location: index.php?error=Unauthorized action.");
    exit();
}

$item_id = $_GET['id'] ?? null;
if (!$item_id) {
    header("Location: index.php?error=Invalid item ID");
    exit();
}

// Fetch existing item details
$stmt = $pdo->prepare("SELECT * FROM inventory WHERE item_id = ?");
$stmt->execute([$item_id]);
$item = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$item) {
    header("Location: index.php?error=Inventory item not found");
    exit();
}

$error_msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $item_name = trim($_POST['item_name'] ?? '');
    $batch_number = trim($_POST['batch_number'] ?? '');
    $initial_stock = max(0, intval($_POST['initial_stock'] ?? 0));
    $current_stock = max(0, intval($_POST['current_stock'] ?? 0));
    $expiration_date = $_POST['expiration_date'] ?? null;
    $reorder_level = intval($_POST['reorder_level'] ?? 15); // Fixed policy value
    $daily_consumption = floatval($_POST['daily_consumption_rate'] ?? 0.00);

    if (empty($item_name) || empty($batch_number) || empty($expiration_date)) {
        $error_msg = "Item name, batch number, and expiration date are required.";
    } else {
        try {
            $pdo->beginTransaction();

            $lock_stmt = $pdo->prepare("SELECT current_stock FROM inventory WHERE item_id = ? FOR UPDATE");
            $lock_stmt->execute([$item_id]);
            $prev_stock = (int)$lock_stmt->fetchColumn();

            $update = $pdo->prepare("UPDATE inventory SET item_name = ?, batch_number = ?, initial_stock = ?, current_stock = ?, expiration_date = ?, reorder_level = ?, daily_consumption_rate = ? WHERE item_id = ?");
            $update->execute([$item_name, $batch_number, $initial_stock, $current_stock, $expiration_date, $reorder_level, $daily_consumption, $item_id]);

            if ($current_stock !== $prev_stock) {
                $diff = abs($current_stock - $prev_stock);
                $action_type = $current_stock > $prev_stock ? 'Restocked' : 'Adjusted';
                $audit_stmt = $pdo->prepare("
                    INSERT INTO inventory_audit 
                        (item_id, item_name, action_type, quantity, previous_stock, new_stock, reason_or_remarks, performed_by) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $audit_stmt->execute([
                    $item_id,
                    $item_name,
                    $action_type,
                    $diff,
                    $prev_stock,
                    $current_stock,
                    "Updated via Inventory Edit (Batch: {$batch_number})",
                    $performed_by
                ]);
            }

            $pdo->commit();
            header("Location: index.php?success=Inventory item updated successfully with fixed alert policy");
            exit();
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
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
        .form-control { border-radius: 12px; padding: 12px 16px; border: 1px solid #e2e8f0; background-color: #f8fafc; }
        .form-control:focus { background-color: #ffffff; border-color: #6f42c1; box-shadow: 0 0 0 4px rgba(111, 66, 193, 0.1); }
        .bg-gradient-purple {
            background: linear-gradient(135deg, #6f42c1 0%, #4e73df 100%);
            color: white;
            border-radius: 20px;
        }
    </style>
</head>
<body>

    <?php include '../../includes/sidebar.php'; ?>

    <div class="main-content">
        <?php include '../../includes/topbar.php'; ?>

        <div class="card bg-gradient-purple p-4 mb-4 shadow-sm mt-3">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h3 class="fw-bold mb-1 text-white"><i class="fa-solid fa-pen-to-square me-2"></i> Edit Inventory Item</h3>
                    <p class="text-white-50 mb-0 small">Update stock quantities, batch numbers, and expiration dates with fixed policy controls.</p>
                </div>
                <a href="index.php" class="btn btn-light fw-bold px-4 py-2 shadow text-primary rounded-pill">
                    <i class="fa-solid fa-arrow-left me-2"></i> Back to Inventory
                </a>
            </div>
        </div>

        <?php if (!empty($error_msg)): ?>
            <div class="alert alert-danger rounded-4 p-3 mb-4"><i class="fa-solid fa-triangle-exclamation me-2"></i><?php echo htmlspecialchars($error_msg); ?></div>
        <?php endif; ?>

        <div class="card p-5 col-lg-8 mx-auto">
            <form method="POST">
                <div class="mb-3">
                    <label class="form-label text-muted small fw-bold text-uppercase">Medicine / Supply Name</label>
                    <input type="text" name="item_name" class="form-control" value="<?php echo htmlspecialchars($item['item_name']); ?>" required>
                </div>

                <div class="mb-3">
                    <label class="form-label text-muted small fw-bold text-uppercase">Batch Number</label>
                    <input type="text" name="batch_number" class="form-control" value="<?php echo htmlspecialchars($item['batch_number'] ?? ''); ?>" placeholder="e.g., BN-2026-001" required>
                </div>

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label text-muted small fw-bold text-uppercase">Initial Stock</label>
                        <input type="number" name="initial_stock" class="form-control" value="<?php echo intval($item['initial_stock'] ?? 0); ?>" min="0" required>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label text-muted small fw-bold text-uppercase">Current Stock</label>
                        <input type="number" name="current_stock" class="form-control" value="<?php echo intval($item['current_stock']); ?>" min="0" required>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label text-muted small fw-bold text-uppercase">Expiration Date</label>
                    <input type="date" name="expiration_date" class="form-control" value="<?php echo htmlspecialchars($item['expiration_date'] ?? ''); ?>" required>
                </div>

                <div class="row">
                    <!-- Fixed Non-Editable Critical Reorder Level -->
                    <div class="col-md-6 mb-4">
                        <label class="form-label text-muted small fw-bold text-uppercase">Critical Reorder Level (System Policy)</label>
                        <input type="number" class="form-control bg-light text-muted" value="<?php echo intval($item['reorder_level']); ?>" readonly title="Alert levels are fixed by system policy and cannot be edited.">
                        <input type="hidden" name="reorder_level" value="<?php echo intval($item['reorder_level']); ?>">
                        <div class="form-text text-muted" style="font-size: 0.75rem;"><i class="fa-solid fa-lock me-1"></i> Fixed rule: Threshold cannot be mended.</div>
                    </div>
                    <div class="col-md-6 mb-4">
                        <label class="form-label text-muted small fw-bold text-uppercase">Daily Consumption Rate (Holt's Data)</label>
                        <input type="number" step="0.01" name="daily_consumption_rate" class="form-control" value="<?php echo htmlspecialchars($item['daily_consumption_rate'] ?? 0); ?>" min="0" required>
                    </div>
                </div>

                <div class="d-flex justify-content-end gap-2 pt-2">
                    <a href="index.php" class="btn btn-light px-4 py-2 rounded-pill fw-semibold">Cancel</a>
                    <button type="submit" class="btn btn-primary px-4 py-2 rounded-pill fw-semibold shadow-sm" style="background: linear-gradient(135deg, #6f42c1 0%, #4e73df 100%); border: none;">Update Item</button>
                </div>
            </form>
        </div>
    </div>

    <?php include '../../includes/footer.php'; ?>
</body>
</html>