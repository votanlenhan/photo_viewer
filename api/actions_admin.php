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

                        // Apply search filter
                        if ($admin_search_term !== null && mb_stripos($dir_name, $admin_search_term, 0, 'UTF-8') === false) {
                            continue;
                        }

                        $stats = $folder_stats[$source_prefixed_path] ?? [
                            'views' => 0, 
                            'downloads' => 0,
                            'last_cached_fully_at' => null
                        ];

                        $folders_data[] = [
                            'name' => $dir_name,
                            'path' => $source_prefixed_path,
                            'source' => $source_key,
                            'protected' => isset($protected_status[$source_prefixed_path]),
                            'views' => $stats['views'],
                            'downloads' => $stats['downloads'],
                            'last_cached_fully_at' => $stats['last_cached_fully_at']
                        ];
                    }
                } catch (Exception $e) {
                    error_log("[admin_list_folders] Error scanning source '{$source_key}': " . $e->getMessage());
                }
            } // End foreach IMAGE_SOURCES

            usort($folders_data, fn ($a, $b) => strnatcasecmp($a['name'], $b['name']));

            json_response(['folders' => $folders_data]);
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

    default:
        // If the action starts with 'admin_' but isn't handled above
        if (strpos($action, 'admin_') === 0) {
            json_error("Hành động admin không xác định: " . htmlspecialchars($action), 400);
        }
        // Otherwise, fall through (handled by api.php main or actions_public.php default)
        break;
} 