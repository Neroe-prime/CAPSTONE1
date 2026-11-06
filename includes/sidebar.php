<?php
$role = $_SESSION['role'] ?? 'Teacher'; // Default to lowest privilege if not set
?>
<div class="sidebar d-flex flex-column justify-content-between">
    <div>
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

    <!-- Official School Logo & Developer Credits at the Bottom of Sidebar -->
    <div class="p-3 text-center border-top border-secondary border-opacity-25" style="background-color: rgba(0, 0, 0, 0.3);">
        <div class="bg-white p-2 rounded-3 mb-2 shadow-sm d-inline-block w-100">
            <img src="../../assets/img/spcc_campus.png" alt="SPCC Caloocan Logo" class="img-fluid" style="max-height: 45px; width: auto; object-fit: contain;">
        </div>
        <div class="fst-italic" style="font-size: 0.7rem; color: #ffffff;">Developed by BSIT Capstone Researchers</div>
    </div>
</div>