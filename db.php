<?php
$servername = "localhost"; 
$username = "root";
$password = ""; 
$dbname = "project_hub_db";

// CHANGE THIS NUMBER TO MATCH YOUR XAMPP MYSQL PORT (usually 3306 or 3307)
$port = 3306; 

// Disable mysqli exceptions globally (PHP 8.1+ default) so that queries return false on error instead of throwing a fatal exception causing a blank page.
mysqli_report(MYSQLI_REPORT_OFF);
$conn = new mysqli($servername, $username, $password, $dbname, $port);

if ($conn->connect_error) {
    if (function_exists('log_error_message')) {
        log_error_message("Database Connection Failed: " . $conn->connect_error, "Database");
    }
    if (defined('APP_DEBUG') && APP_DEBUG) {
        die("Connection failed: " . $conn->connect_error);
    } else {
        die("A system error occurred. Please try again later.");
    }
}

// NOTE: For production, run migrations (migrate.php) instead of creating tables at runtime.

/**
 * Writes membership rows for a project (by moodle IDs).
 * Also sets leader_id on projects and keeps membership consistent.
 */
function set_project_members(mysqli $conn, int $project_id, array $member_moodle_ids, int $leader_index, int $fallback_leader_student_id): int {
    $project_id = (int)$project_id;
    $leader_index = (int)$leader_index;

    $clean = [];
    foreach ($member_moodle_ids as $mid) {
        $m = trim((string)$mid);
        if ($m === '') continue;
        $clean[] = $m;
    }
    $clean = array_values(array_unique($clean));

    // Resolve to student IDs (ignore invalid IDs silently; validation happens elsewhere).
    $resolved = [];
    foreach ($clean as $mid) {
        $m_esc = $conn->real_escape_string($mid);
        $q = $conn->query("SELECT id FROM student WHERE moodle_id = '$m_esc' LIMIT 1");
        if ($q && $q->num_rows > 0) {
            $resolved[] = [
                'moodle_id' => $mid,
                'student_id' => (int)$q->fetch_assoc()['id'],
            ];
        }
    }

    // Determine leader student_id.
    $leader_student_id = $fallback_leader_student_id;
    if (isset($resolved[$leader_index])) {
        $leader_student_id = (int)$resolved[$leader_index]['student_id'];
    }

    // Rewrite membership rows.
    $conn->query("DELETE FROM project_members WHERE project_id = $project_id");
    foreach ($resolved as $i => $row) {
        $sid = (int)$row['student_id'];
        $is_leader = ($sid === $leader_student_id) ? 1 : 0;
        $conn->query("INSERT IGNORE INTO project_members (project_id, student_id, is_leader) VALUES ($project_id, $sid, $is_leader)");
    }

    // Ensure leader is present.
    $conn->query("INSERT IGNORE INTO project_members (project_id, student_id, is_leader) VALUES ($project_id, $leader_student_id, 1)");


    return $leader_student_id;
}

/**
 * SQL snippet (string) to compute dynamic member_details for a project row alias `p`.
 * Usage: SELECT p.*, (" . project_member_details_sql('p') . ") AS member_details FROM projects p ...
 */
function project_member_details_sql(string $project_alias = 'p'): string {
    $a = preg_replace('/[^a-zA-Z0-9_]/', '', $project_alias);
    if ($a === '') $a = 'p';
    return "
        (
            SELECT TRIM(GROUP_CONCAT(
                CASE
                    WHEN pm2.is_leader = 1 THEN CONCAT(s2.full_name, IF(s2.status = 'Disabled', ' [Disabled]', ''), ' (Leader - ', s2.moodle_id, ')')
                    ELSE CONCAT(s2.full_name, IF(s2.status = 'Disabled', ' [Disabled]', ''), ' (', s2.moodle_id, ')')
                END
                ORDER BY pm2.is_leader DESC, s2.full_name
                SEPARATOR '\n'
            ))
            FROM project_members pm2
            JOIN student s2 ON pm2.student_id = s2.id
            WHERE pm2.project_id = {$a}.id
        )
    ";
}

