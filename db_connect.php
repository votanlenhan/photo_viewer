<?php
// --- Database Connection Configuration ---

// Load central configuration
$config = require_once __DIR__ . '/config.php';

if (!$config) {
    error_log("CRITICAL CONFIG ERROR: Failed to load config.php");
    die("Server Configuration Error: Could not load configuration file.");
}

// Use settings from config
$db_type = 'sqlite'; // Assuming SQLite for now, could be moved to config if needed
$db_path = $config['db_path'] ?? (__DIR__ . '/database.sqlite'); // Fallback just in case

// --- MySQL settings (EXAMPLE ONLY - NOT USED WHEN $db_type is 'sqlite') ---
/*
$db_host = 'localhost';
$db_name = 'photo_gallery_db';
$db_user = 'root'; // SECURITY: Use a dedicated, less privileged user.
$db_pass = ''; // SECURITY: Use a strong password and avoid hardcoding.
*/

// --- Image Source Configuration (Get from config) ---
// Validate IMAGE_SOURCES paths from config
$valid_image_sources = [];
if (isset($config['image_sources']) && is_array($config['image_sources'])) {
    foreach ($config['image_sources'] as $key => $source_config) {
        if (isset($source_config['path'])) {
            // Resolve path relative to config file location if needed, or use absolute path
            $resolved_path = realpath($source_config['path']); 
            if ($resolved_path && is_dir($resolved_path) && is_readable($resolved_path)) {
                // Use the resolved path for the source configuration
                $valid_image_sources[$key] = [
                    'path' => $resolved_path,
                    'name' => $source_config['name'] ?? $key // Use name from config or key as fallback
                ];
            } else {
                error_log("CONFIG WARNING: Image source '{$key}' path '{$source_config['path']}' is invalid or not readable. Skipping.");
            }
        } else {
             error_log("CONFIG WARNING: Image source '{$key}' is missing 'path'. Skipping.");
        }
    }
} else {
     error_log("CRITICAL CONFIG ERROR: 'image_sources' is not defined or not an array in config.php");
     // Potentially die here if sources are absolutely required
     // die("Server Configuration Error: Image sources configuration missing or invalid.");
}

// Define the constant with VALIDATED sources only
define('IMAGE_SOURCES', $valid_image_sources);

// --- Cache and Thumbnail Configuration (Get from config) ---
$allowed_extensions = $config['allowed_extensions'] ?? ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp'];
$thumbnail_sizes = $config['thumbnail_sizes'] ?? [150, 750];

// Define constants for use in API etc.
define('ALLOWED_EXTENSIONS', $allowed_extensions);
define('THUMBNAIL_SIZES', $thumbnail_sizes);

try {
    // Resolve path relative to config file location
    $cache_thumb_root_path = $config['cache_thumb_root'] ?? (__DIR__ . '/cache/thumbnails');
    $resolved_cache_path = realpath($cache_thumb_root_path);

    if (!$resolved_cache_path) {
        // Attempt to create cache directories if realpath failed
        $cacheBase = dirname($cache_thumb_root_path);
        $thumbDir = $cache_thumb_root_path;
        error_log("Attempting to create cache directories: Base='{$cacheBase}', Thumb='{$thumbDir}'");
        if (!is_dir($cacheBase)) @mkdir($cacheBase, 0775, true);
        if (!is_dir($thumbDir)) @mkdir($thumbDir, 0775, true);
        clearstatcache(); // Clear cache after creating directory
        $resolved_cache_path = realpath($thumbDir);
    }

    if (!$resolved_cache_path || !is_dir($resolved_cache_path) || !is_writable($resolved_cache_path)) {
        throw new Exception("Failed to resolve, create, or write to CACHE_THUMB_ROOT path: '" . htmlspecialchars($cache_thumb_root_path) . "'. Check permissions and path in config.php. Resolved to: " . ($resolved_cache_path ?: 'false'));
    }
    define('CACHE_THUMB_ROOT', $resolved_cache_path);

    // Pre-create size directories if they don't exist
    foreach ($thumbnail_sizes as $size) {
        $size_dir = CACHE_THUMB_ROOT . DIRECTORY_SEPARATOR . $size;
        if (!is_dir($size_dir)) {
            if (!@mkdir($size_dir, 0775, true)) { // Added recursive flag
                error_log("Warning: Failed to automatically create thumbnail size directory: {$size_dir}");
            }
        }
    }

} catch (Throwable $e) {
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
        throw new Exception("Unsupported database type configured: {$db_type}");
    }

    // Connection successful (optional: log success)
    // error_log("Database connection successful ({$db_type}).");

} catch (PDOException $e) {
    // Log the detailed error but show a generic message to the user/API.
    error_log("Database Connection Error: " . $e->getMessage() . " (DSN: {$dsn})"); // Log DSN
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

        // Create cache_jobs table (NEW)
        $pdo->exec("CREATE TABLE IF NOT EXISTS cache_jobs (\r\n            id INTEGER PRIMARY KEY AUTOINCREMENT,\r\n            folder_path TEXT NOT NULL,\r\n            status TEXT NOT NULL DEFAULT 'pending', -- 'pending', 'processing', 'completed', 'failed'\r\n            created_at INTEGER NOT NULL,\r\n            processed_at INTEGER NULL,\r\n            completed_at INTEGER NULL,\r\n            result_message TEXT NULL\r\n        )");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_cache_jobs_status_created ON cache_jobs (status, created_at)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_cache_jobs_folder_path ON cache_jobs (folder_path)");

        // +++ Add last_cached_fully_at column if it doesn't exist (SQLite specific) +++
        try {
            // Check if column exists
            $stmt_check = $pdo->query("PRAGMA table_info(folder_stats)");
            $columns = $stmt_check->fetchAll(PDO::FETCH_COLUMN, 1); // Fetch column names
            if (!in_array('last_cached_fully_at', $columns)) {
                $pdo->exec("ALTER TABLE folder_stats ADD COLUMN last_cached_fully_at INTEGER NULL");
                error_log("[DB Connect] Added 'last_cached_fully_at' column to folder_stats table.");
            }
        } catch (PDOException $e) {
            // Log error but continue, as altering might fail if schema changed differently
            error_log("[DB Connect] Warning: Could not check/add 'last_cached_fully_at' column: " . $e->getMessage());
        }
        // +++ End column add +++
    }
} catch (PDOException $e) {
    error_log("Failed to create or check database tables (folder_stats, folder_passwords): " . $e->getMessage());
    // Depending on requirements, you might want to throw this error
    // or handle it gracefully, allowing the script to continue without stats table.
}

?>