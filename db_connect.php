<?php
// --- Database Connection Configuration ---

// SECURITY: Store credentials outside the web root if possible (e.g., using environment variables or config files).
$db_type = 'sqlite'; // Database type (e.g., sqlite, mysql)
$db_path = __DIR__ . '/database.sqlite'; // Path for SQLite file

// --- MySQL settings (EXAMPLE ONLY - NOT USED WHEN $db_type is 'sqlite') ---
/*
$db_host = 'localhost';
$db_name = 'photo_gallery_db';
$db_user = 'root'; // SECURITY: Use a dedicated, less privileged user.
$db_pass = ''; // SECURITY: Use a strong password and avoid hardcoding.
*/

// --- Image Source Configuration ---
// IMPORTANT: Replace the path for 'extra_drive' with the actual absolute path 
//            to your additional image directory on the other drive.
//            Ensure the web server process has read access to this directory.
// Use unique keys for each source (e.g., 'main', 'extra_drive').
// These keys will be used internally to identify the source.
define('IMAGE_SOURCES', [
    'main' => realpath(__DIR__ . '/images'), // Primary source inside the project
    'extra_drive' => 'G:\\2020' // <--- CORRECTED PATH
    // Add more sources here if needed, e.g.:
    // 'network_share' => '/mnt/shared_photos' 
]);

// Validate IMAGE_SOURCES paths
foreach (IMAGE_SOURCES as $key => $path) {
    if ($path === false || !is_dir($path) || !is_readable($path)) {
        // Log a fatal error and stop execution if any source is invalid
        $error_msg = "CRITICAL CONFIG ERROR: Image source '{$key}' is invalid or not readable: '{$path}'. Check path and permissions.";
        error_log($error_msg); 
        // Display a user-friendly error if possible (might fail if headers already sent)
        if (!headers_sent()) {
             header('Content-Type: text/plain; charset=utf-8', true, 500);
        }
        die("Server Configuration Error: One or more image sources are invalid. Please check server logs.");
    }
}

// --- Cache and Thumbnail Configuration ---
define('ALLOWED_EXTENSIONS', ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp']);
define('THUMBNAIL_SIZES', [150, 750]); // Available thumbnail sizes

try {
    $cache_thumb_root_path = realpath(__DIR__ . '/cache/thumbnails');
    if (!$cache_thumb_root_path) {
        // Attempt to create cache/thumbnails directory if it doesn't exist
        $cacheBase = __DIR__ . '/cache';
        $thumbDir = $cacheBase . '/thumbnails';
        error_log("Attempting to create cache directories: Base='{$cacheBase}', Thumb='{$thumbDir}'");
        if (!is_dir($cacheBase)) @mkdir($cacheBase, 0775);
        if (!is_dir($thumbDir)) @mkdir($thumbDir, 0775);
        // Try realpath again
        $cache_thumb_root_path = realpath($thumbDir);
    }
    
    if (!$cache_thumb_root_path || !is_dir($cache_thumb_root_path) || !is_writable($cache_thumb_root_path)) {
        throw new Exception("Failed to resolve, create, or write to CACHE_THUMB_ROOT path: '" . (__DIR__ . '/cache/thumbnails') . "'. Check permissions.");
    }
    define('CACHE_THUMB_ROOT', $cache_thumb_root_path);
    
    // Pre-create size directories if they don't exist
    foreach (THUMBNAIL_SIZES as $size) {
        $size_dir = CACHE_THUMB_ROOT . DIRECTORY_SEPARATOR . $size;
        if (!is_dir($size_dir)) {
            if (!@mkdir($size_dir, 0775)) {
                error_log("Warning: Failed to automatically create thumbnail size directory: {$size_dir}");
                // Don't make it fatal, but log it.
            }
        }
    }

} catch (Throwable $e) {
    // Log the detailed error and stop execution
    $error_msg = "CRITICAL CONFIG ERROR: Failed to configure cache paths - " . $e->getMessage();
    error_log($error_msg);
    if (!headers_sent()) {
        header('Content-Type: text/plain; charset=utf-8', true, 500);
    }
    die("Server Configuration Error: Cache path setup failed. Please check server logs and permissions.");
}

// --- PDO Connection Options ---
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // Throw exceptions on error
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,       // Fetch associative arrays by default
    PDO::ATTR_EMULATE_PREPARES   => false,                  // Use native prepared statements
];

// --- Establish Connection ---
$dsn = '';
$pdo = null;

try {
    if ($db_type === 'sqlite') {
        $dsn = "sqlite:" . $db_path;
        // SECURITY: Ensure the .sqlite file and its directory have correct, minimal permissions.
        $pdo = new PDO($dsn, null, null, $options);
        // Optional: Enable WAL mode for better concurrency with SQLite
        $pdo->exec('PRAGMA journal_mode = WAL;');
    } elseif ($db_type === 'mysql') {
        $dsn = "mysql:host={$db_host};dbname={$db_name};charset=utf8mb4";
        $pdo = new PDO($dsn, $db_user, $db_pass, $options);
    } else {
        throw new Exception("Unsupported database type: {$db_type}");
    }

    // Connection successful (optional: log success)
    // error_log("Database connection successful ({$db_type}).");

} catch (PDOException $e) {
    // Log the detailed error but show a generic message to the user/API.
    error_log("Database Connection Error: " . $e->getMessage());
    // If this script is included by api.php, it should handle sending the JSON error.
    // Avoid echoing direct errors here.
    // For standalone use or direct access, you might want to die() or output an error page.
     if (basename($_SERVER['PHP_SELF']) == basename(__FILE__)) {
         http_response_code(500);
         die("Database connection error. Please check server logs.");
     }
     // If included, let the including script handle the error (e.g., api.php will send JSON error)
     // We might set a global flag or re-throw exception if needed by caller.

} catch (Exception $e) {
    error_log("Configuration Error: " . $e->getMessage());
     if (basename($_SERVER['PHP_SELF']) == basename(__FILE__)) {
         http_response_code(500);
         die("Database configuration error.");
     }
}

// $pdo variable is now available for use in including scripts (like api.php)

// Ensure folder_stats table exists
try {
    if ($pdo) { // Only proceed if connection was successful
        // Create folder_stats table
        $pdo->exec("CREATE TABLE IF NOT EXISTS folder_stats (\r\n            folder_name TEXT PRIMARY KEY,\r\n            views INTEGER DEFAULT 0,\r\n            downloads INTEGER DEFAULT 0\r\n        )");
        
        // Create folder_passwords table
        $pdo->exec("CREATE TABLE IF NOT EXISTS folder_passwords (\r\n            folder_name TEXT PRIMARY KEY,\r\n            password_hash TEXT NOT NULL\r\n        )");
    }
} catch (PDOException $e) {
    error_log("Failed to create or check database tables (folder_stats, folder_passwords): " . $e->getMessage());
    // Depending on requirements, you might want to throw this error
    // or handle it gracefully, allowing the script to continue without stats table.
}

?>