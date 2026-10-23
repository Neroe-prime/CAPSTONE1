<?php
// Clear any prior output buffers to prevent blank screens
while (ob_get_level()) {
    ob_end_clean();
}

ini_set('display_errors', 0);
error_reporting(E_ALL);

session_start();
if(!isset($_SESSION['user_id'])){
    header("Location: ../../index.php?error=Please log in first");
    exit();
}
require_once '../../includes/db_connect.php';

// Check role permissions (Admin or Nurse only)
try {
    $role_stmt = $pdo->prepare("SELECT role FROM users WHERE user_id = ?");
    $role_stmt->execute([$_SESSION['user_id']]);
    $user_role = $role_stmt->fetchColumn();
} catch (PDOException $e) {
    $user_role = 'Teacher';
}

if ($user_role !== 'Admin' && $user_role !== 'Nurse') {
    die("Unauthorized access.");
}

// Set headers for CSV download
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="MedFlow_Consultations_Report_' . date('Y-m-d') . '.csv"');
header('Pragma: no-cache');
header('Expires: 0');

$output = fopen('php://output', 'w');

// CSV Column Headers
fputcsv($output, ['Consultation ID', 'Date & Time', 'Student Name', 'Grade & Section', 'Chief Complaint', 'Symptom Category', 'Action Taken', 'Status']);

// Fetch Consultation Records
try {
    $stmt = $pdo->query("
        SELECT c.consultation_id, c.consultation_date, s.first_name, s.last_name, s.grade_section, c.chief_complaint, c.symptom_category, c.action_taken, c.status 
        FROM consultations c
        JOIN students s ON c.student_id = s.student_id
        ORDER BY c.consultation_date DESC
    ");
    
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($output, [
            $row['consultation_id'],
            $row['consultation_date'],
            $row['first_name'] . ' ' . $row['last_name'],
            $row['grade_section'],
            $row['chief_complaint'],
            $row['symptom_category'],
            $row['action_taken'],
            $row['status']
        ]);
    }
} catch (PDOException $e) {
    fputcsv($output, ['Database Error: ' . $e->getMessage()]);
}

fclose($output);
exit();
?>