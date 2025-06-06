<?php
// API actions for the Jet Culling App

// Constants like RAW_IMAGE_SOURCES, RAW_FILE_EXTENSIONS and functions like json_error()
// are expected to be available globally via api/init.php and api/helpers.php, loaded by the main api.php router.

if (!defined('RAW_IMAGE_SOURCES')) {
    // This is a fallback / error check. init.php should have defined this.
    json_error("Lỗi cấu hình nghiêm trọng: RAW_IMAGE_SOURCES không được tải bởi init.php.", 500);
    exit;
}
if (!function_exists('json_error')) {
    // This is a fallback / error check. init.php (via helpers.php) should have defined this.
    // Attempt to load helpers directly if not found, though this indicates a deeper issue.
    if (file_exists(__DIR__ . '/helpers.php')) {
        require_once __DIR__ . '/helpers.php';
    } 
    if (!function_exists('json_error')) {
        error_log("CRITICAL: json_error function not found and helpers.php could not be loaded directly by actions_jet.php");
        // Fallback to plain text error if json_error is truly unavailable
        header('Content-Type: text/plain');
        http_response_code(500);
        echo "Lỗi máy chủ nghiêm trọng: Chức năng ghi nhật ký lỗi không khả dụng.";
        exit;
    }
}


$jet_action = $_REQUEST['action'] ?? null;



