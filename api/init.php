<?php
// api/init.php

error_log('--- PHP LOGGING TEST FROM init.php ---');

// Start output buffering at the very beginning of the API request lifecycle
ob_start();

// --- Configure Error Handling ---
ini_set('display_errors', 0); // Turn off displaying errors
ini_set('display_startup_errors', 0);
ini_set('log_errors', 1); // Enable logging errors
// Determine log path dynamically (adjust if needed)
$log_path = dirname(__DIR__) . '/logs/php_error.log';
ini_set('error_log', $log_path);
error_reporting(E_ALL); // Report all errors

// --- Session Start & JSON Header (with robust error handling) ---
try {
    if (session_status() == PHP_SESSION_NONE) {
        session_start();
    } else {
        // Optionally log if session was already started unexpectedly
        // error_log("API Init Warning: Session already started before init.php.");
    }
} catch (Throwable $e) {
    error_log("FATAL ERROR during session start: " . $e->getMessage());
    http_response_code(500);
    if (ob_get_level() > 0) ob_end_clean(); // Clean buffer on fatal error
    // Avoid echoing JSON here if header wasn't set yet
    echo "Lỗi khởi tạo session.";
    exit;
}

// Set JSON header AFTER potential session errors
header('Content-Type: application/json; charset=utf-8');

// --- Database Connection & Core Config Loading ---
try {
    // Use __DIR__ to ensure correct path regardless of where api.php is included from
    require_once __DIR__ . '/../db_connect.php';

    // Verify PDO connection object
    if (!isset($pdo) || !$pdo instanceof PDO) {
        error_log("API Init Error: \$pdo object is not set or not a PDO instance after require_once 'db_connect.php'.");
        throw new Exception("Đối tượng kết nối PDO không được tạo hoặc không hợp lệ.");
    }

    // Verify IMAGE_SOURCES constant
    if (!defined('IMAGE_SOURCES') || !is_array(IMAGE_SOURCES) || empty(IMAGE_SOURCES)) {
        error_log("API Init Error: Hằng số IMAGE_SOURCES không được định nghĩa, không phải mảng, hoặc rỗng sau require_once 'db_connect.php'. Kiểm tra cấu hình db_connect.php.");
        throw new Exception("Cấu hình nguồn ảnh bị thiếu hoặc không hợp lệ.");
    }

    // Verify CACHE_THUMB_ROOT constant
    if (!defined('CACHE_THUMB_ROOT') || !CACHE_THUMB_ROOT || !is_dir(CACHE_THUMB_ROOT)) {
        $cache_root_path = defined('CACHE_THUMB_ROOT') ? CACHE_THUMB_ROOT : 'N/A';
        error_log("API Init Error: CACHE_THUMB_ROOT ('{$cache_root_path}') không được định nghĩa hoặc không phải là thư mục. Kiểm tra cấu hình và quyền.");
        throw new Exception("Lỗi cấu hình đường dẫn cache thumbnail.");
    }

} catch (Throwable $e) { // Catch any error/exception during include or validation
    error_log("FATAL ERROR during DB connection or config loading: " . $e->getMessage());
    http_response_code(500);
    if (ob_get_level() > 0) ob_end_clean(); // Clean buffer before outputting error
    // Echo JSON error since header should have been set by now
    echo json_encode(['error' => 'Lỗi kết nối cơ sở dữ liệu hoặc tải cấu hình.', 'details' => $e->getMessage()]);
    exit;
}

// --- Define API-Specific Constants & Global Variables ---

// Get constants from db_connect.php or set fallbacks
// Ensure ALLOWED_EXTENSIONS is defined and is an array
$allowed_ext = (defined('ALLOWED_EXTENSIONS') && is_array(ALLOWED_EXTENSIONS))
    ? ALLOWED_EXTENSIONS
    : ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp']; // Sensible default

// Use a different constant name for API thumbnail sizes to avoid potential conflicts
// Ensure THUMBNAIL_SIZES is defined and is an array
define('THUMBNAIL_SIZES_API', (defined('THUMBNAIL_SIZES') && is_array(THUMBNAIL_SIZES))
    ? THUMBNAIL_SIZES
    : [150, 750]); // Default API sizes

// --- Request Action and Search Term ---
$action = $_REQUEST['action'] ?? ''; // Use REQUEST to handle both GET and POST actions
$search_term = isset($_GET['search']) ? trim($_GET['search']) : null;

// $pdo, $action, $search_term, $allowed_ext are now available globally
// IMAGE_SOURCES, CACHE_THUMB_ROOT, THUMBNAIL_SIZES_API are available as constants 