/**
 * Password helpers (support legacy plaintext and upgrade on login).
 */
function password_is_hash(string $stored): bool {
    return str_starts_with($stored, '$2y$') || str_starts_with($stored, '$argon2');
}

function verify_and_upgrade_password(mysqli $conn, string $table, int $id, string $provided_password, string $stored_password): bool {
    $ok = false;
    if (password_is_hash($stored_password)) {
        $ok = password_verify($provided_password, $stored_password);
    } else {
        // Legacy plaintext
        $ok = hash_equals($stored_password, $provided_password);
    }
    if ($ok && !password_is_hash($stored_password)) {
        $newHash = password_hash($provided_password, PASSWORD_DEFAULT);
        $tbl = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
        $id = (int)$id;
        $h = $conn->real_escape_string($newHash);
        $conn->query("UPDATE `$tbl` SET password='$h' WHERE id=$id");
    }
    return $ok;
}

/**
 * Upload helper: validates file and stores with random name.
 * Returns ['ok'=>true,'path'=>..., 'original'=>...] or ['ok'=>false,'error'=>...]
 */
function store_uploaded_file(array $file, string $prefix, string $upload_dir = 'uploads/'): array {
    if (!isset($file['error']) || $file['error'] !== 0) return ['ok'=>false,'error'=>'Upload failed.'];
    if (!isset($file['size']) || $file['size'] <= 0) return ['ok'=>false,'error'=>'Empty file.'];
    if (defined('UPLOAD_MAX_BYTES') && $file['size'] > UPLOAD_MAX_BYTES) return ['ok'=>false,'error'=>'File too large.'];

    $orig = (string)($file['name'] ?? 'file');
    $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    if ($ext === '' || !in_array($ext, (defined('UPLOAD_ALLOWED_EXT') ? UPLOAD_ALLOWED_EXT : []), true)) {
        return ['ok'=>false,'error'=>'File type not allowed.'];
    }
    // Block dangerous extensions even if someone adds them later.
    if (in_array($ext, ['php','phtml','phar','htaccess','exe','bat','cmd','js','sh'], true)) {
        return ['ok'=>false,'error'=>'File type not allowed.'];
    }

    $tmp = $file['tmp_name'] ?? '';
    if (!is_string($tmp) || $tmp === '' || !is_uploaded_file($tmp)) return ['ok'=>false,'error'=>'Invalid upload.'];

    $mime = '';
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $mime = (string)finfo_file($finfo, $tmp);
            finfo_close($finfo);
        }
    }
    if ($mime !== '' && defined('UPLOAD_ALLOWED_MIME') && !in_array($mime, UPLOAD_ALLOWED_MIME, true)) {
        return ['ok'=>false,'error'=>'File MIME not allowed.'];
    }

    if (!is_dir($upload_dir)) @mkdir($upload_dir, 0777, true);
    $rand = bin2hex(random_bytes(12));
    $safePrefix = preg_replace('/[^a-zA-Z0-9_\\-]/', '', $prefix);
    $newName = $safePrefix . '_' . $rand . '.' . $ext;
    $target = rtrim($upload_dir, '/\\') . '/' . $newName;

    if (!move_uploaded_file($tmp, $target)) return ['ok'=>false,'error'=>'Failed to save file.'];
    return ['ok'=>true,'path'=>$target,'original'=>$orig];
}

/**
 * Returns rows of where a Moodle ID is already used across the system.
 * Each row: ['role' => 'student|guide|head|admin', 'id' => int|null]
 */
