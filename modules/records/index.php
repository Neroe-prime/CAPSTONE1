<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../index.php?error=Please log in first");
    exit();
}

// Prevent browser caching after logout so 'Back' button won't show student records
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

require_once '../../includes/db_connect.php';

// Fetch current user role
try {
    $role_stmt = $pdo->prepare("SELECT role FROM users WHERE user_id = ?");
    $role_stmt->execute([$_SESSION['user_id']]);
    $user_role = $role_stmt->fetchColumn() ?: ($_SESSION['role'] ?? 'Nurse');
} catch (PDOException $e) {
    $user_role = $_SESSION['role'] ?? 'Nurse';
}

// Filter parameters for Generate Report / Record Filtering
$filter_section = trim($_GET['grade_section'] ?? '');
$filter_history = trim($_GET['history_filter'] ?? 'all');
$filter_search = trim($_GET['q'] ?? '');

// Fetch distinct Grade / Section options for the filter dropdown
$sections = [];
try {
    $sec_stmt = $pdo->query("SELECT DISTINCT grade_section FROM students WHERE grade_section IS NOT NULL AND grade_section != '' ORDER BY grade_section ASC");
    $sections = $sec_stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    $sections = [];
}

// Build filtered query for student records
$students = [];
$query_error = '';
try {
    $sql = "SELECT * FROM students WHERE 1=1";
    $params = [];

    if ($filter_section !== '') {
        $sql .= " AND grade_section = ?";
        $params[] = $filter_section;
    }

    if ($filter_history === 'with_history') {
        $sql .= " AND medical_history IS NOT NULL AND TRIM(medical_history) != '' AND LOWER(TRIM(medical_history)) NOT IN ('none', 'n/a', 'na', 'none noted')";
    } elseif ($filter_history === 'no_history') {
        $sql .= " AND (medical_history IS NULL OR TRIM(medical_history) = '' OR LOWER(TRIM(medical_history)) IN ('none', 'n/a', 'na', 'none noted'))";
    }

    if ($filter_search !== '') {
        $sql .= " AND (CAST(student_id AS CHAR) LIKE ? OR first_name LIKE ? OR last_name LIKE ? OR CONCAT(first_name, ' ', last_name) LIKE ? OR medical_history LIKE ?)";
        $like = '%' . $filter_search . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }

    $sql .= " ORDER BY student_id DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $query_error = "Error loading student records.";
}

