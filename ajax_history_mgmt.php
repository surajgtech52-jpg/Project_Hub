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
// 1. FETCH HISTORICAL FORM SCHEMA
// ============================================================
if ($action === 'get_historical_form') {
    $session = $_POST['academic_session'] ?? '';
    $year = $_POST['academic_year'] ?? '';
    $semester = (int)($_POST['semester'] ?? 0);

    if (empty($session) || empty($year) || $semester <= 0) {
        send_ajax_response('error', 'Invalid selection criteria.');
    }

    // Role check for Heads
    if ($user_role === 'head' && $year !== $head_year) {
        send_ajax_response('error', "You can only manage history for $head_year.");
    }

    $stmt = $conn->prepare("SELECT form_schema, min_team_size, max_team_size FROM form_settings WHERE academic_session = ? AND academic_year = ? AND semester = ?");
    $stmt->bind_param("ssi", $session, $year, $semester);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        // Fallback: If no archived settings found, try to find current settings to use as a template
        $stmt_fb = $conn->prepare("SELECT form_schema, min_team_size, max_team_size FROM form_settings WHERE academic_session = 'Current' AND academic_year = ? AND semester = ?");
        $stmt_fb->bind_param("si", $year, $semester);
        $stmt_fb->execute();
        $result_fb = $stmt_fb->get_result();
        if ($result_fb->num_rows > 0) {
            $form = $result_fb->fetch_assoc();
        } else {
            $form = ['form_schema' => json_encode([['label' => 'Project Topic', 'type' => 'text', 'required' => true]]), 'min_team_size' => 1, 'max_team_size' => 4];
        }
        $stmt_fb->close();
    } else {
        $form = $result->fetch_assoc();
    }
    $stmt->close();

    // Fetch global folders for this session/sem
    $folders = [];
    $stmt_f = $conn->prepare("SELECT folder_name, instructions FROM upload_requests WHERE is_global = 1 AND academic_session = ? AND academic_year = ? AND semester = ?");
    $stmt_f->bind_param("ssi", $session, $year, $semester);
    $stmt_f->execute();
    $res_f = $stmt_f->get_result();
    while($f = $res_f->fetch_assoc()) { $folders[] = $f; }
    $stmt_f->close();

    send_ajax_response('success', 'Form schema fetched.', [
        'schema' => json_decode($form['form_schema'], true),
        'min_size' => $form['min_team_size'],
        'max_size' => $form['max_team_size'],
        'folders' => $folders
    ]);
}

