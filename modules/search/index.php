<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();
if(!isset($_SESSION['user_id'])){
    header("Location: ../../index.php?error=Please log in first");
    exit();
}

// Prevent browser caching after logout so 'Back' button won't expose search results
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

require_once '../../includes/db_connect.php';

$search_query = isset($_GET['q']) ? trim($_GET['q']) : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Search Results - MedFlow</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background-color: #f4f5fa; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; overflow-x: hidden; }
        .sidebar { width: 250px; height: 100vh; background-color: #1a252f; color: #ecf0f1; position: fixed; padding-top: 20px; top: 0; left: 0; z-index: 1000; }
        .sidebar .brand { font-size: 1.5rem; font-weight: bold; text-align: center; margin-bottom: 30px; color: #3498db; }
        .sidebar a { padding: 15px 25px; text-decoration: none; font-size: 1.1rem; color: #bdc3c7; display: block; transition: 0.3s; }
        .sidebar a:hover, .sidebar a.active { color: #ffffff; background-color: #2c3e50; border-left: 4px solid #3498db; }
        .sidebar a i { margin-right: 10px; width: 20px; text-align: center; }
        
        .main-content { margin-left: 250px; padding: 45px; width: calc(100% - 250px); }
        .card { border: none; border-radius: 20px; box-shadow: 0 8px 25px rgba(111, 66, 193, 0.07); background-color: #ffffff; }
        
        /* Purple Gradient Banner */
        .bg-gradient-purple {
            background: linear-gradient(135deg, #6f42c1 0%, #4e73df 100%);
            color: white;
            border-radius: 20px;
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

        .btn-action-view { background-color: #ebf8ff; color: #3182ce; border: none; border-radius: 10px; transition: all 0.2s; }
        .btn-action-view:hover { background-color: #3182ce; color: white; }
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
                    <h2 class="fw-bold mb-2 text-white"><i class="fa-solid fa-magnifying-glass me-3"></i> Search Results</h2>
                    <p class="text-white-50 fs-6 mb-0">Showing records matching keyword: "<strong><?php echo htmlspecialchars($search_query); ?></strong>"</p>
                </div>
            </div>
        </div>

        <div class="card p-4">
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead>
                        <tr>
                            <th class="ps-4">Student ID</th>
                            <th>Full Name</th>
                            <th>Grade / Section</th>
                            <th>Medical History</th>
                            <th class="text-end pe-4">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        if ($search_query !== '') {
                            try {
                                $stmt = $pdo->prepare("SELECT * FROM students WHERE student_id LIKE ? OR first_name LIKE ? OR last_name LIKE ? ORDER BY last_name ASC");
                                $searchTerm = "%{$search_query}%";
                                $stmt->execute([$searchTerm, $searchTerm, $searchTerm]);

                                if ($stmt->rowCount() > 0) {
                                    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                                        echo "<tr>";
                                        echo "<td class='ps-4'><span class='badge bg-purple bg-opacity-10 text-purple border px-3 py-2 rounded-pill fw-semibold' style='background-color: #f3f0ff !important; color: #6f42c1 !important;'>#" . htmlspecialchars($row['student_id']) . "</span></td>";
                                        echo "<td><strong class='text-dark fs-6'>" . htmlspecialchars($row['first_name'] . ' ' . $row['last_name']) . "</strong></td>";
                                        echo "<td><span class='badge bg-info bg-opacity-10 text-info px-3 py-2 rounded-pill'>" . htmlspecialchars($row['grade_section']) . "</span></td>";
                                        echo "<td><span class='badge bg-danger bg-opacity-10 text-danger px-2 py-1'>" . htmlspecialchars($row['medical_history'] ?: 'None') . "</span></td>";
                                        
                                        // View Profile link pointing to records module
                                        echo "<td class='text-end pe-4'>
                                                <a href='../records/view.php?id=" . $row['student_id'] . "' class='btn btn-sm btn-action-view px-3 py-2' title='View Profile'>
                                                    <i class='fa-solid fa-eye me-1'></i> View Profile
                                                </a>
                                              </td>";
                                        echo "</tr>";
                                    }
                                } else {
                                    echo "<tr><td colspan='5' class='text-center py-5 text-muted'><i class='fa-solid fa-folder-open fa-3x mb-3 text-secondary opacity-25'></i><p class='mb-0 fw-medium'>No matching student records found.</p></td></tr>";
                                }
                            } catch (PDOException $e) {
                                echo "<tr><td colspan='5' class='text-center text-danger py-4'>Error performing search query.</td></tr>";
                            }
                        } else {
                            echo "<tr><td colspan='5' class='text-center py-5 text-muted'><i class='fa-solid fa-keyboard fa-3x mb-3 text-secondary opacity-25'></i><p class='mb-0 fw-medium'>Please enter a search term in the top bar.</p></td></tr>";
                        }
                        ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <?php include '../../includes/footer.php'; ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>