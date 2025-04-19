<?php
// --- Database Connection Configuration ---

// SECURITY: Store credentials outside the web root if possible (e.g., using environment variables or config files).
$db_type = 'sqlite'; // Database type (e.g., sqlite, mysql)
$db_path = __DIR__ . '/database.sqlite'; // Path for SQLite file

// MySQL settings (only used if $db_type is 'mysql')
$db_host = 'localhost';
$db_name = 'photo_gallery_db';
$db_user = 'root'; // SECURITY: Use a dedicated, less privileged user.
$db_pass = ''; // SECURITY: Use a strong password and avoid hardcoding.

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
?>