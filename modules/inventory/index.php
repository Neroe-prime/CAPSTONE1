<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();
if(!isset($_SESSION['user_id'])){
    header("Location: ../../index.php?error=Please log in first");
    exit();
}
require_once '../../includes/db_connect.php';

// Handle Form Submission for Adding a New Medicine
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_item'])) {
    $item_name = trim($_POST['item_name']);
    $current_stock = (int)$_POST['current_stock'];
    $reorder_level = (int)$_POST['reorder_level'];

    try {
        $stmt = $pdo->prepare("INSERT INTO inventory (item_name, current_stock, reorder_level) VALUES (?, ?, ?)");
        $stmt->execute([$item_name, $current_stock, $reorder_level]);
        $success_msg = "Item successfully added to inventory!";
    } catch (PDOException $e) {
        $error_msg = "Error: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory - MedFlow</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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
        .card { border: none; border-radius: 10px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); }
    </style>
</head>
<body>

    <!-- INJECT SIDEBAR -->
    <?php include '../../includes/sidebar.php'; ?>

    <div class="main-content">
        <!-- INJECT TOPBAR -->
        <?php include '../../includes/topbar.php'; ?>

        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4 class="fw-bold m-0">Medical Supplies Inventory</h4>
            <button class="btn btn-primary fw-semibold" data-bs-toggle="modal" data-bs-target="#addItemModal">
                <i class="fa-solid fa-plus me-2"></i> Add New Item
            </button>
        </div>

        <?php if(isset($success_msg)): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fa-solid fa-check-circle me-2"></i> <?php echo $success_msg; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if(isset($error_msg)): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fa-solid fa-triangle-exclamation me-2"></i> <?php echo $error_msg; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Inventory Data Table -->
        <div class="card p-4">
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Item ID</th>
                            <th>Medicine / Supply Name</th>
                            <th>Current Stock</th>
                            <th>Critical Reorder Level</th>
                            <th>Daily Consumption (Holt's Data)</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $stmt = $pdo->query("SELECT * FROM inventory ORDER BY item_name ASC");
                        if ($stmt->rowCount() > 0) {
                            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                                
                                // Determine Stock Status
                                $statusBadge = "<span class='badge bg-success'>Healthy Stock</span>";
                                if ($row['current_stock'] <= $row['reorder_level']) {
                                    $statusBadge = "<span class='badge bg-danger'>Critical Low</span>";
                                } elseif ($row['current_stock'] <= ($row['reorder_level'] * 1.5)) {
                                    $statusBadge = "<span class='badge bg-warning text-dark'>Running Low</span>";
                                }

                                echo "<tr>";
                                echo "<td><span class='text-muted'>#{$row['item_id']}</span></td>";
                                echo "<td><strong>" . htmlspecialchars($row['item_name']) . "</strong></td>";
                                echo "<td class='fw-bold fs-5'>{$row['current_stock']}</td>";
                                echo "<td>{$row['reorder_level']}</td>";
                                echo "<td>{$row['daily_consumption_rate']} units/day</td>";
                                echo "<td>{$statusBadge}</td>";
                                echo "<td>
                                        <button class='btn btn-sm btn-outline-secondary'><i class='fa-solid fa-pen'></i> Update</button>
                                      </td>";
                                echo "</tr>";
                            }
                        } else {
                            echo "<tr><td colspan='7' class='text-center py-4 text-muted'>Inventory is currently empty.</td></tr>";
                        }
                        ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>

    <!-- ADD ITEM MODAL -->
    <div class="modal fade" id="addItemModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header border-0">
                    <h5 class="modal-title fw-bold">Add New Medical Supply</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form action="" method="POST">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Item Name</label>
                            <input type="text" name="item_name" class="form-control" placeholder="e.g., Paracetamol (500mg)" required>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-semibold">Initial Stock Quantity</label>
                                <input type="number" name="current_stock" class="form-control" min="0" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-semibold">Critical Reorder Level</label>
                                <input type="number" name="reorder_level" class="form-control" min="1" required>
                                <div class="form-text">System alerts when stock hits this number.</div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer border-0">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="add_item" class="btn btn-primary">Add to Inventory</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- INJECT FOOTER -->
    <?php include '../../includes/footer.php'; ?>
</body>
</html>