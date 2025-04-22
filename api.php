<?php
ob_start(); // Start output buffering at the very beginning

// --- Configure Error Handling ---
ini_set('display_errors', 0); // Turn off displaying errors
ini_set('display_startup_errors', 0);
ini_set('log_errors', 1); // Enable logging errors
ini_set('error_log', __DIR__ . '/logs/php-error.log'); // Log to logs/php-error.log
error_reporting(E_ALL); // Report all errors

// --- Test Log Write ---

// --- Bắt đầu session và set header JSON --- 
// NOTE: Error display might interfere with JSON header if error occurs before header() call.
try {
    if (session_status() == PHP_SESSION_NONE) {
        session_start();
    } else {
    }
} catch (Throwable $e) {
    error_log("FATAL ERROR during session start: " . $e->getMessage());
    http_response_code(500);
    ob_end_clean();
    echo json_encode(['error' => 'Lỗi khởi tạo session.']);
    exit;
}
header('Content-Type: application/json; charset=utf-8'); // Restore JSON header

// --- Kết nối DB (Vẫn include để kiểm tra) ---
try {
    require_once 'db_connect.php';
    if (!isset($pdo) || !$pdo instanceof PDO) {
        error_log("API Error: \$pdo object is not set or not a PDO instance after require_once");
         throw new Exception("PDO connection object not created/available or is not a PDO instance.");
    }
    // Also check if IMAGE_SOURCES is loaded correctly
    if (!defined('IMAGE_SOURCES') || !is_array(IMAGE_SOURCES) || empty(IMAGE_SOURCES)) {
        error_log("API Error: IMAGE_SOURCES is not defined, not an array, or empty after require_once 'db_connect.php'. Check db_connect.php configuration.");
        throw new Exception("Image source configuration is missing or invalid.");
    }
    // Check CACHE_THUMB_ROOT as well, needed for thumbnail generation
    if (!defined('CACHE_THUMB_ROOT') || !CACHE_THUMB_ROOT || !is_dir(CACHE_THUMB_ROOT)) {
         // Log, but maybe don't throw fatal error immediately? Depends on API actions.
         // For now, let's throw, as thumbnails are core.
         error_log("API Error: CACHE_THUMB_ROOT ('" . (defined('CACHE_THUMB_ROOT') ? CACHE_THUMB_ROOT : 'N/A') . "') is not defined or not a directory. Check config and permissions.");
         throw new Exception("Thumbnail cache path configuration error.");
    }

} catch (Throwable $e) { // Catch any error/exception during include
    error_log("FATAL ERROR during DB connection or config loading: " . $e->getMessage());
    http_response_code(500);
    ob_end_clean(); // Clear buffer before outputting error
    echo json_encode(['error' => 'Lỗi kết nối cơ sở dữ liệu hoặc cấu hình.', 'details' => $e->getMessage()]);
    exit;
}

// --- Định nghĩa Hằng số và Biến toàn cục ---
// IMAGE_ROOT is now removed, use IMAGE_SOURCES from db_connect.php

// Get constants from db_connect.php or set fallbacks
$allowed_ext = defined('ALLOWED_EXTENSIONS') && is_array(ALLOWED_EXTENSIONS) ? ALLOWED_EXTENSIONS : ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp'];
define('THUMBNAIL_SIZES_API', defined('THUMBNAIL_SIZES') && is_array(THUMBNAIL_SIZES) ? THUMBNAIL_SIZES : [150, 750]); // Use a different const name to avoid conflicts if included elsewhere

// --- Các Hàm Hỗ Trợ ---

/** Gửi JSON phản hồi thành công */
function json_response($data, $code = 200) {
    global $action; // Access the global action variable
    http_response_code($code);
    
    // Log data specifically for admin_list_folders before encoding
    if ($action === 'admin_list_folders') {
         error_log("Data before json_encode for admin_list_folders: " . print_r($data, true));
    }
    
    $json_output = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($json_output === false) {
        $error_msg = 'JSON Encode Error: ' . json_last_error_msg() . " | Data was: " . print_r($data, true);
        error_log($error_msg); 
    }
    
    ob_end_clean(); // Clear the output buffer
    echo $json_output; // Echo only the final JSON
    exit; 
}
/** Gửi JSON lỗi */
function json_error($msg, $code = 400) {
    json_response(['error' => $msg], $code);
}

/**
 * Làm sạch, xác thực đường dẫn thư mục con và trả về thông tin nguồn.
 * Accepts a source-prefixed relative path (e.g., "main/album/sub" or "extra_drive/stuff").
 * Returns null if invalid, or an array ['source_key' => string, 'relative_path' => string, 'absolute_path' => string] on success.
 * The returned 'relative_path' is relative to the source's base path.
 * SECURITY: Crucial for preventing path traversal across different sources.
 */
function validate_source_and_path($source_prefixed_path) {
    // No need for 'global IMAGE_SOURCES;' - it's a constant.
    // Access directly using IMAGE_SOURCES

    // *** REMOVING DEBUGGING for global variable check ***
    // error_log("[validate_source_and_path DEBUG] IMAGE_SOURCES available? " . (isset($IMAGE_SOURCES) ? 'Yes, count=' . count($IMAGE_SOURCES) : 'No'));
    // if (!isset($IMAGE_SOURCES)) { return null; }

    if ($source_prefixed_path === null || $source_prefixed_path === '' || $source_prefixed_path === '/') {
         return ['source_key' => null, 'relative_path' => '', 'absolute_path' => null, 'is_root' => true];
    }

    // 1. Normalize and split
    $normalized_path = trim(str_replace(['..', '\\', "\0"], '', $source_prefixed_path), '/');
    if ($normalized_path === '') {
        return ['source_key' => null, 'relative_path' => '', 'absolute_path' => null, 'is_root' => true];
    }
    $normalized_path = str_replace(DIRECTORY_SEPARATOR, '/', $normalized_path);
    $parts = explode('/', $normalized_path, 2);
    $source_key = $parts[0];
    $relative_path_in_source = $parts[1] ?? '';

    // 2. Check source key existence using the CONSTANT
    if (!defined('IMAGE_SOURCES') || !isset(IMAGE_SOURCES[$source_key])) { // Check constant directly
        error_log("Path validation failed: Invalid source key '{$source_key}' in path '{$source_prefixed_path}'");
        return null;
    }
    // Access the constant correctly
    $source_config = IMAGE_SOURCES[$source_key];
    $source_base_path = $source_config['path'];

    // 3. Check if the source base path itself is valid (using resolved path)
    $resolved_source_base_path = @realpath($source_base_path);
    if ($resolved_source_base_path === false || !is_dir($resolved_source_base_path) || !is_readable($resolved_source_base_path)) {
        error_log("[validate_source_and_path] Source base path is invalid or not accessible for key '{$source_key}': {$source_base_path} (Resolved: " . ($resolved_source_base_path ?: 'false') . ")");
        return null; // Source itself is bad
    }
    $source_base_path = $resolved_source_base_path; // Use the resolved path from now on

    // 4. Construct target absolute path using the *resolved* source base path
    $target_absolute_path = $source_base_path . ($relative_path_in_source ? DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative_path_in_source) : '');

    // 5. Get real path of the target and validate
    $real_target_path = @realpath($target_absolute_path);

    // 6. Final checks:
    //    - Must resolve
    //    - Must be a DIRECTORY
    //    - Must be within the source's *resolved* base path
    if ($real_target_path === false || !is_dir($real_target_path) || strpos($real_target_path, $source_base_path) !== 0) {
        $log_details = [
            'requested_path' => $source_prefixed_path,
            'normalized' => $normalized_path,
            'source_key' => $source_key,
            'relative_in_source' => $relative_path_in_source,
            'target_absolute' => $target_absolute_path,
            'real_target' => $real_target_path === false ? 'false' : $real_target_path,
            'source_base' => $source_base_path, // Use resolved base path here
            'is_dir' => $real_target_path ? (is_dir($real_target_path) ? 'yes' : 'no') : 'N/A',
            'in_source_base' => ($real_target_path && $source_base_path) ? (strpos($real_target_path, $source_base_path) === 0 ? 'yes' : 'no') : 'N/A'
        ];
        error_log("Path validation failed for directory: " . json_encode($log_details));
        return null; // Invalid path
    }

    // 7. Calculate final relative path based on realpath
    $final_relative_path = substr($real_target_path, strlen($source_base_path));
    $final_relative_path = ltrim(str_replace(DIRECTORY_SEPARATOR, '/', $final_relative_path), '/');

    // 8. Return valid info
    return [
        'source_key' => $source_key,
        'relative_path' => $final_relative_path,
        'absolute_path' => $real_target_path,
        'source_prefixed_path' => $final_relative_path === '' ? $source_key : $source_key . '/' . $final_relative_path,
        'is_root' => false
    ];
}

/**
 * Legacy function placeholder/adapter - TO BE REMOVED OR REPLACED
 * Attempts to map old subdir logic to new structure - temporary measure.
 * Prefer calling validate_source_and_path directly.
 */
