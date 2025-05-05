<?php
// api/helpers.php

// Dependencies: 
// - Assumes $pdo is available globally for check_folder_access.
// - Assumes IMAGE_SOURCES and CACHE_THUMB_ROOT constants are defined (from init.php).
// - Assumes $action is available globally for json_response logging.
// - Assumes $allowed_ext is available globally for find_first_image_in_source.

/** Gửi JSON phản hồi thành công */
function json_response($data, $code = 200)
{
    global $action; // Access the global action variable for logging specific cases
    http_response_code($code);

    // Log data specifically for admin_list_folders before encoding (example)
    if (isset($action) && $action === 'admin_list_folders') {
        // Consider passing $action explicitly if globals are avoided later
        error_log("Data before json_encode for admin_list_folders: " . print_r($data, true));
    }

    $json_output = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($json_output === false) {
        $error_msg = 'JSON Encode Error: ' . json_last_error_msg() . " | Data was: " . print_r($data, true);
        error_log($error_msg);
        // Fallback if encoding failed after setting headers
        http_response_code(500);
        $json_output = json_encode(['error' => 'Lỗi mã hóa JSON nội bộ.', 'details' => $error_msg]);
        if ($json_output === false) { // Total failure
            $json_output = '{"error": "Lỗi mã hóa JSON nghiêm trọng."}';
        }
    }

    // Clear any previous output buffer before sending the final JSON
    if (ob_get_level() > 0) {
        ob_end_clean();
    }
    echo $json_output;
    exit;
}

/** Gửi JSON lỗi */
function json_error($msg, $code = 400)
{
    json_response(['error' => $msg], $code);
}

/**
 * Làm sạch, xác thực đường dẫn thư mục con và trả về thông tin nguồn.
 * Accepts a source-prefixed relative path (e.g., "main/album/sub" or "extra_drive/stuff").
 * Returns null if invalid, or an array ['source_key', 'relative_path', 'absolute_path', 'source_prefixed_path', 'is_root' => bool] on success.
 * The returned 'relative_path' is relative to the source's base path.
 * SECURITY: Crucial for preventing path traversal.
 */
