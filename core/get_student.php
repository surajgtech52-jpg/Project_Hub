<?php
session_start();
require_once 'bootstrap.php';

header('Content-Type: application/json');

// Check if basic parameters are provided
if (!isset($_GET['moodle_id']) || !isset($_GET['year'])) {
    echo json_encode(['status' => 'error', 'message' => 'Missing parameters']);
    exit();
}

$moodle_id = $conn->real_escape_string($_GET['moodle_id']);
$year = $conn->real_escape_string($_GET['year']);
$div = isset($_GET['div']) ? $conn->real_escape_string($_GET['div']) : '';
$ignore_pid = isset($_GET['ignore_pid']) && $_GET['ignore_pid'] !== '' ? (int)$_GET['ignore_pid'] : 0;

// 1. Fetch Student from Database
$student_q = $conn->query("SELECT full_name, academic_year, division, status FROM student WHERE moodle_id = '$moodle_id'");

if (!$student_q || $student_q->num_rows === 0) {
    echo json_encode(['status' => 'error', 'message' => 'Student ID not found']);
    exit();
}

$student_data = $student_q->fetch_assoc();
$student_id = (int)($conn->query("SELECT id FROM student WHERE moodle_id = '$moodle_id' LIMIT 1")->fetch_assoc()['id'] ?? 0);

// 2. Ensure Student is Active
if ($student_data['status'] !== 'Active' && !empty($student_data['status'])) {
    echo json_encode(['status' => 'error', 'message' => 'Student account is disabled']);
    exit();
}

// 3. Match Academic Year
if ($student_data['academic_year'] !== $year) {
    echo json_encode(['status' => 'error', 'message' => "Student is in " . $student_data['academic_year']]);
    exit();
}

// 4. THE CRITICAL FIX: Check if student is in a CURRENT, ACTIVE group
// By forcing `is_archived = 0`, we ignore all past transferred projects!
$ignore_sql = $ignore_pid > 0 ? "AND id != $ignore_pid" : "";

$proj_q = $conn->query("SELECT p.id FROM projects p
                        JOIN project_members pm ON pm.project_id = p.id
                        WHERE pm.student_id = $student_id
                        AND p.is_archived = 0
                        AND p.project_year = '$year'
                        $ignore_sql
                        LIMIT 1");

if ($proj_q && $proj_q->num_rows > 0) {
    echo json_encode(['status' => 'error', 'message' => 'Already assigned to an active team!']);
    exit();
}

// If everything passes, return success with the student's name
echo json_encode(['status' => 'success', 'name' => $student_data['full_name']]);
?>