function sanitize_subdir($subdir) {
     // This function is deprecated. Need to update callers.
     // For now, assume it might be a path within the *first* source if not prefixed.
     // This is a HACK and likely WRONG.
     error_log("DEPRECATED FUNCTION CALL: sanitize_subdir called with '{$subdir}'. Callers must be updated to provide source-prefixed paths.");

     if ($subdir === null || $subdir === '') return ['source_key' => null, 'relative_path' => '', 'absolute_path' => null, 'is_root' => true];

     // Check if it already looks like a source-prefixed path
     if (strpos($subdir, '/') !== false) {
         $parts = explode('/', $subdir, 2);
         if (isset(IMAGE_SOURCES[$parts[0]])) {
             // Looks like it's already prefixed, try validating it
             $validated = validate_source_and_path($subdir);
             // Return only the source-prefixed path for compatibility, or null
             return $validated ? $validated['source_prefixed_path'] : null;
         }
     }

     // If not prefixed, assume the *first* source key as a guess (VERY UNSAFE)
     $first_source_key = key(IMAGE_SOURCES);
     $assumed_prefixed_path = $first_source_key . '/' . ltrim($subdir, '/');
     error_log("Attempting validation assuming first source: '{$assumed_prefixed_path}'");
     $validated = validate_source_and_path($assumed_prefixed_path);
     return $validated ? $validated['source_prefixed_path'] : null;

     // Original logic (commented out, relied on IMAGE_ROOT)
    /*
    if ($subdir === null || $subdir === '') return ''; // Thư mục gốc
    $subdir = str_replace(['..', '\\', "\0"], '', $subdir);
    $subdir = trim(str_replace('/', DIRECTORY_SEPARATOR, $subdir), DIRECTORY_SEPARATOR);
    if ($subdir === '') return '';
    $base = IMAGE_ROOT;
    $target_path = @realpath($base . DIRECTORY_SEPARATOR . $subdir);
    if ($target_path === false || !is_dir($target_path) || strpos($target_path, $base) !== 0) {
        error_log("Path validation failed for subdir: '{$subdir}'");
        return null;
    }
    $relative_path = substr($target_path, strlen($base));
    return ltrim(str_replace(DIRECTORY_SEPARATOR, '/', $relative_path), '/');
    */
}

/** 
 * Kiểm tra quyền truy cập thư mục (dựa vào DB và Session) 
 * IMPORTANT: $folder_source_prefixed_path MUST be the source-prefixed path (e.g., 'main/album')
 *            which is used as the key in the folder_passwords table.
 */
function check_folder_access($folder_source_prefixed_path) {
    global $pdo;
    error_log("[DEBUG check_folder_access] Checking access for: '{$folder_source_prefixed_path}'");
    // Handle root access (listing sources) - always allowed publicly
    if (empty($folder_source_prefixed_path)) {
        error_log("[DEBUG check_folder_access] Path is empty (root access). Returning authorized=true.");
        // Root is considered not protected and authorized
        return ['protected' => false, 'authorized' => true, 'password_required' => false];
    }
    try {
        // Use the source-prefixed path directly as the key
        $stmt = $pdo->prepare("SELECT password_hash FROM folder_passwords WHERE folder_name = ? LIMIT 1");
        $stmt->execute([$folder_source_prefixed_path]);
        $row = $stmt->fetch();
        $is_protected = ($row !== false); // Folder is protected if a password hash exists
        $found_hash = $is_protected ? 'Yes' : 'No';
        error_log("[DEBUG check_folder_access] Found password hash in DB? {$found_hash}");

        if (!$is_protected) {
            error_log("[DEBUG check_folder_access] Not protected. Returning protected=false, authorized=true.");
            return ['protected' => false, 'authorized' => true, 'password_required' => false]; // Not protected, always authorized
        }

        // If protected, check session authorization
        $session_key = 'authorized_folders';
        $is_authorized_in_session = !empty($_SESSION[$session_key][$folder_source_prefixed_path]);
        $session_status_log = $is_authorized_in_session ? 'Yes' : 'No';
        error_log("[DEBUG check_folder_access] Is authorized in session (\$_SESSION['{$session_key}']['{$folder_source_prefixed_path}'])? {$session_status_log}");
        
        if ($is_authorized_in_session) {
            error_log("[DEBUG check_folder_access] Protected but authorized via session. Returning protected=true, authorized=true.");
            return ['protected' => true, 'authorized' => true, 'password_required' => false]; // Protected, but authorized in session
        }
        
        // If protected and not authorized in session, password is required
        error_log("[DEBUG check_folder_access] Protected and requires password. Returning protected=true, authorized=false, password_required=true.");
        return ['protected' => true, 'authorized' => false, 'password_required' => true]; // Protected, needs password

    } catch (PDOException $e) {
        error_log("DB Error checking folder access for '{$folder_source_prefixed_path}': " . $e->getMessage());
        // Return as protected and unauthorized on DB error
        return ['protected' => true, 'authorized' => false, 'error' => 'Lỗi server khi kiểm tra quyền truy cập.'];
    }
}

/**
 * Find the first image recursively within a specific directory of a specific source.
 *
 * @param string $source_key The key of the source (e.g., 'main').
 * @param string $relative_dir_path Path relative to the source's base path (e.g., 'album/subalbum'). Use '' for source root.
 * @param array $allowed_ext Reference to allowed extensions array.
 * @return string|null Source-prefixed relative path of the first image found (e.g., 'main/album/subalbum/image.jpg'), or null if none found.
 */
function find_first_image_in_source($source_key, $relative_dir_path, &$allowed_ext) {
    // No need for 'global IMAGE_SOURCES;' - it's a constant.

    // Access the constant directly
    if (!defined('IMAGE_SOURCES') || !isset(IMAGE_SOURCES[$source_key])) { // Check constant directly
         error_log("[find_first_image_in_source] Invalid source key provided: '{$source_key}'");
            return null;
        }
    $source_config = IMAGE_SOURCES[$source_key];
    $source_base_path = $source_config['path'];
    $resolved_source_base_path = realpath($source_base_path);

    if ($resolved_source_base_path === false) {
        error_log("[find_first_image_in_source] Source base path does not resolve for key '{$source_key}': {$source_base_path}");
        return null;
    }

    // Construct the absolute path to the directory to search
    // Ensure relative_dir_path doesn't have leading/trailing slashes for concatenation
    $normalized_relative_dir = trim(str_replace(['..', '\\', "\0"], '', $relative_dir_path), '/');
    $target_dir_absolute = $resolved_source_base_path . (empty($normalized_relative_dir) ? '' : DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $normalized_relative_dir));
    $resolved_target_dir_absolute = realpath($target_dir_absolute);

    // Check if the target directory is valid and readable
    if ($resolved_target_dir_absolute === false || !is_dir($resolved_target_dir_absolute) || !is_readable($resolved_target_dir_absolute)) {
        // Don't log error if the directory simply doesn't exist or isn't readable, common case
        // error_log("[find_first_image_in_source] Target directory is invalid or not readable for key '{$source_key}', path '{$relative_dir_path}': {$target_dir_absolute}");
        return null;
    }
    
    // Ensure the target directory is within the source base path (security)
     if (strpos($resolved_target_dir_absolute, $resolved_source_base_path) !== 0) {
         error_log("[find_first_image_in_source] Security Check Failed: Target directory '{$resolved_target_dir_absolute}' is outside source base '{$resolved_source_base_path}'.");
         return null;
     }


    // *** BEGIN RESTORED LOGIC ***
    try {
        $directory = new RecursiveDirectoryIterator($resolved_target_dir_absolute, RecursiveDirectoryIterator::SKIP_DOTS | RecursiveDirectoryIterator::FOLLOW_SYMLINKS);
        $iterator = new RecursiveIteratorIterator($directory, RecursiveIteratorIterator::LEAVES_ONLY);
        // Sort the iterator to get a predictable order (optional, but good for consistency)
        // Note: Sorting can be memory intensive for large directories.
        // $files = iterator_to_array($iterator);
        // ksort($files); // Sort by path name
        // $iterator = new ArrayIterator($files);
        
        foreach ($iterator as $fileinfo) {
            // Basic check to prevent processing files outside the *target* directory (security paranoia)
            // $realPath = $fileinfo->getRealPath();
            // if (!$realPath || strpos($realPath, $resolved_target_dir_absolute) !== 0) {
            //     continue; // Should not happen with LEAVES_ONLY from resolved path, but just in case
            // }
            
            if ($fileinfo->isFile() && $fileinfo->isReadable()) {
                $extension = strtolower($fileinfo->getExtension());
                if (in_array($extension, $allowed_ext, true)) {
                    // Found the first valid image!
                    $image_real_path = $fileinfo->getRealPath();
                    
                    // Calculate the path relative to the *original target directory* ($resolved_target_dir_absolute)
                    $image_relative_path = substr($image_real_path, strlen($resolved_target_dir_absolute));
                    $image_relative_path = ltrim(str_replace(DIRECTORY_SEPARATOR, '/', $image_relative_path), '/');
                    
                    // Return this relative path
                    return $image_relative_path;
                }
            }
        }
    } catch (Exception $e) {
        error_log("[find_first_image_in_source] Error scanning directory '{$resolved_target_dir_absolute}': " . $e->getMessage());
        return null;
    }
    // *** END RESTORED LOGIC ***

    // If loop completes without finding an image
    return null;
}

/**
 * Create a thumbnail image using GD library.
 * (Restored for on-the-fly generation)
 * 
 * @param string $source_path Absolute path to the source image.
 * @param string $cache_path Absolute path to save the thumbnail (INCLUDING the size subdirectory).
 * @param int $thumb_size Desired width/height of the thumbnail (square).
 * @return bool True on success, false on failure.
 */