$has_active_filters = ($filter_section !== '' || $filter_history !== 'all' || $filter_search !== '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Records - MedFlow</title>
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
        .table { border-collapse: separate; border-spacing: 0 10px; margin-top: -10px; }
        .table thead th { background-color: transparent; color: #6c757d; font-weight: 700; text-transform: uppercase; font-size: 0.75rem; letter-spacing: 1px; border-bottom: 2px solid #eaedf2; padding: 15px 20px; }
        .table tbody tr { background-color: #ffffff; box-shadow: 0 3px 10px rgba(0, 0, 0, 0.02); }
        .table tbody td { padding: 18px 20px; color: #2d3748; vertical-align: middle; font-size: 0.95rem; border-top: 1px solid #f1f3f8; border-bottom: 1px solid #f1f3f8; }
        .table tbody td:first-child { border-left: 1px solid #f1f3f8; border-top-left-radius: 12px; border-bottom-left-radius: 12px; }
        .table tbody td:last-child { border-right: 1px solid #f1f3f8; border-top-right-radius: 12px; border-bottom-right-radius: 12px; }
        
        .btn-action-view { background-color: #ebf8ff; color: #3182ce; border: none; border-radius: 10px; transition: all 0.2s; }
        .btn-action-view:hover { background-color: #3182ce; color: white; }
        .btn-action-edit { background-color: #fefcbf; color: #d69e2e; border: none; border-radius: 10px; transition: all 0.2s; }
        .btn-action-edit:hover { background-color: #d69e2e; color: white; }
        .btn-action-delete { background-color: #fff5f5; color: #e53e3e; border: none; border-radius: 10px; transition: all 0.2s; }
        .btn-action-delete:hover { background-color: #e53e3e; color: white; }

        .print-report-header { display: none; }

        @media print {
            .sidebar, .top-navbar, .no-print, .btn, .alert { display: none !important; }
            .main-content { margin-left: 0 !important; width: 100% !important; padding: 15px !important; }
            .card { box-shadow: none !important; border: 1px solid #dee2e6 !important; }
            .print-report-header { display: block !important; margin-bottom: 20px; border-bottom: 2px solid #2d3748; padding-bottom: 12px; }
            .table { border-collapse: collapse !important; border-spacing: 0 !important; }
            .table tbody tr { box-shadow: none !important; }
            .table tbody td { border: 1px solid #dee2e6 !important; border-radius: 0 !important; }
        }
    </style>
</head>
<body>
    <?php include '../../includes/sidebar.php'; ?>
    <div class="main-content">
        <?php include '../../includes/topbar.php'; ?>

        <!-- Print-Only Official Report Header -->
        <div class="print-report-header">
            <h3 class="fw-bold mb-1">MedFlow — Student Health Records Report</h3>
            <p class="text-muted small mb-0">
                Generated on: <?php echo date('F d, Y h:i A'); ?> | Total Matching Records: <strong><?php echo count($students); ?></strong>
                <?php if ($filter_section !== ''): ?> | Grade/Section: <strong><?php echo htmlspecialchars($filter_section); ?></strong><?php endif; ?>
                <?php if ($filter_history !== 'all'): ?> | Medical History Filter: <strong><?php echo htmlspecialchars($filter_history === 'with_history' ? 'With History / Allergies' : 'No History / None'); ?></strong><?php endif; ?>
                <?php if ($filter_search !== ''): ?> | Keyword: <strong><?php echo htmlspecialchars($filter_search); ?></strong><?php endif; ?>
            </p>
        </div>

        <!-- Header Banner -->
        <div class="card bg-gradient-purple p-5 mb-5 shadow-sm mt-3">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
                <div>
                    <h2 class="fw-bold mb-2 text-white"><i class="fa-solid fa-user-injured me-3"></i> Student Health Records</h2>
                    <p class="text-white-50 fs-6 mb-0">Manage registered student profiles, medical histories, and printable health reports.</p>
                </div>
                <div class="d-flex flex-wrap gap-2 no-print">
                    <button type="button" class="btn btn-outline-light fw-bold px-4 py-3 rounded-pill shadow-sm text-nowrap" onclick="window.print();">
                        <i class="fa-solid fa-file-pdf me-2"></i> Generate / Print Report
                    </button>
                    <?php if ($user_role === 'Admin' || $user_role === 'Nurse'): ?>
                        <a href="register.php" class="btn btn-light fw-bold px-4 py-3 shadow text-primary rounded-pill text-nowrap">
                            <i class="fa-solid fa-user-plus me-2"></i> Register Student
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php if (isset($_GET['success'])): ?>
            <div class="alert alert-success shadow-sm border-0 rounded-4 p-4 mb-4 no-print">
                <i class="fa-solid fa-circle-check me-2"></i> <?php echo htmlspecialchars($_GET['success']); ?>
            </div>
        <?php endif; ?>

        <?php if (isset($_GET['error'])): ?>
            <div class="alert alert-danger shadow-sm border-0 rounded-4 p-4 mb-4 no-print">
                <i class="fa-solid fa-triangle-exclamation me-2"></i> <?php echo htmlspecialchars($_GET['error']); ?>
            </div>
        <?php endif; ?>

        <!-- Filter & Report Criteria Card -->
        <div class="card p-4 mb-4 no-print">
            <form method="GET" action="" class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small fw-bold text-muted text-uppercase">Grade / Section</label>
                    <select name="grade_section" class="form-select rounded-3">
                        <option value="">All Grade / Sections</option>
                        <?php foreach ($sections as $sec): ?>
                            <option value="<?php echo htmlspecialchars($sec); ?>" <?php echo $filter_section === $sec ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($sec); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-bold text-muted text-uppercase">Medical History / Allergies</label>
                    <select name="history_filter" class="form-select rounded-3">
                        <option value="all" <?php echo $filter_history === 'all' ? 'selected' : ''; ?>>All Students</option>
                        <option value="with_history" <?php echo $filter_history === 'with_history' ? 'selected' : ''; ?>>With Recorded History / Allergies</option>
                        <option value="no_history" <?php echo $filter_history === 'no_history' ? 'selected' : ''; ?>>No History / None</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-bold text-muted text-uppercase">Search Name, ID, or Allergy</label>
                    <input type="text" name="q" class="form-control rounded-3" value="<?php echo htmlspecialchars($filter_search); ?>" placeholder="e.g., Asthma or 2026-...">
                </div>
                <div class="col-md-3 d-flex gap-2">
                    <button type="submit" class="btn btn-primary rounded-pill px-4 py-2 fw-semibold flex-grow-1" style="background: linear-gradient(135deg, #6f42c1 0%, #4e73df 100%); border: none;">
                        <i class="fa-solid fa-filter me-1"></i> Apply Filter
                    </button>
                    <?php if ($has_active_filters): ?>
                        <a href="index.php" class="btn btn-light rounded-pill px-3 py-2 fw-semibold" title="Reset Filters">
                            <i class="fa-solid fa-rotate-left"></i>
                        </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- Students Table Card -->
        <div class="card p-4">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <span class="text-muted small fw-semibold">
                    Showing <strong><?php echo count($students); ?></strong> student record<?php echo count($students) === 1 ? '' : 's'; ?>
                    <?php if ($has_active_filters): ?>(Filtered Report View)<?php endif; ?>
                </span>
            </div>

            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead>
                        <tr>
                            <th class="ps-4">Student ID</th>
                            <th>Full Name</th>
                            <th>Grade / Section</th>
                            <th>Medical History / Allergies</th>
                            <th>Date Registered</th>
                            <th class="text-end pe-4 no-print">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        if ($query_error !== '') {
                            echo "<tr><td colspan='6' class='text-center text-danger py-4'>" . htmlspecialchars($query_error) . "</td></tr>";
                        } elseif (count($students) > 0) {
                            foreach ($students as $row) {
                                $reg_date = !empty($row['created_at']) ? date('M d, Y', strtotime($row['created_at'])) : 'Oct 22, 2026';
                                $med_text = trim((string)($row['medical_history'] ?? ''));
                                $has_med = ($med_text !== '' && !in_array(strtolower($med_text), ['none', 'n/a', 'na', 'none noted'], true));
                                $med_badge_class = $has_med
                                    ? 'bg-danger bg-opacity-10 text-danger border border-danger-subtle'
                                    : 'bg-secondary bg-opacity-10 text-secondary';

                                echo "<tr>";
                                echo "<td class='ps-4'><span class='badge bg-purple bg-opacity-10 text-purple border px-3 py-2 rounded-pill fw-semibold' style='background-color: #f3f0ff !important; color: #6f42c1 !important;'>#" . htmlspecialchars($row['student_id']) . "</span></td>";
                                echo "<td><strong class='text-dark fs-6'>" . htmlspecialchars($row['first_name'] . ' ' . $row['last_name']) . "</strong></td>";
                                echo "<td><span class='badge bg-info bg-opacity-10 text-info px-3 py-2 rounded-pill'>" . htmlspecialchars($row['grade_section']) . "</span></td>";
                                echo "<td><span class='badge {$med_badge_class} px-3 py-2 rounded-pill'>" . htmlspecialchars($med_text !== '' ? $med_text : 'None') . "</span></td>";
                                echo "<td class='text-muted'>" . htmlspecialchars($reg_date) . "</td>";
                                
                                echo "<td class='text-end pe-4 no-print'>
                                        <div class='d-flex justify-content-end gap-2'>
                                            <a href='view.php?id=" . urlencode($row['student_id']) . "' class='btn btn-sm btn-action-view px-3 py-2' title='View Profile'>
                                                <i class='fa-solid fa-eye'></i>
                                            </a>";
                                            
                                if ($user_role === 'Admin' || $user_role === 'Nurse') {
                                    echo "<a href='edit.php?id=" . urlencode($row['student_id']) . "' class='btn btn-sm btn-action-edit px-3 py-2' title='Edit Record'>
                                            <i class='fa-solid fa-pen-to-square'></i>
                                          </a>
                                          <a href='delete.php?id=" . urlencode($row['student_id']) . "' class='btn btn-sm btn-action-delete px-3 py-2' title='Delete Record' onclick='return confirm(\"Are you sure you want to delete this student record?\");'>
                                            <i class='fa-solid fa-trash'></i>
                                          </a>";
                                }
                                
                                echo "</div>
                                       </td>";
                                echo "</tr>";
                            }
                        } else {
                            echo "<tr><td colspan='6' class='text-center text-muted py-5'>
                                    <i class='fa-solid fa-user-slash fa-3x mb-3 text-secondary opacity-25'></i>
                                    <p class='mb-0 fw-medium'>No matching student health records found.</p>
                                 </td></tr>";
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