// ============================================================
// 2. VALIDATE HISTORICAL MEMBERS
// ============================================================
if ($action === 'validate_historical_members') {
    $session = $_POST['academic_session'] ?? '';
    $year = $_POST['academic_year'] ?? '';
    $semester = (int)($_POST['semester'] ?? 0);
    $moodle_ids = $_POST['moodle_ids'] ?? []; // Array of IDs

    if (empty($moodle_ids)) send_ajax_response('error', 'No Moodle IDs provided.');

    $validated = [];
    $invalid = [];

    foreach ($moodle_ids as $m_id) {
        $m_id = $conn->real_escape_string(trim($m_id));
        
        // Check student_history for that specific session/year/sem
        $stmt = $conn->prepare("SELECT s.id, s.full_name, s.division 
                                FROM student_history sh 
                                JOIN student s ON sh.student_id = s.id 
                                WHERE sh.moodle_id = ? AND sh.academic_session = ? AND sh.academic_year = ? AND sh.semester = ?");
        $stmt->bind_param("sssi", $m_id, $session, $year, $semester);
        $stmt->execute();
        $res = $stmt->get_result();

        if ($res->num_rows > 0) {
            $student = $res->fetch_assoc();
        } else {
            // Fallback: Check if they are just an active student in the system
            $stmt2 = $conn->prepare("SELECT id, full_name, division FROM student WHERE moodle_id = ? AND status = 'Active'");
            $stmt2->bind_param("s", $m_id);
            $stmt2->execute();
            $res2 = $stmt2->get_result();
            if ($res2->num_rows > 0) {
                $student = $res2->fetch_assoc();
            } else {
                $student = null;
            }
            $stmt2->close();
        }
        
        if ($student) {
            
            // Check if already in a group for this session
            $check_group = $conn->prepare("SELECT p.id FROM projects p 
                                           JOIN project_members pm ON p.id = pm.project_id 
                                           WHERE pm.student_id = ? AND p.academic_session = ? AND p.project_year = ? AND p.semester = ?");
            $check_group->bind_param("isss", $student['id'], $session, $year, $semester);
            $check_group->execute();
            if ($check_group->get_result()->num_rows > 0) {
                $invalid[] = "$m_id (Already in a group for this session)";
            } else {
                $validated[] = $student;
            }
            $check_group->close();
        } else {
            $invalid[] = "$m_id (Not found in history for $session $year Sem $semester)";
        }
        $stmt->close();
    }

    if (!empty($invalid)) {
        send_ajax_response('error', 'Validation failed for some members.', ['invalid' => $invalid]);
    }

    send_ajax_response('success', 'All members validated.', ['members' => $validated]);
}

// ============================================================
// 3. SAVE HISTORICAL PROJECT
// ============================================================
if ($action === 'save_historical_project') {
    verify_csrf_token();

    $session = $_POST['academic_session'] ?? '';
    $year = $_POST['academic_year'] ?? '';
    $semester = (int)($_POST['semester'] ?? 0);
    $leader_id = (int)($_POST['leader_id'] ?? 0);
    $member_ids = $_POST['member_ids'] ?? []; // Array of student IDs
    $form_data = $_POST['form_data'] ?? []; // JSON from the dynamic form

    if (empty($session) || empty($year) || empty($member_ids)) {
        send_ajax_response('error', 'Incomplete project data.');
    }

    // Role check
    if ($user_role === 'head' && $year !== $head_year) {
        send_ajax_response('error', "Unauthorized.");
    }

    $conn->begin_transaction();
    try {
        // Extract common fields dynamically
        $dept = ''; $topic1 = ''; $topic2 = ''; $topic3 = '';
        $extra_data_array = [];
        foreach ($form_data as $lbl => $val) {
            if (stripos($lbl, 'Department') !== false) $dept = $val;
            elseif (stripos($lbl, 'Preference 1') !== false) $topic1 = $val;
            elseif (stripos($lbl, 'Preference 2') !== false) $topic2 = $val;
            elseif (stripos($lbl, 'Preference 3') !== false) $topic3 = $val;
            else $extra_data_array[$lbl] = $val;
        }
        
        $extra_data = empty($extra_data_array) ? null : json_encode($extra_data_array, JSON_UNESCAPED_UNICODE);

        // Fetch first member's division to use as project division
        $stmt_div = $conn->prepare("SELECT division FROM student WHERE id = ?");
        $stmt_div->bind_param("i", $member_ids[0]);
        $stmt_div->execute();
        $p_div = $stmt_div->get_result()->fetch_assoc()['division'] ?? 'A';
        $stmt_div->close();

        $group_name = "Group-" . time() . "-" . $year;

        // Insert project
        $stmt = $conn->prepare("INSERT INTO projects (project_year, semester, division, group_name, department, topic_1, topic_2, topic_3, extra_data, academic_session, is_archived) 
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)");
        $stmt->bind_param("sissssssss", $year, $semester, $p_div, $group_name, $dept, $topic1, $topic2, $topic3, $extra_data, $session);
        
        if (!$stmt->execute()) throw new Exception("Failed to create project: " . $stmt->error);
        $project_id = $stmt->insert_id;
        $stmt->close();

        // Add members
        foreach ($member_ids as $sid) {
            $is_leader = ($sid == $leader_id) ? 1 : 0;
            $stmt_m = $conn->prepare("INSERT INTO project_members (project_id, student_id, is_leader) VALUES (?, ?, ?)");
            $stmt_m->bind_param("iii", $project_id, $sid, $is_leader);
            if (!$stmt_m->execute()) throw new Exception("Failed to add member $sid");
            $stmt_m->close();
        }

        // [PREDEFINED FOLDERS] - Copy global upload requirements to this specific project
        // This ensures the historical project has the same folders that were required "at that time"
        $stmt_global = $conn->prepare("SELECT folder_name, instructions FROM upload_requests WHERE is_global = 1 AND academic_session = ? AND academic_year = ? AND semester = ?");
        $stmt_global->bind_param("ssi", $session, $year, $semester);
        $stmt_global->execute();
        $global_reqs = $stmt_global->get_result();
        while ($g_req = $global_reqs->fetch_assoc()) {
            $f_name = $g_req['folder_name'];
            $instr = $g_req['instructions'];
            $stmt_ins = $conn->prepare("INSERT INTO upload_requests (project_id, folder_name, instructions, academic_session, academic_year, semester, is_global) VALUES (?, ?, ?, ?, ?, ?, 0)");
            $stmt_ins->bind_param("issssi", $project_id, $f_name, $instr, $session, $year, $semester);
            $stmt_ins->execute();
            $stmt_ins->close();
        }
        $stmt_global->close();

        $conn->commit();
        send_ajax_response('success', "Historical group created successfully in the Vault! Group Name: $group_name");
    } catch (Exception $e) {
        $conn->rollback();
        send_ajax_response('error', "Error saving project: " . $e->getMessage());
    }
}

send_ajax_response('error', 'Unknown action.');