function create_thumbnail($source_path, $cache_path, $thumb_size = 150) {
    if (!extension_loaded('gd')) {
        error_log("[API Thumbs] GD extension is not loaded. Cannot create thumbnail.");
        return false;
    }
    
    // Check if already exists to prevent race conditions if multiple requests hit simultaneously
    if (file_exists($cache_path)) {
        return true; 
    }
    
    try {
        if (!is_readable($source_path)) {
             error_log("[API Thumbs] create_thumbnail: Source image not readable: {$source_path}");
             return false;
        }
        $image_info = @getimagesize($source_path);
        if ($image_info === false) {
             error_log("[API Thumbs] create_thumbnail: Failed to get image size for: {$source_path}");
             return false;
        }
        $mime = $image_info['mime'];
        $original_width = $image_info[0];
        $original_height = $image_info[1];

        // Create image resource based on mime type
        $source_image = null;
        switch ($mime) {
            case 'image/jpeg':
            case 'image/jpg':
                $source_image = @imagecreatefromjpeg($source_path);
                break;
            case 'image/png':
                $source_image = @imagecreatefrompng($source_path);
                break;
            case 'image/gif':
                $source_image = @imagecreatefromgif($source_path);
                break;
            case 'image/webp':
                 if (function_exists('imagecreatefromwebp')) {
                     $source_image = @imagecreatefromwebp($source_path);
                 } else {
                    error_log("[API Thumbs] create_thumbnail: WebP is not supported by this GD version for: {$source_path}");
                    return false;
                 }
                break;
            default:
                error_log("[API Thumbs] create_thumbnail: Unsupported image type '{$mime}' for: {$source_path}");
                return false;
        }

        if ($source_image === false) {
            error_log("[API Thumbs] create_thumbnail: Failed to create image resource from: {$source_path}");
            return false;
        }

        // Calculate new dimensions
        $ratio = $original_width / $original_height;
        if ($original_width > $original_height) {
            $thumb_width = $thumb_size;
            $thumb_height = intval($thumb_size / $ratio);
        } else {
            $thumb_height = $thumb_size;
            $thumb_width = intval($thumb_size * $ratio);
        }

        // Create the thumbnail canvas
        $thumb_image = imagecreatetruecolor($thumb_width, $thumb_height);
         if ($thumb_image === false) {
            error_log("[API Thumbs] create_thumbnail: Failed to create true color image resource.");
            imagedestroy($source_image); 
            return false;
        }

        // Handle transparency
        if ($mime == 'image/png' || $mime == 'image/gif') {
            imagealphablending($thumb_image, false);
            imagesavealpha($thumb_image, true);
            $transparent = imagecolorallocatealpha($thumb_image, 255, 255, 255, 127);
            imagefilledrectangle($thumb_image, 0, 0, $thumb_width, $thumb_height, $transparent);
        }

        // Resize
        if (!imagecopyresampled($thumb_image, $source_image, 0, 0, 0, 0, $thumb_width, $thumb_height, $original_width, $original_height)) {
            error_log("[API Thumbs] create_thumbnail: imagecopyresampled failed.");
            imagedestroy($source_image); 
            imagedestroy($thumb_image);
            return false;
        }

        // Save the thumbnail
        $cache_dir = dirname($cache_path);
        if (!is_dir($cache_dir)) {
            if (!@mkdir($cache_dir, 0775, true)) {
                 error_log("[API Thumbs] create_thumbnail: Failed to create cache directory: {$cache_dir}");
                 imagedestroy($source_image); 
                 imagedestroy($thumb_image);
                 return false;
            }
        }

        $save_success = imagejpeg($thumb_image, $cache_path, 85);

        // Clean up resources
        imagedestroy($source_image);
        imagedestroy($thumb_image);

        if (!$save_success) {
             error_log("[API Thumbs] create_thumbnail: Failed to save thumbnail to: {$cache_path}");
             if (file_exists($cache_path)) @unlink($cache_path);
             return false;
        }
        
        error_log("[API Thumbs] Created thumbnail on-the-fly: {$cache_path}"); // Log on-the-fly creation
        return true;

    } catch (Throwable $e) {
        error_log("[API Thumbs] create_thumbnail: Exception while creating thumbnail for {$source_path} -> {$cache_path} : " . $e->getMessage());
        if (isset($source_image) && is_resource($source_image)) imagedestroy($source_image);
        if (isset($thumb_image) && is_resource($thumb_image)) imagedestroy($thumb_image);
         if (file_exists($cache_path)) @unlink($cache_path);
        return false;
    }
}

/**
 * Validate a source-prefixed path points to a valid, readable FILE within the correct source.
 *
 * @param string $source_prefixed_path e.g., "main/album/image.jpg"
 * @return array|null ['source_key', 'relative_path', 'absolute_path', 'source_prefixed_path'] or null if invalid.
 */
function validate_source_and_file_path($source_prefixed_path) {
    // No need for 'global' - IMAGE_SOURCES is a constant.

    if (!defined('IMAGE_SOURCES') || !is_array(IMAGE_SOURCES)) {
        error_log("File validation failed: IMAGE_SOURCES constant not defined or not an array.");
        return null;
    }

    if ($source_prefixed_path === null || $source_prefixed_path === '' || $source_prefixed_path === '/') {
         error_log("File validation failed: Path cannot be empty or root.");
         return null; // Cannot validate root or empty path as a file
    }

    // 1. Normalize and split (similar to directory validation)
    $normalized_path = trim(str_replace(['..', '\\', "\0"], '', $source_prefixed_path), '/');
    if ($normalized_path === '') {
         error_log("File validation failed: Normalized path is empty.");
         return null;
    }
    $normalized_path = str_replace(DIRECTORY_SEPARATOR, '/', $normalized_path);
    $parts = explode('/', $normalized_path, 2);
    $source_key = $parts[0];
    $relative_path_in_source = $parts[1] ?? '';

    // File must have a name within the source
    if ($relative_path_in_source === '') {
         error_log("File validation failed: Path refers only to a source key, not a file within it: {$source_prefixed_path}");
        return null;
    }

    // 2. Check source key existence
    if (!isset(IMAGE_SOURCES[$source_key])) {
        error_log("File validation failed: Invalid source key '{$source_key}' in path '{$source_prefixed_path}'");
        return null;
    }
    $source_config = IMAGE_SOURCES[$source_key];
    $source_base_path = $source_config['path'];

    // 3. Check if the source base path itself is valid
    $resolved_source_base_path = @realpath($source_base_path);
    if ($resolved_source_base_path === false || !is_dir($resolved_source_base_path) || !is_readable($resolved_source_base_path)) {
        error_log("[validate_source_and_file_path] Source base path is invalid or not accessible for key '{$source_key}': {$source_base_path} (Resolved: " . ($resolved_source_base_path ?: 'false') . ")");
        return null; // Source itself is bad
    }
    $source_base_path = $resolved_source_base_path; // Use the resolved path

    // 4. Construct target absolute path
    $target_absolute_path = $source_base_path . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative_path_in_source);

    // 5. Get real path of the target and validate
    $real_target_path = @realpath($target_absolute_path);

    // 6. Final checks for FILE:
    //    - Must resolve
    //    - Must be a FILE
    //    - Must be READABLE
    //    - Must be within the source's *resolved* base path
    if ($real_target_path === false || !is_file($real_target_path) || !is_readable($real_target_path) || strpos($real_target_path, $source_base_path) !== 0) {
        $log_details = [
            'requested_path' => $source_prefixed_path,
            'normalized' => $normalized_path,
            'source_key' => $source_key,
            'relative_in_source' => $relative_path_in_source,
            'target_absolute' => $target_absolute_path,
            'real_target' => $real_target_path === false ? 'false' : $real_target_path,
            'source_base' => $source_base_path,
            'is_file' => $real_target_path ? (is_file($real_target_path) ? 'yes' : 'no') : 'N/A',
            'is_readable' => $real_target_path ? (is_readable($real_target_path) ? 'yes' : 'no') : 'N/A',
            'in_source_base' => ($real_target_path && $source_base_path) ? (strpos($real_target_path, $source_base_path) === 0 ? 'yes' : 'no') : 'N/A'
        ];
        error_log("File validation failed: " . json_encode($log_details));
        return null; // Invalid path or file
    }

    // 7. Calculate final relative path based on realpath (relative to source)
    $final_relative_path = substr($real_target_path, strlen($source_base_path));
    $final_relative_path = ltrim(str_replace(DIRECTORY_SEPARATOR, '/', $final_relative_path), '/');

    // 8. Return valid info
    return [
        'source_key' => $source_key,
        'relative_path' => $final_relative_path, // Path relative to source base
        'absolute_path' => $real_target_path,
        'source_prefixed_path' => $source_key . '/' . $final_relative_path // Reconstruct canonical source-prefixed path
    ];
}

// --- Restore Router and Switch ---

// --- ROUTER XỬ LÝ ACTION ---
$action = $_REQUEST['action'] ?? ''; // Use REQUEST again to handle POST actions

// --- Process search term (can come from GET) ---
$search_term = $_GET['search'] ?? null;
if ($search_term !== null) {
    $search_term = trim($search_term);
}