function find_moodle_id_owners(mysqli $conn, string $moodle_id): array {
    $m = $conn->real_escape_string($moodle_id);
    $sql = "
        SELECT 'admin' AS role, id FROM admin WHERE moodle_id = '$m'
        UNION ALL
        SELECT 'head'  AS role, id FROM head  WHERE moodle_id = '$m'
        UNION ALL
        SELECT 'guide' AS role, id FROM guide WHERE moodle_id = '$m'
        UNION ALL
        SELECT 'student' AS role, id FROM student WHERE moodle_id = '$m'
    ";
    $res = $conn->query($sql);
    $rows = [];
    if ($res) {
        while ($r = $res->fetch_assoc()) $rows[] = $r;
    }
    return $rows;
}

/**
 * True if moodle_id exists anywhere besides the optional excluded record.
 */
function moodle_id_in_use(mysqli $conn, string $moodle_id, ?string $exclude_role = null, ?int $exclude_id = null): bool {
    $owners = find_moodle_id_owners($conn, $moodle_id);
    foreach ($owners as $o) {
        $role = $o['role'] ?? null;
        $id = isset($o['id']) ? (int)$o['id'] : null;
        if ($exclude_role !== null && $exclude_id !== null && $role === $exclude_role && $id === $exclude_id) continue;
        return true;
    }
    return false;
}

// Polyfill for PHP versions older than 8.0 (Keep this if you added it!)
if (!function_exists('str_contains')) {
    function str_contains($haystack, $needle) {
        return $needle !== '' && strpos($haystack, $needle) !== false;
    }
}

/**
 * Creates or updates a project log entry.
 * Returns the log ID on success, 0 on failure.
 */
function create_or_update_log(mysqli $conn, int $project_id, string $created_by_role, int $created_by_id, string $created_by_name, string $log_title, array $log_entries, string $log_date = null, string $progress_planned = '', string $progress_achieved = ''): int {
    $save = save_project_log($conn, [
        'project_id' => $project_id,
        'log_id' => 0,
        'created_by_role' => $created_by_role,
        'created_by_id' => $created_by_id,
        'created_by_name' => $created_by_name,
        'log_title' => $log_title,
        'log_date' => $log_date,
        'log_date_to' => null,
        'log_status' => $progress_achieved !== '' ? $progress_achieved : 'Working',
        'log_entries' => $log_entries,
        'guide_review' => null,
    ]);
    return $save['ok'] ? (int)$save['id'] : 0;
}

/**
 * Returns project_logs columns (lowercase => true).
 */
function project_logs_columns(mysqli $conn): array {
    static $cache = null;
    if ($cache !== null) return $cache;

    $cache = [];
    $res = $conn->query("SHOW COLUMNS FROM project_logs");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $cache[strtolower((string)$row['Field'])] = true;
        }
    }
    return $cache;
}

function project_logs_has_column(mysqli $conn, string $column): bool {
    $cols = project_logs_columns($conn);
    return isset($cols[strtolower($column)]);
}

/**
 * Normalizes mixed/legacy log payloads into [{planned, achieved}, ...].
 */
function normalize_log_entries_from_row(array $row): array {
    $entries = [];
    $rawEntries = $row['log_entries'] ?? null;

    if (is_string($rawEntries) && trim($rawEntries) !== '') {
        $decoded = json_decode($rawEntries, true);
        if (is_array($decoded)) $entries = $decoded;
    } elseif (is_array($rawEntries)) {
        $entries = $rawEntries;
    }

    // Legacy fallback: some rows may still store table data in progress_planned.
    if (empty($entries) && !empty($row['progress_planned']) && is_string($row['progress_planned'])) {
        $decoded = json_decode($row['progress_planned'], true);
        if (is_array($decoded)) {
            $entries = $decoded;
        } else {
            $entries = [[
                'planned' => (string)$row['progress_planned'],
                'achieved' => (string)($row['progress_achieved'] ?? ''),
            ]];
        }
    }

    $normalized = [];
    foreach ($entries as $entry) {
        if (is_array($entry)) {
            $planned = trim((string)($entry['planned'] ?? ($entry['description'] ?? '')));
            $achieved = trim((string)($entry['achieved'] ?? ''));
        } else {
            $planned = trim((string)$entry);
            $achieved = '';
        }

        if ($planned === '' && $achieved === '') continue;
        $normalized[] = ['planned' => $planned, 'achieved' => $achieved];
    }

    return $normalized;
}

