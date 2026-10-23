<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();
if(!isset($_SESSION['user_id'])){
    header("Location: ../../index.php?error=Please log in first");
    exit();
}
require_once '../../includes/db_connect.php';

$consultation_id = $_GET['id'] ?? '';
if (empty($consultation_id)) {
    header("Location: index.php?error=Invalid consultation ID");
    exit();
}

// Handle update form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $chief_complaint = trim($_POST['chief_complaint'] ?? '');
    $symptom_category = trim($_POST['symptom_category'] ?? 'Other');
    $action_taken = trim($_POST['action_taken'] ?? '');
    $status = trim($_POST['status'] ?? 'Cleared');

    try {
        $update_stmt = $pdo->prepare("UPDATE consultations SET chief_complaint = ?, symptom_category = ?, action_taken = ?, status = ? WHERE consultation_id = ?");
        $update_stmt->execute([$chief_complaint, $symptom_category, $action_taken, $status, $consultation_id]);
        
        header("Location: index.php?success=Consultation record updated successfully!");
        exit();
    } catch (PDOException $e) {
        $error_msg = "Database error while updating consultation record.";
    }
}

// Fetch consultation details along with student name
try {
    $stmt = $pdo->prepare("
        SELECT c.*, s.first_name, s.last_name, s.student_id 
        FROM consultations c
        JOIN students s ON c.student_id = s.student_id
        WHERE c.consultation_id = ?
    ");
    $stmt->execute([$consultation_id]);
    $consultation = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$consultation) {
        header("Location: index.php?error=Consultation not found");
        exit();
    }
} catch (PDOException $e) {
    header("Location: index.php?error=Database error");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Consultation - MedFlow</title>
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

        .form-control, .form-select {
            border-radius: 12px;
            padding: 12px 16px;
            border: 1px solid #e2e8f0;
            background-color: #f8fafc;
            transition: all 0.2s;
        }
        .form-control:focus, .form-select:focus {
            background-color: #ffffff;
            border-color: #6f42c1;
            box-shadow: 0 0 0 4px rgba(111, 66, 193, 0.1);
        }
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
                    <h2 class="fw-bold mb-2 text-white"><i class="fa-solid fa-pen-to-square me-3"></i> Edit Consultation Log</h2>
                    <p class="text-white-50 fs-6 mb-0">Modify clinical notes, symptoms, or status for this student visit.</p>
                </div>
                <a href="index.php" class="btn btn-light fw-bold px-4 py-3 shadow text-primary rounded-pill">
                    <i class="fa-solid fa-arrow-left me-2"></i> Back to Consultations
                </a>
            </div>
        </div>

        <?php if (isset($error_msg)): ?>
            <div class="alert alert-danger shadow-sm border-0 rounded-4 p-4 mb-4"><?php echo htmlspecialchars($error_msg); ?></div>
        <?php endif; ?>

        <div class="card p-5">
            <form action="edit.php?id=<?php echo $consultation_id; ?>" method="POST">
                <div class="mb-4">
                    <label class="form-label text-muted small fw-bold text-uppercase"><i class="fa-solid fa-user me-2 text-purple" style="color: #6f42c1;"></i> Student Record</label>
                    <input type="text" class="form-control bg-light fw-semibold text-dark" value="<?php echo htmlspecialchars($consultation['first_name'] . ' ' . $consultation['last_name'] . ' (' . $consultation['student_id'] . ')'); ?>" readonly>
                </div>

                <div class="mb-4">
                    <label class="form-label text-muted small fw-bold text-uppercase"><i class="fa-solid fa-comment-medical me-2 text-purple" style="color: #6f42c1;"></i> Chief Complaint / Symptoms</label>
                    <textarea name="chief_complaint" class="form-control" rows="3" required><?php echo htmlspecialchars($consultation['chief_complaint']); ?></textarea>
                </div>

                <div class="row mb-4">
                    <div class="col-md-6">
                        <label class="form-label text-muted small fw-bold text-uppercase"><i class="fa-solid fa-tags me-2 text-purple" style="color: #6f42c1;"></i> Symptom Category</label>
                        <select name="symptom_category" class="form-select" required>
                            <option value="Fever" <?php echo ($consultation['symptom_category'] == 'Fever') ? 'selected' : ''; ?>>Fever</option>
                            <option value="Cough & Cold" <?php echo ($consultation['symptom_category'] == 'Cough & Cold') ? 'selected' : ''; ?>>Cough & Cold</option>
                            <option value="Headache" <?php echo ($consultation['symptom_category'] == 'Headache') ? 'selected' : ''; ?>>Headache</option>
                            <option value="Stomachache" <?php echo ($consultation['symptom_category'] == 'Stomachache') ? 'selected' : ''; ?>>Stomachache / GI</option>
                            <option value="Injury" <?php echo ($consultation['symptom_category'] == 'Injury') ? 'selected' : ''; ?>>Injury / Wound</option>
                            <option value="Other" <?php echo ($consultation['symptom_category'] == 'Other') ? 'selected' : ''; ?>>Other</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label text-muted small fw-bold text-uppercase"><i class="fa-solid fa-circle-check me-2 text-purple" style="color: #6f42c1;"></i> Status</label>
                        <select name="status" class="form-select" required>
                            <option value="Cleared" <?php echo ($consultation['status'] == 'Cleared') ? 'selected' : ''; ?>>Cleared (Back to Class)</option>
                            <option value="Under Observation" <?php echo ($consultation['status'] == 'Under Observation') ? 'selected' : ''; ?>>Under Observation</option>
                            <option value="Sent Home" <?php echo ($consultation['status'] == 'Sent Home') ? 'selected' : ''; ?>>Sent Home</option>
                        </select>
                    </div>
                </div>

                <div class="mb-5">
                    <label class="form-label text-muted small fw-bold text-uppercase"><i class="fa-solid fa-kit-medical me-2 text-purple" style="color: #6f42c1;"></i> Action Taken / Treatment Given</label>
                    <textarea name="action_taken" class="form-control" rows="3" required><?php echo htmlspecialchars($consultation['action_taken']); ?></textarea>
                </div>

                <div class="d-flex justify-content-end gap-3">
                    <a href="index.php" class="btn btn-light px-4 py-3 rounded-pill fw-semibold text-muted border">Cancel</a>
                    <button type="submit" class="btn btn-primary px-5 py-3 rounded-pill fw-bold shadow-sm" style="background: linear-gradient(135deg, #6f42c1 0%, #4e73df 100%); border: none;">
                        <i class="fa-solid fa-floppy-disk me-2"></i> Update Consultation
                    </button>
                </div>
            </form>
        </div>
    </div>
    <?php include '../../includes/footer.php'; ?>
</body>
</html>