switch ($action) {

    case 'download_zip':
        @ini_set('max_execution_time', '300'); // 300 seconds = 5 minutes
        @ini_set('memory_limit', '1024M');    // 1 GB memory limit

        // NOTE: This action outputs a file directly, NOT JSON.
        // It needs to handle output buffering carefully.
        $dir_param = $_GET['dir'] ?? null;
        $zip_filename = 'download.zip';
        $temp_zip_file = null;

        try {
             // Validate the requested path using the source-aware function
             if ($dir_param === null) {
                  throw new Exception("Thiếu tham số thư mục (dir).", 400);
             }
             $path_info = validate_source_and_path($dir_param);

             if ($path_info === null || $path_info['is_root']) {
                  // Prevent downloading the entire source or invalid paths
                  throw new Exception("Đường dẫn thư mục không hợp lệ hoặc không thể tải thư mục gốc.", 400);
             }
             
             // Use validated paths
             $source_prefixed_path = $path_info['source_prefixed_path']; // e.g., main/Album 1
             $absolute_path_to_zip = $path_info['absolute_path'];    // e.g., D:\path\to\images\Album 1
             
             // Double check if the validated path is actually a directory 
             if (!is_dir($absolute_path_to_zip)) {
                 error_log("[download_zip] Validated path is not a directory: {$absolute_path_to_zip}");
                 throw new Exception("Đường dẫn hợp lệ nhưng không phải là thư mục.", 500); 
             }

             // --- Access Check (Use source-prefixed path) ---
             $access = check_folder_access($source_prefixed_path); 
             if (!$access['authorized']) {
                 // Determine specific error (password required vs forbidden)
                  if (!empty($access['password_required'])) {
                       // Consider if downloading requires password entry or just blocks. Blocking is safer.
                       // json_response(['password_required' => true, 'folder' => $source_prefixed_path], 401); // Option 1: Trigger password prompt (needs JS handling)
                       throw new Exception("Yêu cầu xác thực để tải thư mục này.", 401); // Option 2: Simply block download
                  } else {
                       throw new Exception($access['error'] ?? 'Không được phép truy cập thư mục này.', 403); // Forbidden
                  }
             }

             // --- Check Zip Extension ---
             if (!extension_loaded('zip')) {
                 error_log("[download_zip] PHP extension 'zip' is not enabled.");
                 throw new Exception("Tính năng nén file ZIP chưa được kích hoạt trên server.", 501); // Service Unavailable
             }

             // --- Increment Download Count in DB (Use source-prefixed path) --- 
             try {
                 $sql = "INSERT INTO folder_stats (folder_name, downloads) VALUES (?, 1) 
                         ON CONFLICT(folder_name) DO UPDATE SET downloads = downloads + 1";
                 $stmt = $pdo->prepare($sql);
                 $stmt->execute([$source_prefixed_path]); 
             } catch (PDOException $e) {
                 error_log("[download_zip] WARNING: Failed to increment download count in DB for '{$source_prefixed_path}': " . $e.getMessage());
                 // Continue even if stats fail
             }

             // --- Create Zip --- 
             $zip = new ZipArchive();
             // Generate filename from the last part of the source-prefixed path
             $zip_basename = basename($source_prefixed_path); // Gets "Album 1" from "main/Album 1"
             $zip_filename = preg_replace('/[^a-zA-Z0-9_-]+/', '_', $zip_basename) . '.zip'; 
             
             $temp_zip_file = tempnam(sys_get_temp_dir(), 'photozip_');
             if ($temp_zip_file === false) {
                  error_log("[download_zip] Failed to create temp file using tempnam(). Check sys_temp_dir permissions.");
                  throw new Exception("Không thể tạo file nén tạm.", 500);
             }

             if ($zip->open($temp_zip_file, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
                 @unlink($temp_zip_file); // Clean up failed temp file
                 error_log("[download_zip] Cannot open temp zip file for writing: {$temp_zip_file}");
                 throw new Exception("Không thể mở file nén tạm để ghi.", 500);
             }

             // Add files recursively using the validated absolute path
             $files_added_count = 0;
             $iterator = new RecursiveIteratorIterator(
                 new RecursiveDirectoryIterator($absolute_path_to_zip, RecursiveDirectoryIterator::SKIP_DOTS), 
                 RecursiveIteratorIterator::LEAVES_ONLY
             );

             foreach ($iterator as $name => $file) {
                 // Skip directories (they would be created automatically)
                 if (!$file->isDir()) {
                     // Get real path for the file
                     $filePath = $file->getRealPath();
                     // Store file relative path *inside* the zip archive
                     // (relative to the directory being zipped)
                     $relativePath = substr($filePath, strlen($absolute_path_to_zip) + 1);

                     if ($zip->addFile($filePath, $relativePath)) {
                         $files_added_count++;
                     } else {
                          error_log("[download_zip] WARNING: Failed to add file to zip: " . $filePath . " (Relative: {$relativePath})");
                     }
                 }
             }

             $status = $zip->close();
             if ($status === false) {
                 @unlink($temp_zip_file);
                 error_log("[download_zip] Failed to close zip archive.");
                 throw new Exception("Không thể hoàn tất file nén.", 500);
             }

             // --- Send File --- 
             if ($files_added_count > 0 && file_exists($temp_zip_file)) {
                 // Clear output buffer BEFORE sending headers
                 if (ob_get_level()) ob_end_clean(); 
                 
                 header('Content-Type: application/zip');
                 header('Content-Disposition: attachment; filename="' . $zip_filename . '"');
                 header('Content-Length: ' . filesize($temp_zip_file));
                 header('Pragma: no-cache'); 
                 header('Expires: 0');
                 header('Cache-Control: must-revalidate'); 

                 // Send the file using readfile for efficiency
                 $readfile_result = readfile($temp_zip_file);

                 // Delete temp file after sending
                 unlink($temp_zip_file); 

                 if ($readfile_result === false) {
                      // Error already sent file headers, hard to recover nicely. Log it.
                      error_log("[download_zip] readfile() failed for temp zip: {$temp_zip_file}");
                 }
                 exit; // IMPORTANT: Stop script execution after sending file
             } else {
                 @unlink($temp_zip_file); // Clean up empty/missing temp file
                 error_log("[download_zip] No files added or temp file missing. Added: {$files_added_count}, Exists: " . file_exists($temp_zip_file));
                 // Throw error to be caught and sent as plain text
                 throw new Exception("Không có file nào hợp lệ trong thư mục để nén.", 404);
             }

        } catch (Throwable $e) {
             // Clean up temp file if it exists and an error occurred
             if ($temp_zip_file && file_exists($temp_zip_file)) {
                 @unlink($temp_zip_file);
             }
             
             $http_code = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 500; 
             $error_message = $e->getMessage();
             error_log("[download_zip] ERROR (HTTP {$http_code}): {$error_message}\nPath Info: " . json_encode($path_info ?? null) . "\nStack Trace:\n" . $e->getTraceAsString()); 

             // Clear buffer before sending error output 
             if (ob_get_level()) ob_end_clean();
             
             // Send plain text error for direct download links
             http_response_code($http_code);
             header('Content-Type: text/plain; charset=utf-8');
             die("Lỗi Server khi tạo file ZIP: " . htmlspecialchars($error_message)); 
        }
        break; // End case 'download_zip'

    case 'admin_list_folders':
        if (empty($_SESSION['admin_logged_in'])) {
             error_log("Admin not logged in for admin_list_folders.");
             json_error("Yêu cầu đăng nhập Admin.", 403);
        }
        $admin_search_term = $search_term; // Use the already processed search_term
        
        try {
            $folders_data = [];
            $protected_status = [];
            // Fetch all protected folders (using source-prefixed paths)
            $stmt = $pdo->query("SELECT folder_name FROM folder_passwords");
            while ($row = $stmt->fetchColumn()) {
                $protected_status[$row] = true;
            }

            // Fetch all folder stats (using source-prefixed paths)
            $folder_stats = [];
            try {
                // Fetch stats - assuming folder_name in DB is source-prefixed
                $stmt = $pdo->query("SELECT folder_name, views, downloads FROM folder_stats"); 
                 while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                     $folder_stats[$row['folder_name']] = ['views' => $row['views'], 'downloads' => $row['downloads']];
                 }
            } catch (PDOException $e) {
                error_log("ERROR fetching folder stats for admin: " . $e->getMessage());
            }

            // --- Iterate through IMAGE_SOURCES --- 
            foreach (IMAGE_SOURCES as $source_key => $source_config) {
                // Ensure $source_config is an array and has 'path' key
                if (!is_array($source_config) || !isset($source_config['path'])) {
                    error_log("[admin_list_folders] Invalid source config structure for key '{$source_key}'. Skipping.");
                    continue;
                }
                $source_base_path = $source_config['path']; // Get path from config
                $resolved_source_base_path = realpath($source_base_path); // Resolve it

                // *** DEBUGGING ADDED ***
                error_log("[admin_list_folders DEBUG] Before check: key={$source_key}, path='{$source_base_path}', resolved_type=" . gettype($resolved_source_base_path) . ", resolved_value=" . print_r($resolved_source_base_path, true));
                // *** END DEBUGGING ***

                // Check the resolved path (string or false)
                if ($resolved_source_base_path === false || !is_dir($resolved_source_base_path) || !is_readable($resolved_source_base_path)) {
                    error_log("[admin_list_folders] Source '{$source_key}' ({$source_base_path} -> " . ($resolved_source_base_path === false ? 'false' : $resolved_source_base_path) . ") is not readable or not a directory. Skipping.");
                    continue;
                }
                try {
                    // Iterate using the resolved path (which MUST be a string here)
                    $iterator = new DirectoryIterator($resolved_source_base_path);
            foreach ($iterator as $fileinfo) {
                        if ($fileinfo->isDot() || !$fileinfo->isDir()) {
                            continue;
                        }
                        
                        $dir_name = $fileinfo->getFilename(); // e.g., "Album 1"
                        $source_prefixed_path = $source_key . '/' . $dir_name; // e.g., "main/Album 1"

                        // Apply search filter if provided
                    if ($admin_search_term !== null && mb_stripos($dir_name, $admin_search_term, 0, 'UTF-8') === false) {
                        continue; 
                    }
                        
                        // Get stats using the source-prefixed path
                        $stats = $folder_stats[$source_prefixed_path] ?? ['views' => 0, 'downloads' => 0];
                        
                    $folders_data[] = [
                            'name' => $dir_name, // Display name
                            'path' => $source_prefixed_path, // Identifier (source-prefixed)
                            'source' => $source_key,
                            'protected' => isset($protected_status[$source_prefixed_path]),
                        'views' => $stats['views'],
                        'downloads' => $stats['downloads']
                    ];
                    }
                } catch (Exception $e) {
                     error_log("[admin_list_folders] Error scanning source '{$source_key}': " . $e->getMessage());
                     // Continue to next source if one fails
                }
            } // --- End foreach IMAGE_SOURCES ---
            
            // Sort the combined list by display name
            usort($folders_data, fn($a, $b) => strnatcasecmp($a['name'], $b['name'])); 
            
            // Log before sending
            error_log("[admin_list_folders] Data before json_response: " . print_r($folders_data, true));
            json_response(['folders' => $folders_data]);
            
        } catch (Throwable $e) { 
            error_log("FATAL ERROR in admin_list_folders: " . $e->getMessage() . "\nStack Trace:\n" . $e->getTraceAsString());
            json_error("Không thể lấy danh sách thư mục quản lý. Lỗi: " . $e->getMessage(), 500);
        }
        break;

    case 'admin_set_password':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            json_error("Phương thức không hợp lệ.", 405);
        }
        if (empty($_SESSION['admin_logged_in'])) {
            json_error("Yêu cầu đăng nhập Admin.", 403);
        }

        $folder_param = $_POST['folder'] ?? null; // Expect source-prefixed path e.g., 'main/Album 1'
        $password = $_POST['password'] ?? null;

        if ($folder_param === null || $password === null) { // Check if params exist
             json_error("Thiếu thông tin thư mục hoặc mật khẩu.", 400);
        }
        
        // Validate the FOLDER path
        $folder_path_info = validate_source_and_path($folder_param);

        if ($folder_path_info === null || $folder_path_info['is_root']) {
            // Cannot set password for invalid folder or root
            json_error("Tên thư mục không hợp lệ hoặc không thể đặt mật khẩu cho thư mục gốc.", 400); 
        }
        $source_prefixed_path = $folder_path_info['source_prefixed_path']; // Use the validated path e.g., 'main/Album 1'

         if ($password === '') {
             json_error("Mật khẩu không được để trống.", 400);
         }

        try {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            if ($hash === false) {
                 error_log("admin_set_password: password_hash() failed.");
                 throw new Exception("Không thể tạo hash mật khẩu.");
            }
            
            // Use the source-prefixed path in the SQL query
            $sql = "INSERT OR REPLACE INTO folder_passwords (folder_name, password_hash) VALUES (?, ?)";
            $stmt = $pdo->prepare($sql);
            if ($stmt->execute([$source_prefixed_path, $hash])) { // Use validated path
                unset($_SESSION['authorized_folders'][$source_prefixed_path]); // Use validated path
                json_response(['success' => true, 'message' => "Đặt/Cập nhật mật khẩu thành công cho thư mục '" . htmlspecialchars($source_prefixed_path) . "' (" . htmlspecialchars($folder_path_info['source_key']) . ")."]);
            } else {
                 error_log("admin_set_password: DB execute failed for path '{$source_prefixed_path}'.");
                 throw new Exception("Execute query failed.");
            }
        } catch (Throwable $e) {
            error_log("admin_set_password: Error for '{$source_prefixed_path}': " . $e->getMessage());
            json_error("Lỗi server khi đặt mật khẩu. " . $e->getMessage(), 500);
        }
        break;

    case 'admin_remove_password':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
             json_error("Phương thức không hợp lệ.", 405);
        }
        if (empty($_SESSION['admin_logged_in'])) {
            json_error("Yêu cầu đăng nhập Admin.", 403);
        }

        $folder_param = $_POST['folder'] ?? null; // Expect source-prefixed path e.g., 'main/Album 1'
        
        if ($folder_param === null) {
             json_error("Thiếu thông tin thư mục.", 400);
        }

        // Validate the FOLDER path
        $folder_path_info = validate_source_and_path($folder_param);

        // Allow removing password even if folder doesn't strictly exist anymore (path might be valid format but dir gone)
        // Just check if the format is valid and not root.
        if ($folder_path_info === null || $folder_path_info['is_root']) { 
            json_error("Đường dẫn thư mục không hợp lệ.", 400);
        }
        $source_prefixed_path = $folder_path_info['source_prefixed_path']; // Use the validated path e.g., 'main/Album 1'

        try {
            // Use the source-prefixed path
            $sql = "DELETE FROM folder_passwords WHERE folder_name = ?";
            $stmt = $pdo->prepare($sql);
            $success = $stmt->execute([$source_prefixed_path]); // Use validated path
            $affected_rows = $stmt->rowCount(); // Check how many rows were deleted

            unset($_SESSION['authorized_folders'][$source_prefixed_path]); // Use validated path
            
            json_response(['success' => true, 'message' => "Đã xóa mật khẩu (nếu có) cho thư mục '" . htmlspecialchars($source_prefixed_path) . "'. Bị ảnh hưởng: {$affected_rows} dòng."]);
        } catch (Throwable $e) {
            error_log("[admin_remove_password] FATAL ERROR for '{$source_prefixed_path}': " . $e->getMessage() . "\nStack Trace:\n" . $e->getTraceAsString());
            json_error("Lỗi server khi xóa mật khẩu. " . $e->getMessage(), 500);
        }
        break;

    case 'list_files':
        $subdir_requested = $_GET['dir'] ?? '';
        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        $items_per_page = 100; // Example: items per page

        // Validate the requested directory using the new function
        $path_info = validate_source_and_path($subdir_requested);

        if ($path_info === null) {
            json_error('Thư mục không hợp lệ hoặc không tồn tại.', 404);
        }

        // --- Handle Root Request (List Merged Subdirs from All Sources) ---
        if ($path_info['is_root']) {
            $all_subdirs = [];

            // Loop through each defined source
            foreach (IMAGE_SOURCES as $source_key => $source_config) {
                 // Ensure $source_config is an array and has 'path' key
                 if (!is_array($source_config) || !isset($source_config['path'])) {
                     error_log("[list_files - Root] Invalid source config structure for key '{$source_key}'. Skipping.");
                     continue;
                 }
                $source_base_path = $source_config['path']; // Get path from config
                $resolved_source_base_path = realpath($source_base_path); // Resolve it

                 // *** DEBUGGING ADDED ***
                 error_log("[list_files - Root DEBUG] Before check: key={$source_key}, path='{$source_base_path}', resolved_type=" . gettype($resolved_source_base_path) . ", resolved_value=" . print_r($resolved_source_base_path, true));
                 // *** END DEBUGGING ***

                try {
                    // Check the resolved path (string or false)
                    if ($resolved_source_base_path === false || !is_dir($resolved_source_base_path) || !is_readable($resolved_source_base_path)) {
                        error_log("[list_files - Root] Source '{$source_key}' is not readable or not a directory. Skipping.");
                        continue;
                    }
                    // Iterate using the RESOLVED path (MUST be a string here)
                    $iterator = new DirectoryIterator($resolved_source_base_path);
                    foreach ($iterator as $fileinfo) {
                        // We only care about DIRECTORIES directly inside the source base path
                        if ($fileinfo->isDot() || !$fileinfo->isDir()) {
                            continue;
                        }
                        $subdir_name = $fileinfo->getFilename();
                        $subdir_absolute_path = $fileinfo->getPathname(); // Use getPathname for consistency
                        $subdir_source_prefixed_path = $source_key . '/' . $subdir_name;

                        // Add directory info to the merged list
                        $all_subdirs[] = [
                            'name' => $subdir_name, // Actual subdir name
                            'type' => 'folder',
                            'path' => $subdir_source_prefixed_path, // Source-prefixed path
                            'is_dir' => true,
                            'fileinfo' => $subdir_absolute_path, // Absolute path
                            'source_key' => $source_key // Keep track of the source
                        ];
                    }
                } catch (Exception $e) {
                    error_log("[list_files - Root] Error scanning source '{$source_key}': " . $e->getMessage());
                    // Continue to next source if one fails
                }
            }
            error_log("[list_files - Root] Found total subdirs across sources: " . count($all_subdirs));

            // --- Sort the merged list of subdirectories by name --- 
            usort($all_subdirs, function ($a, $b) {
                 return strnatcasecmp($a['name'], $b['name']); // Natural case-insensitive sort
            });

            // --- Apply Pagination to the merged list --- 
            $total_items = count($all_subdirs);
            $total_pages = ceil($total_items / $items_per_page);
            $offset = ($page - 1) * $items_per_page;
            // Use the merged list $all_subdirs for slicing
            $paginated_items = array_slice($all_subdirs, $offset, $items_per_page); 
            error_log("[list_files - Root] Paginated items count: " . count($paginated_items));

            // --- Process Paginated Subdirectories (Similar to non-root) --- 
            $folders_data = []; // This will hold the final folder data for JSON
            $files_data = [];   // Will remain empty for root view

            // Fetch all protected folder names once for efficiency in root view
            $all_protected_folders = [];
            try {
                $stmt = $pdo->query("SELECT folder_name FROM folder_passwords");
                while ($protected_folder = $stmt->fetchColumn()) {
                    $all_protected_folders[$protected_folder] = true;
                }
            } catch (PDOException $e) {
                error_log("[list_files - Root] Error fetching protected folders: " . $e->getMessage());
                // Continue without protected status if DB fails, maybe?
            }

            foreach ($paginated_items as $item) {
                // Item already contains name, path, source_key, fileinfo
                error_log("[list_files - Root] Processing paginated subdir: " . $item['name'] . " (Path: " . $item['path'] . ")");
                $folder_path_prefixed = $item['path']; // Use the source-prefixed path
                
                // Check access for subfolder
                $subfolder_access = check_folder_access($folder_path_prefixed);
                error_log("[list_files - Root] Access check result for '{$folder_path_prefixed}': " . json_encode($subfolder_access));

                // Always try to find the thumbnail regardless of password status (as requested)
                $subfolder_relative_path_in_source = $item['name']; // The relative path within the source base is just the subdir name
                
                // This function returns the relative path of the image *inside* the subfolder (or null)
                $first_image_relative_to_subdir = find_first_image_in_source($item['source_key'], $subfolder_relative_path_in_source, $allowed_ext);
                
                // *** FIX: Construct the full source-prefixed path for the thumbnail image ***
                $thumbnail_source_prefixed_path = null;
                if ($first_image_relative_to_subdir !== null) {
                    // Combine the subfolder's source-prefixed path with the image's relative path within it
                    $thumbnail_source_prefixed_path = $folder_path_prefixed . '/' . $first_image_relative_to_subdir;
                     // Normalize just in case find_first_image_in_source returns leading slash (it shouldn't)
                     $thumbnail_source_prefixed_path = str_replace('//', '/', $thumbnail_source_prefixed_path);
                }
                // *** END FIX ***

                error_log("[list_files - Root] Found thumbnail path for '{$folder_path_prefixed}': " . ($thumbnail_source_prefixed_path ?? 'None')); // Log the corrected path

                $folders_data[] = [
                    'name' => $item['name'],
                    'type' => 'folder',
                    'path' => $folder_path_prefixed, // Source-prefixed path for navigation
                    'protected' => $subfolder_access['protected'],       // ADDED
                    'authorized' => $subfolder_access['authorized'],      // ADDED
                    // 'password_required' => $subfolder_access['password_required'], // Redundant if using protected/authorized flags
                    'thumbnail' => $thumbnail_source_prefixed_path // Use the full, source-prefixed path
                ];
            }

            // --- Final Root Response --- 
            $final_response_data = [
                'files' => $files_data, // Still empty
                'folders' => $folders_data, // Populated with processed subdirs
                'breadcrumb' => [], // Empty for root
                'current_dir' => '', // Empty for root
                'pagination' => [
                    'current_page' => $page,
                    'total_pages' => $total_pages,
                    'total_items' => $total_items
                ],
                'is_root' => true // Indicate it's the root view
            ];
            error_log("[list_files - Root] Data before json_response: " . json_encode($final_response_data));
            json_response($final_response_data);
            exit; // Exit after handling root
        }

        // --- Handle Specific Directory Request ---
        $source_key = $path_info['source_key'];
        $current_relative_path = $path_info['relative_path']; // Path relative to source base
        $current_absolute_path = $path_info['absolute_path'];
        $current_source_prefixed_path = $path_info['source_prefixed_path']; // e.g., main/album

        // Check access for the specific directory
        error_log("[DEBUG list_files] Calling check_folder_access for: {$current_source_prefixed_path}"); // ADDED LOG
        $access = check_folder_access($current_source_prefixed_path);
        error_log("[DEBUG list_files] Access result for {$current_source_prefixed_path}: " . json_encode($access)); // ADDED LOG
        
        if (!$access['authorized']) {
            if (!empty($access['password_required'])) {
                error_log("[DEBUG list_files] Access DENIED (password required) for {$current_source_prefixed_path}. Sending 401."); // ADDED LOG
                json_error('Yêu cầu mật khẩu.', 401); // Unauthorized, password needed
            } else {
                error_log("[DEBUG list_files] Access DENIED (forbidden) for {$current_source_prefixed_path}. Sending 403. Reason: " . ($access['error'] ?? 'N/A')); // ADDED LOG
                json_error($access['error'] ?? 'Không có quyền truy cập.', 403); // Forbidden
            }
        }
        error_log("[DEBUG list_files] Access GRANTED for {$current_source_prefixed_path}. Proceeding to list items."); // ADDED LOG

        // --- Increment View Count (Only on first page load AND for top-level album directories) ---
        if ($page === 1 && substr_count($current_source_prefixed_path, '/') === 1) {
            try {
                $sql = "INSERT INTO folder_stats (folder_name, views) VALUES (?, 1) 
                        ON CONFLICT(folder_name) DO UPDATE SET views = views + 1";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$current_source_prefixed_path]);
                error_log("[DEBUG list_files] Incremented view count for '{$current_source_prefixed_path}'"); // ADDED LOG
            } catch (PDOException $e) {
                error_log("[list_files] WARNING: Failed to increment view count in DB for '{$current_source_prefixed_path}': " . $e->getMessage());
                // Continue even if stats update fails
            }
        }
        // ------------------------------------------------------

        // Build Breadcrumb using the source-prefixed path
        $breadcrumb = [];
        if ($current_source_prefixed_path) {
            $parts = explode('/', $current_source_prefixed_path);
            $current_crumb_path = '';
            foreach ($parts as $part) {
                $current_crumb_path = $current_crumb_path ? $current_crumb_path . '/' . $part : $part;
                $breadcrumb[] = ['name' => $part, 'path' => $current_crumb_path];
            }
        }

        // --- Scan Directory --- 
        $items = [];
        try {
            error_log("[list_files DEBUG] Scanning path: " . $current_absolute_path); // DEBUG LOG 1
            $iterator = new DirectoryIterator($current_absolute_path);
            foreach ($iterator as $fileinfo) {
                if ($fileinfo->isDot()) continue;
                $filename = $fileinfo->getFilename();
                error_log("[list_files DEBUG] Found item: " . $filename); // DEBUG LOG 2
                // Construct the source-prefixed path for this item
                $item_source_prefixed_path = $current_relative_path ? $source_key . '/' . $current_relative_path . '/' . $filename : $source_key . '/' . $filename;

                if ($fileinfo->isDir()) {
                     // Add directory item (no dimension needed)
                     $items[] = ['name' => $filename, 'type' => 'folder', 'path' => $item_source_prefixed_path, 'is_dir' => true, 'fileinfo' => $fileinfo->getPathname()];
                } elseif ($fileinfo->isFile() && in_array(strtolower($fileinfo->getExtension()), $allowed_ext, true)) {
                     // Add file item WITHOUT dimensions initially
                     $items[] = [
                         'name' => $filename, 
                         'type' => 'file', 
                         'path' => $item_source_prefixed_path, 
                         'is_dir' => false, 
                         'fileinfo' => $fileinfo->getPathname(), // Store absolute path for later use
                         // 'width' => null, // Remove width/height from initial scan
                         // 'height' => null
                     ];
                     error_log("[list_files DEBUG] Added file item (no dimensions yet): " . $filename);
                }
            }
        } catch (Exception $e) {
            error_log("Error scanning directory '{$current_absolute_path}': " . $e->getMessage());
            json_error('Lỗi khi đọc thư mục.', 500);
        }
        error_log("[list_files DEBUG] Total items found after scan: " . count($items)); // DEBUG LOG 3

        // --- Sort items: folders first, then by name --- 
        usort($items, function ($a, $b) {
            if ($a['is_dir'] !== $b['is_dir']) {
                return $a['is_dir'] ? -1 : 1; // Directories first
            }
            return strnatcasecmp($a['name'], $b['name']); // Natural case-insensitive sort
        });

        // --- Pagination --- 
        $total_items = count($items);
        $total_pages = ceil($total_items / $items_per_page);
        $offset = ($page - 1) * $items_per_page;
        $paginated_items = array_slice($items, $offset, $items_per_page);
        error_log("[list_files DEBUG] Paginated items count: " . count($paginated_items)); // DEBUG LOG 4

        // --- Process Paginated Items (Add thumbnails, check passwords, width/height) --- 
        $folders_data = [];
        $files_data = [];

        foreach ($paginated_items as $item) {
            error_log("[list_files DEBUG] Processing paginated item: " . $item['name']);
            if ($item['is_dir']) {
                 $folder_path_abs = $item['fileinfo']; // Absolute path from iterator
                 $folder_path_prefixed = $item['path']; // Source-prefixed path

                // Check access for subfolder
                $subfolder_access = check_folder_access($folder_path_prefixed);
                error_log("[list_files DEBUG] Access check for '{$folder_path_prefixed}': " . json_encode($subfolder_access)); // DEBUG LOG 6
                if (!$subfolder_access['authorized']) {
                     if ($subfolder_access['password_required']) {
                         error_log("[list_files DEBUG] Folder '{$folder_path_prefixed}' requires password. Adding to response.");
                         $folders_data[] = [
                             'name' => $item['name'],
                             'type' => 'folder',
                             'path' => $folder_path_prefixed,
                             'protected' => true,
                             'authorized' => false,
                             'thumbnail' => null // No thumbnail shown if password required initially
                         ];
                     } // Else: skip inaccessible/error folders
                     error_log("[list_files DEBUG] Folder '{$folder_path_prefixed}' skipped (unauthorized or error).");
                     continue;
                }

                // Find thumbnail for accessible subfolder
                // Need the relative path *within* the source for find_first_image
                $subfolder_relative_path = substr($folder_path_prefixed, strlen($source_key) + 1); // +1 for the slash

                // This function returns the relative path of the image *inside* the subfolder
                $first_image_relative_to_subdir = find_first_image_in_source($source_key, $subfolder_relative_path, $allowed_ext);

                // *** FIX: Construct the full source-prefixed path for the thumbnail image ***
                $thumbnail_source_prefixed_path = null;
                if ($first_image_relative_to_subdir !== null) {
                    // Combine the subfolder's source-prefixed path with the image's relative path within it
                    $thumbnail_source_prefixed_path = $folder_path_prefixed . '/' . $first_image_relative_to_subdir;
                     // Normalize just in case find_first_image_in_source returns leading slash
                     $thumbnail_source_prefixed_path = str_replace('//', '/', $thumbnail_source_prefixed_path);
                     error_log("[list_files DEBUG] Constructed thumbnail path for '{$folder_path_prefixed}': {$thumbnail_source_prefixed_path}"); // DEBUG LOG 7
                } else {
                     error_log("[list_files DEBUG] No first image found for thumbnail in '{$folder_path_prefixed}'"); // DEBUG LOG 8
                }
                // *** END FIX ***

                $folders_data[] = [
                    'name' => $item['name'],
                    'type' => 'folder',
                    'path' => $folder_path_prefixed, // Use source-prefixed path for navigation
                    'protected' => $subfolder_access['protected'], // Will be true if unlocked, false if public
                    'authorized' => $subfolder_access['authorized'], // Will be true if authorized, false if unauthorized
                    'thumbnail' => $thumbnail_source_prefixed_path // Use the corrected, full source-prefixed path
                ];
            } else { // Item is a file
                // --- Process IMAGE File ---
                $absolutePath = $item['fileinfo']; // Get absolute path stored earlier
                $width = null;
                $height = null;

                // Get dimensions ONLY for paginated items
                try {
                    $imageSize = @getimagesize($absolutePath);
                    if ($imageSize) {
                        $width = $imageSize[0];
                        $height = $imageSize[1];
                        error_log("[list_files DEBUG] Got dimensions for paginated item {$item['name']}: W=$width, H=$height");
                    } else {
                         error_log("[list_files WARNING] getimagesize() failed for paginated item {$item['name']}"); // Use error_log
                    }
                } catch (Exception $e) {
                     error_log("[list_files ERROR] Exception during getimagesize for paginated item {$item['name']}: " . $e->getMessage()); // Use error_log
                }

                error_log("[list_files DEBUG] Adding file to response with dimensions: " . $item['name'] . " W:" . ($width ?? 'N/A') . " H:" . ($height ?? 'N/A')); // Use error_log
                $files_data[] = [
                    'name' => $item['name'],
                    'type' => 'file',
                    'path' => $item['path'], // Already source-prefixed
                    'width' => $width,
                    'height' => $height
                ];
            } // End if ($item['is_dir'])
        } // End foreach ($paginated_items as $item)

        // --- Final Non-Root Response ---
        $final_response_data = [
            'files' => $files_data,
            'folders' => $folders_data,
            'breadcrumb' => $breadcrumb,
            'current_dir' => $current_source_prefixed_path,
            'pagination' => [
                'current_page' => $page,
                'total_pages' => $total_pages,
                'total_items' => $total_items
            ],
            'is_root' => false
        ];
        error_log("[list_files DEBUG] Data before json_response: " . json_encode($final_response_data)); // DEBUG LOG 9
        json_response($final_response_data);
        break; // End case 'list_files'

    case 'get_thumbnail':
        $requested_path = $_GET['path'] ?? null;
        $size = isset($_GET['size']) ? (int)$_GET['size'] : 150;

        if (!in_array($size, THUMBNAIL_SIZES_API, true)) {
            json_error("Kích thước thumbnail không hợp lệ.", 400);
        }
        if (empty($requested_path)) {
            json_error('Đường dẫn ảnh/thư mục không được cung cấp.', 400);
        }

        $source_image_absolute_path = null;
        $source_image_prefixed_path = null; // Path used for hashing
        $is_folder_thumb_request = (strpos($requested_path, 'folderthumb:') === 0);

        // --- Determine Source Image Path --- 
        if ($is_folder_thumb_request) {
            $folder_path_prefixed = substr($requested_path, strlen('folderthumb:'));
            $folder_path_info = validate_source_and_path($folder_path_prefixed);
            if ($folder_path_info === null || $folder_path_info['is_root']) {
                json_error('Đường dẫn thư mục không hợp lệ cho thumbnail.', 404);
            }
            // NOTE: Access check for the folder itself is bypassed here intentionally for folder thumbs
            $first_image_path_prefixed = find_first_image_in_source(
                $folder_path_info['source_key'], 
                $folder_path_info['relative_path'], 
                $allowed_ext
            );
            if ($first_image_path_prefixed === null) {
                json_error('Không tìm thấy ảnh đại diện cho thư mục.', 404);
            }
            $image_path_info = validate_source_and_file_path($first_image_path_prefixed);
            if ($image_path_info === null) {
                error_log("Error getting folder thumb: Found image '{$first_image_path_prefixed}' but failed validation.");
                json_error('Lỗi xử lý ảnh đại diện thư mục.', 500);
            }
            $source_image_absolute_path = $image_path_info['absolute_path'];
            $source_image_prefixed_path = $image_path_info['source_prefixed_path']; 
        } else {
            // Request is for a specific image file thumbnail
            $image_path_info = validate_source_and_file_path($requested_path);
            if ($image_path_info === null) {
                json_error('Đường dẫn ảnh không hợp lệ hoặc không tìm thấy.', 404);
            }
            // Access check for direct image thumbnails WILL be done later if cache miss
            $source_image_absolute_path = $image_path_info['absolute_path'];
            $source_image_prefixed_path = $image_path_info['source_prefixed_path'];
        }

        // --- Generate Cache Path (Same logic for both types) --- 
        $cache_hash = md5($source_image_prefixed_path); 
        $cache_filename = $cache_hash . '.jpg';
        $cache_dir_for_size = CACHE_THUMB_ROOT . DIRECTORY_SEPARATOR . $size;
        $cache_absolute_path = $cache_dir_for_size . DIRECTORY_SEPARATOR . $cache_filename;
        error_log("[get_thumbnail DEBUG] Source path: {$source_image_prefixed_path} | Cache path: {$cache_absolute_path}");

        // --- Step 1: Check Cache --- 
        if (file_exists($cache_absolute_path) && is_readable($cache_absolute_path)) {
            error_log("[get_thumbnail DEBUG] Cache HIT for {$cache_absolute_path}. Serving cache.");
            ob_end_clean(); 
            header('Content-Type: image/jpeg');
            header('Content-Length: ' . filesize($cache_absolute_path));
            header('Cache-Control: public, max-age=2592000');
            header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 2592000) . ' GMT');
            readfile($cache_absolute_path);
            exit;
        }
        error_log("[get_thumbnail DEBUG] Cache MISS for {$cache_absolute_path}.");

        // --- Step 2: Cache MISS - Check Access (ONLY if NOT a folder thumb request) --- 
        if (!$is_folder_thumb_request) {
            // Get containing folder for the direct image
            $containing_folder_path = dirname($source_image_prefixed_path);
            if ($containing_folder_path === $image_path_info['source_key'] || $containing_folder_path === '.') {
                $containing_folder_path = $image_path_info['source_key']; 
            }
            
            $folder_access = check_folder_access($containing_folder_path);
            if (!$folder_access['authorized']) {
                error_log("[get_thumbnail FORBIDDEN] Access denied (cache miss) for image in folder: {$containing_folder_path}");
                json_error('Không có quyền truy cập thư mục chứa ảnh (cache miss).', 403);
            }
             error_log("[get_thumbnail DEBUG] Access granted (cache miss) for image in folder: {$containing_folder_path}");
        } else {
             error_log("[get_thumbnail DEBUG] Skipping access check for folder thumbnail generation (cache miss).");
        }

        // --- Step 3: Create Thumbnail (If cache missed AND access granted or bypassed) --- 
        // Create size directory if it doesn't exist
        if (!is_dir($cache_dir_for_size)) {
            if (!@mkdir($cache_dir_for_size, 0775, true)) {
                $error = error_get_last();
                error_log("Failed to create thumbnail cache directory: {$cache_dir_for_size} - Error: {$error['message']}");
                json_error('Lỗi tạo thư mục cache thumbnail.', 500);
            }
        }
        if (!is_writable($cache_dir_for_size)) {
            error_log("Thumbnail cache directory is not writable: {$cache_dir_for_size}");
            json_error('Lỗi quyền ghi thư mục cache thumbnail.', 500);
        }

        // Create the thumbnail
        if (!create_thumbnail($source_image_absolute_path, $cache_absolute_path, $size)) {
            json_error('Lỗi tạo ảnh thumbnail.', 500);
        }

        // --- Step 4: Serve the NEWLY Created Thumbnail --- 
        if (file_exists($cache_absolute_path) && is_readable($cache_absolute_path)) {
             error_log("[get_thumbnail DEBUG] Serving newly created thumbnail: {$cache_absolute_path}");
             ob_end_clean();
             header('Content-Type: image/jpeg');
             header('Content-Length: ' . filesize($cache_absolute_path));
             // Shorter cache for newly generated?
             // header('Cache-Control: public, max-age=3600'); 
             // header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 3600) . ' GMT');
             readfile($cache_absolute_path);
             exit;
        } else {
            error_log("Thumbnail file not found or not readable AFTER creation attempt: {$cache_absolute_path}");
            json_error('Không thể đọc file thumbnail cache sau khi tạo.', 500);
        }
        break;

    case 'download_file':
        // Expect source-prefixed path, e.g., "main/album/image.jpg"
        $requested_path = $_GET['path'] ?? null;

        if (empty($requested_path)) {
            json_error('Đường dẫn file không được cung cấp.', 400);
        }

        // Validate the file path
        $file_path_info = validate_source_and_file_path($requested_path);

        if ($file_path_info === null) {
            json_error('File không hợp lệ, không tìm thấy hoặc không thể đọc.', 404);
        }

        $source_key = $file_path_info['source_key'];
        $source_prefixed_path = $file_path_info['source_prefixed_path'];
        $absolute_path = $file_path_info['absolute_path'];
        $filename = basename($absolute_path);

        // Check access to the containing folder
        $containing_folder_path = dirname($source_prefixed_path);
        // Handle case where image is directly in source root (e.g., 'main/image.jpg')
        if ($containing_folder_path === $source_key || $containing_folder_path === '.') {
             $containing_folder_path = $source_key; // Access check is on the source itself
        }
        
        $folder_access = check_folder_access($containing_folder_path);
        if (!$folder_access['authorized']) {
            json_error('Không có quyền truy cập thư mục chứa file này.', 403);
        }
        
        // --- Update Download Stats --- 
        try {
            // Increment download count for the containing folder (using source-prefixed path)
            $stmt = $pdo->prepare("INSERT INTO folder_stats (folder_name, downloads) VALUES (?, 1) 
                                 ON CONFLICT(folder_name) DO UPDATE SET downloads = downloads + 1");
            $stmt->execute([$containing_folder_path]);
        } catch (PDOException $e) {
            error_log("Failed to update download stats for folder '{$containing_folder_path}': " . $e->getMessage());
            // Continue with download even if stats update fails
        }

        // --- Prepare and Send File --- 
        ob_end_clean(); // Clear buffer before sending headers/file

        // Determine Content-Type
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime_type = finfo_file($finfo, $absolute_path);
        finfo_close($finfo);
        if (!$mime_type) {
            $mime_type = 'application/octet-stream'; // Default fallback
        }

        header('Content-Type: ' . $mime_type);
        header('Content-Disposition: attachment; filename="' . $filename . '"'); // Force download
        header('Content-Length: ' . filesize($absolute_path));
        header('Cache-Control: private'); // Prevent caching of download itself
        header('Pragma: private');
        header('Expires: Mon, 26 Jul 1997 05:00:00 GMT'); // Date in the past

        // Read the file and send it to the output buffer
        if (!readfile($absolute_path)) {
            // This might happen if file disappears between check and read, or read error
             http_response_code(500);
             error_log("Error reading file for download: {$absolute_path}");
             // Don't try to send JSON here as headers are already sent
        }
        exit;
        break;

    case 'authenticate':
        $folder_requested = $_POST['folder'] ?? null;
        $password = $_POST['password'] ?? null;

        if (empty($folder_requested) || $password === null) {
            json_error('Thiếu tên thư mục hoặc mật khẩu.', 400);
        }

        // Validate the folder path using the source-aware function
        // Note: Authenticating against the root (listing sources) doesn't make sense here.
        // validate_source_and_path returns null for invalid paths.
        $folder_path_info = validate_source_and_path($folder_requested);

        if ($folder_path_info === null || $folder_path_info['is_root']) {
            json_error('Thư mục không hợp lệ hoặc không thể xác thực.', 404); // Or 400 Bad Request
        }

        $folder_source_prefixed_path = $folder_path_info['source_prefixed_path']; // e.g., main/protected_album

        try {
            // Fetch the hash using the source-prefixed path as the key
            $stmt = $pdo->prepare("SELECT password_hash FROM folder_passwords WHERE folder_name = ?");
            $stmt->execute([$folder_source_prefixed_path]);
            $row = $stmt->fetch();

            if (!$row) {
                json_error('Thư mục này không yêu cầu mật khẩu hoặc không tồn tại.', 404);
            }

            $password_hash = $row['password_hash'];

            // Verify password
            if (password_verify($password, $password_hash)) {
                // Password OK - Store authorization in session using the source-prefixed path
                $_SESSION['authorized_folders'][$folder_source_prefixed_path] = true;
                // Regenerate session ID after successful login for security
                session_regenerate_id(true);
                json_response(['success' => true, 'message' => 'Xác thực thành công.']);
            } else {
                // Password incorrect
                json_error('Mật khẩu không đúng.', 401);
            }

        } catch (PDOException $e) {
            error_log("DB Error during authentication for folder '{$folder_source_prefixed_path}': " . $e->getMessage());
            json_error('Lỗi server khi xác thực.', 500);
        }
        break;

    // --- MOVE get_image HERE ---
    case 'get_image':
        // error_log("--- [get_image] START ---"); // REMOVE DEBUG
        $relativePath = $_GET['path'] ?? null;
        // error_log("[get_image] Received path parameter: " . print_r($relativePath, true)); // REMOVE DEBUG
        if (!$relativePath) {
            error_log("[get_image ERROR] Path parameter is missing. Sending 400.");
            http_response_code(400);
            if(ob_get_level()) ob_end_clean();
            header('Content-Type: text/plain');
            echo "Error: Image path parameter is missing.";
            exit;
        }

        // error_log("[get_image] Validating path: $relativePath"); // REMOVE DEBUG
        $image_path_info = validate_source_and_file_path($relativePath);

        if ($image_path_info === null) { 
            error_log("[get_image ERROR] Path validation failed for: $relativePath. Sending 404.");
            http_response_code(404);
             if(ob_get_level()) ob_end_clean();
            header('Content-Type: text/plain');
            echo "Error: Image path is invalid, not found, or source issue.";
            exit;
        }
        $absolutePath = $image_path_info['absolute_path'];
        $source_prefixed_path = $image_path_info['source_prefixed_path'];
        // error_log("[get_image] Path validated. Absolute: {$absolutePath}, Prefixed: {$source_prefixed_path}"); // REMOVE DEBUG
        
        // --- ACCESS CHECK (Based on containing folder) ---
        $containing_folder_path = dirname($source_prefixed_path);
        if ($containing_folder_path === $image_path_info['source_key'] || $containing_folder_path === '.') {
            $containing_folder_path = $image_path_info['source_key']; 
        }
        // error_log("[get_image] Checking access for containing folder: {$containing_folder_path}"); // REMOVE DEBUG
        $access = check_folder_access($containing_folder_path); 
        // error_log("[get_image] Access check result: " . json_encode($access)); // REMOVE DEBUG
        if (!$access['authorized']) {
            error_log("[get_image ACCESS DENIED] Access denied for image in folder: $containing_folder_path (Image: $source_prefixed_path). Sending 401."); 
            http_response_code(401); 
             if(ob_get_level()) ob_end_clean();
            header('Content-Type: text/plain');
            echo "Error: Access denied to the folder containing this image.";
            exit;
        }
        // error_log("[get_image ACCESS GRANTED] Access granted for image in folder: $containing_folder_path (Image: $source_prefixed_path)"); // REMOVE DEBUG

        // Determine MIME type
        // error_log("[get_image] Determining MIME type for: {$absolutePath}"); // REMOVE DEBUG
        $mime_type = mime_content_type($absolutePath);
        if (!$mime_type) {
            // Fallback based on extension if mime_content_type fails
            $extension = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION));
            $mime_map = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp']; // Added webp
            $mime_type = $mime_map[$extension] ?? 'application/octet-stream'; 
            error_log("[get_image WARNING] mime_content_type failed, using fallback based on extension ($extension): $mime_type"); 
        // } else {
            // error_log("[get_image] Determined MIME type: $mime_type"); // REMOVE DEBUG
        }
        
        // error_log("[get_image] Getting filesize for: {$absolutePath}"); // REMOVE DEBUG
        $file_size = @filesize($absolutePath); 
        if ($file_size === false) {
             error_log("[get_image ERROR] filesize() failed for: {$absolutePath}. Sending 500.");
             http_response_code(500);
             if(ob_get_level()) ob_end_clean();
             header('Content-Type: text/plain');
             echo "Error: Could not get file size.";
             exit;
        }
        // error_log("[get_image] Filesize: $file_size bytes"); // REMOVE DEBUG

        // Set headers
        // error_log("[get_image] Setting headers: Content-Type={$mime_type}, Content-Length={$file_size}"); // REMOVE DEBUG
         // Clear buffer BEFORE sending headers
         if (ob_get_level()) {
             // error_log("[get_image] Cleaning output buffer (level: " . ob_get_level() . ")."); // REMOVE DEBUG
             ob_end_clean(); 
         // } else {
              // error_log("[get_image] Output buffer level is 0."); // REMOVE DEBUG
         }
         
        header("Content-Type: $mime_type");
        header("Content-Length: $file_size");
        header("Cache-Control: public, max-age=86400"); 
        header("Expires: " . gmdate("D, d M Y H:i:s", time() + 86400) . " GMT");
        // Add content disposition header to suggest filename (optional but helpful)
        header('Content-Disposition: inline; filename="' . basename($absolutePath) . '"'); 

        // Clear output buffer and read the file
        // error_log("[get_image] Flushing output buffer..."); // REMOVE DEBUG
        flush(); 
        // error_log("[get_image] Calling readfile() for: {$absolutePath}"); // REMOVE DEBUG
        $readfile_result = readfile($absolutePath);

        if ($readfile_result === false) {
            error_log("[get_image ERROR] readfile() failed for: {$absolutePath}. Headers were already sent."); 
        // } else {
            // error_log("[get_image] Successfully served file: $absolutePath ($file_size bytes, readfile returned: " . print_r($readfile_result, true) . "). Exiting."); // REMOVE DEBUG
        }
        exit; 
    // --- END ACTION: get_image ---

    default:
        json_error("Hành động không hợp lệ: '{$action}'.", 400);

} // <--- Closing brace for switch ($action)

// Clean up buffer if script reaches end without exit (should not happen)
if (ob_get_level()) ob_end_flush(); 

?>