<?php
$notif_inventory = [];
$notif_expiry = [];
$notif_count = 0;
$user_role = $_SESSION['role'] ?? 'Teacher';

// Only query inventory and expiration notifications if the user is an Admin or Nurse
if (isset($pdo) && $user_role !== 'Teacher') {
    try {
        // Fetch low stock items
        $inv_stmt = $pdo->query("SELECT item_name, current_stock, reorder_level FROM inventory WHERE current_stock <= reorder_level LIMIT 3");
        $notif_inventory = $inv_stmt->fetchAll(PDO::FETCH_ASSOC);
        $notif_count += count($notif_inventory);

        // Fetch expired or expiring soon items (< 30 days)
        $expiry_stmt = $pdo->query("
            SELECT item_name, batch_number, expiration_date,
                   CASE 
                       WHEN expiration_date < CURDATE() THEN 'Expired'
                       ELSE 'Expiring Soon' 
                   END as expiry_status
            FROM inventory 
            WHERE expiration_date IS NOT NULL 
              AND expiration_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
            LIMIT 3
        ");
        $notif_expiry = $expiry_stmt->fetchAll(PDO::FETCH_ASSOC);
        $notif_count += count($notif_expiry);
    } catch (Exception $e) {}
}
?>
<div class="top-navbar d-flex justify-content-between align-items-center mb-3" style="position: relative; z-index: 1050;">
    <form action="../../modules/search/index.php" method="GET" class="input-group" style="max-width: 400px;">
        <span class="input-group-text bg-white border-end-0"><i class="fa-solid fa-magnifying-glass text-muted"></i></span>
        <input type="text" name="q" class="form-control border-start-0 shadow-none" placeholder="Search student ID or name..." required>
    </form>
    
    <div class="d-flex align-items-center gap-3">
        <!-- Notification Dropdown (Restricted to Admin and Nurse) -->
        <?php if ($user_role === 'Admin' || $user_role === 'Nurse'): ?>
        <div class="dropdown">
            <button class="btn position-relative dropdown-toggle hide-arrow" type="button" id="notifDropdown" data-bs-toggle="dropdown" aria-expanded="false" style="border: none; background: transparent;">
                <i class="fa-regular fa-bell fs-5 text-secondary"></i>
                <?php if ($notif_count > 0): ?>
                    <span class="position-absolute top-25 start-75 translate-middle p-1 bg-danger border border-light rounded-circle">
                        <span class="visually-hidden">New alerts</span>
                    </span>
                <?php endif; ?>
            </button>
            <ul class="dropdown-menu dropdown-menu-end shadow border-0 py-2 mt-2" aria-labelledby="notifDropdown" style="width: 320px; max-height: 400px; overflow-y: auto; z-index: 1060;">
                <li><h6 class="dropdown-header fw-bold text-dark">System Notifications</h6></li>
                <li><hr class="dropdown-divider my-1"></li>
                
                <!-- CDC EARS Alert -->
                <li>
                    <a class="dropdown-item py-2 text-wrap" href="../../modules/analytics/index.php">
                        <div class="d-flex align-items-start">
                            <i class="fa-solid fa-triangle-exclamation text-danger fs-6 me-2 mt-1"></i>
                            <div>
                                <p class="mb-0 small fw-semibold text-danger">CDC EARS Outbreak Alert</p>
                                <small class="text-muted" style="font-size: 0.75rem;">Fever spike detected across sections.</small>
                            </div>
                        </div>
                    </a>
                </li>
                
                <li><hr class="dropdown-divider my-1"></li>

                <!-- Expiration / Expiring Soon Alerts -->
                <?php if (!empty($notif_expiry)): ?>
                    <?php foreach ($notif_expiry as $exp): ?>
                        <li>
                            <a class="dropdown-item py-2 text-wrap" href="../../modules/inventory/index.php">
                                <div class="d-flex align-items-start">
                                    <i class="fa-solid fa-calendar-xmark text-warning fs-6 me-2 mt-1"></i>
                                    <div>
                                        <p class="mb-0 small fw-semibold text-dark"><?php echo $exp['expiry_status']; ?>: <?php echo htmlspecialchars($exp['item_name']); ?></p>
                                        <small class="text-muted" style="font-size: 0.75rem;">Batch: <?php echo htmlspecialchars($exp['batch_number'] ?? 'N/A'); ?> (<?php echo $exp['expiration_date']; ?>)</small>
                                    </div>
                                </div>
                            </a>
                        </li>
                    <?php endforeach; ?>
                    <li><hr class="dropdown-divider my-1"></li>
                <?php endif; ?>
                
                <!-- Low Stock Alerts -->
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
                <?php endif; ?>

                <?php if (empty($notif_inventory) && empty($notif_expiry)): ?>
                    <li><span class="dropdown-item-text text-muted small text-center py-2 d-block">No active stock-out or expiration alerts.</span></li>
                <?php endif; ?>
            </ul>
        </div>
        <?php endif; ?>
        
        <!-- Profile Dropdown -->
        <div class="dropdown">
            <a href="#" class="d-flex align-items-center gap-2 text-decoration-none dropdown-toggle text-dark" id="profileDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                <img src="https://ui-avatars.com/api/?name=<?php echo urlencode($_SESSION['username'] ?? 'User'); ?>&background=3498db&color=fff" alt="Profile" class="rounded-circle" width="40">
                <span class="fw-semibold">
                    <?php echo htmlspecialchars($_SESSION['username'] ?? 'User'); ?> 
                    <span class="badge bg-secondary ms-1"><?php echo htmlspecialchars($_SESSION['role'] ?? 'Guest'); ?></span>
                </span>
            </a>
            <ul class="dropdown-menu dropdown-menu-end shadow border-0 mt-2" aria-labelledby="profileDropdown" style="z-index: 1060;">
                <li><h6 class="dropdown-header">Signed in as <?php echo htmlspecialchars($_SESSION['role'] ?? 'Guest'); ?></h6></li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item text-danger" href="../../logout.php"><i class="fa-solid fa-right-from-bracket me-2"></i> Logout</a></li>
            </ul>
        </div>
    </div>
</div>

<script>
// Fallback click handler to guarantee dropdowns open even if Bootstrap JS has loading conflicts
document.addEventListener('DOMContentLoaded', function () {
    const dropdownToggles = document.querySelectorAll('.top-navbar .dropdown-toggle');
    
    dropdownToggles.forEach(toggle => {
        toggle.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            
            // Close all other open dropdowns
            document.querySelectorAll('.top-navbar .dropdown-menu').forEach(menu => {
                if (menu !== this.nextElementSibling) {
                    menu.classList.remove('show');
                }
            });

            // Toggle current dropdown
            let menu = this.nextElementSibling;
            if (menu && menu.classList.contains('dropdown-menu')) {
                menu.classList.toggle('show');
            }
        });
    });

    // Close when clicking anywhere outside
    window.addEventListener('click', function () {
        document.querySelectorAll('.top-navbar .dropdown-menu').forEach(menu => {
            menu.classList.remove('show');
        });
    });
});
</script>