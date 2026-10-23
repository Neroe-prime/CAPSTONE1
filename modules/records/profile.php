    <?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();
if(!isset($_SESSION['user_id'])){
    header("Location: ../../index.php?error=Please log in first");
    exit();
}
require_once '../../includes/db_connect.php';

// Get Student ID from URL
$student_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($student_id <= 0) {
    header("Location: index.php?error=Invalid student ID.");
    exit();
}

// Fetch Student Details
try {
    $stmt = $pdo->prepare("SELECT * FROM students WHERE student_id = ?");
    $stmt->execute([$student_id]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$student) {
        header("Location: index.php?error=Student not found.");
        exit();
    }
} catch (PDOException $e) {
    header("Location: index.php?error=Database error.");
    exit();
}

// Fetch Student's Consultation History
try {
    $hist_stmt = $pdo->prepare("
        SELECT * FROM consultations 
        WHERE student_id = ? 
        ORDER BY consultation_date DESC
    ");
    $hist_stmt->execute([$student_id]);
    $consultations = $hist_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $consultations = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Profile & Health History - MedFlow</title>
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

        .table { border-collapse: separate; border-spacing: 0 10px; margin-top: -10px; }
        .table thead th { background-color: transparent; color: #6c757d; font-weight: 700; text-transform: uppercase; font-size: 0.75rem; letter-spacing: 1px; border-bottom: 2px solid #eaedf2; padding: 15px 20px; }
        .table tbody tr { background-color: #ffffff; box-shadow: 0 3px 10px rgba(0, 0, 0, 0.02); }
        .table tbody td { padding: 18px 20px; color: #2d3748; vertical-align: middle; font-size: 0.95rem; border-top: 1px solid #f1f3f8; border-bottom: 1px solid #f1f3f8; }
        .table tbody td:first-child { border-left: 1px solid #f1f3f8; border-top-left-radius: 12px; border-bottom-left-radius: 12px; }
        .table tbody td:last-child { border-right: 1px solid #f1f3f8; border-top-right-radius: 12px; border-bottom-right-radius: 12px; }
    </style>
</head>
<body>
    <?php include '../../includes/sidebar.php'; ?>
    <div class="main-content">
        <?php include '../../includes/topbar.php'; ?>

        <!-- Header Banner -->
        <div class="card bg-gradient-purple p-5 mb-5 shadow-sm mt-3">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2 class="fw-bold mb-2 text-white"><i class="fa-solid fa-user-injured me-3"></i> Student Health Profile</h2>
                    <p class="text-white-50 fs-6 mb-0">Longitudinal clinical history and visit logs for <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?>.</p>
                </div>
                <a href="index.php" class="btn btn-outline-light fw-bold px-4 py-2 rounded-pill shadow-sm">
                    <i class="fa-solid fa-arrow-left me-2"></i> Back to Students
                </a>
            </div>
        </div>

        <!-- Student Info Card -->
        <div class="row g-4 mb-4">
            <div class="col-md-4">
                <div class="card p-4 h-100 border-start border-4 border-primary">
                    <span class="text-muted small fw-bold text-uppercase">Full Name</span>
                    <h4 class="fw-bold text-dark mt-1 mb-3"><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></h4>
                    
                    <span class="text-muted small fw-bold text-uppercase">Grade & Section</span>
                    <p class="fw-semibold text-primary mb-3"><?php echo htmlspecialchars($student['grade_section']); ?></p>

                    <span class="text-muted small fw-bold text-uppercase">Contact Number</span>
                    <p class="fw-semibold text-dark mb-0"><?php echo htmlspecialchars($student['contact_number'] ?? 'N/A'); ?></p>
                </div>
            </div>
            <div class="col-md-8">
                <div class="card p-4 h-100">
                    <h5 class="fw-bold text-dark mb-3"><i class="fa-solid fa-notes-medical me-2 text-purple" style="color: #6f42c1;"></i> Medical Notes & Profile Summary</h5>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <span class="text-muted small fw-bold text-uppercase">Total Clinic Visits</span>
                            <h3 class="fw-bold text-dark mt-1"><?php echo count($consultations); ?></h3>
                        </div>
                        <div class="col-md-6 mb-3">
                            <span class="text-muted small fw-bold text-uppercase">Primary Status</span>
                            <p class="mt-1"><span class="badge bg-success bg-opacity-10 text-success border border-success-subtle px-3 py-2 rounded-pill fw-semibold">Active Student Record</span></p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Consultation History Timeline Table -->
        <div class="card p-4">
            <h5 class="fw-bold text-dark mb-4"><i class="fa-solid fa-timeline me-2 text-purple" style="color: #6f42c1;"></i> Chronological Clinic Visit History</h5>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead>
                        <tr>
                            <th class="ps-3">Date & Time</th>
                            <th>Chief Complaint</th>
                            <th>Symptom Category</th>
                            <th>Action Taken / Treatment</th>
                            <th class="text-end pe-3">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        if (!empty($consultations)) {
                            foreach ($consultations as $row) {
                                $date = date('M d, Y h:i A', strtotime($row['consultation_date']));
                                $status = $row['status'];
                                $badgeClass = 'bg-secondary';
                                if ($status == 'Cleared') $badgeClass = 'bg-success bg-opacity-10 text-success border border-success-subtle';
                                elseif ($status == 'Under Observation') $badgeClass = 'bg-warning bg-opacity-10 text-warning border border-warning-subtle';
                                elseif ($status == 'Sent Home') $badgeClass = 'bg-danger bg-opacity-10 text-danger border border-danger-subtle';

                                echo "<tr>";
                                echo "<td class='ps-3 fw-semibold text-dark'>{$date}</td>";
                                echo "<td>" . htmlspecialchars($row['chief_complaint']) . "</td>";
                                echo "<td><span class='badge bg-light text-dark border px-2 py-1'>" . htmlspecialchars($row['symptom_category']) . "</span></td>";
                                echo "<td>" . htmlspecialchars($row['action_taken']) . "</td>";
                                echo "<td class='text-end pe-3'><span class='badge {$badgeClass} px-3 py-2 rounded-pill fw-semibold'>{$status}</span></td>";
                                echo "</tr>";
                            }
                        } else {
                            echo "<tr><td colspan='5' class='text-center text-muted py-5'><i class='fa-solid fa-folder-open fa-3x mb-3 text-secondary opacity-25'></i><p class='mb-0 fw-medium'>No consultation history recorded for this student.</p></td></tr>";
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