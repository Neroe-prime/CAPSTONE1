<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();
if(!isset($_SESSION['user_id'])){
    header("Location: ../../index.php?error=Please log in first");
    exit();
}
require_once '../../includes/db_connect.php';

// Fetch current user data
try {
    $stmt = $pdo->prepare("SELECT username, role FROM users WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $current_user = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $current_user = ['username' => 'Unknown', 'role' => 'Guest'];
}

// Fetch all users if Admin wants to manage them
$all_users = [];
if ($current_user['role'] === 'Admin') {
    try {
        $u_stmt = $pdo->query("SELECT user_id, username, role FROM users ORDER BY user_id DESC");
        $all_users = $u_stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $all_users = [];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings - MedFlow</title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>
        body { background-color: #f4f5fa; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; overflow-x: hidden; }
        .sidebar { width: 250px; height: 100vh; background-color: #1a252f; color: #ecf0f1; position: fixed; padding-top: 20px; top: 0; left: 0; z-index: 1000; }
        .sidebar .brand { font-size: 1.5rem; font-weight: bold; text-align: center; margin-bottom: 30px; color: #3498db; }
        .sidebar a { padding: 15px 25px; text-decoration: none; font-size: 1.1rem; color: #bdc3c7; display: block; transition: 0.3s; }
        .sidebar a:hover, .sidebar a.active { color: #ffffff; background-color: #2c3e50; border-left: 4px solid #3498db; }
        .sidebar a i { margin-right: 10px; width: 20px; text-align: center; }
        
        /* Spacious Layout */
        .main-content { margin-left: 250px; padding: 45px; width: calc(100% - 250px); }
        
        .card { border: none; border-radius: 20px; box-shadow: 0 8px 25px rgba(111, 66, 193, 0.07); background-color: #ffffff; }
        
        /* Purple Gradient Banner */
        .bg-gradient-purple {
            background: linear-gradient(135deg, #6f42c1 0%, #4e73df 100%);
            color: white;
            border-radius: 20px;
        }

        .nav-tabs { border-bottom: 2px solid #eaedf2; }
        .nav-tabs .nav-link { color: #718096; font-weight: 600; border: none; border-bottom: 3px solid transparent; transition: all 0.2s; }
        .nav-tabs .nav-link.active { color: #6f42c1; background-color: transparent; border-color: #6f42c1; }
        .nav-tabs .nav-link:hover { color: #4a5568; border-color: transparent; }

        .form-control, .form-select {
            border-radius: 12px;
            padding: 12px 16px;
            border: 1px solid #e2e8f0;
            background-color: #f8fafc;
        }
        .form-control:focus, .form-select:focus {
            background-color: #ffffff;
            border-color: #6f42c1;
            box-shadow: 0 0 0 4px rgba(111, 66, 193, 0.1);
        }

        /* Table Spacing */
        .table {
            border-collapse: separate;
            border-spacing: 0 10px;
            margin-top: -10px;
        }
        .table thead th {
            background-color: transparent;
            color: #6c757d;
            font-weight: 700;
            text-transform: uppercase;
            font-size: 0.75rem;
            letter-spacing: 1px;
            border-bottom: 2px solid #eaedf2;
            padding: 15px 20px;
        }
        .table tbody tr {
            background-color: #ffffff;
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.02);
        }
        .table tbody td {
            padding: 18px 20px;
            color: #2d3748;
            vertical-align: middle;
            font-size: 0.95rem;
            border-top: 1px solid #f1f3f8;
            border-bottom: 1px solid #f1f3f8;
        }
        .table tbody td:first-child {
            border-left: 1px solid #f1f3f8;
            border-top-left-radius: 12px;
            border-bottom-left-radius: 12px;
        }
        .table tbody td:last-child {
            border-right: 1px solid #f1f3f8;
            border-top-right-radius: 12px;
            border-bottom-right-radius: 12px;
        }
    </style>
</head>
<body>

    <?php include '../../includes/sidebar.php'; ?>

    <div class="main-content">
        
        <?php include '../../includes/topbar.php'; ?>

        <!-- Purple Gradient Header Banner -->
        <div class="card bg-gradient-purple p-5 mb-5 shadow-sm mt-3">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2 class="fw-bold mb-2 text-white"><i class="fa-solid fa-gear me-3"></i> System Settings</h2>
                    <p class="text-white-50 fs-6 mb-0">Manage profile information, security credentials, and system users.</p>
                </div>
                <span class="badge bg-light text-purple px-4 py-2 rounded-pill fw-semibold shadow-sm" style="color: #6f42c1 !important;">
                    <i class="fa-solid fa-shield-halved me-2"></i> Role: <?php echo htmlspecialchars($current_user['role']); ?>
                </span>
            </div>
        </div>

        <?php if (isset($_GET['error'])): ?>
            <div class="alert alert-danger shadow-sm border-0 rounded-4 p-4 mb-4"><i class="fa-solid fa-triangle-exclamation me-2"></i> <?php echo htmlspecialchars($_GET['error']); ?></div>
        <?php endif; ?>
        
        <?php if (isset($_GET['success'])): ?>
            <div class="alert alert-success shadow-sm border-0 rounded-4 p-4 mb-4"><i class="fa-solid fa-circle-check me-2"></i> <?php echo htmlspecialchars($_GET['success']); ?></div>
        <?php endif; ?>

        <div class="row g-4">
            <div class="col-md-4 mb-4">
                <!-- Profile Summary Card -->
                <div class="card p-5 text-center h-100">
                    <img src="https://ui-avatars.com/api/?name=<?php echo urlencode($current_user['username']); ?>&background=6f42c1&color=fff&size=120" alt="Profile" class="rounded-circle mx-auto mb-4 shadow-sm" width="110">
                    <h4 class="fw-bold text-dark mb-1"><?php echo htmlspecialchars($current_user['username']); ?></h4>
                    <p class="text-muted mb-4"><span class="badge bg-purple bg-opacity-10 text-purple border px-3 py-2 rounded-pill fw-semibold" style="color: #6f42c1 !important; background-color: #f3f0ff !important;"><?php echo htmlspecialchars($current_user['role']); ?></span></p>
                    <hr class="text-muted opacity-25 my-3">
                    <div class="d-flex justify-content-between px-2 text-start small mt-2">
                        <span class="text-muted fw-semibold">Status:</span>
                        <span class="text-success fw-bold"><i class="fa-solid fa-circle-check me-1"></i> Active</span>
                    </div>
                </div>
            </div>

            <div class="col-md-8">
                <div class="card p-4">
                    <div class="card-header bg-white pt-3 pb-0 border-bottom-0">
                        <ul class="nav nav-tabs" id="settingsTabs" role="tablist">
                            <li class="nav-item" role="presentation">
                                <button class="nav-link active px-4 pb-3" id="profile-tab" data-bs-toggle="tab" data-bs-target="#profile" type="button" role="tab" aria-controls="profile" aria-selected="true">
                                    <i class="fa-regular fa-user me-2"></i> Account Details
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link px-4 pb-3" id="security-tab" data-bs-toggle="tab" data-bs-target="#security" type="button" role="tab" aria-controls="security" aria-selected="false">
                                    <i class="fa-solid fa-shield-halved me-2"></i> Security
                                </button>
                            </li>
                            <?php if ($current_user['role'] === 'Admin'): ?>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link px-4 pb-3" id="users-tab" data-bs-toggle="tab" data-bs-target="#users" type="button" role="tab" aria-controls="users" aria-selected="false">
                                    <i class="fa-solid fa-users-gear me-2"></i> Manage Users
                                </button>
                            </li>
                            <?php endif; ?>
                        </ul>
                    </div>
                    <div class="card-body p-4">
                        <div class="tab-content" id="settingsTabsContent">
                            
                            <!-- Account Details Tab -->
                            <div class="tab-pane fade show active" id="profile" role="tabpanel" aria-labelledby="profile-tab">
                                <h5 class="fw-bold text-dark mb-4">Profile Information</h5>
                                <div class="row mb-3">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label text-muted small fw-bold text-uppercase">Username</label>
                                        <input type="text" class="form-control bg-light fw-semibold" value="<?php echo htmlspecialchars($current_user['username']); ?>" readonly>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label text-muted small fw-bold text-uppercase">System Role</label>
                                        <input type="text" class="form-control bg-light fw-semibold" value="<?php echo htmlspecialchars($current_user['role']); ?>" readonly>
                                        <div class="form-text mt-2 small"><i class="fa-solid fa-circle-info text-purple me-1" style="color: #6f42c1;"></i> You hold full administrator privileges.</div>
                                    </div>
                                </div>
                            </div>

                            <!-- Security / Password Tab -->
                            <div class="tab-pane fade" id="security" role="tabpanel" aria-labelledby="security-tab">
                                <h5 class="fw-bold text-dark mb-4">Change Password</h5>
                                <form action="update_password.php" method="POST">
                                    <div class="mb-3">
                                        <label class="form-label text-muted small fw-bold text-uppercase">Current Password</label>
                                        <input type="password" name="current_password" class="form-control" required>
                                    </div>
                                    <div class="row mb-4">
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label text-muted small fw-bold text-uppercase">New Password</label>
                                            <input type="password" name="new_password" class="form-control" required>
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label text-muted small fw-bold text-uppercase">Confirm New Password</label>
                                            <input type="password" name="confirm_password" class="form-control" required>
                                        </div>
                                    </div>
                                    <button type="submit" class="btn btn-primary px-4 py-2 rounded-pill fw-semibold shadow-sm" style="background: linear-gradient(135deg, #6f42c1 0%, #4e73df 100%); border: none;">Update Password</button>
                                </form>
                            </div>

                            <!-- Manage Users Tab (Admin Only) -->
                            <?php if ($current_user['role'] === 'Admin'): ?>
                            <div class="tab-pane fade" id="users" role="tabpanel" aria-labelledby="users-tab">
                                <div class="d-flex justify-content-between align-items-center mb-4">
                                    <h5 class="fw-bold text-dark mb-0">Registered System Accounts</h5>
                                    <button type="button" class="btn btn-sm btn-primary rounded-pill px-3 py-2 fw-semibold shadow-sm" style="background: linear-gradient(135deg, #6f42c1 0%, #4e73df 100%); border: none;" data-bs-toggle="modal" data-bs-target="#addUserModal">
                                        <i class="fa-solid fa-user-plus me-1"></i> Add New User
                                    </button>
                                </div>
                                <div class="table-responsive">
                                    <table class="table align-middle mb-0">
                                        <thead>
                                            <tr>
                                                <th class="ps-3">ID</th>
                                                <th>Username</th>
                                                <th class="text-end pe-3">Role</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($all_users as $u): ?>
                                            <tr>
                                                <td class="ps-3"><span class="badge bg-light text-dark border px-2 py-1">#<?php echo $u['user_id']; ?></span></td>
                                                <td><strong class="text-dark"><?php echo htmlspecialchars($u['username']); ?></strong></td>
                                                <td class="text-end pe-3">
                                                    <span class="badge <?php echo $u['role'] === 'Admin' ? 'bg-danger bg-opacity-10 text-danger border border-danger-subtle' : ($u['role'] === 'Nurse' ? 'bg-success bg-opacity-10 text-success border border-success-subtle' : 'bg-secondary bg-opacity-10 text-secondary border border-secondary-subtle'); ?> px-3 py-2 rounded-pill fw-semibold">
                                                        <?php echo htmlspecialchars($u['role']); ?>
                                                    </span>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                            <?php endif; ?>

                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Add User Modal -->
    <div class="modal fade" id="addUserModal" tabindex="-1" aria-labelledby="addUserModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg rounded-4">
                <div class="modal-header border-0 text-white rounded-top-4 p-4" style="background: linear-gradient(135deg, #6f42c1 0%, #4e73df 100%);">
                    <h5 class="modal-title fw-bold" id="addUserModalLabel"><i class="fa-solid fa-user-plus me-2"></i> Create New User Account</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form action="create_user.php" method="POST">
                    <div class="modal-body p-4">
                        <div class="mb-3">
                            <label class="form-label text-muted small fw-bold text-uppercase">Username</label>
                            <input type="text" name="new_username" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label text-muted small fw-bold text-uppercase">Password</label>
                            <input type="password" name="new_password" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label text-muted small fw-bold text-uppercase">Role</label>
                            <select name="new_role" class="form-select" required>
                                <option value="Teacher">Teacher</option>
                                <option value="Nurse">Nurse</option>
                                <option value="Admin">Admin</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer border-0 bg-light rounded-bottom-4 p-3">
                        <button type="button" class="btn btn-secondary btn-sm px-4 rounded-pill" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary btn-sm px-4 rounded-pill fw-semibold" style="background: linear-gradient(135deg, #6f42c1 0%, #4e73df 100%); border: none;">Create Account</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php include '../../includes/footer.php'; ?>
    
</body>
</html>