function encode_log_entries_json(array $entries): string {
    $clean = [];
    foreach ($entries as $entry) {
        if (!is_array($entry)) continue;
        $planned = trim((string)($entry['planned'] ?? ''));
        $achieved = trim((string)($entry['achieved'] ?? ''));
        if ($planned === '' && $achieved === '') continue;
        $clean[] = ['planned' => $planned, 'achieved' => $achieved];
    }
    $encoded = json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    return is_string($encoded) ? $encoded : '[]';
}

/**
 * Saves a weekly project log and returns ['ok' => bool, 'id' => int, 'error' => string].
 */
function save_project_log(mysqli $conn, array $data): array {
    $project_id = (int)($data['project_id'] ?? 0);
    $log_id = (int)($data['log_id'] ?? 0);
    $created_by_id = (int)($data['created_by_id'] ?? 0);
    $created_by_role = $conn->real_escape_string((string)($data['created_by_role'] ?? 'student'));
    $created_by_name = $conn->real_escape_string((string)($data['created_by_name'] ?? 'Unknown'));
    $log_title = $conn->real_escape_string(trim((string)($data['log_title'] ?? 'Weekly Log')));

    $log_status_raw = trim((string)($data['log_status'] ?? 'Working'));
    $log_status = $conn->real_escape_string($log_status_raw !== '' ? $log_status_raw : 'Working');
    $log_date_from_raw = trim((string)($data['log_date'] ?? ''));
    $log_date_to_raw = trim((string)($data['log_date_to'] ?? ''));

    $log_date = $log_date_from_raw !== '' ? "'" . $conn->real_escape_string($log_date_from_raw) . "'" : 'NULL';
    $log_date_to = $log_date_to_raw !== '' ? "'" . $conn->real_escape_string($log_date_to_raw) . "'" : 'NULL';

    $entries_json_raw = encode_log_entries_json(is_array($data['log_entries'] ?? null) ? $data['log_entries'] : []);
    $entries_json = $conn->real_escape_string($entries_json_raw);

    $cols = project_logs_columns($conn);
    if (empty($cols)) {
        return ['ok' => false, 'id' => 0, 'error' => 'project_logs table is missing.'];
    }

    $guide_review_provided = array_key_exists('guide_review', $data);
    $guide_review_sql = null;
    if ($guide_review_provided) {
        $guide_review_raw = trim((string)$data['guide_review']);
        $guide_review_sql = $guide_review_raw === '' ? "''" : "'" . $conn->real_escape_string($guide_review_raw) . "'";
    }

    if ($log_id > 0) {
        $set = ["log_title='$log_title'"];

        if (isset($cols['log_entries'])) $set[] = "log_entries='$entries_json'";
        if (isset($cols['progress_planned'])) $set[] = "progress_planned='$entries_json'";
        if (isset($cols['log_status'])) $set[] = "log_status='$log_status'";
        if (isset($cols['log_date'])) $set[] = "log_date=$log_date";
        if (isset($cols['log_date_to'])) $set[] = "log_date_to=$log_date_to";
        if ($guide_review_provided) {
            if (isset($cols['guide_review'])) $set[] = "guide_review=$guide_review_sql";
            if (isset($cols['guides_review'])) $set[] = "guides_review=$guide_review_sql";
        }
        $set[] = "updated_at=NOW()";

        $sql = "UPDATE project_logs SET " . implode(', ', $set) . " WHERE id = $log_id AND project_id = $project_id";
        $ok = $conn->query($sql) === TRUE;
        if (!$ok) {
            return ['ok' => false, 'id' => 0, 'error' => (string)$conn->error];
        }
        if ($conn->affected_rows === 0) {
            return ['ok' => false, 'id' => 0, 'error' => 'Log not found for this project.'];
        }
        return ['ok' => true, 'id' => $log_id, 'error' => ''];
    }

    $insertCols = ['project_id', 'created_by_role', 'created_by_id', 'created_by_name', 'log_title'];
    $insertVals = ["$project_id", "'$created_by_role'", "$created_by_id", "'$created_by_name'", "'$log_title'"];

    if (isset($cols['log_entries'])) {
        $insertCols[] = 'log_entries';
        $insertVals[] = "'$entries_json'";
    }
    if (isset($cols['progress_planned'])) {
        $insertCols[] = 'progress_planned';
        $insertVals[] = "'$entries_json'";
    }
    if (isset($cols['log_status'])) {
        $insertCols[] = 'log_status';
        $insertVals[] = "'$log_status'";
    }
    if (isset($cols['log_date'])) {
        $insertCols[] = 'log_date';
        $insertVals[] = $log_date;
    }
    if (isset($cols['log_date_to'])) {
        $insertCols[] = 'log_date_to';
        $insertVals[] = $log_date_to;
    }
    if ($guide_review_provided) {
        if (isset($cols['guide_review'])) {
            $insertCols[] = 'guide_review';
            $insertVals[] = $guide_review_sql;
        }
        if (isset($cols['guides_review'])) {
            $insertCols[] = 'guides_review';
            $insertVals[] = $guide_review_sql;
        }
    }

    $sql = "INSERT INTO project_logs (" . implode(', ', $insertCols) . ") VALUES (" . implode(', ', $insertVals) . ")";
    if ($conn->query($sql) !== TRUE) {
        return ['ok' => false, 'id' => 0, 'error' => (string)$conn->error];
    }

    return ['ok' => true, 'id' => (int)$conn->insert_id, 'error' => ''];
}

