<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../index.php?error=Please log in first");
    exit();
}

// Prevent browser caching after logout so 'Back' button won't show inventory
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

require_once '../../includes/db_connect.php';

// Fetch current user role and username
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

// Restrict access to Admin and Nurse only
if ($user_role !== 'Admin' && $user_role !== 'Nurse') {
    header("Location: ../dashboard/index.php?error=Unauthorized access to inventory");
    exit();
}

$success_msg = $_GET['success'] ?? '';
$error_msg = $_GET['error'] ?? '';

// Handle Disposal / Wastage Logging (Disposed/Thrown) with FOR UPDATE concurrency lock
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['log_disposal'])) {
    $item_id = (int)($_POST['item_id'] ?? 0);
    $dispose_qty = (int)($_POST['dispose_qty'] ?? 0);
    $reason = trim($_POST['reason_or_remarks'] ?? '');

    if ($item_id <= 0 || $dispose_qty <= 0 || empty($reason)) {
        $error_msg = "Please select a valid item, enter a disposal quantity of at least 1, and specify the reason/remarks.";
    } else {
        try {
            $pdo->beginTransaction();

            $lock_stmt = $pdo->prepare("SELECT item_id, item_name, current_stock, batch_number FROM inventory WHERE item_id = ? FOR UPDATE");
            $lock_stmt->execute([$item_id]);
            $locked_item = $lock_stmt->fetch(PDO::FETCH_ASSOC);

            if (!$locked_item) {
                throw new Exception("Inventory item not found.");
            }

            $prev_stock = (int)$locked_item['current_stock'];
            if ($dispose_qty > $prev_stock) {
                throw new Exception("Cannot dispose {$dispose_qty} pcs of {$locked_item['item_name']}. Only {$prev_stock} pcs currently in stock.");
            }

            $new_stock = $prev_stock - $dispose_qty;

            $upd_stmt = $pdo->prepare("UPDATE inventory SET current_stock = ? WHERE item_id = ?");
            $upd_stmt->execute([$new_stock, $item_id]);

            $audit_stmt = $pdo->prepare("
                INSERT INTO inventory_audit 
                    (item_id, item_name, action_type, quantity, previous_stock, new_stock, reason_or_remarks, performed_by) 
                VALUES (?, ?, 'Disposed/Thrown', ?, ?, ?, ?, ?)
            ");
            $full_remarks = "Batch: " . ($locked_item['batch_number'] ?: 'N/A') . " | Reason: " . $reason;
            $audit_stmt->execute([
                $locked_item['item_id'],
                $locked_item['item_name'],
                $dispose_qty,
                $prev_stock,
                $new_stock,
                $full_remarks,
                $performed_by
            ]);

            $pdo->commit();
            $success_msg = "Logged disposal of {$dispose_qty} pcs ({$locked_item['item_name']}) and recorded in Inventory Audit Trail.";
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error_msg = $e->getMessage();
        }
    }
}

// Fetch all inventory items once so both the stock table and disposal modal can use them
$inventory_items = [];
try {
    $inv_stmt = $pdo->query("SELECT * FROM inventory ORDER BY item_id DESC");
    $inventory_items = $inv_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error_msg = $error_msg ?: ("Error loading inventory data: " . $e->getMessage());
}

// Fetch audit trail records
$audit_logs = [];
try {
    $aud_stmt = $pdo->query("SELECT * FROM inventory_audit ORDER BY created_at DESC LIMIT 200");
    $audit_logs = $aud_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error_msg = $error_msg ?: ("Error loading inventory audit trail: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Medical Supplies Inventory & Audit - MedFlow</title>
    
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
            padding: 15px 15px;
        }
        .table tbody tr {
            background-color: #ffffff;
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.02);
        }
        .table tbody td {
            padding: 18px 15px;
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
        
        .btn-action-dispose { background-color: #fffaf0; color: #dd6b20; border: none; border-radius: 10px; transition: all 0.2s; }
        .btn-action-dispose:hover { background-color: #dd6b20; color: white; }

        .btn-action-delete { background-color: #fff5f5; color: #e53e3e; border: none; border-radius: 10px; transition: all 0.2s; }
        .btn-action-delete:hover { background-color: #e53e3e; color: white; }

        .nav-pills .nav-link {
            border-radius: 50px;
            font-weight: 600;
            color: #4a5568;
            padding: 10px 22px;
        }
        .nav-pills .nav-link.active {
            background: linear-gradient(135deg, #6f42c1 0%, #4e73df 100%);
            color: #ffffff;
        }

        @media print {
            .sidebar, .top-navbar, .no-print, .btn, .nav-pills, .alert { display: none !important; }
            .main-content { margin-left: 0 !important; width: 100% !important; padding: 15px !important; }
            .card { box-shadow: none !important; border: 1px solid #dee2e6 !important; }
        }
    </style>
</head>
<body>

    <?php include '../../includes/sidebar.php'; ?>

    <div class="main-content">
        
        <?php include '../../includes/topbar.php'; ?>

        <div class="card bg-gradient-purple p-5 mb-5 shadow-sm mt-3">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
                <div>
                    <h2 class="fw-bold mb-2 text-white"><i class="fa-solid fa-pills me-3"></i> Medical Supplies Inventory & Audit</h2>
                    <p class="text-white-50 fs-6 mb-0">Monitor stock levels, log expired/thrown disposals, and review accounting audit trails.</p>
                </div>
                <div class="d-flex flex-wrap gap-2 no-print">
                    <button type="button" class="btn btn-outline-light fw-bold px-4 py-3 rounded-pill shadow-sm" data-bs-toggle="modal" data-bs-target="#disposeModal">
                        <i class="fa-solid fa-trash-can-arrow-up me-2"></i> Log Disposal / Throw
                    </button>
                    <button type="button" class="btn btn-outline-light fw-bold px-4 py-3 rounded-pill shadow-sm" onclick="window.print();">
                        <i class="fa-solid fa-print me-2"></i> Print Audit Report
                    </button>
                    <a href="create.php" class="btn btn-light fw-bold px-4 py-3 shadow text-primary rounded-pill">
                        <i class="fa-solid fa-plus me-2"></i> Add New Item
                    </a>
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

        <!-- Module Navigation Tabs -->
        <ul class="nav nav-pills mb-4 gap-2" id="inventoryTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="stock-tab" data-bs-toggle="pill" data-bs-target="#stock-panel" type="button" role="tab">
                    <i class="fa-solid fa-boxes-stacked me-2"></i> Current Stock (<?php echo count($inventory_items); ?>)
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="audit-tab" data-bs-toggle="pill" data-bs-target="#audit-panel" type="button" role="tab">
                    <i class="fa-solid fa-clipboard-list me-2"></i> Inventory Audit Trail (<?php echo count($audit_logs); ?>)
                </button>
            </li>
        </ul>

        <div class="tab-content" id="inventoryTabsContent">
            <!-- TAB 1: INVENTORY STOCK LIST -->
            <div class="tab-pane fade show active" id="stock-panel" role="tabpanel">
                <div class="card p-4">
                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
                            <thead>
                                <tr>
                                    <th class="ps-4">Item ID</th>
                                    <th>Medicine / Supply Name</th>
                                    <th>Batch Number</th>
                                    <th>Initial Stock</th>
                                    <th>Current Stock</th>
                                    <th>Expiration Date</th>
                                    <th>Stock Status</th>
                                    <th class="text-end pe-4 no-print">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                if (count($inventory_items) > 0) {
                                    $today = date('Y-m-d');
                                    $warning_date = date('Y-m-d', strtotime('+30 days'));

                                    foreach ($inventory_items as $row) {
                                        $stock = (int)$row['current_stock'];
                                        $initial = (int)($row['initial_stock'] ?? 0);
                                        $reorder = (int)$row['reorder_level'];
                                        $batch = !empty($row['batch_number']) ? $row['batch_number'] : 'N/A';
                                        $expiry = $row['expiration_date'] ?? null;
                                        
                                        // Supply Status Badge (Sufficient / Low Stock / Out of Stock)
                                        if ($stock <= 0) {
                                            $stockBadge = "<span class='badge bg-dark bg-opacity-10 text-dark border border-dark-subtle px-3 py-2 rounded-pill fw-semibold'>Out of Stock</span>";
                                        } elseif ($stock <= $reorder) {
                                            $stockBadge = "<span class='badge bg-danger bg-opacity-10 text-danger border border-danger-subtle px-3 py-2 rounded-pill fw-semibold'>Low Stock</span>";
                                        } else {
                                            $stockBadge = "<span class='badge bg-success bg-opacity-10 text-success border border-success-subtle px-3 py-2 rounded-pill fw-semibold'>Sufficient</span>";
                                        }

                                        // Expiry Status Badge Logic
                                        $expiryBadge = "<span class='text-muted'>N/A</span>";
                                        if (!empty($expiry)) {
                                            if ($expiry < $today) {
                                                $expiryBadge = "<span class='badge bg-danger px-2 py-1 rounded-pill'>Expired (" . htmlspecialchars($expiry) . ")</span>";
                                            } elseif ($expiry <= $warning_date) {
                                                $expiryBadge = "<span class='badge bg-warning text-dark px-2 py-1 rounded-pill'>Expiring Soon (" . htmlspecialchars($expiry) . ")</span>";
                                            } else {
                                                $expiryBadge = "<span class='text-dark fw-medium'>" . htmlspecialchars($expiry) . "</span>";
                                            }
                                        }
                                        
                                        echo "<tr>";
                                        echo "<td class='ps-4'><span class='badge bg-purple bg-opacity-10 text-purple border px-3 py-2 rounded-pill fw-semibold' style='background-color: #f3f0ff !important; color: #6f42c1 !important;'>#" . htmlspecialchars($row['item_id']) . "</span></td>";
                                        echo "<td><strong class='text-dark fs-6'>" . htmlspecialchars($row['item_name']) . "</strong></td>";
                                        echo "<td><code class='text-secondary fw-bold'>" . htmlspecialchars($batch) . "</code></td>";
                                        echo "<td>" . $initial . "</td>";
                                        echo "<td><span class='fw-bold fs-6'>" . $stock . "</span></td>";
                                        echo "<td>{$expiryBadge}</td>";
                                        echo "<td>{$stockBadge}</td>";
                                        echo "<td class='text-end pe-4 no-print'>
                                                <div class='d-flex justify-content-end gap-2'>
                                                    <a href='edit.php?id=" . (int)$row['item_id'] . "' class='btn btn-sm btn-action-edit px-3 py-2' title='Update Item'>
                                                        <i class='fa-solid fa-pen-to-square'></i> Update
                                                    </a>
                                                    <button type='button' class='btn btn-sm btn-action-dispose px-3 py-2' title='Log Disposal / Throw' onclick='openDisposeModal(" . (int)$row['item_id'] . ", " . json_encode($row['item_name']) . ", " . $stock . ")'>
                                                        <i class='fa-solid fa-trash-can-arrow-up'></i> Dispose
                                                    </button>
                                                    <a href='delete.php?id=" . (int)$row['item_id'] . "' class='btn btn-sm btn-action-delete px-3 py-2' title='Delete Item' onclick='return confirm(\"Are you sure you want to delete this inventory item?\");'>
                                                        <i class='fa-solid fa-trash'></i>
                                                    </a>
                                                </div>
                                              </td>";
                                        echo "</tr>";
                                    }
                                } else {
                                    echo "<tr><td colspan='8' class='text-center text-muted py-5'>
                                            <i class='fa-solid fa-boxes-stacked fa-3x mb-3 text-secondary opacity-25'></i>
                                            <p class='mb-0 fw-medium'>No inventory items found.</p>
                                          </td></tr>";
                                }
                                ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- TAB 2: INVENTORY AUDIT TRAIL (FOR ACCOUNTING & MANAGEMENT) -->
            <div class="tab-pane fade" id="audit-panel" role="tabpanel">
                <div class="card p-4">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <div>
                            <h5 class="fw-bold text-dark mb-1"><i class="fa-solid fa-clipboard-check me-2 text-primary"></i> Inventory Movement & Disposal Audit Log</h5>
                            <p class="text-muted small mb-0">Tracks all dispensed medicines, restocks, disposed/thrown items, and manual stock adjustments.</p>
                        </div>
                        <button type="button" class="btn btn-outline-primary btn-sm rounded-pill px-3 py-2 fw-semibold no-print" onclick="window.print();">
                            <i class="fa-solid fa-print me-1"></i> Print Audit Sheet
                        </button>
                    </div>

                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
                            <thead>
                                <tr>
                                    <th class="ps-4">Date & Time</th>
                                    <th>Item Name</th>
                                    <th>Action</th>
                                    <th>Qty</th>
                                    <th>Before &rarr; After</th>
                                    <th>Remarks / Reason</th>
                                    <th class="pe-4">Staff</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($audit_logs) > 0): ?>
                                    <?php foreach ($audit_logs as $log): ?>
                                        <?php
                                        $actionType = $log['action_type'];
                                        $actionBadge = 'bg-secondary bg-opacity-10 text-secondary';
                                        if ($actionType === 'Dispensed') {
                                            $actionBadge = 'bg-primary bg-opacity-10 text-primary border border-primary-subtle';
                                        } elseif ($actionType === 'Restocked') {
                                            $actionBadge = 'bg-success bg-opacity-10 text-success border border-success-subtle';
                                        } elseif ($actionType === 'Disposed/Thrown') {
                                            $actionBadge = 'bg-danger bg-opacity-10 text-danger border border-danger-subtle';
                                        } elseif ($actionType === 'Adjusted') {
                                            $actionBadge = 'bg-warning bg-opacity-10 text-warning border border-warning-subtle';
                                        }
                                        $logDate = date('M d, Y h:i A', strtotime($log['created_at']));
                                        ?>
                                        <tr>
                                            <td class="ps-4"><span class="fw-semibold text-dark"><?php echo htmlspecialchars($logDate); ?></span></td>
                                            <td><strong><?php echo htmlspecialchars($log['item_name']); ?></strong></td>
                                            <td><span class="badge <?php echo $actionBadge; ?> px-3 py-2 rounded-pill fw-semibold"><?php echo htmlspecialchars($actionType); ?></span></td>
                                            <td><strong class="text-dark"><?php echo (int)$log['quantity']; ?> pcs</strong></td>
                                            <td><span class="text-muted"><?php echo (int)$log['previous_stock']; ?></span> &rarr; <strong class="text-dark"><?php echo (int)$log['new_stock']; ?></strong></td>
                                            <td><?php echo htmlspecialchars($log['reason_or_remarks'] ?? '—'); ?></td>
                                            <td class="pe-4"><span class="badge bg-light text-dark border px-3 py-2 rounded-pill"><?php echo htmlspecialchars($log['performed_by']); ?></span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="7" class="text-center text-muted py-5">
                                            <i class="fa-solid fa-file-lines fa-3x mb-3 text-secondary opacity-25"></i>
                                            <p class="mb-0 fw-medium">No inventory audit entries recorded yet.</p>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- LOG DISPOSAL / WASTAGE MODAL -->
    <div class="modal fade" id="disposeModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg rounded-4">
                <div class="modal-header border-0 text-white rounded-top-4 p-4" style="background: linear-gradient(135deg, #dd6b20 0%, #e53e3e 100%);">
                    <h5 class="modal-title fw-bold"><i class="fa-solid fa-trash-can-arrow-up me-2"></i> Log Disposed / Thrown Stock</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form action="" method="POST">
                    <div class="modal-body p-4">
                        <input type="hidden" name="log_disposal" value="1">
                        <div class="mb-3">
                            <label class="form-label text-muted small fw-bold text-uppercase">Select Inventory Item</label>
                            <select name="item_id" id="dispose_item_id" class="form-select rounded-3" required onchange="syncMaxDispose()">
                                <option value="">-- Choose Medicine / Supply --</option>
                                <?php foreach ($inventory_items as $inv): ?>
                                    <option value="<?php echo (int)$inv['item_id']; ?>" data-stock="<?php echo (int)$inv['current_stock']; ?>">
                                        <?php echo htmlspecialchars($inv['item_name']); ?> (Current Stock: <?php echo (int)$inv['current_stock']; ?> pcs)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label text-muted small fw-bold text-uppercase">Quantity Disposed / Thrown</label>
                            <input type="number" name="dispose_qty" id="dispose_qty" class="form-control rounded-3" min="1" value="1" required>
                            <div class="form-text text-muted small" id="dispose_stock_hint">Select an item to view available stock.</div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label text-muted small fw-bold text-uppercase">Reason / Accounting Remarks</label>
                            <textarea name="reason_or_remarks" class="form-control rounded-3" rows="2" placeholder="e.g., Expired batch, damaged packaging, contaminated supply" required></textarea>
                        </div>
                    </div>
                    <div class="modal-footer border-0 bg-light rounded-bottom-4 p-3">
                        <button type="button" class="btn btn-secondary btn-sm px-4 rounded-pill" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger btn-sm px-4 rounded-pill fw-semibold">Confirm Disposal & Log Audit</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php include '../../includes/footer.php'; ?>

    <script>
        function openDisposeModal(itemId, itemName, currentStock) {
            const selectEl = document.getElementById('dispose_item_id');
            const qtyEl = document.getElementById('dispose_qty');
            const hintEl = document.getElementById('dispose_stock_hint');

            selectEl.value = String(itemId);
            qtyEl.value = 1;
            qtyEl.max = Math.max(1, currentStock);
            hintEl.textContent = 'Available stock for ' + itemName + ': ' + currentStock + ' pcs.';

            const modal = new bootstrap.Modal(document.getElementById('disposeModal'));
            modal.show();
        }

        function syncMaxDispose() {
            const selectEl = document.getElementById('dispose_item_id');
            const qtyEl = document.getElementById('dispose_qty');
            const hintEl = document.getElementById('dispose_stock_hint');
            const selectedOption = selectEl.options[selectEl.selectedIndex];

            if (selectedOption && selectedOption.dataset.stock !== undefined) {
                const stock = parseInt(selectedOption.dataset.stock, 10) || 0;
                qtyEl.max = Math.max(1, stock);
                hintEl.textContent = 'Available stock: ' + stock + ' pcs.';
            } else {
                qtyEl.removeAttribute('max');
                hintEl.textContent = 'Select an item to view available stock.';
            }
        }
    </script>
</body>
</html>