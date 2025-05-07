<?php
// api/actions_public.php

// Dependencies: 
// - Assumes $action, $pdo, $allowed_ext, $search_term are available (from init.php)
// - Assumes all helper functions are available (from helpers.php)
// - Assumes THUMBNAIL_SIZES_API, CACHE_THUMB_ROOT, IMAGE_SOURCES constants are defined

// Prevent direct access
if (!isset($action)) {
    die('Invalid access.');
}

// Helper function to generate a unique token
if (!function_exists('generate_job_token')) {
    function generate_job_token($length = 32) {
        if (function_exists('random_bytes')) {
            return bin2hex(random_bytes($length / 2));
        } elseif (function_exists('openssl_random_pseudo_bytes')) {
            return bin2hex(openssl_random_pseudo_bytes($length / 2));
        } else {
            // Fallback for older PHP versions (less secure)
            $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
            $charactersLength = strlen($characters);
            $randomString = '';
            for ($i = 0; $i < $length; $i++) {
                $randomString .= $characters[rand(0, $charactersLength - 1)];
            }
            return $randomString . uniqid(); // Add uniqid for more uniqueness
        }
    }
}

switch ($action) {

    case 'list_files':
        $subdir_requested = $_GET['dir'] ?? '';
        $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
        // Use a reasonable default/configurable limit, but allow API override?
        // Let's use 100 as default to match original logic, but maybe make this configurable later.
        $items_per_page = isset($_GET['limit']) ? max(1, min(500, (int)$_GET['limit'])) : 100; // Default 100, max 500

        $path_info = validate_source_and_path($subdir_requested);

        if ($path_info === null) {
            json_error('Thư mục không hợp lệ hoặc không tồn tại.', 404);
        }

        // --- Handle Root Request (List Sources/Top-Level Dirs) ---
        if ($path_info['is_root']) {
            $all_subdirs = [];
            // Fetch all protected folder names once
            $all_protected_folders = [];
            try {
                $stmt = $pdo->query("SELECT folder_name FROM folder_passwords");
                while ($protected_folder = $stmt->fetchColumn()) {
                    $all_protected_folders[$protected_folder] = true;
                }
            } catch (PDOException $e) { /* Log error, continue */ error_log("[list_files Root] Error fetching protected: " . $e->getMessage()); }

            foreach (IMAGE_SOURCES as $source_key => $source_config) {
                if (!is_array($source_config) || !isset($source_config['path'])) continue;
                $source_base_path = $source_config['path'];
                $resolved_source_base_path = realpath($source_base_path);

                if ($resolved_source_base_path === false || !is_dir($resolved_source_base_path) || !is_readable($resolved_source_base_path)) {
                    error_log("[list_files Root] Skipping source '{$source_key}': Path invalid or not readable.");
                    continue;
                }

                try {
                    $iterator = new DirectoryIterator($resolved_source_base_path);
                    foreach ($iterator as $fileinfo) {
                        if ($fileinfo->isDot() || !$fileinfo->isDir()) continue;

                        $subdir_name = $fileinfo->getFilename();
                        $subdir_source_prefixed_path = $source_key . '/' . $subdir_name;

                        // Client-side filtering is usually done in JS (loadTopLevelDirectories)
                        // If server-side search for root is needed, implement here using $search_term

                        $all_subdirs[] = [
                            'name' => $subdir_name,
                            'type' => 'folder',
                            'path' => $subdir_source_prefixed_path,
                            'is_dir' => true,
                            'source_key' => $source_key,
                            'absolute_path' => $fileinfo->getPathname() // Keep absolute path for potential thumbnail search
                        ];
                    }
                } catch (Exception $e) { /* Log error */ error_log("[list_files Root] Error scanning source '{$source_key}': " . $e->getMessage()); }
            }

            usort($all_subdirs, fn($a, $b) => strnatcasecmp($a['name'], $b['name']));

            $total_items = count($all_subdirs);
            $total_pages = ceil($total_items / $items_per_page);
            $offset = ($page - 1) * $items_per_page;
            $paginated_items = array_slice($all_subdirs, $offset, $items_per_page);

            $folders_data = [];
            foreach ($paginated_items as $item) {
                $folder_path_prefixed = $item['path'];
                $subfolder_access = check_folder_access($folder_path_prefixed); // Check access

                // Find thumbnail
                $thumbnail_source_prefixed_path = null;
                // Pass $allowed_ext to the helper function
                $first_image_relative_to_subdir = find_first_image_in_source($item['source_key'], $item['name'], $allowed_ext);
                if ($first_image_relative_to_subdir !== null) {
                    $thumbnail_source_prefixed_path = $folder_path_prefixed . '/' . $first_image_relative_to_subdir;
                    $thumbnail_source_prefixed_path = str_replace('//', '/', $thumbnail_source_prefixed_path); // Normalize
                }

                $folders_data[] = [
                    'name' => $item['name'],
                    'type' => 'folder',
                    'path' => $folder_path_prefixed,
                    'protected' => $subfolder_access['protected'],
                    'authorized' => $subfolder_access['authorized'],
                    'thumbnail' => $thumbnail_source_prefixed_path
                ];
            }

            json_response([
                'files' => [],
                'folders' => $folders_data,
                'breadcrumb' => [],
                'current_dir' => '',
                'pagination' => [
                    'current_page' => $page,
                    'total_pages' => $total_pages,
                    'total_items' => $total_items
                ],
                'is_root' => true
            ]);
            break; // End root handling
        }

        // --- Handle Specific Directory Request ---
        $source_key = $path_info['source_key'];
        $current_relative_path = $path_info['relative_path'];
        $current_absolute_path = $path_info['absolute_path'];
        $current_source_prefixed_path = $path_info['source_prefixed_path'];

        $access = check_folder_access($current_source_prefixed_path);
        if (!$access['authorized']) {
            if (!empty($access['password_required'])) {
                json_error('Yêu cầu mật khẩu.', 401);
            } else {
                json_error($access['error'] ?? 'Không có quyền truy cập.', 403);
            }
        }

        // Increment View Count (only on first page load of top-level albums)
        if ($page === 1 && substr_count($current_source_prefixed_path, '/') === 1) {
            try {
                $sql = "INSERT INTO folder_stats (folder_name, views) VALUES (?, 1)
                        ON CONFLICT(folder_name) DO UPDATE SET views = views + 1";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$current_source_prefixed_path]);
            } catch (PDOException $e) { /* Log warning */ error_log("[list_files ViewCount] DB Error for '{$current_source_prefixed_path}': " . $e->getMessage());}
        }

        // Build Breadcrumb
        $breadcrumb = [];
        if ($current_source_prefixed_path) {
            $parts = explode('/', $current_source_prefixed_path);
            $current_crumb_path = '';
            foreach ($parts as $part) {
                $current_crumb_path = $current_crumb_path ? $current_crumb_path . '/' . $part : $part;
                $breadcrumb[] = ['name' => $part, 'path' => $current_crumb_path];
            }
        }

        // Scan Directory
        $items = [];
        try {
            $iterator = new DirectoryIterator($current_absolute_path);
            foreach ($iterator as $fileinfo) {
                if ($fileinfo->isDot()) continue;
                $filename = $fileinfo->getFilename();
                $item_source_prefixed_path = $current_relative_path ? $source_key . '/' . $current_relative_path . '/' . $filename : $source_key . '/' . $filename;

                if ($fileinfo->isDir()) {
                    $items[] = ['name' => $filename, 'type' => 'folder', 'path' => $item_source_prefixed_path, 'is_dir' => true, 'source_key' => $source_key, 'absolute_path' => $fileinfo->getPathname()];
                } elseif ($fileinfo->isFile() && in_array(strtolower($fileinfo->getExtension()), $allowed_ext, true)) {
                    // Get image dimensions if possible (can be slow)
                    $dims = @getimagesize($fileinfo->getPathname());
                    $items[] = [
                        'name' => $filename,
                        'type' => 'file',
                        'path' => $item_source_prefixed_path,
                        'is_dir' => false,
                        'width' => $dims[0] ?? 0,
                        'height' => $dims[1] ?? 0,
                        'size_bytes' => $fileinfo->getSize()
                    ];
                }
            }
        } catch (Exception $e) {
            error_log("Error scanning directory '{$current_absolute_path}': " . $e->getMessage());
            json_error('Lỗi khi đọc thư mục.', 500);
        }

        // Sort items: folders first, then by name
        usort($items, function ($a, $b) {
            if ($a['is_dir'] !== $b['is_dir']) {
                return $a['is_dir'] ? -1 : 1;
            }
            return strnatcasecmp($a['name'], $b['name']);
        });

        // Pagination
        $total_items = count($items);
        $total_pages = ceil($total_items / $items_per_page);
        $offset = ($page - 1) * $items_per_page;
        $paginated_items = array_slice($items, $offset, $items_per_page);

        // Process Paginated Items
        $folders_data = [];
        $files_data = [];

        foreach ($paginated_items as $item) {
            if ($item['is_dir']) {
                $folder_path_prefixed = $item['path'];
                $subfolder_access = check_folder_access($folder_path_prefixed);

                if (!$subfolder_access['authorized'] && $subfolder_access['password_required']) {
                    // If password required, add basic info but no thumbnail
                    $folders_data[] = [
                         'name' => $item['name'], 'type' => 'folder', 'path' => $folder_path_prefixed,
                         'protected' => true, 'authorized' => false, 'thumbnail' => null
                    ];
                } elseif ($subfolder_access['authorized']) {
                    // If authorized (or public), find thumbnail
                    $thumbnail_source_prefixed_path = null;
                    $subfolder_relative_to_source = substr($folder_path_prefixed, strlen($item['source_key']) + 1);

                    $first_image_relative = find_first_image_in_source(
                        $item['source_key'],
                        $subfolder_relative_to_source,
                        $allowed_ext
                    );
                    if ($first_image_relative !== null) {
                        $thumbnail_source_prefixed_path = $folder_path_prefixed . '/' . $first_image_relative;
                        $thumbnail_source_prefixed_path = str_replace('//', '/', $thumbnail_source_prefixed_path);
                    }
                    $folders_data[] = [
                        'name' => $item['name'], 'type' => 'folder', 'path' => $folder_path_prefixed,
                        'protected' => $subfolder_access['protected'], 'authorized' => true, // Must be true here
                        'thumbnail' => $thumbnail_source_prefixed_path
                    ];
                } // Else (error during check_folder_access or forbidden): Skip folder

            } else { // Is file
                // File data already has dimensions from scan phase
                $files_data[] = $item;
            }
        }

        json_response([
            'files' => $files_data,
            'folders' => $folders_data,
            'breadcrumb' => $breadcrumb,
            'current_dir' => $current_source_prefixed_path,
            'pagination' => [
                'current_page' => $page,
                'total_pages' => $total_pages,
                'total_items' => $total_items
            ],
            'is_root' => false,
            'is_search' => (bool)$search_term, // Add is_search flag
            'current_search_term' => $search_term // Add current search term
        ]);
        break;

    case 'request_zip':
        $folder_to_zip = $_POST['path'] ?? ''; // Expect path via POST

        if (empty($folder_to_zip)) {
            json_error('Đường dẫn thư mục không được cung cấp.', 400);
        }

        $path_info = validate_source_and_path($folder_to_zip);
        if (!$path_info || $path_info['is_root']) {
            json_error('Đường dẫn thư mục không hợp lệ hoặc là thư mục gốc.', 400);
        }

        $current_source_prefixed_path = $path_info['source_prefixed_path'];
        $access = check_folder_access($current_source_prefixed_path);
        if (!$access['authorized']) {
            json_error($access['error'] ?? 'Không có quyền truy cập thư mục này để tạo ZIP.', $access['password_required'] ? 401 : 403);
        }

        // Check if a recent, non-failed job for this path already exists to avoid duplicates
        try {
            // Priority 1: Check for ANY existing 'pending' or 'processing' job for this source_path
            $stmt_check_active = $pdo->prepare("SELECT job_token, status FROM zip_jobs WHERE source_path = ? AND status IN ('pending', 'processing') ORDER BY created_at DESC LIMIT 1");
            $stmt_check_active->execute([$current_source_prefixed_path]);
            $active_job = $stmt_check_active->fetch(PDO::FETCH_ASSOC);

            if ($active_job) {
                // If an active job (pending/processing) exists, always return its info
                json_response([
                    'message' => 'Một yêu cầu tạo ZIP cho thư mục này đã tồn tại và đang được xử lý hoặc chờ xử lý.',
                    'job_token' => $active_job['job_token'],
                    'status' => $active_job['status']
                ], 202); // 202 Accepted, client should poll this token
                return; // Exit early
            }

            // Priority 2: Check for a RECENT 'completed' job (within 5 minutes) whose file still exists
            $stmt_check_completed = $pdo->prepare("SELECT job_token, status, zip_filename FROM zip_jobs WHERE source_path = ? AND status = 'completed' AND created_at > datetime('now', '-5 minutes') ORDER BY created_at DESC LIMIT 1");
            $stmt_check_completed->execute([$current_source_prefixed_path]);
            $completed_job = $stmt_check_completed->fetch(PDO::FETCH_ASSOC);

            if ($completed_job && !empty($completed_job['zip_filename'])) {
                if (!defined('ZIP_CACHE_DIR_API_REQ')) {
                    define('ZIP_CACHE_DIR_API_REQ', __DIR__ . '/../cache/zips/');
                }
                $existing_zip_filepath = realpath(ZIP_CACHE_DIR_API_REQ . $completed_job['zip_filename']);
                
                if ($existing_zip_filepath && is_file($existing_zip_filepath)) {
                    json_response([
                        'message' => 'Một file ZIP đã được tạo gần đây cho thư mục này và vẫn tồn tại.',
                        'job_token' => $completed_job['job_token'],
                        'status' => 'completed',
                        'zip_filename' => $completed_job['zip_filename']
                    ], 200); // OK, file is ready
                    return; // Exit early
                } else {
                    error_log("[API request_zip] Recent completed job found (token: {$completed_job['job_token']}), but physical file '{$completed_job['zip_filename']}' is missing. Proceeding to create new job.");
                }
            }
            // If no active job, and no recent valid completed job, proceed to create a new one.

        } catch (PDOException $e) {
            error_log("API Error (request_zip - check existing): " . $e->getMessage());
            // Don't fail the request, just proceed to create a new job if check fails
        }


        $job_token = generate_job_token();

        try {
            $sql = "INSERT INTO zip_jobs (source_path, job_token, status) VALUES (?, ?, 'pending')";
            $stmt = $pdo->prepare($sql);
            if ($stmt->execute([$current_source_prefixed_path, $job_token])) {
                json_response([
                    'message' => 'Yêu cầu tạo ZIP đã được nhận. Quá trình sẽ bắt đầu sớm.',
                    'job_token' => $job_token
                ], 202); // 202 Accepted
            } else {
                json_error('Không thể tạo yêu cầu ZIP trong cơ sở dữ liệu.', 500);
            }
        } catch (PDOException $e) {
            error_log("API Error (request_zip - insert new): " . $e->getMessage());
            // Check for unique constraint violation on job_token (highly unlikely but good practice)
            if (strpos($e->getMessage(), 'UNIQUE constraint failed: zip_jobs.job_token') !== false) {
                 json_error('Lỗi tạo mã định danh công việc duy nhất. Vui lòng thử lại.', 500);
            } else {
                 json_error('Lỗi máy chủ khi tạo yêu cầu ZIP.', 500);
            }
        }
        break;

    case 'get_zip_status':
        $job_token = $_GET['token'] ?? '';
        if (empty($job_token)) {
            json_error('Job token không được cung cấp.', 400);
        }

        try {
            $stmt = $pdo->prepare("SELECT job_token, source_path, status, total_files, processed_files, current_file_processing, zip_filename, zip_filesize, error_message, created_at, updated_at FROM zip_jobs WHERE job_token = ?");
            $stmt->execute([$job_token]);
            $job_details = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($job_details) {
                // Ensure path is still accessible by current user for status check too? (Optional, adds overhead)
                // $path_info_status = validate_source_and_path($job_details['source_path']);
                // $access_status = check_folder_access($job_details['source_path']);
                // if (!$access_status['authorized']) {
                //     json_error('Không có quyền xem trạng thái cho công việc này.', 403);
                // }
                json_response($job_details, 200);
            } else {
                json_error('Không tìm thấy công việc ZIP với token được cung cấp.', 404);
            }
        } catch (PDOException $e) {
            error_log("API Error (get_zip_status): " . $e->getMessage());
            json_error('Lỗi máy chủ khi truy vấn trạng thái ZIP.', 500);
        }
        break;

    case 'download_final_zip':
        $job_token = $_GET['token'] ?? '';
        error_log("[API download_final_zip] Received token: " . $job_token);

        if (empty($job_token)) {
            json_error('Job token không được cung cấp để tải về.', 400);
        }

        define('ZIP_CACHE_DIR_API', __DIR__ . '/../cache/zips/'); // Path relative to api/actions_public.php
        error_log("[API download_final_zip] ZIP_CACHE_DIR_API defined as: " . ZIP_CACHE_DIR_API);

        try {
            $stmt = $pdo->prepare("SELECT source_path, status, zip_filename FROM zip_jobs WHERE job_token = ?");
            $stmt->execute([$job_token]);
            $job = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$job) {
                error_log("[API download_final_zip] Job not found for token: " . $job_token);
                json_error('Không tìm thấy công việc ZIP.', 404);
                return; 
            }
            error_log("[API download_final_zip] Job details: " . print_r($job, true));

            if ($job['status'] !== 'completed' || empty($job['zip_filename'])) {
                error_log("[API download_final_zip] Job not completed or zip_filename empty. Status: " . $job['status'] . ", Filename: " . $job['zip_filename']);
                json_error('File ZIP chưa sẵn sàng hoặc đã xảy ra lỗi trong quá trình tạo.', 409); // 409 Conflict
                return;
            }
            
            $path_info_dl = validate_source_and_path($job['source_path']);
            if (!$path_info_dl || $path_info_dl['is_root']) { 
                error_log("[API download_final_zip] Invalid original path for ZIP: " . $job['source_path']);
                json_error('Đường dẫn gốc của file ZIP không hợp lệ.', 403);
                return;
            }
            $access_dl = check_folder_access($path_info_dl['source_prefixed_path']);
            if (!$access_dl['authorized']) {
                error_log("[API download_final_zip] Access denied for original path: " . $job['source_path']);
                json_error('Không có quyền tải file ZIP này.', $access_dl['password_required'] ? 401 : 403);
                return;
            }

            $zip_filename_from_db = $job['zip_filename'];
            $zip_filepath = realpath(ZIP_CACHE_DIR_API . $zip_filename_from_db);
            error_log("[API download_final_zip] Attempting to serve file. DB Filename: '{$zip_filename_from_db}', Resolved Path: '" . ($zip_filepath ?: 'false (realpath failed)') . "'");

            if ($zip_filepath && is_file($zip_filepath) && is_readable($zip_filepath)) {
                error_log("[API download_final_zip] File found and readable: {$zip_filepath}. Sending headers.");
                if (ob_get_level() > 0) {
                    ob_end_clean(); // Clean any existing output buffer
                }

                // Set headers
                header('Content-Description: File Transfer');
                header('Content-Type: application/zip');
                header('Content-Disposition: attachment; filename="' . basename($zip_filename_from_db) . '"');
                header('Expires: 0');
                header('Cache-Control: must-revalidate');
                header('Pragma: public');
                header('Content-Length: ' . filesize($zip_filepath));
                // Prevent PHP from timing out during large file download
                set_time_limit(0);
                
                error_log("[API download_final_zip] Headers sent. Starting chunked readfile for: {$zip_filepath}");

                // Stream the file in chunks
                $file_handle = fopen($zip_filepath, 'rb'); // Open in binary read mode
                if (!$file_handle) {
                    error_log("[API download_final_zip] Failed to open file for reading: {$zip_filepath}");
                    // We can't use json_error here as headers are already sent.
                    // The client will likely receive an incomplete or corrupted download.
                    http_response_code(500);
                    exit;
                }

                $bytes_sent = 0;
                while (!feof($file_handle) && connection_status() === CONNECTION_NORMAL) {
                    // Read 8KB chunks (or adjust chunk size as needed)
                    print(fread($file_handle, 8192));
                    flush(); // Flush PHP output buffer to the browser
                    if (ob_get_level() > 0) { // Ensure outer buffers are flushed too if any
                        ob_flush();
                    }
                    $bytes_sent += 8192; // Approximate, actual read might be less at EOF
                }
                fclose($file_handle);
                error_log("[API download_final_zip] Finished streaming file. Approximate bytes handled by loop: {$bytes_sent}");
                exit;
            } else {
                error_log("[API download_final_zip] File NOT found or NOT readable. Resolved Path: '" . ($zip_filepath ?: 'false') . "'. is_file: " . (is_file($zip_filepath) ? 'yes' : 'no') . ", is_readable: " . (is_readable($zip_filepath) ? 'yes' : 'no'));
                json_error('File ZIP không tìm thấy trên máy chủ hoặc không thể đọc được.', 404);
            }

        } catch (PDOException $e) {
            error_log("API Error (download_final_zip - PDO): " . $e->getMessage());
            json_error('Lỗi cơ sở dữ liệu khi cố gắng tải file ZIP.', 500);
        } catch (Throwable $e) {
            error_log("API Error (download_final_zip - General): " . $e->getMessage());
            json_error('Lỗi không xác định khi cố gắng tải file ZIP.', 500);
        }
        break;

    case 'get_thumbnail':
        $image_path_param = $_GET['path'] ?? null;
        $size_param = isset($_GET['size']) ? (int)$_GET['size'] : 150;

        if (!$image_path_param) {
            json_error('Thiếu đường dẫn ảnh.', 400);
        }

        // Validate the IMAGE file path
        $image_path_info = validate_source_and_file_path($image_path_param);
        if ($image_path_info === null) {
            json_error('Đường dẫn ảnh không hợp lệ.', 404);
        }

        // *** REMOVED FOLDER ACCESS CHECK FOR THUMBNAILS ***
        /*
        // Check access for the PARENT FOLDER of the image
        $parent_folder_path = dirname($image_path_info['source_prefixed_path']);
        if ($parent_folder_path === '.' || $parent_folder_path === $image_path_info['source_key']) {
            $parent_folder_path = $image_path_info['source_key']; // Handle top-level source folder
        } 

        $folder_access = check_folder_access($parent_folder_path);
        if (!$folder_access['authorized']) {
             json_error('Không có quyền truy cập thư mục chứa ảnh.', 403); // Use 403 for general forbidden
             // Or potentially 401 if you want to trigger password prompt, but that might be confusing here
             // json_error('Yêu cầu mật khẩu.', 401);
        }
        */
        // *** END REMOVED CHECK ***

        // Validate requested size against allowed sizes
        $allowed_sizes = THUMBNAIL_SIZES_API; // Use API specific sizes if needed
        if (!in_array($size_param, $allowed_sizes)) {
            json_error("Kích thước thumbnail không hợp lệ: {$size_param}", 400);
        }

        $source_image_absolute_path = $image_path_info['absolute_path'];
        $source_image_prefixed_path = $image_path_info['source_prefixed_path'];

        // Generate cache path
        $cache_dir_for_size = CACHE_THUMB_ROOT . DIRECTORY_SEPARATOR . $size_param;
        $thumb_filename = sha1($source_image_prefixed_path) . '_' . $size_param . '.jpg';
        $cache_absolute_path = $cache_dir_for_size . DIRECTORY_SEPARATOR . $thumb_filename;

        // Check if cache exists and is recent enough (optional, remove if always regenerating)
        if (file_exists($cache_absolute_path)) {
            // Cache hit - output cached file
            // Send appropriate headers
            header("Content-Type: image/jpeg");
            header("Content-Length: " . filesize($cache_absolute_path));
            // Cache control headers (adjust as needed)
            header("Cache-Control: public, max-age=2592000"); // Cache for 30 days
            header("Expires: " . gmdate("D, d M Y H:i:s", time() + 2592000) . " GMT");
            header("Pragma: cache");

            // Clear any output buffer before reading file
             if (ob_get_level() > 0) ob_end_clean(); 
            readfile($cache_absolute_path);
            exit;
        }

        // Cache miss - Create thumbnail on the fly
        try {
            if (!is_dir($cache_dir_for_size)) {
                if (!@mkdir($cache_dir_for_size, 0775, true)) {
                     error_log("Failed to create cache dir on-the-fly: {$cache_dir_for_size}");
                     throw new Exception("Lỗi tạo thư mục cache.");
                }
            }

            // Call create_thumbnail helper (it now throws Exception on failure)
            if (create_thumbnail($source_image_absolute_path, $cache_absolute_path, $size_param)) {
                // Successfully created - output the new file
                header("Content-Type: image/jpeg");
                header("Content-Length: " . filesize($cache_absolute_path));
                header("Cache-Control: public, max-age=2592000");
                header("Expires: " . gmdate("D, d M Y H:i:s", time() + 2592000) . " GMT");
                header("Pragma: cache");

                 if (ob_get_level() > 0) ob_end_clean();
                readfile($cache_absolute_path);
                exit;
            } else {
                 // Should not happen if create_thumbnail throws Exception, but as fallback
                 throw new Exception('Hàm create_thumbnail trả về false không mong muốn.');
            }
        } catch (Exception $e) {
            error_log("Error creating thumbnail on-the-fly for '{$source_image_prefixed_path}': " . $e->getMessage());
            // Return a placeholder image or a 500 error
            // Option 1: Return 500 Error (client can show broken image)
             http_response_code(500);
             // Clear buffer and echo simple text error (since we can't send JSON now)
             if (ob_get_level() > 0) ob_end_clean(); 
             header('Content-Type: text/plain');
             echo "Lỗi tạo thumbnail: " . htmlspecialchars($e->getMessage());
             exit;

            // Option 2: Output a placeholder image (requires a placeholder file)
            /*
            $placeholder = 'path/to/placeholder.jpg';
            if (file_exists($placeholder)) {
                header("Content-Type: image/jpeg");
                header("Content-Length: " . filesize($placeholder));
                readfile($placeholder);
            } else {
                http_response_code(404); // Placeholder not found
            }
            exit;
            */
        }
        break;

    case 'get_image': // Serve original image
        $image_path_param = $_GET['path'] ?? null;

        if (!$image_path_param) {
            http_response_code(400); exit;
        }

        $file_info = validate_source_and_file_path($image_path_param);
        if (!$file_info) {
            http_response_code(404); exit;
        }

        // Check access to the containing folder
        $folder_path_prefixed = dirname($file_info['source_prefixed_path']);
         if ($folder_path_prefixed === $file_info['source_key']) {
              $folder_path_prefixed = '';
         }
        $access = check_folder_access($folder_path_prefixed);
        if (!$access['authorized']) {
            http_response_code($access['password_required'] ? 401 : 403);
            exit;
        }

        $source_absolute_path = $file_info['absolute_path'];
        $mime = mime_content_type($source_absolute_path);
        if ($mime === false || strpos($mime, 'image/') !== 0) {
            error_log("[GetImage] Invalid mime type '{$mime}' for: {$source_absolute_path}");
            http_response_code(500); // Or 415 Unsupported Media Type?
            exit;
        }

        header("Content-Type: " . $mime);
        header('Content-Length: ' . filesize($source_absolute_path));
        // Add cache headers for original images too?
        header('Cache-Control: public, max-age=86400'); // e.g., cache original for 1 day
        header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 86400) . ' GMT');

        readfile($source_absolute_path);
        exit;
        break;

    case 'authenticate': // Public action to authorize a protected folder
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            json_error('Phương thức không hợp lệ.', 405);
        }
        $folder_param = $_POST['folder'] ?? null;
        $password = $_POST['password'] ?? null;

        if ($folder_param === null || $password === null) {
            json_error('Thiếu thông tin thư mục hoặc mật khẩu.', 400);
        }

        // Validate folder path format (doesn't need to exist, just valid source/format)
        // Use validate_source_and_path, but ignore the absolute path result for auth.
        $path_parts = explode('/', trim(str_replace(['..', '\\', "\0"], '', $folder_param), '/'), 2);
        if (count($path_parts) < 2 || !isset(IMAGE_SOURCES[$path_parts[0]])) {
             json_error('Định dạng tên thư mục không hợp lệ.', 400);
        }
        $source_prefixed_path = $path_parts[0] . '/' . $path_parts[1]; // Reconstruct validated format

        try {
            $stmt = $pdo->prepare("SELECT password_hash FROM folder_passwords WHERE folder_name = ? LIMIT 1");
            $stmt->execute([$source_prefixed_path]);
            $row = $stmt->fetch();

            if ($row && password_verify($password, $row['password_hash'])) {
                // Password is correct, store authorization in session
                $session_key = 'authorized_folders';
                if (!isset($_SESSION[$session_key])) {
                    $_SESSION[$session_key] = [];
                }
                $_SESSION[$session_key][$source_prefixed_path] = true;
                error_log("[Authenticate] Success for folder: {$source_prefixed_path}");
                json_response(['success' => true]);
            } else {
                // Incorrect password or folder not protected
                error_log("[Authenticate] Failed for folder: {$source_prefixed_path} - Incorrect password or not protected.");
                json_error('Mật khẩu không đúng hoặc thư mục không được bảo vệ.', 401);
            }
        } catch (PDOException $e) {
            error_log("[Authenticate] DB Error for '{$source_prefixed_path}': " . $e->getMessage());
            json_error('Lỗi server khi xác thực.', 500);
        }
        break;

    default:
        json_error('Hành động không hợp lệ.', 400);
} 