/**
 * Adds a description entry to an existing log.
 * Returns true on success, false on failure.
 */
function add_log_entry(mysqli $conn, int $log_id, string $description): bool {
    $log_id = (int)$log_id;
    $description = trim($description);
    if ($description === '') return false;

    $row = get_log_by_id($conn, $log_id);
    if ($row === null) return false;

    $entries = is_array($row['log_entries'] ?? null) ? $row['log_entries'] : [];
    $entries[] = ['planned' => $description, 'achieved' => ''];

    $save = save_project_log($conn, [
        'project_id' => (int)($row['project_id'] ?? 0),
        'log_id' => $log_id,
        'created_by_role' => (string)($row['created_by_role'] ?? 'student'),
        'created_by_id' => (int)($row['created_by_id'] ?? 0),
        'created_by_name' => (string)($row['created_by_name'] ?? 'Unknown'),
        'log_title' => (string)($row['log_title'] ?? 'Weekly Log'),
        'log_date' => (string)($row['log_date'] ?? ''),
        'log_date_to' => (string)($row['log_date_to'] ?? ''),
        'log_status' => (string)($row['log_status'] ?? 'Working'),
        'log_entries' => $entries,
    ]);

    return $save['ok'];
}

/**
 * Fetches all logs for a project.
 * Returns array of log rows, empty array if none found.
 */
function get_project_logs(mysqli $conn, int $project_id): array {
    $project_id = (int)$project_id;
    $order = project_logs_has_column($conn, 'log_date')
        ? "COALESCE(log_date, created_at) DESC, id DESC"
        : "created_at DESC, id DESC";
    $result = $conn->query("SELECT * FROM project_logs WHERE project_id = $project_id ORDER BY $order");
    
    $logs = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $row['log_entries'] = normalize_log_entries_from_row($row);
            if (!isset($row['guide_review']) || $row['guide_review'] === null) {
                $row['guide_review'] = (string)($row['guides_review'] ?? '');
            }
            if (!isset($row['log_status']) || trim((string)$row['log_status']) === '') {
                $row['log_status'] = 'Working';
            }
            $logs[] = $row;
        }
    }
    return $logs;
}

