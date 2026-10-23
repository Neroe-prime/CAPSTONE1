<?php
$role = $_SESSION['role'] ?? 'Teacher'; // Default to lowest privilege if not set
?>
<div class="sidebar">
    <div class="brand">
        <i class="fa-solid fa-heart-pulse"></i> MedFlow
    </div>
    
    <a href="../../modules/dashboard/index.php">
        <i class="fa-solid fa-house"></i> Dashboard
    </a>
    
    <a href="../../modules/records/index.php">
        <i class="fa-solid fa-users"></i> Student Records
    </a>
    
    <a href="../../modules/consultations/index.php">
        <i class="fa-solid fa-stethoscope"></i> Consultations
    </a>
    
    <!-- Only Admin and Nurse can see Inventory and Analytics -->
    <?php if ($role === 'Admin' || $role === 'Nurse'): ?>
    <a href="../../modules/inventory/index.php">
        <i class="fa-solid fa-box-open"></i> Inventory
    </a>
    
    <a href="../../modules/analytics/index.php">
        <i class="fa-solid fa-chart-line"></i> Analytics
    </a>
    <?php endif; ?>
    
    <!-- Only Admin can see Settings -->
    <?php if ($role === 'Admin'): ?>
    <a href="../../modules/settings/index.php">
        <i class="fa-solid fa-gear"></i> Settings
    </a>
    <?php endif; ?>
</div>