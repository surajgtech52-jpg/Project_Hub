<?php
session_start();
require_once 'bootstrap.php';

header('Content-Type: application/json');

// 1. Handle SEARCH mode (for autocomplete)
if (isset($_GET['query'])) {
    $year = $conn->real_escape_string($_GET['year'] ?? '');
    $div = $conn->real_escape_string($_GET['div'] ?? '');
    $sem = isset($_GET['sem']) ? (int)$_GET['sem'] : 0;
    $ignore_pid = isset($_GET['ignore_pid']) && $_GET['ignore_pid'] !== '' ? (int)$_GET['ignore_pid'] : 0;
    
    if (empty($year) || empty($div)) {
        echo json_encode([]);
        exit();
    }

    $ignore_sql = $ignore_pid > 0 ? "AND p.id != $ignore_pid" : "";
    $sem_sql = $sem > 0 ? "AND s.current_semester = $sem" : "";

    $query = $conn->real_escape_string($_GET['query']);
    $sql = "SELECT s.moodle_id, s.full_name FROM student s
            WHERE (s.moodle_id LIKE '%$query%' OR s.full_name LIKE '%$query%') 
            AND s.academic_year = '$year' 
            AND s.division = '$div' 
            $sem_sql
            AND s.status = 'Active' 
            AND s.deleted_at IS NULL 
            AND NOT EXISTS (
                SELECT 1 FROM project_members pm
                JOIN projects p ON pm.project_id = p.id
                WHERE pm.student_id = s.id
                AND p.is_archived = 0
                AND p.project_year = '$year'
                AND p.semester = $sem
                $ignore_sql
            )
            LIMIT 10";
            
    $res = $conn->query($sql);
    $students = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $students[] = $row;
        }
    }
    echo json_encode($students);
    exit();
}

// 2. Handle specific FETCH/VALIDATION mode
if (!isset($_GET['moodle_id']) || !isset($_GET['year'])) {
    echo json_encode(['status' => 'error', 'message' => 'Missing parameters.']);
    exit();
}

$moodle_id = $conn->real_escape_string($_GET['moodle_id']);
$year = $conn->real_escape_string($_GET['year']);
$sem = isset($_GET['sem']) ? (int)$_GET['sem'] : 0;
$div = isset($_GET['div']) ? $conn->real_escape_string($_GET['div']) : '';
$ignore_pid = isset($_GET['ignore_pid']) && $_GET['ignore_pid'] !== '' ? (int)$_GET['ignore_pid'] : 0;

// Fetch Student from Database
$student_q = $conn->query("SELECT id, full_name, academic_year, current_semester, division, status FROM student WHERE moodle_id = '$moodle_id' AND deleted_at IS NULL");

if (!$student_q || $student_q->num_rows === 0) {
    echo json_encode(['status' => 'error', 'message' => 'Student ID not found or registered.']);
    exit();
}

$student_data = $student_q->fetch_assoc();
$student_id = (int)$student_data['id'];

// Ensure Student is Active
if ($student_data['status'] !== 'Active' && !empty($student_data['status'])) {
    echo json_encode(['status' => 'error', 'message' => 'Student account is marked as Disabled.']);
    exit();
}

// Match Academic Year
if ($student_data['academic_year'] !== $year) {
    echo json_encode(['status' => 'error', 'message' => "Year mismatch: Student is in " . $student_data['academic_year'] . " year."]);
    exit();
}

// Match Semester (NEW)
if ($sem > 0 && $student_data['current_semester'] != $sem) {
    echo json_encode(['status' => 'error', 'message' => "Semester mismatch: Student is in Sem " . $student_data['current_semester'] . "."]);
    exit();
}

// Match Division (NEW RESTRICTION)
if (!empty($div) && $student_data['division'] !== $div) {
    echo json_encode(['status' => 'error', 'message' => "Division mismatch: Student is in Div " . $student_data['division'] . "."]);
    exit();
}

// Check if student is in a CURRENT, ACTIVE group
$ignore_sql = $ignore_pid > 0 ? "AND p.id != $ignore_pid" : "";

$proj_q = $conn->query("SELECT p.group_name FROM projects p
                        JOIN project_members pm ON pm.project_id = p.id
                        WHERE pm.student_id = $student_id
                        AND p.is_archived = 0
                        AND p.project_year = '$year'
                        $ignore_sql
                        LIMIT 1");

if ($proj_q && $proj_q->num_rows > 0) {
    $proj_data = $proj_q->fetch_assoc();
    $gname = htmlspecialchars($proj_data['group_name']);
    echo json_encode(['status' => 'error', 'message' => "Student is already in '$gname'."]);
    exit();
}

// If everything passes, return success with the student's name
echo json_encode(['status' => 'success', 'name' => $student_data['full_name']]);
?>