function validate_source_and_path($source_prefixed_path)
{
    // Access constant directly
    if (!defined('IMAGE_SOURCES')) {
        error_log("[validate_source_and_path] Error: IMAGE_SOURCES constant not defined.");
        return null;
    }

    if ($source_prefixed_path === null || $source_prefixed_path === '' || $source_prefixed_path === '/') {
        return ['source_key' => null, 'relative_path' => '', 'absolute_path' => null, 'source_prefixed_path' => '', 'is_root' => true];
    }

    // 1. Normalize and split
    $normalized_path = trim(str_replace(['..', '\\', "\0"], '', $source_prefixed_path), '/');
    if ($normalized_path === '') {
        return ['source_key' => null, 'relative_path' => '', 'absolute_path' => null, 'source_prefixed_path' => '', 'is_root' => true]; // Treat as root if becomes empty after normalization
    }
    $normalized_path = str_replace(DIRECTORY_SEPARATOR, '/', $normalized_path);
    $parts = explode('/', $normalized_path, 2);
    $source_key = $parts[0];
    $relative_path_in_source = $parts[1] ?? '';

    // 2. Check source key existence
    if (!isset(IMAGE_SOURCES[$source_key])) {
        error_log("Path validation failed: Invalid source key '{$source_key}' in path '{$source_prefixed_path}'");
        return null;
    }
    $source_config = IMAGE_SOURCES[$source_key];
    $source_base_path = $source_config['path'];

    // 3. Check if the source base path itself is valid
    $resolved_source_base_path = @realpath($source_base_path);
    if ($resolved_source_base_path === false || !is_dir($resolved_source_base_path) || !is_readable($resolved_source_base_path)) {
        error_log("[validate_source_and_path] Source base path is invalid or not accessible for key '{$source_key}': {$source_base_path} (Resolved: " . ($resolved_source_base_path ?: 'false') . ")");
        return null;
    }
    $source_base_path = $resolved_source_base_path; // Use the resolved path

    // 4. Construct target absolute path
    $target_absolute_path = $source_base_path . ($relative_path_in_source ? DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative_path_in_source) : '');

    // 5. Get real path of the target and validate
    $real_target_path = @realpath($target_absolute_path);

    // 6. Final checks: Must resolve, be a DIRECTORY, and be within the source base
    if (
        $real_target_path === false ||
        !is_dir($real_target_path) ||
        strpos($real_target_path, $source_base_path . DIRECTORY_SEPARATOR) !== 0 && $real_target_path !== $source_base_path // Allow exact match for source root
    ) {
        // Log details for debugging
        // ... (logging code omitted for brevity, same as original)
        error_log("Path validation failed for directory: [Details Omitted]");
        return null;
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
 * Validate a source-prefixed path points to a valid, readable FILE within the correct source.
 *
 * @param string $source_prefixed_path e.g., "main/album/image.jpg"
 * @return array|null ['source_key', 'relative_path', 'absolute_path', 'source_prefixed_path'] or null if invalid.
 */
function validate_source_and_file_path($source_prefixed_path)
{
    // Access constant directly
    if (!defined('IMAGE_SOURCES')) {
        error_log("[validate_source_and_file_path] Error: IMAGE_SOURCES constant not defined.");
        return null;
    }

    if ($source_prefixed_path === null || $source_prefixed_path === '' || $source_prefixed_path === '/') {
        error_log("File validation failed: Path cannot be empty or root.");
        return null; // Cannot validate root or empty path as a file
    }

    // 1. Normalize and split
    $normalized_path = trim(str_replace(['..', '\\', "\0"], '', $source_prefixed_path), '/');
    if ($normalized_path === '') {
        error_log("File validation failed: Normalized path is empty.");
        return null;
    }
    $normalized_path = str_replace(DIRECTORY_SEPARATOR, '/', $normalized_path);
    $parts = explode('/', $normalized_path, 2);
    $source_key = $parts[0];
    $relative_path_in_source = $parts[1] ?? '';

    if ($relative_path_in_source === '') {
        error_log("File validation failed: Path refers only to a source key, not a file: {$source_prefixed_path}");
        return null;
    }

    // 2. Check source key existence
    if (!isset(IMAGE_SOURCES[$source_key])) {
        error_log("File validation failed: Invalid source key '{$source_key}' in path '{$source_prefixed_path}'");
        return null;
    }
    $source_config = IMAGE_SOURCES[$source_key];
    $source_base_path = $source_config['path'];

    // 3. Check source base path validity
    $resolved_source_base_path = @realpath($source_base_path);
    if ($resolved_source_base_path === false || !is_dir($resolved_source_base_path) || !is_readable($resolved_source_base_path)) {
        error_log("[validate_source_and_file_path] Source base path invalid/inaccessible for key '{$source_key}': {$source_base_path}");
        return null;
    }
    $source_base_path = $resolved_source_base_path;

    // 4. Construct target absolute path
    $target_absolute_path = $source_base_path . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative_path_in_source);

    // 5. Get real path of the target
    $real_target_path = @realpath($target_absolute_path);

    // 6. Final checks for FILE: Must resolve, be a FILE, be READABLE, and within source base
    if (
        $real_target_path === false ||
        !is_file($real_target_path) ||
        !is_readable($real_target_path) ||
        strpos($real_target_path, $source_base_path . DIRECTORY_SEPARATOR) !== 0
    ) {
        // Log details for debugging
        // ... (logging code omitted for brevity, same as original)
        error_log("File validation failed: [Details Omitted]");
        return null;
    }

    // 7. Calculate final relative path based on realpath
    $final_relative_path = substr($real_target_path, strlen($source_base_path));
    $final_relative_path = ltrim(str_replace(DIRECTORY_SEPARATOR, '/', $final_relative_path), '/');

    // 8. Return valid info
    return [
        'source_key' => $source_key,
        'relative_path' => $final_relative_path,
        'absolute_path' => $real_target_path,
        'source_prefixed_path' => $source_key . '/' . $final_relative_path // Reconstruct canonical path
    ];
}


/**
 * Kiểm tra quyền truy cập thư mục (dựa vào DB và Session)
 * IMPORTANT: $folder_source_prefixed_path MUST be the source-prefixed path.
 * Assumes $pdo is globally available.
 */
function check_folder_access($folder_source_prefixed_path)
{
    global $pdo; // Assuming $pdo is available from init.php

    if (!isset($pdo)) {
        error_log("[check_folder_access] FATAL: PDO object not available.");
        return ['protected' => true, 'authorized' => false, 'error' => 'Lỗi server nghiêm trọng (DB unavailable).'];
    }

    // Root access (listing sources) is always allowed
    if (empty($folder_source_prefixed_path)) {
        return ['protected' => false, 'authorized' => true, 'password_required' => false];
    }

    try {
        $stmt = $pdo->prepare("SELECT password_hash FROM folder_passwords WHERE folder_name = ? LIMIT 1");
        $stmt->execute([$folder_source_prefixed_path]);
        $row = $stmt->fetch();
        $is_protected = ($row !== false);

        if (!$is_protected) {
            return ['protected' => false, 'authorized' => true, 'password_required' => false];
        }

        // If protected, check session authorization
        $session_key = 'authorized_folders';
        $is_authorized_in_session = !empty($_SESSION[$session_key][$folder_source_prefixed_path]);

        if ($is_authorized_in_session) {
            return ['protected' => true, 'authorized' => true, 'password_required' => false];
        }

        // Protected and not authorized in session
        return ['protected' => true, 'authorized' => false, 'password_required' => true];

    } catch (PDOException $e) {
        error_log("DB Error checking folder access for '{$folder_source_prefixed_path}': " . $e->getMessage());
        return ['protected' => true, 'authorized' => false, 'error' => 'Lỗi server khi kiểm tra quyền truy cập.'];
    }
}

