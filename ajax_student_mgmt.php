<?php
require_once 'bootstrap.php';

// Strict security check
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin', 'head'])) {
    send_ajax_response('error', 'Unauthorized access.');
}

$action = $_POST['action'] ?? '';
$user_role = $_SESSION['role'];
$user_id = $_SESSION['user_id'];

// Get Head's assigned year if applicable
$head_year = null;
if ($user_role === 'head') {
    $stmt = $conn->prepare("SELECT assigned_year FROM head WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $head_year = $stmt->get_result()->fetch_assoc()['assigned_year'] ?? null;
    $stmt->close();
}

// ============================================================
// 1. IMPORT STUDENTS FROM CSV
// ============================================================
if ($action === 'import_csv') {
    verify_csrf_token();
    
    $year = $_POST['academic_year'] ?? '';
    $semester = (int)($_POST['semester'] ?? 0);
    
    // Scoping for Heads
    if ($user_role === 'head' && $year !== $head_year) {
        send_ajax_response('error', "You are only authorized to import students for $head_year.");
    }
    
    if (empty($year) || $semester <= 0) {
        send_ajax_response('error', 'Please select a valid Year and Semester.');
    }

    if (!isset($_FILES['student_csv']) || $_FILES['student_csv']['error'] !== UPLOAD_ERR_OK) {
        send_ajax_response('error', 'No file uploaded or upload error.');
    }

    $file = $_FILES['student_csv']['tmp_name'];
    $handle = fopen($file, 'r');
    
    // Check headers
    $headers = fgetcsv($handle);
    $expected_headers = ['moodle_id', 'full_name', 'division', 'phone_number'];
    
    // Normalize headers for comparison
    $normalized_headers = array_map(function($h) { return strtolower(trim($h)); }, $headers);
    
    foreach ($expected_headers as $eh) {
        if (!in_array($eh, $normalized_headers)) {
            fclose($handle);
            send_ajax_response('error', "Invalid CSV format. Missing required column: $eh");
        }
    }

    // Map header indexes
    $idx = array_flip($normalized_headers);
    
    $success_count = 0;
    $error_count = 0;
    $default_password = password_hash('1234', PASSWORD_DEFAULT);
    
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("
            INSERT INTO student (moodle_id, password, full_name, academic_year, current_semester, division, phone_number, status, deleted_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, 'Active', NULL)
            ON DUPLICATE KEY UPDATE 
            full_name = VALUES(full_name),
            academic_year = VALUES(academic_year),
            current_semester = VALUES(current_semester),
            division = VALUES(division),
            phone_number = VALUES(phone_number),
            status = 'Active',
            deleted_at = NULL
        ");

        while (($row = fgetcsv($handle)) !== FALSE) {
            if (empty($row[$idx['moodle_id']])) continue;
            
            $moodle_id = trim($row[$idx['moodle_id']]);
            $full_name = trim($row[$idx['full_name']]);
            $division = trim($row[$idx['division']]);
            $phone = trim($row[$idx['phone_number']]);
            
            $stmt->bind_param("ssssiss", $moodle_id, $default_password, $full_name, $year, $semester, $division, $phone);
            
            if ($stmt->execute()) {
                $success_count++;
            } else {
                $error_count++;
            }
        }
        $stmt->close();
        $conn->commit();
        fclose($handle);

        send_ajax_response('success', "Import complete! $success_count students processed successfully" . ($error_count > 0 ? ", $error_count errors." : "."));
    } catch (Exception $e) {
        $conn->rollback();
        fclose($handle);
        send_ajax_response('error', "Database error during import: " . $e->getMessage());
    }
}

// ============================================================
// 2. BULK DELETE STUDENTS
// ============================================================
if ($action === 'bulk_delete') {
    verify_csrf_token();
    
    $year = $_POST['academic_year'] ?? '';
    $semester = (int)($_POST['semester'] ?? 0);
    
    // Scoping for Heads
    if ($user_role === 'head' && $year !== $head_year) {
        send_ajax_response('error', "You are only authorized to delete students for $head_year.");
    }
    
    if (empty($year) || $semester <= 0) {
        send_ajax_response('error', 'Please select a valid Year and Semester.');
    }
    
    // Soft delete
    $stmt = $conn->prepare("UPDATE student SET deleted_at = NOW(), status = 'Disabled' WHERE academic_year = ? AND current_semester = ? AND deleted_at IS NULL");
    $stmt->bind_param("si", $year, $semester);
    
    if ($stmt->execute()) {
        $affected = $stmt->affected_rows;
        $stmt->close();
        send_ajax_response('success', "Successfully removed $affected students from $year Semester $semester.");
    } else {
        send_ajax_response('error', "Delete operation failed: " . $conn->error);
    }
}

// ============================================================
// 3. GET INACTIVE STUDENTS
// ============================================================
if ($action === 'get_inactive_students') {
    $year = $_POST['academic_year'] ?? '';
    $semester = (int)($_POST['semester'] ?? 0);
    
    // Scoping for Heads
    if ($user_role === 'head' && $year !== $head_year) {
        send_ajax_response('error', "Unauthorized.");
    }
    
    $query = "SELECT id, moodle_id, full_name, academic_year, current_semester, division, status, deleted_at 
              FROM student 
              WHERE (status = 'Disabled' OR deleted_at IS NOT NULL)";
    
    $params = [];
    $types = "";
    
    if (!empty($year)) {
        $query .= " AND academic_year = ?";
        $params[] = $year;
        $types .= "s";
    }
    
    if ($semester > 0) {
        $query .= " AND current_semester = ?";
        $params[] = $semester;
        $types .= "i";
    }
    
    $query .= " ORDER BY deleted_at DESC, full_name ASC";
    
    $stmt = $conn->prepare($query);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $students = [];
    while ($row = $result->fetch_assoc()) {
        $students[] = $row;
    }
    $stmt->close();
    
    send_ajax_response('success', 'Inactive students fetched.', ['students' => $students]);
}

// ============================================================
// 4. REACTIVATE STUDENT
// ============================================================
if ($action === 'reactivate_student') {
    verify_csrf_token();
    
    $student_id = (int)($_POST['student_id'] ?? 0);
    
    if ($student_id <= 0) send_ajax_response('error', 'Invalid student ID.');
    
    // Fetch student first to check scoping
    $stmt_f = $conn->prepare("SELECT academic_year FROM student WHERE id = ?");
    $stmt_f->bind_param("i", $student_id);
    $stmt_f->execute();
    $res = $stmt_f->get_result();
    if ($res->num_rows === 0) send_ajax_response('error', 'Student not found.');
    $student = $res->fetch_assoc();
    $stmt_f->close();

    if ($user_role === 'head' && $student['academic_year'] !== $head_year) {
        send_ajax_response('error', 'Unauthorized.');
    }
    
    $stmt = $conn->prepare("UPDATE student SET deleted_at = NULL, status = 'Active' WHERE id = ?");
    $stmt->bind_param("i", $student_id);
    if ($stmt->execute()) {
        send_ajax_response('success', 'Student reactivated successfully.');
    } else {
        send_ajax_response('error', 'Failed to reactivate student.');
    }
    $stmt->close();
}

// ============================================================
// 5. PERMANENTLY DELETE STUDENT
// ============================================================
if ($action === 'permanent_delete_student') {
    verify_csrf_token();
    
    $student_id = (int)($_POST['student_id'] ?? 0);
    
    if ($student_id <= 0) send_ajax_response('error', 'Invalid student ID.');
    
    // Fetch student first to check scoping
    $stmt_f = $conn->prepare("SELECT academic_year FROM student WHERE id = ?");
    $stmt_f->bind_param("i", $student_id);
    $stmt_f->execute();
    $res = $stmt_f->get_result();
    if ($res->num_rows === 0) send_ajax_response('error', 'Student not found.');
    $student = $res->fetch_assoc();
    $stmt_f->close();

    if ($user_role === 'head' && $student['academic_year'] !== $head_year) {
        send_ajax_response('error', 'Unauthorized.');
    }
    
    $stmt = $conn->prepare("DELETE FROM student WHERE id = ?");
    $stmt->bind_param("i", $student_id);
    if ($stmt->execute()) {
        send_ajax_response('success', 'Student permanently deleted.');
    } else {
        send_ajax_response('error', 'Failed to delete student.');
    }
    $stmt->close();
}

// ============================================================
// 6. GET PROJECT DETAILS (LOGS & FILES)
// ============================================================
if ($action === 'get_project_details') {
    $project_id = (int)($_POST['project_id'] ?? 0);
    if ($project_id <= 0) send_ajax_response('error', 'Invalid project ID.');

    // Fetch project to check scoping
    // Fetch ALL project details
    $md_sql = project_member_details_sql('p');
    $sql = "SELECT p.*, ($md_sql) as member_details, s.full_name as leader_name, s.moodle_id as leader_moodle, f.form_schema 
            FROM projects p 
            LEFT JOIN project_members pm_ldr ON pm_ldr.project_id = p.id AND pm_ldr.is_leader = 1 
            LEFT JOIN student s ON pm_ldr.student_id = s.id
            LEFT JOIN form_settings f ON (f.academic_session = p.academic_session OR (p.academic_session IS NULL AND f.academic_session = 'Current')) 
                 AND f.academic_year = p.project_year AND f.semester = p.semester
            WHERE p.id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $project_id);
    $stmt->execute();
    $project = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$project) send_ajax_response('error', 'Project not found.');

    if ($user_role === 'head' && $project['project_year'] !== $head_year) {
        send_ajax_response('error', 'Unauthorized access to this project.');
    }

    $p_year = $project['project_year'];
    $p_sem = (int)$project['semester'];
    $p_session = $project['academic_session'] ?? 'Current';

    // Fetch Upload Requests & Files
    $requests = [];
    $req_sql = "(SELECT * FROM upload_requests WHERE project_id = $project_id) UNION (SELECT * FROM upload_requests WHERE is_global = 1 AND academic_year = '$p_year' AND semester = $p_sem AND academic_session = '$p_session') ORDER BY is_global ASC, id ASC";
    $reqs = $conn->query($req_sql);
    while($r = $reqs->fetch_assoc()) {
        $r_id = $r['id'];
        $r['files'] = [];
        $files = $conn->query("SELECT * FROM student_uploads WHERE request_id = $r_id AND project_id = $project_id ORDER BY uploaded_at DESC");
        while($f = $files->fetch_assoc()) { $r['files'][] = $f; }
        $requests[] = $r;
    }

    // Fetch Logs
    $logs = get_project_logs($conn, $project_id);

    send_ajax_response('success', 'Details fetched.', [
        'project' => $project,
        'requests' => $requests,
        'logs' => $logs
    ]);
}

send_ajax_response('error', 'Unknown action requested.');

