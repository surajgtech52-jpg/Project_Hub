<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
ob_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

// --- GLOBAL ERROR & SHUTDOWN HANDLING ---
// This ensures that even fatal PHP errors return a valid JSON response for AJAX calls
function ajax_fatal_handler() {
    $error = error_get_last();
    if ($error !== NULL && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        $msg = "Fatal Error: " . $error['message'] . " in " . $error['file'] . " on line " . $error['line'];
        log_error_message($msg, "CRITICAL");
        
        if (isset($_POST['is_ajax']) || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] == 'XMLHttpRequest')) {
            if (ob_get_length()) ob_clean();
            header('Content-Type: application/json');
            echo json_encode([
                'status' => 'error',
                'message' => 'A critical server error occurred. Please contact the administrator.',
                'debug' => (defined('APP_DEBUG') && APP_DEBUG) ? $msg : null
            ]);
            exit();
        }
    }
}
register_shutdown_function('ajax_fatal_handler');

function custom_error_handler($errno, $errstr, $errfile, $errline) {
    if (!(error_reporting() & $errno)) return false;
    $msg = "Error [$errno]: $errstr in $errfile on line $errline";
    log_error_message($msg, "PHP_ERROR");
    return false; // Continue to standard PHP error handler
}
set_error_handler("custom_error_handler");

// Centralized error handling configuration
if (defined('APP_DEBUG') && APP_DEBUG) {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    error_reporting(E_ALL);
    ini_set('log_errors', '1');
    $logDir = __DIR__ . '/logs';
    if (!is_dir($logDir)) @mkdir($logDir, 0777, true);
    ini_set('error_log', $logDir . '/php_errors.log');
}

/**
 * Centralized error logging helper
 */
function log_error_message($message, $context = 'General') {
    $timestamp = date('[Y-m-d H:i:s]');
    $logDir = __DIR__ . '/logs';
    if (!is_dir($logDir)) @mkdir($logDir, 0777, true);
    $logFile = $logDir . '/php_errors.log';
    
    $formatted = "$timestamp [$context] $message" . PHP_EOL;
    error_log($formatted, 3, $logFile);
}

// Basic security headers
if (!headers_sent()) {
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
}

// CSRF Protection Engine
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/**
 * Validates CSRF token for all POST requests.
 * Provides detailed logging and JSON feedback for AJAX.
 */
function verify_csrf_token() {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $token_present = isset($_POST['csrf_token']);
        $token_match = $token_present && hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token']);
        
        if (!$token_match) {
            $reason = !$token_present ? "Token missing" : "Token mismatch";
            $log_msg = "CSRF Failure: $reason. POST Token: " . ($_POST['csrf_token'] ?? 'N/A') . " | Session Token: " . ($_SESSION['csrf_token'] ?? 'N/A') . " | URI: " . $_SERVER['REQUEST_URI'];
            log_error_message($log_msg, "SECURITY");
            
            $err = 'Security Error: CSRF token validation failed. Please refresh the page and try again.';
            if (isset($_POST['is_ajax']) || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] == 'XMLHttpRequest')) {
                send_ajax_response('error', $err);
            }
            die($err);
        }
    }
}

// Secure File Upload wrapper (Strict extensions & MIME types)
function secure_store_uploaded_file($file, $prefix) {
    $allowed_extensions = ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'zip', 'rar', 'jpg', 'jpeg', 'png', 'txt'];
    
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'Upload error code: ' . $file['error']];
    }

    $file_info = pathinfo($file['name']);
    $ext = strtolower($file_info['extension'] ?? '');

    if (!in_array($ext, $allowed_extensions)) {
        return ['ok' => false, 'error' => 'Invalid file extension. Allowed: ' . implode(', ', $allowed_extensions)];
    }

    if (function_exists('store_uploaded_file')) {
        return store_uploaded_file($file, $prefix);
    }
    return ['ok' => false, 'error' => 'Base storage function not found.'];
}

/**
 * Standardized AJAX Response handler
 */
function send_ajax_response($status, $message, $extra = []) {
    if (ob_get_length()) ob_clean(); 
    header('Content-Type: application/json');

    if ($status === 'error') {
        log_error_message($message, "AJAX Error");
    }

    $response = array_merge([
        'status' => $status,
        'message' => $message 
    ], $extra);
    echo json_encode($response);
    exit();
}