/**
 * Find the first image recursively within a specific directory of a specific source.
 *
 * @param string $source_key The key of the source.
 * @param string $relative_dir_path Path relative to the source's base path. Use '' for source root.
 * @param array $allowed_ext Reference to allowed extensions array (now passed explicitly).
 * @return string|null Source-prefixed relative path of the first image found, or null.
 */
function find_first_image_in_source($source_key, $relative_dir_path, array &$allowed_ext)
{
    if (!defined('IMAGE_SOURCES') || !isset(IMAGE_SOURCES[$source_key])) {
        error_log("[find_first_image_in_source] Invalid source key: '{$source_key}'");
        return null;
    }
    $source_config = IMAGE_SOURCES[$source_key];
    $source_base_path = $source_config['path'];
    $resolved_source_base_path = realpath($source_base_path);

    if ($resolved_source_base_path === false) {
        error_log("[find_first_image_in_source] Source base path does not resolve for key '{$source_key}': {$source_base_path}");
        return null;
    }

    $normalized_relative_dir = trim(str_replace(['..', '\\', "\0"], '', $relative_dir_path), '/');
    $target_dir_absolute = $resolved_source_base_path . (empty($normalized_relative_dir) ? '' : DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $normalized_relative_dir));
    $resolved_target_dir_absolute = realpath($target_dir_absolute);

    if ($resolved_target_dir_absolute === false || !is_dir($resolved_target_dir_absolute) || !is_readable($resolved_target_dir_absolute)) {
        // error_log("[find_first_image DEBUG] Target directory invalid/unreadable: {$target_dir_absolute} (Resolved: " . ($resolved_target_dir_absolute ?: 'false') . ")"); // REMOVED LOG
        return null; // Directory doesn't exist or isn't readable
    }

    if (strpos($resolved_target_dir_absolute, $resolved_source_base_path) !== 0) {
        error_log("[find_first_image_in_source] Security Check Failed: Target '{$resolved_target_dir_absolute}' outside source base '{$resolved_source_base_path}'.");
        return null;
    }

    // error_log("[find_first_image DEBUG] Scanning recursively inside: {$resolved_target_dir_absolute}"); // REMOVED LOG

    try {
        // *** Use Recursive Iterator for finding the first image recursively ***
        $directory = new RecursiveDirectoryIterator(
            $resolved_target_dir_absolute,
            RecursiveDirectoryIterator::SKIP_DOTS | RecursiveDirectoryIterator::FOLLOW_SYMLINKS
        );
        $iterator = new RecursiveIteratorIterator($directory, RecursiveIteratorIterator::LEAVES_ONLY);

        $image_files = [];
        // $files_scanned_count = 0; // REMOVED COUNTER
        foreach ($iterator as $fileinfo) {
            // $files_scanned_count++; // REMOVED COUNTER
            if ($fileinfo->isFile() && $fileinfo->isReadable()) {
                $extension = strtolower($fileinfo->getExtension());
                if (in_array($extension, $allowed_ext, true)) {
                    $image_real_path = $fileinfo->getRealPath();
                    // Calculate relative path from the TARGET directory being scanned
                    $image_relative_to_target_dir = substr($image_real_path, strlen($resolved_target_dir_absolute));
                    $image_relative_to_target_dir = ltrim(str_replace(DIRECTORY_SEPARATOR, '/', $image_relative_to_target_dir), '/');
                    // error_log("[find_first_image DEBUG] Found valid image: {$image_relative_to_target_dir}"); // REMOVED LOG
                    $image_files[$image_relative_to_target_dir] = true; 
                }
            }
        }
        // error_log("[find_first_image DEBUG] Scanned {$files_scanned_count} files/items total."); // REMOVED LOG

        if (!empty($image_files)) {
            uksort($image_files, 'strnatcasecmp');
            $first_image_relative_path = key($image_files);
            // error_log("[find_first_image DEBUG] Returning first image: {$first_image_relative_path}"); // REMOVED LOG
            return $first_image_relative_path;
        }

    } catch (Exception $e) {
        error_log("[find_first_image ERROR] Exception scanning directory '{$resolved_target_dir_absolute}': " . $e->getMessage()); // Keep error log
        return null;
    }

    // error_log("[find_first_image DEBUG] No valid images found in {$resolved_target_dir_absolute} or its subdirectories."); // REMOVED LOG
    return null; // No image found
}


