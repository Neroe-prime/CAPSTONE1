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
    <title>Medical Supplies Inventory - MedFlow</title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>
        body { background-color: #f4f5fa; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; overflow-x: hidden; }
        .sidebar { width: 250px; height: 100vh; background-color: #1a252f; color: #ecf0f1; position: fixed; padding-top: 20px; top: 0; left: 0; z-index: 1000; }
        .sidebar .brand { font-size: 1.5rem; font-weight: bold; text-align: center; margin-bottom: 30px; color: #3498db; }
        .sidebar a { padding: 15px 25px; text-decoration: none; font-size: 1.1rem; color: #bdc3c7; display: block; transition: 0.3s; }
        .sidebar a:hover, .sidebar a.active { color: #ffffff; background-color: #2c3e50; border-left: 4px solid #3498db; }
        
        .main-content { margin-left: 250px; padding: 45px; width: calc(100% - 250px); }
        .card { border: none; border-radius: 20px; box-shadow: 0 8px 25px rgba(111, 66, 193, 0.07); }
        
        .bg-gradient-purple {
            background: linear-gradient(135deg, #6f42c1 0%, #4e73df 100%);
            color: white;
            border-radius: 20px;
        }
        
        .table {
            border-collapse: separate;
            border-spacing: 0 12px;
            margin-top: -10px;
        }
        .table thead th {
            background-color: transparent;
            color: #6c757d;
            font-weight: 700;
            text-transform: uppercase;
            font-size: 0.8rem;
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
            border-top-left-radius: 14px;
            border-bottom-left-radius: 14px;
        }
        .table tbody td:last-child {
            border-right: 1px solid #f1f3f8;
            border-top-right-radius: 14px;
            border-bottom-right-radius: 14px;
        }

        .btn-action-edit { background-color: #fefcbf; color: #d69e2e; border: none; border-radius: 10px; transition: all 0.2s; }
        .btn-action-edit:hover { background-color: #d69e2e; color: white; }
        
        .btn-action-delete { background-color: #fff5f5; color: #e53e3e; border: none; border-radius: 10px; transition: all 0.2s; }
        .btn-action-delete:hover { background-color: #e53e3e; color: white; }
    </style>
</head>
<body>

    <?php include '../../includes/sidebar.php'; ?>

    <div class="main-content">
        
        <?php include '../../includes/topbar.php'; ?>

        <div class="card bg-gradient-purple p-5 mb-5 shadow-sm mt-3">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2 class="fw-bold mb-2 text-white"><i class="fa-solid fa-pills me-3"></i> Medical Supplies Inventory</h2>
                    <p class="text-white-50 fs-6 mb-0">Monitor stock levels, reorder thresholds, and consumption rates.</p>
                </div>
                <a href="create.php" class="btn btn-light fw-bold px-4 py-3 shadow text-primary rounded-pill">
                    <i class="fa-solid fa-plus me-2"></i> Add New Item
                </a>
            </div>
        </div>

        <?php if (isset($_GET['success'])): ?>
            <div class="alert alert-success shadow-sm border-0 alert-dismissible fade show mb-4 rounded-4 p-4" role="alert">
                <i class="fa-solid fa-circle-check me-2"></i> <?php echo htmlspecialchars($_GET['success']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <?php if (isset($_GET['error'])): ?>
            <div class="alert alert-danger shadow-sm border-0 alert-dismissible fade show mb-4 rounded-4 p-4" role="alert">
                <i class="fa-solid fa-triangle-exclamation me-2"></i> <?php echo htmlspecialchars($_GET['error']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <div class="card p-4">
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead>
                        <tr>
                            <th class="ps-4">Item ID</th>
                            <th>Medicine / Supply Name</th>
                            <th>Current Stock</th>
                            <th>Critical Reorder Level</th>
                            <th>Daily Consumption (Holt's Data)</th>
                            <th>Status</th>
                            <th class="text-end pe-4">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        try {
                            $stmt = $pdo->query("SELECT * FROM inventory ORDER BY item_id DESC");
                            
                            if ($stmt->rowCount() > 0) {
                                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                                    $stock = (int)$row['current_stock'];
                                    $reorder = (int)$row['reorder_level'];
                                    
                                    if ($stock <= $reorder) {
                                        $statusBadge = "<span class='badge bg-danger bg-opacity-10 text-danger border border-danger-subtle px-3 py-2 rounded-pill fw-semibold'>Low Stock</span>";
                                    } else {
                                        $statusBadge = "<span class='badge bg-success bg-opacity-10 text-success border border-success-subtle px-3 py-2 rounded-pill fw-semibold'>Healthy Stock</span>";
                                    }
                                    
                                    echo "<tr>";
                                    echo "<td class='ps-4'><span class='badge bg-purple bg-opacity-10 text-purple border px-3 py-2 rounded-pill fw-semibold' style='background-color: #f3f0ff !important; color: #6f42c1 !important;'>#" . htmlspecialchars($row['item_id']) . "</span></td>";
                                    echo "<td><strong class='text-dark fs-6'>" . htmlspecialchars($row['item_name']) . "</strong></td>";
                                    echo "<td><span class='fw-bold fs-6'>" . $stock . "</span></td>";
                                    echo "<td>" . $reorder . "</td>";
                                    echo "<td class='text-muted'>" . number_format($row['daily_consumption_rate'] ?? 0, 2) . " units/day</td>";
                                    echo "<td>{$statusBadge}</td>";
                                    echo "<td class='text-end pe-4'>
                                            <div class='d-flex justify-content-end gap-2'>
                                                <a href='edit.php?id=" . $row['item_id'] . "' class='btn btn-sm btn-action-edit px-3 py-2' title='Update Item'>
                                                    <i class='fa-solid fa-pen-to-square'></i> Update
                                                </a>
                                                <a href='delete.php?id=" . $row['item_id'] . "' class='btn btn-sm btn-action-delete px-3 py-2' title='Delete Item' onclick='return confirm(\"Are you sure you want to delete this inventory item?\");'>
                                                    <i class='fa-solid fa-trash'></i>
                                                </a>
                                            </div>
                                          </td>";
                                    echo "</tr>";
                                }
                            } else {
                                echo "<tr><td colspan='7' class='text-center text-muted py-5'>
                                        <i class='fa-solid fa-boxes-stacked fa-3x mb-3 text-secondary opacity-25'></i>
                                        <p class='mb-0 fw-medium'>No inventory items found.</p>
                                      </td></tr>";
                            }
                        } catch (PDOException $e) {
                            echo "<tr><td colspan='7' class='text-center text-danger py-4'>Error loading inventory data.</td></tr>";
                        }
                        ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <?php include '../../includes/footer.php'; ?>
    
</body>
</html>