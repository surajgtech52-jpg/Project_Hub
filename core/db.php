<?php
$servername = "localhost"; 
$username = "root";
$password = ""; 
$dbname = "project_hub_db";

// CHANGE THIS NUMBER TO MATCH YOUR XAMPP MYSQL PORT (usually 3306 or 3307)
$port = 3307; 

$conn = new mysqli($servername, $username, $password, $dbname, $port);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// NOTE: For production, run migrations instead of creating tables at runtime.

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

    // Persist leader_id on projects (still used for joins/ownership).
    $conn->query("UPDATE projects SET leader_id = $leader_student_id WHERE id = $project_id");

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
            SELECT GROUP_CONCAT(
                CASE
                    WHEN pm2.is_leader = 1 THEN CONCAT(s2.full_name, ' (Leader - ', s2.moodle_id, ')')
                    ELSE CONCAT(s2.full_name, ' (', s2.moodle_id, ')')
                END
                ORDER BY pm2.is_leader DESC, s2.full_name
                SEPARATOR '\n'
            )
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
?>