/**
 * Create a thumbnail image using GD library.
 *
 * @param string $source_path Absolute path to the source image.
 * @param string $cache_path Absolute path to save the thumbnail.
 * @param int $thumb_size Desired width/height.
 * @return bool True on success, false on failure.
 */
function create_thumbnail($source_path, $cache_path, $thumb_size = 150)
{
    if (!extension_loaded('gd')) {
        error_log("[create_thumbnail] GD extension is not loaded.");
        return false;
    }

    // Prevent race conditions
    if (file_exists($cache_path)) {
        touch($cache_path); // Update timestamp maybe?
        return true;
    }

    try {
        if (!is_readable($source_path)) {
            error_log("[create_thumbnail] Source image not readable: {$source_path}");
            return false;
        }
        $image_info = @getimagesize($source_path);
        if ($image_info === false) {
            error_log("[create_thumbnail] Failed to get image size for: {$source_path}");
            return false;
        }
        list($original_width, $original_height, $image_type) = $image_info;
        $mime = image_type_to_mime_type($image_type);

        $source_image = null;
        switch ($mime) {
            case 'image/jpeg': $source_image = @imagecreatefromjpeg($source_path); break;
            case 'image/png': $source_image = @imagecreatefrompng($source_path); break;
            case 'image/gif': $source_image = @imagecreatefromgif($source_path); break;
            case 'image/webp':
                if (function_exists('imagecreatefromwebp')) {
                    $source_image = @imagecreatefromwebp($source_path);
                } else {
                    error_log("[create_thumbnail] WebP not supported by GD: {$source_path}"); return false;
                }
                break;
            default: error_log("[create_thumbnail] Unsupported type '{$mime}': {$source_path}"); return false;
        }

        if ($source_image === false) {
            error_log("[create_thumbnail] Failed to create image resource from: {$source_path}");
            return false;
        }

        // Calculate new dimensions (simple resize, maintain aspect ratio)
        $ratio = $original_width / $original_height;
        if ($original_width > $original_height) {
            $thumb_width = $thumb_size;
            $thumb_height = intval($thumb_size / $ratio);
        } else {
            $thumb_height = $thumb_size;
            $thumb_width = intval($thumb_size * $ratio);
        }
        // Prevent zero dimensions
        $thumb_width = max(1, $thumb_width);
        $thumb_height = max(1, $thumb_height);

        $thumb_image = imagecreatetruecolor($thumb_width, $thumb_height);
        if ($thumb_image === false) {
            error_log("[create_thumbnail] Failed to create true color canvas.");
            imagedestroy($source_image); return false;
        }

        // Handle transparency for PNG/GIF
        if ($mime == 'image/png' || $mime == 'image/gif') {
            imagealphablending($thumb_image, false);
            imagesavealpha($thumb_image, true);
            $transparent = imagecolorallocatealpha($thumb_image, 255, 255, 255, 127);
            imagefilledrectangle($thumb_image, 0, 0, $thumb_width, $thumb_height, $transparent);
        }

        if (!imagecopyresampled($thumb_image, $source_image, 0, 0, 0, 0, $thumb_width, $thumb_height, $original_width, $original_height)) {
            error_log("[create_thumbnail] imagecopyresampled failed.");
            imagedestroy($source_image); imagedestroy($thumb_image); return false;
        }

        // Ensure cache directory exists
        $cache_dir = dirname($cache_path);
        if (!is_dir($cache_dir)) {
            if (!@mkdir($cache_dir, 0775, true)) {
                error_log("[create_thumbnail] Failed to create cache directory: {$cache_dir}");
                imagedestroy($source_image); imagedestroy($thumb_image); return false;
            }
        }

        // Save thumbnail (use JPEG for consistency)
        $save_success = imagejpeg($thumb_image, $cache_path, 85); // Quality 85

        imagedestroy($source_image);
        imagedestroy($thumb_image);

        if (!$save_success) {
            error_log("[create_thumbnail] Failed to save thumbnail to: {$cache_path}");
            if (file_exists($cache_path)) @unlink($cache_path); // Clean up failed attempt
            return false;
        }

        // error_log("[create_thumbnail] Created thumbnail: {$cache_path}"); // Optional logging
        return true;

    } catch (Throwable $e) {
        error_log("[create_thumbnail] Exception for {$source_path} -> {$cache_path} : " . $e->getMessage());
        if (isset($source_image) && is_resource($source_image)) imagedestroy($source_image);
        if (isset($thumb_image) && is_resource($thumb_image)) imagedestroy($thumb_image);
        if (file_exists($cache_path)) @unlink($cache_path);
        return false;
    }
}

// Add any other helper functions from api.php here...
// Note: sanitize_subdir was marked as DEPRECATED, so it's omitted unless needed. 