switch ($jet_action) {
    case 'jet_list_raw_sources':
        error_log("[JET_ACTION] Entered case: jet_list_raw_sources - Now listing top-level folders from all RAW sources.");

        if (!defined('RAW_IMAGE_SOURCES') || !is_array(RAW_IMAGE_SOURCES)) {
            error_log("[Jet API] RAW_IMAGE_SOURCES constant is not defined or not an array. Check config.");
            json_error("Lỗi cấu hình nguồn RAW phía server.", 500);
        }

        if (empty(RAW_IMAGE_SOURCES)) {
            json_response(['folders' => []]); // Return empty array if no RAW sources are configured
            exit;
        }

        $top_level_folders = [];

        foreach (RAW_IMAGE_SOURCES as $source_key => $source_config) {
            if (!is_array($source_config) || !isset($source_config['path'])) {
                error_log("[Jet API] Skipping invalid RAW source config for key: {$source_key}");
                continue;
            }
            $base_path = $source_config['path']; // This is already a realpath

            if (!is_dir($base_path) || !is_readable($base_path)) {
                error_log("[Jet API] RAW source path not found or not readable for key '{$source_key}': {$base_path}");
                continue;
            }

            try {
                $iterator = new DirectoryIterator($base_path);
                foreach ($iterator as $fileinfo) {
                    if ($fileinfo->isDot() || !$fileinfo->isDir()) {
                        continue;
                    }
                    $folder_name = $fileinfo->getFilename();
                    $top_level_folders[] = [
                        'name' => $folder_name,
                        // Full path relative to system, but for API, client needs source_key/folder_name
                        'path' => $source_key . '/' . $folder_name, 
                        'source_key' => $source_key,
                        'type' => 'folder' // Consistent with jet_list_folders_in_raw_source
                    ];
                }
            } catch (Exception $e) {
                error_log("[Jet API] Error scanning RAW source directory '{$base_path}' for key '{$source_key}': " . $e->getMessage());
                // Continue to next source if one fails
            }
        }

        // Sort all collected top-level folders alphabetically by name
        usort($top_level_folders, fn($a, $b) => strnatcasecmp($a['name'], $b['name']));

        json_response(['folders' => $top_level_folders]);
        exit;

    case 'jet_list_folders_in_raw_source':
        $source_key = $_GET['source_key'] ?? null;
        $relative_path = $_GET['path'] ?? ''; // Relative path within the source

        if (!$source_key) {
            json_error("Thiếu tham số 'source_key'.", 400);
            exit;
        }

        if (!defined('RAW_IMAGE_SOURCES') || !is_array(RAW_IMAGE_SOURCES) || !isset(RAW_IMAGE_SOURCES[$source_key])) {
            error_log("[Jet API] Invalid or undefined RAW source key: {$source_key}");
            json_error("Nguồn RAW không hợp lệ hoặc không được định nghĩa: " . htmlspecialchars($source_key), 404);
            exit;
        }

        $source_config = RAW_IMAGE_SOURCES[$source_key];
        $base_path = $source_config['path']; // This is already a realpath from db_connect.php

        // Sanitize and validate the relative path to prevent directory traversal
        // Normalize slashes, remove leading/trailing slashes, remove ".."
        $sanitized_relative_path = trim(str_replace('..', '', $relative_path), '/');
        $sanitized_relative_path = str_replace('\\', '/', $sanitized_relative_path);
        
        $full_path_to_scan = $base_path;
        if (!empty($sanitized_relative_path)) {
            $full_path_to_scan .= '/' . $sanitized_relative_path;
        }

        // Final check that the path is still within the base path (though str_replace('..','') helps a lot)
        if (realpath($full_path_to_scan) === false || strpos(realpath($full_path_to_scan), $base_path) !== 0) {
            error_log("[Jet API] Path traversal attempt or invalid path for source '{$source_key}': {$relative_path} (resolved: {$full_path_to_scan})");
            json_error("Đường dẫn không hợp lệ.", 400);
            exit;
        }
        
        if (!is_dir($full_path_to_scan) || !is_readable($full_path_to_scan)) {
            error_log("[Jet API] Directory not found or not readable: {$full_path_to_scan}");
            json_error("Không thể truy cập thư mục: " . htmlspecialchars($sanitized_relative_path), 404);
            exit;
        }

        $folders = [];
        $files = []; // For later, if we list files too

        try {
            $iterator = new DirectoryIterator($full_path_to_scan);
            foreach ($iterator as $fileinfo) {
                if ($fileinfo->isDot()) continue;

                $itemName = $fileinfo->getFilename();
                $item_relative_path = !empty($sanitized_relative_path) ? $sanitized_relative_path . '/' . $itemName : $itemName;

                if ($fileinfo->isDir()) {
                    $folders[] = [
                        'name' => $itemName,
                        'path' => $item_relative_path, // Path relative to the source_key root
                        'type' => 'folder'
                    ];
                }
                // TODO: Later, identify RAW files based on allowed RAW extensions if needed in this view
                // else if ($fileinfo->isFile()) { ... }
            }
            // Sort folders alphabetically
            usort($folders, fn($a, $b) => strnatcasecmp($a['name'], $b['name']));

            json_response([
                'source_key' => $source_key,
                'current_path' => $sanitized_relative_path,
                'folders' => $folders,
                // 'files' => $files // For later
            ]);
        } catch (Exception $e) {
            error_log("[Jet API] Error scanning directory '{$full_path_to_scan}': " . $e->getMessage());
            json_error("Lỗi khi quét thư mục: " . $e->getMessage(), 500);
        }
        exit;

    case 'jet_list_images':
        error_log("[JET_LIST_IMAGES] Entered case.");
        $source_key = $_GET['source_key'] ?? null;
        $relative_path = $_GET['path'] ?? ''; 
        error_log("[JET_LIST_IMAGES] Source Key: {$source_key}, Relative Path: {$relative_path}");

        if (!$source_key || !isset(RAW_IMAGE_SOURCES[$source_key])) {
            error_log("[JET_LIST_IMAGES] Error: Invalid source key or not set. Key: " . ($source_key ?? 'NULL'));
            json_error("Nguồn RAW không hợp lệ hoặc không được cung cấp.", 400);
            exit;
        }
        if (!defined('RAW_FILE_EXTENSIONS')) {
            error_log("[JET_LIST_IMAGES] Error: RAW_FILE_EXTENSIONS not defined.");
            json_error("Lỗi cấu hình: RAW_FILE_EXTENSIONS không được xác định.", 500);
            exit;
        }
        error_log("[JET_LIST_IMAGES] Source key and RAW_FILE_EXTENSIONS validated.");

        $source_config = RAW_IMAGE_SOURCES[$source_key];
        $base_path = rtrim($source_config['path'], '/\\'); 
        error_log("[JET_LIST_IMAGES] Base path from config: {$base_path}");
        
        // Construct the full path to scan
        $clean_relative_path = '';
        if (!empty($relative_path)) {
            $path_parts = explode('/', str_replace('//', '/', trim($relative_path, '/\\')));
            $safe_parts = [];
            foreach ($path_parts as $part) {
                if ($part !== '' && $part !== '.') { // Keep valid parts
                    // Additionally, explicitly disallow '..' to prevent path traversal above the $base_path
                    if ($part === '..') {
                        error_log("[JET_LIST_IMAGES] Path traversal attempt detected and blocked: '..' in relative_path.");
                        json_error("Đường dẫn không hợp lệ (chứa '..').", 400);
                        exit;
                    }
                    $safe_parts[] = $part; 
                }
            }
            $clean_relative_path = implode('/', $safe_parts);
        }
        error_log("[JET_LIST_IMAGES] Cleaned relative path: {$clean_relative_path}");

        $full_scan_path = $base_path . (empty($clean_relative_path) ? '' : '/' . $clean_relative_path);
        error_log("[JET_LIST_IMAGES] Full scan path before realpath: {$full_scan_path}");
        $full_scan_path_realpath = realpath($full_scan_path);
        error_log("[JET_LIST_IMAGES] Full scan path after realpath: " . ($full_scan_path_realpath ?: 'false'));


        if ($full_scan_path_realpath === false || !is_dir($full_scan_path_realpath)) {
            error_log("[JET_LIST_IMAGES] Error: Invalid or non-existent directory. Path: {$full_scan_path}, Realpath: " . ($full_scan_path_realpath ?: 'false'));
            json_error("Đường dẫn thư mục không hợp lệ hoặc không tồn tại: " . htmlspecialchars($full_scan_path), 404);
            exit;
        }

        // Security check: Ensure the resolved path is still within the defined base_path
        $base_path_realpath = realpath($base_path);
        error_log("[JET_LIST_IMAGES] Base path realpath for security check: " . ($base_path_realpath ?: 'false'));
        if (!$base_path_realpath || strpos($full_scan_path_realpath, $base_path_realpath) !== 0) {
            error_log("[JET_LIST_IMAGES] Error: Path security check failed. Full realpath: " . ($full_scan_path_realpath ?: 'false') . ", Base realpath: " . ($base_path_realpath ?: 'false'));
            json_error("Truy cập đường dẫn bị từ chối (ngoài phạm vi nguồn được phép).", 403);
            exit;
        }
        error_log("[JET_LIST_IMAGES] Path security check passed.");

        $images = [];
        $folders = []; // NEW: Also collect folders for nested navigation
        $raw_extensions = array_map('strtolower', RAW_FILE_EXTENSIONS);
        error_log("[JET_LIST_IMAGES] About to iterate directory: {$full_scan_path_realpath}");

        // Get current user for checking pick status
        $current_user_id = $_SESSION['user_id'] ?? null;
        $current_user_role = $_SESSION['user_role'] ?? null;
        $user_picks = [];
        $all_picks = []; // For admin to see all picks
        
        if ($current_user_id && $pdo) {
            try {
                if ($current_user_role === 'admin') {
                    // Admin can see all picks from all users
                    $sql_all_picks = "SELECT image_relative_path, pick_color, user_id, u.username 
                                     FROM jet_image_picks j 
                                     LEFT JOIN users u ON j.user_id = u.id 
                                     WHERE j.source_key = :source_key AND j.pick_color IS NOT NULL";
                    $stmt_all_picks = $pdo->prepare($sql_all_picks);
                    $stmt_all_picks->bindParam(':source_key', $source_key, PDO::PARAM_STR);
                    $stmt_all_picks->execute();
                    while ($row = $stmt_all_picks->fetch(PDO::FETCH_ASSOC)) {
                        $image_path = $row['image_relative_path'];
                        if (!isset($all_picks[$image_path])) {
                            $all_picks[$image_path] = [];
                        }
                        $all_picks[$image_path][] = [
                            'color' => $row['pick_color'],
                            'user_id' => $row['user_id'],
                            'username' => $row['username'] ?? 'Unknown'
                        ];
                    }
                    
                    // Also get current admin's picks for current user context
                    $sql_admin_picks = "SELECT image_relative_path, pick_color FROM jet_image_picks WHERE user_id = :user_id AND source_key = :source_key";
                    $stmt_admin_picks = $pdo->prepare($sql_admin_picks);
                    $stmt_admin_picks->bindParam(':user_id', $current_user_id, PDO::PARAM_INT);
                    $stmt_admin_picks->bindParam(':source_key', $source_key, PDO::PARAM_STR);
                    $stmt_admin_picks->execute();
                    while ($row = $stmt_admin_picks->fetch(PDO::FETCH_ASSOC)) {
                        $user_picks[$row['image_relative_path']] = $row['pick_color']; 
                    }
                } else {
                    // Designer sees their own picks + admin picks (for guidance)
                    $sql_picks = "SELECT image_relative_path, pick_color FROM jet_image_picks WHERE user_id = :user_id AND source_key = :source_key";
                    $stmt_picks = $pdo->prepare($sql_picks);
                    $stmt_picks->bindParam(':user_id', $current_user_id, PDO::PARAM_INT);
                    $stmt_picks->bindParam(':source_key', $source_key, PDO::PARAM_STR);
                    $stmt_picks->execute();
                    while ($row = $stmt_picks->fetch(PDO::FETCH_ASSOC)) {
                        $user_picks[$row['image_relative_path']] = $row['pick_color']; 
                    }
                    
                    // Get admin picks for guidance (admin user_id = 1 typically)
                    $sql_admin_picks = "SELECT j.image_relative_path, j.pick_color, u.username 
                                       FROM jet_image_picks j 
                                       LEFT JOIN users u ON j.user_id = u.id 
                                       WHERE u.role = 'admin' AND j.source_key = :source_key AND j.pick_color IS NOT NULL";
                    $stmt_admin_picks = $pdo->prepare($sql_admin_picks);
                    $stmt_admin_picks->bindParam(':source_key', $source_key, PDO::PARAM_STR);
                    $stmt_admin_picks->execute();
                    while ($row = $stmt_admin_picks->fetch(PDO::FETCH_ASSOC)) {
                        $image_path = $row['image_relative_path'];
                        if (!isset($all_picks[$image_path])) {
                            $all_picks[$image_path] = [];
                        }
                        $all_picks[$image_path][] = [
                            'color' => $row['pick_color'],
                            'user_id' => 1, // Admin typically
                            'username' => $row['username'] ?? 'Admin'
                        ];
                    }
                }
            } catch (PDOException $e) {
                error_log("[JET_LIST_IMAGES] Error fetching user picks: " . $e->getMessage());
                // Non-fatal, proceed without pick info for now
            }
        }

        try {
            $iterator = new DirectoryIterator($full_scan_path_realpath);
            foreach ($iterator as $fileinfo) {
                if ($fileinfo->isDot()) {
                    continue; // Skip . and ..
                }

                $entry = $fileinfo->getFilename();

                if ($fileinfo->isDir()) {
                    // NEW: Collect folders for nested navigation
                    $folders[] = [
                        'name' => $entry,
                        'type' => 'folder',
                        'path' => (empty($clean_relative_path) ? '' : $clean_relative_path . '/') . $entry
                    ];
                } elseif ($fileinfo->isFile()) {
                    $extension = strtolower($fileinfo->getExtension());
                    // error_log("[JET_LIST_IMAGES_ITERATOR] Checking file: {$entry}, Extension found: '{$extension}'"); 
                    if (in_array($extension, $raw_extensions)) {
                        $image_full_relative_path = (empty($clean_relative_path) ? '' : $clean_relative_path . '/') . $entry;
                        $image_data = [
                            'name' => $entry,
                            'path' => $image_full_relative_path,
                            'source_key' => $source_key,
                            // Use pick_color, defaulting to null if not set in $user_picks
                            'pick_color' => $user_picks[$image_full_relative_path] ?? null,
                            'modified_timestamp' => $fileinfo->getMTime()
                        ];
                        
                        // Add all picks info for admin or admin picks for designer guidance
                        if (isset($all_picks[$image_full_relative_path])) {
                            $image_data['all_picks'] = $all_picks[$image_full_relative_path];
                        }
                        
                        $images[] = $image_data;
                    }
                }
            }
        } catch (Exception $e) {
            error_log("[JET_LIST_IMAGES] Exception during DirectoryIterator: " . $e->getMessage() . " for path: " . $full_scan_path_realpath);
            json_error("Không thể đọc thư mục: " . htmlspecialchars($clean_relative_path) . " Error: " . $e->getMessage(), 500);
            exit;
        }
        error_log("[JET_LIST_IMAGES] Directory iteration complete. Found " . count($images) . " images and " . count($folders) . " folders.");
        
        // Sort both arrays
        usort($images, function($a, $b) {
            return strnatcasecmp($a['name'], $b['name']);
        });
        usort($folders, function($a, $b) {
            return strnatcasecmp($a['name'], $b['name']);
        });

        header('Content-Type: application/json');
        echo json_encode([
            'success' => true, 
            'images' => $images, 
            'folders' => $folders, // NEW: Include folders in response
            'debug_path' => $full_scan_path_realpath
        ]);
        exit;

    case 'jet_get_raw_preview':
        error_log("[JET_GET_RAW_PREVIEW] Entered case.");
        $source_key = $_GET['source_key'] ?? null;
        $image_relative_path = $_GET['image_path'] ?? null; // e.g., 'folder/image.nef' or 'image.arw'
        $requested_size = $_GET['size'] ?? 'preview'; // NEW: Optional size parameter, default to 'preview'

        if (!$source_key || !isset(RAW_IMAGE_SOURCES[$source_key])) {
            error_log("[JET_GET_RAW_PREVIEW] Error: Invalid source key '{$source_key}'");
            http_response_code(400);
            echo "Invalid RAW source key.";
            exit;
        }
        if (!$image_relative_path) {
            error_log("[JET_GET_RAW_PREVIEW] Error: Missing image_path.");
            http_response_code(400);
            echo "Missing image path.";
            exit;
        }

        $source_config = RAW_IMAGE_SOURCES[$source_key];
        $base_raw_path = rtrim($source_config['path'], '/\\');
        
        // Sanitize image_relative_path (similar to list_images but for a file)
        $path_parts = explode('/', str_replace('\\', '/', $image_relative_path));
        $safe_parts = [];
        foreach ($path_parts as $part) {
            if ($part !== '' && $part !== '.' && $part !== '..') {
                $safe_parts[] = $part;
            }
        }
        $clean_image_relative_path = implode('/', $safe_parts);
        if (empty($clean_image_relative_path) || $clean_image_relative_path !== $image_relative_path) {
             error_log("[JET_GET_RAW_PREVIEW] Error: Invalid characters or structure in image_path '{$image_relative_path}'");
             http_response_code(400);
             echo "Invalid image path structure.";
             exit;
        }

        $full_raw_file_path = $base_raw_path . DIRECTORY_SEPARATOR . $clean_image_relative_path;
        $full_raw_file_path_realpath = realpath($full_raw_file_path);

        if (!$full_raw_file_path_realpath || !is_file($full_raw_file_path_realpath)) {
            error_log("[JET_GET_RAW_PREVIEW] Error: RAW file not found or not a file at '{$full_raw_file_path}' (Realpath: '{$full_raw_file_path_realpath}')");
            http_response_code(404);
            echo "RAW file not found.";
            exit;
        }

        // Security check: ensure real path is within the source base path
        if (strpos($full_raw_file_path_realpath, realpath($base_raw_path)) !== 0) {
            error_log("[JET_GET_RAW_PREVIEW] Error: Path security violation for '{$full_raw_file_path_realpath}'");
            http_response_code(403);
            echo "Access denied to file path.";
            exit;
        }

        $raw_extension = strtolower(pathinfo($full_raw_file_path_realpath, PATHINFO_EXTENSION));
        if (!in_array($raw_extension, RAW_FILE_EXTENSIONS)) {
            error_log("[JET_GET_RAW_PREVIEW] Error: File extension '{$raw_extension}' is not an allowed RAW extension for '{$full_raw_file_path_realpath}'");
            http_response_code(400);
            echo "Invalid file type (not an allowed RAW extension).";
            exit;
        }

        // Cache path construction
        // JET_PREVIEW_CACHE_ROOT / SIZE / source_key / relative_dir (if any) / filename.jpg

        // Always use main preview size (750px) - no more 120px generation
        $target_size = JET_PREVIEW_SIZE;

        // Use helper function for consistent cache path generation
        $cached_preview_full_path = get_jet_cache_path($source_key, $clean_image_relative_path, $target_size);
        $cache_dir_path = dirname($cached_preview_full_path);

        error_log("[JET_GET_RAW_PREVIEW] RAW file: {$full_raw_file_path_realpath}");
        error_log("[JET_GET_RAW_PREVIEW] Cache path: {$cached_preview_full_path}");
        error_log("[JET_GET_RAW_PREVIEW] Target size: {$target_size}"); // Log the determined size

        if (file_exists($cached_preview_full_path) && filesize($cached_preview_full_path) > 0) {
            error_log("[JET_GET_RAW_PREVIEW] Serving from cache: {$cached_preview_full_path}");
            header('Content-Type: image/jpeg');
            readfile($cached_preview_full_path);
            exit;
        }

        // Note: Filmstrip and preview now use the same 750px cache files

        // Cache miss - add job to queue for background processing (750px only)
        if (function_exists('add_jet_cache_job_to_queue')) {
            $job_added = add_jet_cache_job_to_queue($pdo, $source_key, $clean_image_relative_path, JET_PREVIEW_SIZE);
            if ($job_added) {
                error_log("[JET_GET_RAW_PREVIEW] Job added to queue for background processing: {$source_key}/{$clean_image_relative_path} size " . JET_PREVIEW_SIZE);
            } else {
                error_log("[JET_GET_RAW_PREVIEW] Job already exists in queue for: {$source_key}/{$clean_image_relative_path} size " . JET_PREVIEW_SIZE);
            }
        }

        // Return placeholder image or 202 status to indicate processing
        http_response_code(202); // 202 Accepted - processing in background
        header('Content-Type: application/json');
        echo json_encode([
            'status' => 'processing',
            'message' => 'RAW image is being processed in background. Please retry in a few moments.',
            'retry_after' => 5 // Suggest client to retry after 5 seconds
        ]);
        exit;
        // --- End of two-step conversion ---

        // OLD PIPELINE CODE - COMMENTED OUT FOR NOW
        /*
        // Using -c to output PPM to stdout
        $dcraw_cmd = "\"{$dcraw_executable_path}\" -c \"{$full_raw_file_path_realpath}\""; 

        // Input for magick is from stdin, which dcraw pipes to.
        // ppm:- explicitly tells magick to expect PPM from stdin.
        // If dcraw output is just raw RGB, magick might infer it, but ppm:- is safer.
        $magick_cmd = "\"{$magick_executable_path}\" convert ppm:- -resize {$preview_size}x{$preview_size}\> -quality 85 {$escaped_final_cache_path}";
        
        // Pipe dcraw output to magick. Capture combined stderr from the whole pipe to stdout.
        $full_cmd = "{$dcraw_cmd} | {$magick_cmd} 2>&1";

        error_log("[JET_GET_RAW_PREVIEW] Executing full pipeline. CMD: {$full_cmd}");
        $shell_output = shell_exec($full_cmd);
        
        // Handle potentially null or empty shell_output before trimming
        $trimmed_shell_output = is_string($shell_output) ? trim($shell_output) : '';

        error_log("[JET_GET_RAW_PREVIEW] Full pipeline shell_exec output: " . ($trimmed_shell_output ?: "EMPTY"));

        if (!file_exists($cached_preview_full_path) || filesize($cached_preview_full_path) === 0) {
            error_log("[JET_GET_RAW_PREVIEW] Preview generation failed or produced empty JPEG for: {$cached_preview_full_path}. CMD: {$full_cmd}. Shell output was: " . $trimmed_shell_output);
            http_response_code(500);
            // Try to provide more specific feedback to the client if possible
            $error_detail = $trimmed_shell_output ?: "Unknown processing error.";
            echo "Server error: RAW processing failed. Detail: " . htmlspecialchars($error_detail);
            exit;
        } else {
            error_log("[JET_GET_RAW_PREVIEW] Preview generated and cached: {$cached_preview_full_path}");
            header('Content-Type: image/jpeg');
            readfile($cached_preview_full_path);
            exit;
        }
        */
        break; // End of jet_get_raw_preview

    // Renamed from jet_set_pick_status to jet_set_pick_color
    case 'jet_set_pick_color': 
        error_log("[JET_SET_PICK_COLOR] Entered case.");
        error_log("[JET_SET_PICK_COLOR] Session user_id: " . ($_SESSION['user_id'] ?? 'NOT SET'));
        error_log("[JET_SET_PICK_COLOR] Session user_role: " . ($_SESSION['user_role'] ?? 'NOT SET'));
        error_log("[JET_SET_PICK_COLOR] Session username: " . ($_SESSION['username'] ?? 'NOT SET'));
        global $pdo; 

        if (empty($_SESSION['user_id'])) {
            json_error("Lỗi: Người dùng chưa đăng nhập hoặc phiên làm việc đã hết hạn.", 403);
            exit;
        }
        $user_id = $_SESSION['user_id'];
        error_log("[JET_SET_PICK_COLOR] Using user_id: " . $user_id);

        $source_key = $_POST['source_key'] ?? null;
        $image_path = $_POST['image_relative_path'] ?? null;
        $pick_color_input = $_POST['pick_color'] ?? null; // Expect 'red', 'green', 'blue', 'grey', or a value like 'none' or empty for unpick

        if (!$source_key || !isset(RAW_IMAGE_SOURCES[$source_key])) {
            json_error("Nguồn RAW không hợp lệ hoặc không được cung cấp.", 400);
            exit;
        }
        if (!$image_path) {
            json_error("Đường dẫn hình ảnh không được cung cấp.", 400);
            exit;
        }

        // Validate pick_color_input
        $allowed_colors = ['red', 'green', 'blue', 'grey'];
        $color_to_store = null; // Default to NULL (unpicked)

        if ($pick_color_input !== null && $pick_color_input !== '' && $pick_color_input !== 'none' && $pick_color_input !== 'null') {
            if (in_array(strtolower($pick_color_input), $allowed_colors)) {
                $color_to_store = strtolower($pick_color_input);
            } else {
                json_error("Màu chọn không hợp lệ: " . htmlspecialchars($pick_color_input), 400);
                exit;
            }
        } // If 'none', empty, or 'null', $color_to_store remains null, effectively unpicking.
        
        // Path sanitization (simple check, ensure it doesn't contain '..')
        if (strpos($image_path, '..') !== false) {
            json_error("Đường dẫn hình ảnh không hợp lệ.", 400);
            exit;
        }

        try {
            // Use image_relative_path for consistency with existing data
            $sql = "INSERT INTO jet_image_picks (user_id, source_key, image_relative_path, pick_color, pick_status_updated_at) 
                    VALUES (:user_id, :source_key, :image_relative_path, :pick_color_insert, NOW()) 
                    ON DUPLICATE KEY UPDATE pick_color = :pick_color_update, pick_status_updated_at = NOW()";
            
            $stmt = $pdo->prepare($sql);
            $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
            $stmt->bindParam(':source_key', $source_key, PDO::PARAM_STR);
            $stmt->bindParam(':image_relative_path', $image_path, PDO::PARAM_STR);
            
            // Bind $color_to_store to both placeholders.
            // If it's null, PDO should handle it as NULL for the database for both.
            $param_type = ($color_to_store === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $stmt->bindParam(':pick_color_insert', $color_to_store, $param_type);
            $stmt->bindParam(':pick_color_update', $color_to_store, $param_type);

            $stmt->execute();

            if ($stmt->rowCount() > 0) {
                $message = $color_to_store ? "Ảnh đã được chọn màu '{$color_to_store}'." : "Lựa chọn màu của ảnh đã được xóa.";
                json_response(['success' => true, 'message' => $message, 'pick_color' => $color_to_store]);
            } else {
                // This can happen if the color submitted is the same as already in DB, ON DUPLICATE KEY UPDATE affects 0 rows.
                // Still, consider it a success as the state is achieved.
                 $message = $color_to_store ? "Trạng thái màu '{$color_to_store}' của ảnh được giữ nguyên." : "Ảnh không có lựa chọn màu.";
                json_response(['success' => true, 'message' => $message, 'pick_color' => $color_to_store, 'no_change' => true]);
            }
        } catch (PDOException $e) {
            error_log("[JET_SET_PICK_COLOR] Database error: " . $e->getMessage());
            json_error("Lỗi cơ sở dữ liệu khi cập nhật lựa chọn pick: " . $e->getMessage(), 500);
        }
        break;

    // Get user info
    case 'jet_get_user_info':
        if (!isset($_SESSION['user_id'])) {
            json_error("Không tìm thấy thông tin người dùng.");
            exit;
        }

        try {
            $stmt = $pdo->prepare("SELECT id, username, role, created_at, last_login FROM users WHERE id = ?");
            $stmt->execute([$_SESSION['user_id']]);
            $user = $stmt->fetch();

            if ($user) {
                json_success(['user' => $user]);
            } else {
                json_error("Không tìm thấy thông tin người dùng.");
            }
        } catch (PDOException $e) {
            error_log("Error fetching user info: " . $e->getMessage());
            json_error("Lỗi khi lấy thông tin người dùng.");
        }
        exit;

    // List designers (admin only)
    case 'jet_list_designers':
        if ($_SESSION['user_role'] !== 'admin') {
            json_error("Truy cập bị từ chối. Yêu cầu quyền admin.");
            exit;
        }

        try {
            $stmt = $pdo->prepare("SELECT id, username, created_at, last_login FROM users WHERE role = 'designer'");
            $stmt->execute();
            $designers = $stmt->fetchAll();

            json_success(['designers' => $designers]);
        } catch (PDOException $e) {
            error_log("Error listing designers: " . $e->getMessage());
            json_error("Lỗi khi lấy danh sách designer.");
        }
        exit;

    // Get designer stats (admin only)
    case 'jet_get_designer_stats':
        if ($_SESSION['user_role'] !== 'admin') {
            json_error("Truy cập bị từ chối. Yêu cầu quyền admin.", 403);
            exit;
        }

        $designer_id = $_GET['designer_id'] ?? null;
        if (!$designer_id) {
            json_error("Thiếu ID designer.");
            exit;
        }

        try {
            // Get total picks by color
            $stmt = $pdo->prepare("
                SELECT pick_color, COUNT(*) as count 
                FROM jet_image_picks 
                WHERE user_id = ? 
                GROUP BY pick_color
            ");
            $stmt->execute([$designer_id]);
            $picks_by_color = $stmt->fetchAll();

            // Get total albums worked on
            $stmt = $pdo->prepare("
                SELECT COUNT(DISTINCT source_key) as album_count 
                FROM jet_image_picks 
                WHERE user_id = ?
            ");
            $stmt->execute([$designer_id]);
            $album_count = $stmt->fetch()['album_count'];

            json_success([
                'picks_by_color' => $picks_by_color,
                'album_count' => $album_count
            ]);
        } catch (PDOException $e) {
            error_log("Error getting designer stats: " . $e->getMessage());
            json_error("Lỗi khi lấy thống kê designer.");
        }
        exit;

    // Get detailed work progress stats (admin only)
    case 'jet_get_detailed_stats':
        if ($_SESSION['user_role'] !== 'admin') {
            json_error("Truy cập bị từ chối. Yêu cầu quyền admin.");
            exit;
        }

        try {
            // Get all work activities grouped by designer and actual folder (extracted from image_relative_path)
            $stmt = $pdo->prepare("
                SELECT 
                    u.id as user_id,
                    u.username,
                    COALESCE(folder_stats.source_key, 'N/A') as source_key,
                    COALESCE(folder_stats.folder_name, 'N/A') as folder_name,
                    COALESCE(folder_stats.picked_count, 0) as picked_count,
                    COALESCE(folder_stats.red_count, 0) as red_count,
                    COALESCE(folder_stats.green_count, 0) as green_count,
                    COALESCE(folder_stats.blue_count, 0) as blue_count,
                    COALESCE(folder_stats.grey_count, 0) as grey_count,
                    folder_stats.last_activity
                FROM users u
                LEFT JOIN (
                    SELECT 
                        user_id,
                        source_key,
                        CASE 
                            WHEN LOCATE('/', image_relative_path) > 0 
                            THEN SUBSTRING_INDEX(image_relative_path, '/', 1)
                            ELSE 'Root'
                        END as folder_name,
                        COUNT(DISTINCT image_relative_path) as picked_count,
                        SUM(CASE WHEN pick_color = 'red' THEN 1 ELSE 0 END) as red_count,
                        SUM(CASE WHEN pick_color = 'green' THEN 1 ELSE 0 END) as green_count,
                        SUM(CASE WHEN pick_color = 'blue' THEN 1 ELSE 0 END) as blue_count,
                        SUM(CASE WHEN pick_color = 'grey' THEN 1 ELSE 0 END) as grey_count,
                        MAX(pick_status_updated_at) as last_activity
                    FROM jet_image_picks 
                    WHERE pick_color IS NOT NULL
                    GROUP BY user_id, source_key, folder_name
                ) folder_stats ON u.id = folder_stats.user_id
                WHERE u.role IN ('designer', 'admin') AND folder_stats.source_key IS NOT NULL
                ORDER BY u.username, folder_stats.source_key, folder_stats.folder_name, folder_stats.last_activity DESC
            ");
            $stmt->execute();
            $work_progress = $stmt->fetchAll();

            // Get total image counts per source_key from available image sources
            // Note: This would ideally count actual images in each source, but for now we'll approximate
            $source_totals = [];
            foreach ($work_progress as $work) {
                $key = $work['source_key'] . '/' . $work['folder_name'];
                if (!isset($source_totals[$key])) {
                    // For now, we'll set this as unknown - would need to scan actual directories
                    // In a full implementation, you'd count files in each source directory
                    $source_totals[$key] = null; // Will be marked as "unknown"
                }
            }

            json_success([
                'work_progress' => $work_progress,
                'source_totals' => $source_totals
            ]);
        } catch (PDOException $e) {
            error_log("Error getting detailed stats: " . $e->getMessage());
            json_error("Lỗi khi lấy thống kê chi tiết.");
        }
        exit;

    // Create new designer user (admin only)
    case 'jet_create_designer':
        if ($_SESSION['user_role'] !== 'admin') {
            json_error("Truy cập bị từ chối. Yêu cầu quyền admin.");
            exit;
        }

        $username = $_POST['username'] ?? '';
        $password = $_POST['password'] ?? '';

        if (empty($username) || empty($password)) {
            json_error("Tên đăng nhập và mật khẩu không được để trống.");
            exit;
        }

        // Validate username format
        if (!preg_match('/^[a-zA-Z0-9_]{3,20}$/', $username)) {
            json_error("Tên đăng nhập phải từ 3-20 ký tự, chỉ bao gồm chữ cái, số và dấu gạch dưới.");
            exit;
        }

        // Validate password strength
        if (strlen($password) < 6) {
            json_error("Mật khẩu phải có ít nhất 6 ký tự.");
            exit;
        }

        try {
            // Check if username already exists
            $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
            $stmt->execute([$username]);
            if ($stmt->fetch()) {
                json_error("Tên đăng nhập đã tồn tại.");
                exit;
            }

            // Create new designer user
            $password_hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO users (username, password_hash, role) VALUES (?, ?, 'designer')");
            $stmt->execute([$username, $password_hash]);

            json_success([
                'message' => 'Đã tạo tài khoản designer thành công.',
                'user_id' => $pdo->lastInsertId()
            ]);
        } catch (PDOException $e) {
            error_log("Error creating designer: " . $e->getMessage());
            json_error("Lỗi khi tạo tài khoản designer.");
        }
        exit;

    // Change user password (admin only)
    case 'jet_change_user_password':
        if ($_SESSION['user_role'] !== 'admin') {
            json_error("Truy cập bị từ chối. Yêu cầu quyền admin.");
            exit;
        }

        $user_id = $_POST['user_id'] ?? null;
        $new_password = $_POST['new_password'] ?? '';

        if (!$user_id || empty($new_password)) {
            json_error("Thiếu ID người dùng hoặc mật khẩu mới.");
            exit;
        }

        // Validate password strength
        if (strlen($new_password) < 6) {
            json_error("Mật khẩu phải có ít nhất 6 ký tự.");
            exit;
        }

        try {
            // Check if user exists
            $stmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            $user = $stmt->fetch();

            if (!$user) {
                json_error("Không tìm thấy người dùng.");
                exit;
            }

            // Update password
            $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
            $stmt->execute([$password_hash, $user_id]);

            json_success([
                'message' => 'Đã cập nhật mật khẩu cho người dùng: ' . $user['username']
            ]);
        } catch (PDOException $e) {
            error_log("Error changing user password: " . $e->getMessage());
            json_error("Lỗi khi thay đổi mật khẩu.");
        }
        exit;

    // Queue cache job for RAW image folder (admin only)
    case 'jet_queue_folder_cache':
        error_log("[jet_queue_folder_cache] API action called");
        error_log("[jet_queue_folder_cache] Session data: " . print_r($_SESSION, true));
        if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
            error_log("[jet_queue_folder_cache] Access denied: user role is " . ($_SESSION['user_role'] ?? 'null'));
            json_error("Truy cập bị từ chối. Yêu cầu quyền admin.");
            exit;
        }

        $source_key = $_POST['source_key'] ?? null;
        $folder_path = $_POST['folder_path'] ?? '';
        error_log("[jet_queue_folder_cache] Parameters - source_key: " . ($source_key ?? 'null') . ", folder_path: " . $folder_path);

        if (!$source_key || !isset(RAW_IMAGE_SOURCES[$source_key])) {
            error_log("[jet_queue_folder_cache] Invalid source key: " . ($source_key ?? 'null'));
            json_error("Nguồn RAW không hợp lệ hoặc không được cung cấp.", 400);
            exit;
        }

        try {
            // Get all RAW images in the folder
            $source_config = RAW_IMAGE_SOURCES[$source_key];
            $base_path = rtrim($source_config['path'], '/\\');
            error_log("[jet_queue_folder_cache] Base path: " . $base_path);
            
            // Construct full folder path
            $clean_folder_path = '';
            if (!empty($folder_path)) {
                $path_parts = explode('/', str_replace('//', '/', trim($folder_path, '/\\')));
                $safe_parts = [];
                foreach ($path_parts as $part) {
                    if ($part !== '' && $part !== '.' && $part !== '..') {
                        $safe_parts[] = $part;
                    }
                }
                $clean_folder_path = implode('/', $safe_parts);
            }

            $full_scan_path = $base_path . (empty($clean_folder_path) ? '' : '/' . $clean_folder_path);
            $full_scan_path_realpath = realpath($full_scan_path);
            error_log("[jet_queue_folder_cache] Full scan path: " . $full_scan_path . " -> realpath: " . ($full_scan_path_realpath ?: 'false'));

            if (!$full_scan_path_realpath || !is_dir($full_scan_path_realpath)) {
                error_log("[jet_queue_folder_cache] Path validation failed - not a directory or doesn't exist");
                json_error("Đường dẫn thư mục không hợp lệ hoặc không tồn tại.", 404);
                exit;
            }

            // Security check
            $base_path_realpath = realpath($base_path);
            if (!$base_path_realpath || strpos($full_scan_path_realpath, $base_path_realpath) !== 0) {
                json_error("Truy cập đường dẫn bị từ chối.", 403);
                exit;
            }

            $raw_extensions = array_map('strtolower', RAW_FILE_EXTENSIONS);
            $queued_count = 0;
            $existing_count = 0;

            // Scan folder for RAW images
            try {
                $iterator = new DirectoryIterator($full_scan_path_realpath);
                foreach ($iterator as $fileinfo) {
                    if ($fileinfo->isFile()) {
                        $extension = strtolower($fileinfo->getExtension());
                        if (in_array($extension, $raw_extensions)) {
                            $image_relative_path = (empty($clean_folder_path) ? '' : $clean_folder_path . '/') . $fileinfo->getFilename();
                            
                            // Only queue 750px job (no more 120px generation)
                            $preview_queued = add_jet_cache_job_to_queue($pdo, $source_key, $image_relative_path, JET_PREVIEW_SIZE);
                            
                            if ($preview_queued) {
                                $queued_count++;
                            }
                        }
                    }
                }
            } catch (Exception $e) {
                json_error("Lỗi khi quét thư mục: " . $e->getMessage(), 500);
                exit;
            }

            error_log("[jet_queue_folder_cache] Success - queued {$queued_count} jobs for {$source_key}/{$folder_path}");
            
            // Prepare response message based on result
            if ($queued_count > 0) {
                $message = "Đã thêm {$queued_count} công việc cache vào hàng đợi.";
                $status = 'queued';
            } else {
                $message = "Tất cả file RAW trong thư mục này đã có cache. Không có công việc mới nào được tạo.";
                $status = 'no_new_jobs';
            }
            
            json_response([
                'success' => true,
                'message' => $message,
                'queued_count' => $queued_count,
                'status' => $status
            ]);

        } catch (Exception $e) {
            error_log("[jet_queue_folder_cache] Error: " . $e->getMessage());
            json_error("Lỗi khi thêm công việc vào hàng đợi: " . $e->getMessage(), 500);
        }
        exit;

    // Get cache job statistics (admin only)
    case 'jet_get_cache_stats':
        if ($_SESSION['user_role'] !== 'admin') {
            json_error("Truy cập bị từ chối. Yêu cầu quyền admin.");
            exit;
        }

        try {
            // Get cache job counts by status
            $stats_sql = "SELECT status, COUNT(*) as count FROM jet_cache_jobs GROUP BY status";
            $stats_stmt = $pdo->prepare($stats_sql);
            $stats_stmt->execute();
            $stats = $stats_stmt->fetchAll(PDO::FETCH_ASSOC);

            // Get recent failed jobs
            $failed_sql = "SELECT source_key, image_relative_path, cache_size, result_message, created_at 
                          FROM jet_cache_jobs 
                          WHERE status = 'failed' 
                          ORDER BY created_at DESC 
                          LIMIT 10";
            $failed_stmt = $pdo->prepare($failed_sql);
            $failed_stmt->execute();
            $recent_failed = $failed_stmt->fetchAll(PDO::FETCH_ASSOC);

            // Get processing jobs
            $processing_sql = "SELECT source_key, image_relative_path, cache_size, worker_id, processed_at 
                              FROM jet_cache_jobs 
                              WHERE status = 'processing' 
                              ORDER BY processed_at ASC";
            $processing_stmt = $pdo->prepare($processing_sql);
            $processing_stmt->execute();
            $processing_jobs = $processing_stmt->fetchAll(PDO::FETCH_ASSOC);

            json_response([
                'success' => true,
                'stats' => $stats,
                'recent_failed' => $recent_failed,
                'processing_jobs' => $processing_jobs
            ]);

        } catch (PDOException $e) {
            error_log("[jet_get_cache_stats] Error: " . $e->getMessage());
            json_error("Lỗi khi lấy thống kê cache.", 500);
        }
        exit;

    // Clear failed cache jobs (admin only)
    case 'jet_clear_failed_cache_jobs':
        if ($_SESSION['user_role'] !== 'admin') {
            json_error("Truy cập bị từ chối. Yêu cầu quyền admin.");
            exit;
        }

        try {
            $clear_sql = "DELETE FROM jet_cache_jobs WHERE status = 'failed'";
            $clear_stmt = $pdo->prepare($clear_sql);
            $clear_stmt->execute();
            $cleared_count = $clear_stmt->rowCount();

            json_response([
                'success' => true,
                'message' => "Đã xóa {$cleared_count} công việc cache lỗi.",
                'cleared_count' => $cleared_count
            ]);

        } catch (PDOException $e) {
            error_log("[jet_clear_failed_cache_jobs] Error: " . $e->getMessage());
            json_error("Lỗi khi xóa công việc cache lỗi.", 500);
        }
        exit;

    // NEW: Cleanup orphaned cache records (admin only)
    case 'jet_cleanup_orphaned_cache_records':
        if ($_SESSION['user_role'] !== 'admin') {
            json_error("Truy cập bị từ chối. Yêu cầu quyền admin.");
            exit;
        }

        try {
            // Get all completed records with file paths
            $sql_get_completed = "SELECT id, source_key, image_relative_path, cache_size, final_cache_path 
                                 FROM jet_cache_jobs 
                                 WHERE status = 'completed' AND final_cache_path IS NOT NULL";
            $stmt_get = $pdo->prepare($sql_get_completed);
            $stmt_get->execute();
            $completed_records = $stmt_get->fetchAll(PDO::FETCH_ASSOC);

            $orphaned_count = 0;
            $checked_count = 0;
            $orphaned_ids = [];

            foreach ($completed_records as $record) {
                $checked_count++;
                
                // Check if file exists
                if (!file_exists($record['final_cache_path']) || filesize($record['final_cache_path']) === 0) {
                    $orphaned_ids[] = $record['id'];
                    $orphaned_count++;
                    error_log("[jet_cleanup_orphaned] Found orphaned record ID {$record['id']}: {$record['final_cache_path']}");
                }
            }

            // Delete orphaned records if any found
            if ($orphaned_count > 0) {
                $placeholders = str_repeat('?,', count($orphaned_ids) - 1) . '?';
                $delete_sql = "DELETE FROM jet_cache_jobs WHERE id IN ({$placeholders})";
                $delete_stmt = $pdo->prepare($delete_sql);
                $delete_stmt->execute($orphaned_ids);
                $deleted_count = $delete_stmt->rowCount();
            } else {
                $deleted_count = 0;
            }

            json_response([
                'success' => true,
                'message' => "Đã kiểm tra {$checked_count} records. Xóa {$deleted_count} records bị mồ côi.",
                'checked_count' => $checked_count,
                'orphaned_count' => $orphaned_count,
                'deleted_count' => $deleted_count
            ]);

        } catch (Exception $e) {
            error_log("[jet_cleanup_orphaned_cache_records] Error: " . $e->getMessage());
            json_error("Lỗi khi cleanup records bị mồ côi: " . $e->getMessage(), 500);
        }
        exit;

    // Get RAW sources with cache statistics (admin only)
    case 'jet_list_raw_sources_with_cache_stats':
        if ($_SESSION['user_role'] !== 'admin') {
            json_error("Truy cập bị từ chối. Yêu cầu quyền admin.");
            exit;
        }

        try {
            $sources_with_stats = [];

            foreach (RAW_IMAGE_SOURCES as $source_key => $source_config) {
                $source_path = $source_config['path'];
                $source_name = $source_config['name'] ?? $source_key;

                // Count total RAW files in source
                $total_raw_files = 0;
                if (is_dir($source_path) && is_readable($source_path)) {
                    try {
                        $iterator = new RecursiveIteratorIterator(
                            new RecursiveDirectoryIterator($source_path, RecursiveDirectoryIterator::SKIP_DOTS),
                            RecursiveIteratorIterator::LEAVES_ONLY
                        );

                        foreach ($iterator as $fileinfo) {
                            if ($fileinfo->isFile()) {
                                $extension = strtolower($fileinfo->getExtension());
                                if (in_array($extension, RAW_FILE_EXTENSIONS)) {
                                    $total_raw_files++;
                                }
                            }
                        }
                    } catch (Exception $e) {
                        error_log("[jet_list_raw_sources] Error scanning source '{$source_key}': " . $e->getMessage());
                    }
                }

                // Get cache statistics
                $cache_stats_sql = "SELECT 
                    COUNT(*) as total_cache_jobs,
                    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_jobs,
                    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_jobs,
                    SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) as processing_jobs,
                    SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_jobs
                    FROM jet_cache_jobs 
                    WHERE source_key = ?";
                $cache_stats_stmt = $pdo->prepare($cache_stats_sql);
                $cache_stats_stmt->execute([$source_key]);
                $cache_stats = $cache_stats_stmt->fetch(PDO::FETCH_ASSOC);

                $sources_with_stats[] = [
                    'source_key' => $source_key,
                    'name' => $source_name,
                    'path' => $source_path,
                    'total_raw_files' => $total_raw_files,
                    'accessible' => is_dir($source_path) && is_readable($source_path),
                    'cache_stats' => [
                        'total_jobs' => (int)$cache_stats['total_cache_jobs'],
                        'completed' => (int)$cache_stats['completed_jobs'],
                        'pending' => (int)$cache_stats['pending_jobs'],
                        'processing' => (int)$cache_stats['processing_jobs'],
                        'failed' => (int)$cache_stats['failed_jobs']
                    ]
                ];
            }

            json_response([
                'success' => true,
                'sources' => $sources_with_stats
            ]);

        } catch (Exception $e) {
            error_log("[jet_list_raw_sources_with_cache_stats] Error: " . $e->getMessage());
            json_error("Lỗi khi lấy danh sách nguồn RAW: " . $e->getMessage(), 500);
        }
        exit;

    // Queue cache for entire RAW source (admin only)
    case 'jet_queue_source_cache':
        if ($_SESSION['user_role'] !== 'admin') {
            json_error("Truy cập bị từ chối. Yêu cầu quyền admin.");
            exit;
        }

        $source_key = $_POST['source_key'] ?? null;
        if (!$source_key || !isset(RAW_IMAGE_SOURCES[$source_key])) {
            json_error("Nguồn RAW không hợp lệ hoặc không được cung cấp.", 400);
            exit;
        }

        try {
            $source_config = RAW_IMAGE_SOURCES[$source_key];
            $source_path = $source_config['path'];

            if (!is_dir($source_path) || !is_readable($source_path)) {
                json_error("Nguồn RAW không thể truy cập: " . $source_path, 404);
                exit;
            }

            $queued_count = 0;
            $raw_extensions = array_map('strtolower', RAW_FILE_EXTENSIONS);

            // Scan entire source for RAW files
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($source_path, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ($iterator as $fileinfo) {
                if ($fileinfo->isFile()) {
                    $extension = strtolower($fileinfo->getExtension());
                    if (in_array($extension, $raw_extensions)) {
                        // Calculate relative path
                        $full_path = $fileinfo->getRealPath();
                        $relative_path = substr($full_path, strlen(realpath($source_path)) + 1);
                        $relative_path = str_replace('\\', '/', $relative_path);

                        // SIMPLIFIED: Only queue 750px job, no 120px
                        $preview_queued = add_jet_cache_job_to_queue($pdo, $source_key, $relative_path, JET_PREVIEW_SIZE);

                        if ($preview_queued) {
                            $queued_count++;
                        }
                    }
                }
            }

            json_response([
                'success' => true,
                'message' => "Đã thêm {$queued_count} công việc cache vào hàng đợi cho nguồn {$source_key}.",
                'queued_count' => $queued_count
            ]);

        } catch (Exception $e) {
            error_log("[jet_queue_source_cache] Error: " . $e->getMessage());
            json_error("Lỗi khi thêm công việc vào hàng đợi: " . $e->getMessage(), 500);
        }
        exit;

    // NEW: List RAW folders within sources with cache statistics (admin only)
    case 'jet_list_raw_folders_with_cache_stats':
        if ($_SESSION['user_role'] !== 'admin') {
            json_error("Truy cập bị từ chối. Yêu cầu quyền admin.");
            exit;
        }

        try {
            $folders_with_stats = [];
            $raw_extensions = array_map('strtolower', RAW_FILE_EXTENSIONS);
            $auto_cleaned_count = 0; // Track auto-cleanup

            foreach (RAW_IMAGE_SOURCES as $source_key => $source_config) {
                $base_path = $source_config['path'];
                $source_name = $source_config['name'] ?? $source_key;

                if (!is_dir($base_path) || !is_readable($base_path)) {
                    // Skip inaccessible sources, but maybe add a note later
                    continue;
                }

                try {
                    $iterator = new DirectoryIterator($base_path);
                    foreach ($iterator as $fileinfo) {
                        if ($fileinfo->isDot() || !$fileinfo->isDir()) {
                            continue; // Only list directories at the top level of the source
                        }

                        $folder_name = $fileinfo->getFilename();
                        $relative_folder_path = $folder_name; // Path is just the folder name relative to source root
                        $full_folder_path = $fileinfo->getPathname();

                        // Count total RAW files within this specific folder (and sub-subfolders)
                        $total_raw_files_in_folder = 0;
                        try {
                             $sub_iterator = new RecursiveIteratorIterator(
                                new RecursiveDirectoryIterator($full_folder_path, RecursiveDirectoryIterator::SKIP_DOTS),
                                RecursiveIteratorIterator::LEAVES_ONLY
                            );
                            foreach ($sub_iterator as $sub_fileinfo) {
                                if ($sub_fileinfo->isFile()) {
                                    $extension = strtolower($sub_fileinfo->getExtension());
                                    if (in_array($extension, $raw_extensions)) {
                                        $total_raw_files_in_folder++;
                                    }
                                }
                            }
                        } catch (Exception $e) {
                             error_log("[jet_list_raw_folders] Error scanning folder '{$full_folder_path}': " . $e->getMessage());
                        }

                        // Get cache statistics for this specific folder path
                        // We need to count jobs where image_relative_path STARTS WITH this folder path
                        $cache_stats_sql = "SELECT
                            COUNT(*) as total_cache_jobs,
                            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_jobs,
                            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_jobs,
                            SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) as processing_jobs,
                            SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_jobs
                            FROM jet_cache_jobs
                            WHERE source_key = ? AND (image_relative_path LIKE ? OR image_relative_path = ?)";
                        $cache_stats_stmt = $pdo->prepare($cache_stats_sql);
                        
                        // Pattern for subfolder files: 'foldername/%'
                        // Also check for exact folder match (edge case)
                        $like_pattern = $relative_folder_path . '/%';
                        $cache_stats_stmt->execute([$source_key, $like_pattern, $relative_folder_path]);
                        $cache_stats = $cache_stats_stmt->fetch(PDO::FETCH_ASSOC);

                        // AUTO-CLEANUP: Check for orphaned completed records in this folder
                        if ($cache_stats['completed_jobs'] > 0) {
                            $orphaned_cleanup_sql = "SELECT id, final_cache_path FROM jet_cache_jobs 
                                                   WHERE source_key = ? AND (image_relative_path LIKE ? OR image_relative_path = ?) 
                                                   AND status = 'completed' AND final_cache_path IS NOT NULL";
                            $orphaned_stmt = $pdo->prepare($orphaned_cleanup_sql);
                            $orphaned_stmt->execute([$source_key, $like_pattern, $relative_folder_path]);
                            $completed_records = $orphaned_stmt->fetchAll(PDO::FETCH_ASSOC);
                            
                            $orphaned_ids = [];
                            foreach ($completed_records as $record) {
                                if (!file_exists($record['final_cache_path']) || filesize($record['final_cache_path']) === 0) {
                                    $orphaned_ids[] = $record['id'];
                                }
                            }
                            
                            // Auto-delete orphaned records for this folder
                            if (!empty($orphaned_ids)) {
                                $placeholders = str_repeat('?,', count($orphaned_ids) - 1) . '?';
                                $delete_sql = "DELETE FROM jet_cache_jobs WHERE id IN ({$placeholders})";
                                $delete_stmt = $pdo->prepare($delete_sql);
                                $delete_stmt->execute($orphaned_ids);
                                $auto_cleaned_count += $delete_stmt->rowCount();
                                
                                error_log("[jet_list_raw_folders] Auto-cleaned {$delete_stmt->rowCount()} orphaned records for {$source_key}/{$relative_folder_path}");
                                
                                // Re-fetch cache stats after cleanup
                                $cache_stats_stmt->execute([$source_key, $like_pattern, $relative_folder_path]);
                                $cache_stats = $cache_stats_stmt->fetch(PDO::FETCH_ASSOC);
                            }
                        }

                        // Ensure we have valid numbers (handle NULL values)
                        $cache_stats = [
                            'total_cache_jobs' => (int)($cache_stats['total_cache_jobs'] ?? 0),
                            'completed' => (int)($cache_stats['completed_jobs'] ?? 0),
                            'pending' => (int)($cache_stats['pending_jobs'] ?? 0),
                            'processing' => (int)($cache_stats['processing_jobs'] ?? 0),
                            'failed' => (int)($cache_stats['failed_jobs'] ?? 0)
                        ];

                        // SMART VALIDATION: Detect potential inconsistencies
                        $validation_issues = [];
                        
                        // Check if completed jobs exceed total files (impossible scenario)
                        if ($cache_stats['completed'] > $total_raw_files_in_folder) {
                            $validation_issues[] = "completed_exceeds_files";
                        }
                        
                        // Check if total cache jobs significantly exceed expected (1 job per file)
                        $expected_max_jobs = $total_raw_files_in_folder; // SIMPLIFIED: 1 job per file
                        if ($cache_stats['total_cache_jobs'] > $expected_max_jobs * 1.5) {
                            $validation_issues[] = "too_many_jobs";
                        }
                        
                        // Add validation info to folder data
                        $folder_data = [
                            'source_key' => $source_key,
                            'source_name' => $source_name,
                            'folder_name' => $folder_name, // The name of the directory
                            'relative_path' => $relative_folder_path, // Path relative to source root (just folder name)
                            'full_path' => $full_folder_path, // Full system path
                            'total_raw_files' => $total_raw_files_in_folder,
                             // Note: We calculate total jobs based on LIKE % to include sub-subfolders
                            'cache_stats' => $cache_stats
                        ];
                        
                        // Add validation warnings if any issues detected
                        if (!empty($validation_issues)) {
                            $folder_data['validation_issues'] = $validation_issues;
                            $folder_data['needs_sync'] = true;
                        }

                        $folders_with_stats[] = $folder_data;
                    }
                } catch (Exception $e) {
                     error_log("[jet_list_raw_folders] Error scanning base path '{$base_path}' for key '{$source_key}': " . $e->getMessage());
                     // Continue to next source even if one fails
                }
            }

             // Sort results by source name then folder name
            usort($folders_with_stats, function($a, $b) {
                $cmp_source = strnatcasecmp($a['source_name'], $b['source_name']);
                if ($cmp_source !== 0) {
                    return $cmp_source;
                }
                return strnatcasecmp($a['folder_name'], $b['folder_name']);
            });

            $response = [
                'success' => true,
                'folders' => $folders_with_stats
            ];
            
            // Add auto-cleanup info if any records were cleaned
            if ($auto_cleaned_count > 0) {
                $response['auto_cleaned'] = $auto_cleaned_count;
                $response['auto_cleanup_message'] = "Tự động dọn dẹp {$auto_cleaned_count} records bị mồ côi.";
                error_log("[jet_list_raw_folders] Auto-cleanup completed: {$auto_cleaned_count} records removed");
            }

            json_response($response);

        } catch (Exception $e) {
            error_log("[jet_list_raw_folders_with_cache_stats] Error: " . $e->getMessage());
            json_error("Lỗi khi lấy danh sách thư mục RAW: " . $e->getMessage(), 500);
        }
        exit;

    // Delete user (admin only)
    case 'jet_delete_user':
        if ($_SESSION['user_role'] !== 'admin') {
            json_error("Truy cập bị từ chối. Yêu cầu quyền admin.");
            exit;
        }

        $user_id = $_POST['user_id'] ?? null;

        if (!$user_id) {
            json_error("Thiếu ID người dùng.");
            exit;
        }

        // Prevent admin from deleting themselves
        if ($user_id == $_SESSION['user_id']) {
            json_error("Không thể xóa tài khoản của chính mình.");
            exit;
        }

        try {
            // Check if user exists and get info
            $stmt = $pdo->prepare("SELECT username, role FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            $user = $stmt->fetch();

            if (!$user) {
                json_error("Không tìm thấy người dùng.");
                exit;
            }

            // Prevent deleting the last admin
            if ($user['role'] === 'admin') {
                $admin_count_stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role = 'admin'");
                $admin_count_stmt->execute();
                $admin_count = $admin_count_stmt->fetchColumn();
                
                if ($admin_count <= 1) {
                    json_error("Không thể xóa admin cuối cùng trong hệ thống.");
                    exit;
                }
            }

            // Delete user (CASCADE will handle related records in jet_image_picks)
            $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
            $stmt->execute([$user_id]);

            if ($stmt->rowCount() > 0) {
                json_success([
                    'message' => 'Đã xóa người dùng: ' . $user['username']
                ]);
            } else {
                json_error("Không thể xóa người dùng.");
            }
        } catch (PDOException $e) {
            error_log("Error deleting user: " . $e->getMessage());
            json_error("Lỗi khi xóa người dùng: " . $e->getMessage());
        }
        exit;

    default:
        // error_log("[JET_ACTION] Unknown jet action in actions_jet.php: " . $jet_action);
        json_error("Hành động Jet không xác định trong actions_jet.php: " . htmlspecialchars($jet_action ?? 'Không có hành động nào được cung cấp'), 400);
        exit;
}
?> 