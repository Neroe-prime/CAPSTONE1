<?php
$notif_inventory = [];
$notif_count = 0;

if (isset($pdo)) {
    try {
        $inv_stmt = $pdo->query("SELECT item_name, current_stock, reorder_level FROM inventory WHERE current_stock <= reorder_level LIMIT 3");
        $notif_inventory = $inv_stmt->fetchAll(PDO::FETCH_ASSOC);
        $notif_count += count($notif_inventory);
    } catch (Exception $e) {}
}
?>
<div class="top-navbar d-flex justify-content-between align-items-center">
    <form action="../../modules/search/index.php" method="GET" class="input-group" style="max-width: 400px;">
        <span class="input-group-text bg-white border-end-0"><i class="fa-solid fa-magnifying-glass text-muted"></i></span>
        <input type="text" name="q" class="form-control border-start-0 shadow-none" placeholder="Search student ID or name..." required>
    </form>
    
    <div class="d-flex align-items-center gap-3">
        <!-- Notification Dropdown -->
        <div class="dropdown">
            <button class="btn position-relative dropdown-toggle hide-arrow" type="button" id="notifDropdown" data-bs-toggle="dropdown" aria-expanded="false" style="border: none; background: transparent;">
                <i class="fa-regular fa-bell fs-5 text-secondary"></i>
                <?php if ($notif_count > 0): ?>
                    <span class="position-absolute top-25 start-75 translate-middle p-1 bg-danger border border-light rounded-circle">
                        <span class="visually-hidden">New alerts</span>
                    </span>
                <?php endif; ?>
            </button>
            <ul class="dropdown-menu dropdown-menu-end shadow border-0 py-2" aria-labelledby="notifDropdown" style="width: 320px;">
                <li><h6 class="dropdown-header fw-bold text-dark">System Notifications</h6></li>
                <li><hr class="dropdown-divider my-1"></li>
                
                <li>
                    <a class="dropdown-item py-2 text-wrap" href="../../modules/analytics/index.php">
                        <div class="d-flex align-items-start">
                            <i class="fa-solid fa-triangle-exclamation text-danger fs-6 me-2 mt-1"></i>
                            <div>
                                <p class="mb-0 small fw-semibold text-danger">CDC EARS Outbreak Alert</p>
                                <small class="text-muted" style="font-size: 0.75rem;">Fever spike detected across Grade 10 sections.</small>
                            </div>
                        </div>
                    </a>
                </li>
                
                <li><hr class="dropdown-divider my-1"></li>
                
                <?php if (!empty($notif_inventory)): ?>
                    <?php foreach ($notif_inventory as $inv): ?>
                        <li>
                            <a class="dropdown-item py-2 text-wrap" href="../../modules/inventory/index.php">
                                <div class="d-flex align-items-start">
                                    <i class="fa-solid fa-pills text-warning fs-6 me-2 mt-1"></i>
                                    <div>
                                        <p class="mb-0 small fw-semibold text-dark">Low Stock: <?php echo htmlspecialchars($inv['item_name']); ?></p>
                                        <small class="text-muted" style="font-size: 0.75rem;">Current stock is at <?php echo $inv['current_stock']; ?> units.</small>
                                    </div>
                                </div>
                            </a>
                        </li>
                    <?php endforeach; ?>
                <?php else: ?>
                    <li><span class="dropdown-item-text text-muted small text-center py-2">No active stock-out alerts.</span></li>
                <?php endif; ?>
            </ul>
        </div>
        
        <!-- Profile Dropdown -->
        <div class="dropdown">
            <a href="#" class="d-flex align-items-center gap-2 text-decoration-none dropdown-toggle text-dark" id="profileDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                <img src="https://ui-avatars.com/api/?name=<?php echo urlencode($_SESSION['username'] ?? 'User'); ?>&background=3498db&color=fff" alt="Profile" class="rounded-circle" width="40">
                <span class="fw-semibold">
                    <?php echo htmlspecialchars($_SESSION['username'] ?? 'User'); ?> 
                    <span class="badge bg-secondary ms-1"><?php echo htmlspecialchars($_SESSION['role'] ?? 'Guest'); ?></span>
                </span>
            </a>
            <ul class="dropdown-menu dropdown-menu-end shadow border-0" aria-labelledby="profileDropdown">
                <li><h6 class="dropdown-header">Signed in as <?php echo htmlspecialchars($_SESSION['role'] ?? 'Guest'); ?></h6></li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item text-danger" href="../../logout.php"><i class="fa-solid fa-right-from-bracket me-2"></i> Logout</a></li>
            </ul>
        </div>
    </div>
</div>