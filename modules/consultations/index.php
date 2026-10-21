<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();
if(!isset($_SESSION['user_id'])){
    header("Location: ../../index.php?error=Please log in first");
    exit();
}
require_once '../../includes/db_connect.php';

// Handle Form Submission for Logging a New Consultation
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_consultation'])) {
    $student_id = trim($_POST['student_id']);
    $chief_complaint = trim($_POST['chief_complaint']);
    $symptom_category = trim($_POST['symptom_category']);
    $action_taken = trim($_POST['action_taken']);
    $status = trim($_POST['status']);

    try {
        $stmt = $pdo->prepare("INSERT INTO consultations (student_id, chief_complaint, symptom_category, action_taken, status) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$student_id, $chief_complaint, $symptom_category, $action_taken, $status]);
        $success_msg = "Consultation successfully logged!";
    } catch (PDOException $e) {
        $error_msg = "Error: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Consultations - MedFlow</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background-color: #f0f4f8; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; overflow-x: hidden; }
        
        /* Sidebar Styles */
        .sidebar { width: 250px; height: 100vh; background-color: #1a252f; color: #ecf0f1; position: fixed; padding-top: 20px; top: 0; left: 0; z-index: 1000; }
        .sidebar .brand { font-size: 1.5rem; font-weight: bold; text-align: center; margin-bottom: 30px; color: #3498db; }
        .sidebar a { padding: 15px 25px; text-decoration: none; font-size: 1.1rem; color: #bdc3c7; display: block; transition: 0.3s; }
        .sidebar a:hover, .sidebar a.active { color: #ffffff; background-color: #2c3e50; border-left: 4px solid #3498db; }
        .sidebar a i { margin-right: 10px; width: 20px; text-align: center; }
        
        /* Main Content Styles */
        .main-content { margin-left: 250px; padding: 20px; width: calc(100% - 250px); }
        .top-navbar { background-color: #ffffff; border-radius: 8px; padding: 10px 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.04); margin-bottom: 24px; }
        .card { border: none; border-radius: 10px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); }
    </style>
</head>
<body>

    <!-- INJECT SIDEBAR -->
    <?php include '../../includes/sidebar.php'; ?>

    <div class="main-content">
        <!-- INJECT TOPBAR -->
        <?php include '../../includes/topbar.php'; ?>

        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4 class="fw-bold m-0">Clinic Consultations Log</h4>
            <button class="btn btn-primary fw-semibold" data-bs-toggle="modal" data-bs-target="#addConsultationModal">
                <i class="fa-solid fa-plus me-2"></i> Log New Visit
            </button>
        </div>

        <?php if(isset($success_msg)): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fa-solid fa-check-circle me-2"></i> <?php echo $success_msg; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if(isset($error_msg)): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fa-solid fa-triangle-exclamation me-2"></i> <?php echo $error_msg; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Consultations Data Table -->
        <div class="card p-4">
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Date & Time</th>
                            <th>Student Name</th>
                            <th>Grade / Section</th>
                            <th>Chief Complaint</th>
                            <th>Category</th>
                            <th>Action Taken</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $stmt = $pdo->query("
                            SELECT c.*, s.first_name, s.last_name, s.grade_section 
                            FROM consultations c
                            JOIN students s ON c.student_id = s.student_id
                            ORDER BY c.consultation_date DESC
                        ");
                        if ($stmt->rowCount() > 0) {
                            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                                $date = date('M d, Y h:i A', strtotime($row['consultation_date']));
                                
                                $badgeClass = 'bg-secondary';
                                if ($row['status'] == 'Cleared') $badgeClass = 'bg-success';
                                if ($row['status'] == 'Under Observation') $badgeClass = 'bg-warning text-dark';
                                if ($row['status'] == 'Sent Home') $badgeClass = 'bg-danger';

                                echo "<tr>";
                                echo "<td><small class='text-muted'>{$date}</small></td>";
                                echo "<td><strong>" . htmlspecialchars($row['first_name'] . ' ' . $row['last_name']) . "</strong></td>";
                                echo "<td>" . htmlspecialchars($row['grade_section']) . "</td>";
                                echo "<td>" . htmlspecialchars($row['chief_complaint']) . "</td>";
                                echo "<td><span class='badge bg-light text-dark border'>" . htmlspecialchars($row['symptom_category']) . "</span></td>";
                                echo "<td>" . htmlspecialchars($row['action_taken']) . "</td>";
                                echo "<td><span class='badge {$badgeClass}'>" . htmlspecialchars($row['status']) . "</span></td>";
                                echo "</tr>";
                            }
                        } else {
                            echo "<tr><td colspan='7' class='text-center py-4 text-muted'>No consultation logs found.</td></tr>";
                        }
                        ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>

    <!-- LOG CONSULTATION MODAL -->
    <div class="modal fade" id="addConsultationModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header border-0">
                    <h5 class="modal-title fw-bold">Log New Clinic Visit</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form action="" method="POST">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Select Student</label>
                            <select name="student_id" class="form-select" required>
                                <option value="">-- Choose Student --</option>
                                <?php
                                $students = $pdo->query("SELECT student_id, first_name, last_name, grade_section FROM students ORDER BY last_name ASC");
                                while($s = $students->fetch(PDO::FETCH_ASSOC)) {
                                    echo "<option value='{$s['student_id']}'>{$s['last_name']}, {$s['first_name']} ({$s['grade_section']})</option>";
                                }
                                ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Chief Complaint / Symptoms</label>
                            <input type="text" name="chief_complaint" class="form-control" placeholder="e.g., Fever (38.5°C) and body malaise" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Symptom Category (For EARS Surveillance)</label>
                            <select name="symptom_category" class="form-select" required>
                                <option value="Fever">Fever</option>
                                <option value="Cough & Cold">Cough & Cold</option>
                                <option value="Headache">Headache</option>
                                <option value="Stomachache">Stomachache / GI</option>
                                <option value="Injury">Injury / Wound</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Action Taken</label>
                            <input type="text" name="action_taken" class="form-control" placeholder="e.g., Given Paracetamol 500mg, advised rest" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Status</label>
                            <select name="status" class="form-select" required>
                                <option value="Cleared">Cleared (Back to Class)</option>
                                <option value="Under Observation">Under Observation</option>
                                <option value="Sent Home">Sent Home</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer border-0">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="add_consultation" class="btn btn-primary">Save Log</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- INJECT FOOTER -->
    <?php include '../../includes/footer.php'; ?>
</body>
</html>