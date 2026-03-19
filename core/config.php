<?php
// Production-safe defaults. Set to false on your server.
define('APP_DEBUG', false);

// Upload constraints
define('UPLOAD_MAX_BYTES', 5 * 1024 * 1024); // 5MB

// Allowlist of extensions and MIME types (add more if needed)
define('UPLOAD_ALLOWED_EXT', [
    'pdf','png','jpg','jpeg','webp',
    'zip',
    'doc','docx',
    'ppt','pptx',
    'xls','xlsx',
    'txt'
]);

define('UPLOAD_ALLOWED_MIME', [
    'application/pdf',
    'image/png',
    'image/jpeg',
    'image/webp',
    'application/zip',
    'application/x-zip-compressed',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.ms-powerpoint',
    'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    'application/vnd.ms-excel',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'text/plain'
]);
?>