/**
 * Fetches a single log entry by ID.
 * Returns the log row with decoded entries or null if not found.
 */
function get_log_by_id(mysqli $conn, int $log_id): ?array {
    $log_id = (int)$log_id;
    $result = $conn->query("SELECT * FROM project_logs WHERE id = $log_id LIMIT 1");
    
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $row['log_entries'] = normalize_log_entries_from_row($row);
        if (!isset($row['guide_review']) || $row['guide_review'] === null) {
            $row['guide_review'] = (string)($row['guides_review'] ?? '');
        }
        if (!isset($row['log_status']) || trim((string)$row['log_status']) === '') {
            $row['log_status'] = 'Working';
        }
        return $row;
    }
    return null;
}

/**
 * Returns the project ID for a given log, or 0 if not found.
 */
function get_project_id_for_log(mysqli $conn, int $log_id): int {
    $log_id = (int)$log_id;
    if ($log_id <= 0) return 0;
    $res = $conn->query("SELECT project_id FROM project_logs WHERE id = $log_id LIMIT 1");
    if (!$res || $res->num_rows === 0) return 0;
    $row = $res->fetch_assoc();
    return (int)($row['project_id'] ?? 0);
}

/**
 * Checks whether a role/user can manage logs for a given project.
 */
function can_manage_project_log(mysqli $conn, string $role, int $actor_id, int $project_id, ?string $head_assigned_year = null): bool {
    $project_id = (int)$project_id;
    $actor_id = (int)$actor_id;
    if ($project_id <= 0 || $actor_id <= 0) return false;

    if ($role === 'admin') {
        $q = $conn->query("SELECT id FROM projects WHERE id = $project_id LIMIT 1");
        return $q && $q->num_rows > 0;
    }

    if ($role === 'guide') {
        $q = $conn->query("SELECT id FROM projects WHERE id = $project_id AND assigned_guide_id = $actor_id LIMIT 1");
        return $q && $q->num_rows > 0;
    }

    if ($role === 'head') {
        $yearEsc = $conn->real_escape_string((string)$head_assigned_year);
        $q = $conn->query("SELECT id FROM projects WHERE id = $project_id AND project_year = '$yearEsc' LIMIT 1");
        return $q && $q->num_rows > 0;
    }

    if ($role === 'student') {
        $q = $conn->query("SELECT pm.project_id FROM project_members pm WHERE pm.project_id = $project_id AND pm.student_id = $actor_id LIMIT 1");
        return $q && $q->num_rows > 0;
    }

    return false;
}

/**
 * Deletes a log entry by ID.
 * Returns true on success, false on failure.
 */
function delete_log(mysqli $conn, int $log_id): bool {
    $log_id = (int)$log_id;
    return $conn->query("DELETE FROM project_logs WHERE id = $log_id") === TRUE;
}

/**
 * Updates a specific field in a log entry.
 * Returns true on success, false on failure.
 */
function update_log_field(mysqli $conn, int $log_id, string $field, string $value): bool {
    $log_id = (int)$log_id;
    $field = trim($field);
    $value = $conn->real_escape_string($value);
    
    // Only allow specific fields to be updated
    $allowed_fields = ['progress_planned', 'progress_achieved', 'archive', 'guide_review', 'log_date', 'log_date_to', 'log_status'];
    if (!in_array($field, $allowed_fields)) {
        return false;
    }

    // Backward compatibility for installs where the column is still named guides_review.
    if ($field === 'guide_review' && !project_logs_has_column($conn, 'guide_review') && project_logs_has_column($conn, 'guides_review')) {
        $field = 'guides_review';
    }
    if (!project_logs_has_column($conn, $field)) {
        return false;
    }
    
    return $conn->query("UPDATE project_logs SET `$field` = '$value', updated_at = CURRENT_TIMESTAMP WHERE id = $log_id") === TRUE;
}
?>