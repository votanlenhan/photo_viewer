<?php
// api/actions_admin.php

// Dependencies:
// - Assumes $action, $pdo, $search_term are available (from init.php)
// - Assumes all helper functions are available (from helpers.php)
// - Assumes IMAGE_SOURCES constant is defined

// Prevent direct access
if (!isset($action)) {
    die('Invalid access.');
}

// Check if the user is logged in for all admin actions (except login itself)
if ($action !== 'admin_login' && $action !== 'admin_check_auth' && empty($_SESSION['admin_logged_in'])) {
    // For admin_check_auth, it needs to run to report not logged in
    if ($action !== 'admin_check_auth') {
        json_error("Yêu cầu đăng nhập Admin.", 403);
    }
}

switch ($action) {
    case 'admin_login':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            json_error('Phương thức không hợp lệ.', 405);
        }
        $username = $_POST['username'] ?? '';
        $password = $_POST['password'] ?? '';

        // Use ADMIN_USERNAME and ADMIN_PASSWORD_HASH from config (loaded via db_connect->init)
        if (!defined('ADMIN_USERNAME') || !defined('ADMIN_PASSWORD_HASH')) {
            error_log("[Admin Login] Admin credentials constants not defined in config.");
            json_error('Lỗi cấu hình phía server.', 500);
        }

        if ($username === ADMIN_USERNAME && password_verify($password, ADMIN_PASSWORD_HASH)) {
            $_SESSION['admin_logged_in'] = true;
            $_SESSION['admin_username'] = $username;
            error_log("[Admin Login] Login successful for user: {$username}");
            json_response(['success' => true]);
        } else {
            error_log("[Admin Login] Login failed for user: {$username}");
            json_error('Tên đăng nhập hoặc mật khẩu không đúng.', 401);
        }
        break;

    case 'admin_logout':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            json_error('Phương thức không hợp lệ.', 405);
        }
        $username = $_SESSION['admin_username'] ?? 'unknown';
        session_unset();
        session_destroy();
        error_log("[Admin Logout] Logout successful for user: {$username}");
        json_response(['success' => true]);
        break;

    case 'admin_check_auth':
        if (!empty($_SESSION['admin_logged_in'])) {
            json_response(['logged_in' => true, 'username' => $_SESSION['admin_username'] ?? '']);
        } else {
            json_response(['logged_in' => false]);
        }
        break;

    case 'admin_list_folders':
        // $search_term is available from init.php
        $admin_search_term = $search_term;
        // Handle specific path filter for polling/info modal - Initialize to null
        $path_filter = $_GET['path_filter'] ?? null;

        try {
            $folders_data = [];
            $protected_status = [];
            $folder_stats = [];

            // Fetch protected folders
            $stmt = $pdo->query("SELECT folder_name FROM folder_passwords");
            while ($row = $stmt->fetchColumn()) {
                $protected_status[$row] = true;
            }

            // Fetch folder stats
            try {
                $stmt = $pdo->query("SELECT folder_name, views, downloads, last_cached_fully_at FROM folder_stats");
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $folder_stats[$row['folder_name']] = [
                        'views' => $row['views'], 
                        'downloads' => $row['downloads'],
                        'last_cached_fully_at' => $row['last_cached_fully_at']
                    ];
                }
            } catch (PDOException $e) {
                error_log("ERROR fetching folder stats for admin: " . $e->getMessage());
            }

            // +++ NEW: Fetch active cache job statuses +++
            $active_cache_jobs = [];
            try {
                 $sql_jobs = "SELECT folder_path, status FROM cache_jobs WHERE status IN ('pending', 'processing')";
                 $stmt_jobs = $pdo->query($sql_jobs);
                 while ($job_row = $stmt_jobs->fetch(PDO::FETCH_ASSOC)) {
                     $active_cache_jobs[$job_row['folder_path']] = $job_row['status'];
                 }
            } catch (PDOException $e) {
                 error_log("ERROR fetching active cache job statuses for admin: " . $e->getMessage());
                 // Continue without job status if query fails
            }
            // +++ END NEW +++

            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_cache_jobs_folder_path ON cache_jobs (folder_path)");

            // Fetch latest job result messages (NO LONGER PRE-FETCHED)
            // $latest_job_results = []; 
            // try { ... } catch ...

            // Iterate through IMAGE_SOURCES
            foreach (IMAGE_SOURCES as $source_key => $source_config) {
                if (!is_array($source_config) || !isset($source_config['path'])) continue;
                $source_base_path = $source_config['path'];
                $resolved_source_base_path = realpath($source_base_path);

                if ($resolved_source_base_path === false || !is_dir($resolved_source_base_path) || !is_readable($resolved_source_base_path)) {
                    error_log("[admin_list_folders] Skipping source '{$source_key}': Path invalid or not readable.");
                    continue;
                }

                try {
                    $iterator = new DirectoryIterator($resolved_source_base_path);
                    foreach ($iterator as $fileinfo) {
                        if ($fileinfo->isDot() || !$fileinfo->isDir()) {
                            continue;
                        }

                        $dir_name = $fileinfo->getFilename();
                        $source_prefixed_path = $source_key . '/' . $dir_name;

                        // Apply path filter if provided
                        $is_target_folder = ($path_filter === null || $source_prefixed_path === $path_filter);

                        // Apply search filter if provided (and no path filter or it's the target folder)
                        $passes_search = ($admin_search_term === null || mb_stripos($dir_name, $admin_search_term, 0, 'UTF-8') !== false);

                        // Only proceed if it's the target folder (if filtering) AND passes search (if searching)
                        if ($is_target_folder && $passes_search) {
                            $stats = $folder_stats[$source_prefixed_path] ?? [
                                'views' => 0,
                                'downloads' => 0,
                                'last_cached_fully_at' => null
                            ];
                            $latest_job_info = [
                                'message' => null,
                                'image_count' => null,
                                'created_at' => null,
                                'status' => null
                            ];

                            // ---> FETCH LATEST JOB INFO INSIDE LOOP <---
                            try {
                                $sql_latest_job = "SELECT cj.result_message, cj.image_count, cj.created_at, cj.status 
                                                    FROM cache_jobs cj 
                                                    WHERE cj.folder_path = ? 
                                                    ORDER BY cj.id DESC 
                                                    LIMIT 1";
                                $stmt_latest_job = $pdo->prepare($sql_latest_job);
                                $stmt_latest_job->execute([$source_prefixed_path]);
                                $job_row = $stmt_latest_job->fetch(PDO::FETCH_ASSOC);
                                if ($job_row) {
                                    $latest_job_info['message'] = $job_row['result_message'];
                                    $latest_job_info['image_count'] = $job_row['image_count'] ? (int)$job_row['image_count'] : null;
                                    $latest_job_info['created_at'] = $job_row['created_at'];
                                    $latest_job_info['status'] = $job_row['status'];
                                }
                            } catch (PDOException $e_job) {
                                 error_log("[admin_list_folders] Error fetching latest job info for {$source_prefixed_path}: " . $e_job->getMessage());
                                 // Continue without job info
                            }
                            // ---> END FETCH LATEST JOB INFO <---

                            // Determine which image count to use
                            $last_cached_image_count = null; // Initialize
                            if ($stats['last_cached_fully_at']) { // Check if stats has a valid timestamp first
                                if ($latest_job_info['created_at']) {
                                     $job_time = strtotime($latest_job_info['created_at']);
                                     // Use job count if job is newer or equal to stats timestamp
                                     if ($job_time && $job_time >= $stats['last_cached_fully_at']) {
                                         $last_cached_image_count = $latest_job_info['image_count'];
                                     } else {
                                         // If stats is newer, we need a way to know the count from stats.
                                         // Currently, folder_stats table doesn't store count.
                                         // For now, maybe keep using the latest job's count even if older?
                                         // Or set to null if stats is newer and we don't have a count?
                                         // Let's use latest job count for now if available, otherwise null.
                                          $last_cached_image_count = $latest_job_info['image_count']; 
                                     }
                                } else {
                                     // Stats exist, but no job info - count is unknown from stats table
                                     $last_cached_image_count = null; 
                                }
                            } else if ($latest_job_info['created_at']) {
                                 // No stats timestamp, but job exists: use job count
                                 $last_cached_image_count = $latest_job_info['image_count'];
                            }
                           
                            // Add folder data ONLY if it meets the criteria
                            $folders_data[] = [
                                'name' => $dir_name,
                                'path' => $source_prefixed_path,
                                'source' => $source_key,
                                'is_password_protected' => isset($protected_status[$source_prefixed_path]),
                                'views' => (int)($stats['views'] ?? 0),
                                'zip_downloads' => (int)($stats['downloads'] ?? 0),
                                'last_cached_fully_at' => $stats['last_cached_fully_at'] ? (int)$stats['last_cached_fully_at'] : null,
                                'current_cache_job_status' => $active_cache_jobs[$source_prefixed_path] ?? null,
                                'latest_job_result_message' => $latest_job_info['message'],
                                'last_cached_image_count' => $last_cached_image_count ? (int)$last_cached_image_count : null, // Ensure integer or null
                                'latest_job_status' => $latest_job_info['status'] // Add job status
                            ];
                        }
                    }
                } catch (Exception $e) {
                    error_log("[admin_list_folders] Error scanning source '{$source_key}': " . $e->getMessage());
                }
            } // End foreach IMAGE_SOURCES

            usort($folders_data, fn ($a, $b) => strnatcasecmp($a['name'], $b['name']));

            // === End Calculation ===

            json_response([
                'folders' => $folders_data,
                'total_cache_files' => count($folders_data) // Add count to response
            ]);

        } catch (Throwable $e) {
            error_log("FATAL ERROR in admin_list_folders: " . $e->getMessage() . "\nStack Trace:\n" . $e->getTraceAsString());
            json_error("Không thể lấy danh sách thư mục quản lý. Lỗi: " . $e->getMessage(), 500);
        }
        break;

    case 'admin_set_password':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            json_error('Phương thức không hợp lệ.', 405);
        }
        $folder_param = $_POST['folder'] ?? null;
        $password = $_POST['password'] ?? null;

        if ($folder_param === null || $password === null) {
            json_error('Thiếu thông tin thư mục hoặc mật khẩu.', 400);
        }
        if ($password === '') {
            json_error('Mật khẩu không được để trống.', 400);
        }

        // Validate the FOLDER path using helper
        $folder_path_info = validate_source_and_path($folder_param);
        if ($folder_path_info === null || $folder_path_info['is_root']) {
            json_error('Tên thư mục không hợp lệ hoặc không thể đặt mật khẩu cho thư mục gốc.', 400);
        }
        $source_prefixed_path = $folder_path_info['source_prefixed_path'];

        try {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            if ($hash === false) {
                throw new Exception('Không thể tạo hash mật khẩu.');
            }

            $sql = "INSERT OR REPLACE INTO folder_passwords (folder_name, password_hash) VALUES (?, ?)";
            $stmt = $pdo->prepare($sql);
            if ($stmt->execute([$source_prefixed_path, $hash])) {
                unset($_SESSION['authorized_folders'][$source_prefixed_path]); // Clear any existing public auth
                json_response(['success' => true, 'message' => "Đặt/Cập nhật mật khẩu thành công cho '" . htmlspecialchars($source_prefixed_path) . "'."]);
            } else {
                throw new Exception('Lỗi thực thi truy vấn CSDL.');
            }
        } catch (Throwable $e) {
            error_log("admin_set_password: Error for '{$source_prefixed_path}': " . $e->getMessage());
            json_error('Lỗi server khi đặt mật khẩu: ' . $e->getMessage(), 500);
        }
        break;

    case 'admin_remove_password':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            json_error('Phương thức không hợp lệ.', 405);
        }
        $folder_param = $_POST['folder'] ?? null;
        if ($folder_param === null) {
            json_error('Thiếu thông tin thư mục.', 400);
        }

        // Validate path format using helper (folder might not exist anymore, but path format should be valid)
        $folder_path_info = validate_source_and_path($folder_param);
         // We need to check the *format*, even if the directory doesn't resolve perfectly now.
         // A simpler check for format might be better here if validate_source_and_path fails for non-existent dirs.
         $path_parts_remove = explode('/', trim(str_replace(['..', '\\', "\0"], '', $folder_param), '/'), 2);
         if (count($path_parts_remove) < 2 || !isset(IMAGE_SOURCES[$path_parts_remove[0]])) {
             json_error('Định dạng tên thư mục không hợp lệ.', 400);
         }
         $source_prefixed_path = $path_parts_remove[0] . '/' . $path_parts_remove[1]; // Use the formatted path

        // if ($folder_path_info === null || $folder_path_info['is_root']) {
        //     json_error('Đường dẫn thư mục không hợp lệ.', 400);
        // }
        // $source_prefixed_path = $folder_path_info['source_prefixed_path']; // Use the validated path if validation required existence

        try {
            $sql = "DELETE FROM folder_passwords WHERE folder_name = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$source_prefixed_path]);
            $affected_rows = $stmt->rowCount();

            unset($_SESSION['authorized_folders'][$source_prefixed_path]); // Clear any existing public auth

            json_response(['success' => true, 'message' => "Đã xóa mật khẩu (nếu có) cho '" . htmlspecialchars($source_prefixed_path) . "'. Bị ảnh hưởng: {$affected_rows} dòng."]);
        } catch (Throwable $e) {
            error_log("[admin_remove_password] FATAL ERROR for '{$source_prefixed_path}': " . $e->getMessage());
            json_error('Lỗi server khi xóa mật khẩu: ' . $e->getMessage(), 500);
        }
        break;

    // +++ ACTION: Manually cache thumbnails for a folder (NOW ASYNC) +++
    case 'admin_cache_folder':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            json_error('Method Not Allowed', 405);
        }
        $folder_path_param = $_POST['path'] ?? null;
        if (!$folder_path_param) {
            json_error('Missing folder path parameter.', 400);
        }

        $path_info = validate_source_and_path($folder_path_param);
        if (!$path_info || $path_info['is_root']) {
            json_error('Invalid or root folder path provided.', 400);
        }
        $source_prefixed_path_to_queue = $path_info['source_prefixed_path']; // Use validated path

        try {
            // Check if a job for this folder is already pending or processing
            $sql_check = "SELECT status FROM cache_jobs WHERE folder_path = ? AND status IN ('pending', 'processing') LIMIT 1";
            $stmt_check = $pdo->prepare($sql_check);
            $stmt_check->execute([$source_prefixed_path_to_queue]);
            $existing_job = $stmt_check->fetch();

            if ($existing_job) {
                $status_msg = ($existing_job['status'] === 'processing') ? 'đang được xử lý' : 'đã có trong hàng đợi';
                json_response(['success' => true, 'message' => "Yêu cầu cache cho '{$source_prefixed_path_to_queue}' {$status_msg}." , 'status' => 'already_queued']);
                exit;
            }

            // Insert new job into the queue
            $sql_insert = "INSERT INTO cache_jobs (folder_path, created_at) VALUES (?, ?)";
            $stmt_insert = $pdo->prepare($sql_insert);
            $current_time = time();
            if ($stmt_insert->execute([$source_prefixed_path_to_queue, $current_time])) {
                json_response(['success' => true, 'message' => "Đã thêm yêu cầu tạo cache cho '{$source_prefixed_path_to_queue}' vào hàng đợi.", 'status' => 'queued']);
            } else {
                throw new Exception('Không thể thêm công việc vào hàng đợi CSDL.');
            }

        } catch (Throwable $e) {
            error_log("[Admin Cache Enqueue] Error processing folder '{$source_prefixed_path_to_queue}': " . $e->getMessage());
            json_error("Đã xảy ra lỗi khi đưa yêu cầu vào hàng đợi: " . $e->getMessage(), 500);
        }
        break;
    // +++ END ASYNC CACHE ACTION +++

    // +++ ACTION: Get specific folder cache status (for polling) +++
    case 'get_folder_cache_status':
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') { // Use GET for status check
            json_error('Method Not Allowed', 405);
        }
        $folder_path_param = $_GET['path'] ?? null;
        if (!$folder_path_param) {
            json_error('Missing folder path parameter.', 400);
        }

        // Basic validation, might not need full validate_source_and_path if just checking DB
        $path_parts = explode('/', trim(str_replace(['..', '\\', "\0"], '', $folder_path_param), '/'), 2);
        if (count($path_parts) < 2 || !isset(IMAGE_SOURCES[$path_parts[0]])) {
            json_error('Invalid folder path format.', 400);
        }
        $validated_folder_path = $path_parts[0] . '/' . $path_parts[1]; // Use validated format

        try {
            $current_job_status = null;
            $last_cached_at = null;

            // Check for active job
            $sql_job = "SELECT status FROM cache_jobs WHERE folder_path = ? AND status IN ('pending', 'processing') LIMIT 1";
            $stmt_job = $pdo->prepare($sql_job);
            $stmt_job->execute([$validated_folder_path]);
            $current_job_status = $stmt_job->fetchColumn() ?: null; // Fetch status or null

            // Get last cache time
            $sql_stat = "SELECT last_cached_fully_at FROM folder_stats WHERE folder_name = ? LIMIT 1";
            $stmt_stat = $pdo->prepare($sql_stat);
            $stmt_stat->execute([$validated_folder_path]);
            $last_cached_at = $stmt_stat->fetchColumn() ?: null; // Fetch timestamp or null
            
            // Ensure last_cached_at is integer or null
            if ($last_cached_at !== null) {
                $last_cached_at = (int)$last_cached_at;
            }

            json_response([
                'success' => true, 
                'job_status' => $current_job_status, 
                'last_cached_at' => $last_cached_at
            ]);

        } catch (Throwable $e) {
            error_log("[Get Cache Status] Error for '{$validated_folder_path}': " . $e->getMessage());
            json_error("Đã xảy ra lỗi khi kiểm tra trạng thái cache: " . $e->getMessage(), 500);
        }
        break;
    // +++ END GET STATUS ACTION +++

    default:
        // If the action starts with 'admin_' but isn't handled above
        if (strpos($action, 'admin_') === 0) {
            json_error("Hành động admin không xác định: " . htmlspecialchars($action), 400);
        }
        // Otherwise, fall through (handled by api.php main or actions_public.php default)
        break;
} 