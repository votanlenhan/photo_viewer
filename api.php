<?php
ob_start(); // Start output buffering at the very beginning

// --- Configure Error Handling ---
ini_set('display_errors', 0); // Turn off displaying errors
ini_set('display_startup_errors', 0);
ini_set('log_errors', 1); // Enable logging errors
ini_set('error_log', __DIR__ . '/logs/php-error.log'); // Log to logs/php-error.log
error_reporting(E_ALL); // Report all errors

// --- Test Log Write ---
@error_log("API Step 1: Script started");

// --- Bắt đầu session và set header JSON --- 
// NOTE: Error display might interfere with JSON header if error occurs before header() call.
try {
    if (session_status() == PHP_SESSION_NONE) {
        @error_log("API Step 2: Attempting session_start()");
        session_start();
        @error_log("API Step 3: session_start() completed");
    } else {
        @error_log("API Step 2/3: Session already active");
    }
} catch (Throwable $e) {
    @error_log("FATAL ERROR during session start: " . $e->getMessage());
    http_response_code(500);
    ob_end_clean();
    echo json_encode(['error' => 'Lỗi khởi tạo session.']);
    exit;
}
header('Content-Type: application/json; charset=utf-8'); // Restore JSON header

// --- Kết nối DB (Vẫn include để kiểm tra) ---
try {
    @error_log("API Step 5: Attempting require_once db_connect.php");
    require_once 'db_connect.php';
    @error_log("API Step 6: require_once db_connect.php completed");
    if (!isset($pdo) || !$pdo instanceof PDO) {
        @error_log("API Error: \$pdo object is not set or not a PDO instance after require_once");
         throw new Exception("PDO connection object not created/available or is not a PDO instance.");
    }
    @error_log("API Step 7: \$pdo object confirmed to be a PDO instance");
} catch (Throwable $e) { // Catch any error/exception during include
    @error_log("FATAL ERROR during DB connection: " . $e->getMessage());
    http_response_code(500);
    ob_end_clean();
    echo json_encode(['error' => 'Lỗi kết nối cơ sở dữ liệu.', 'details' => $e->getMessage()]);
    exit;
}

// --- Định nghĩa Hằng số và Biến toàn cục ---
// SECURITY: Ensure IMAGE_ROOT is correctly configured and not pointing outside intended scope.
try {
    @error_log("API Step 8: Attempting define IMAGE_ROOT");
    define('IMAGE_ROOT', realpath(__DIR__ . '/images')); 
    @error_log("API Step 9: IMAGE_ROOT defined as: " . (IMAGE_ROOT ?: '[Error or Not Found]'));
    if (!IMAGE_ROOT) {
         throw new Exception("Failed to resolve IMAGE_ROOT path. Check if 'images' directory exists and permissions.");
    }
} catch (Throwable $e) {
    @error_log("FATAL ERROR defining IMAGE_ROOT: " . $e->getMessage());
    http_response_code(500);
    ob_end_clean();
    echo json_encode(['error' => 'Lỗi cấu hình đường dẫn ảnh.', 'details' => $e->getMessage()]);
    exit;
}

// OPTIMIZATION: Consider if allowed_ext needs to be dynamic or configurable.
$allowed_ext = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp'];
@error_log("API Step 10: Constants and variables defined");

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
 * Làm sạch và xác thực đường dẫn thư mục con.
 * SECURITY: This function is crucial. Review carefully for any potential path traversal bypasses.
 * Consider adding checks for excessively long paths or invalid characters.
 */
function sanitize_subdir($subdir) {
    if ($subdir === null || $subdir === '') return ''; // Thư mục gốc

    // 1. Chuẩn hóa dấu phân cách và loại bỏ ký tự nguy hiểm cơ bản
    $subdir = str_replace(['..', '\\', "\0"], '', $subdir); // Loại bỏ '..', '\', null byte
    $subdir = trim(str_replace('/', DIRECTORY_SEPARATOR, $subdir), DIRECTORY_SEPARATOR); // Chuẩn hóa và trim

    if ($subdir === '') return ''; // Trường hợp chỉ có / hoặc \

    // 2. Lấy đường dẫn tuyệt đối thực tế
    $base = IMAGE_ROOT;
    // Dùng @ để chặn warning nếu đường dẫn không tồn tại (sẽ kiểm tra sau)
    $target_path = @realpath($base . DIRECTORY_SEPARATOR . $subdir);

    // 3. Kiểm tra tính hợp lệ
    // Phải tồn tại, phải là thư mục, và phải nằm trong thư mục gốc IMAGE_ROOT
    if ($target_path === false || !is_dir($target_path) || strpos($target_path, $base) !== 0) {
        error_log("Path validation failed for subdir: '{$subdir}'");
        return null; // Không hợp lệ
    }

    // 4. Trả về đường dẫn tương đối so với IMAGE_ROOT (dùng / làm dấu phân cách)
    $relative_path = substr($target_path, strlen($base));
    return ltrim(str_replace(DIRECTORY_SEPARATOR, '/', $relative_path), '/');
}

/** 
 * Kiểm tra quyền truy cập thư mục (dựa vào DB và Session) 
 * SECURITY: Ensure session fixation is prevented.
 */
function check_folder_access($folder_relative_path) {
    global $pdo; // Sử dụng biến PDO toàn cục
    try {
        $stmt = $pdo->prepare("SELECT password_hash FROM folder_passwords WHERE folder_name = ? LIMIT 1");
        $stmt->execute([$folder_relative_path]);
        $row = $stmt->fetch(); // Mặc định là FETCH_ASSOC

        // Nếu không có trong DB -> không cần mật khẩu
        if (!$row) {
            return ['authorized' => true];
        }

        // Nếu có cần mật khẩu, kiểm tra session
        if (!empty($_SESSION['authorized_folders'][$folder_relative_path])) {
            return ['authorized' => true]; // Đã xác thực trong session
        }

        // Cần mật khẩu nhưng chưa xác thực
        return ['authorized' => false, 'password_required' => true];

    } catch (PDOException $e) {
        error_log("DB Error checking folder access for '{$folder_relative_path}': " . $e->getMessage());
        return ['authorized' => false, 'error' => 'Lỗi server khi kiểm tra quyền truy cập.'];
    }
}

/** Tìm ảnh đầu tiên đệ quy */
function find_first_image_recursive($start_path, $base_folder_name, &$allowed_ext) {
    @error_log("[find_thumb] Searching in: '{$start_path}' with base: '{$base_folder_name}'");
    try {
        // Check read permissions before iterating
        if (!is_readable($start_path)) {
            @error_log("[find_thumb] Directory not readable: '{$start_path}'");
            return null;
        }
        $iterator = new DirectoryIterator($start_path);
        $first_sub_dir_path = null;
        $first_sub_dir_name = null;

        foreach ($iterator as $fileinfo) {
            if ($fileinfo->isDot()) continue;

            // Priority 1: Find direct image in current directory
            if ($fileinfo->isFile() && in_array(strtolower($fileinfo->getExtension()), $allowed_ext, true)) {
                $found_thumb = $base_folder_name . '/' . $fileinfo->getFilename();
                @error_log("[find_thumb] Direct image found: '{$found_thumb}' in '{$start_path}'");
                return $found_thumb;
            }
            // Priority 2: Remember the first subdirectory encountered
            if ($first_sub_dir_path === null && $fileinfo->isDir()) {
                $first_sub_dir_path = $fileinfo->getPathname();
                $first_sub_dir_name = $fileinfo->getFilename();
                @error_log("[find_thumb] First subdirectory found: '{$first_sub_dir_path}'");
            }
        }

        // Priority 3: If no direct image found, search in the first subdirectory recursively
        if ($first_sub_dir_path !== null) {
            @error_log("[find_thumb] No direct image in '{$start_path}'. Recursing into first sub: '{$first_sub_dir_path}'");
            // Recursively call, updating the base folder name for relative path construction
            return find_first_image_recursive($first_sub_dir_path, $base_folder_name . '/' . $first_sub_dir_name, $allowed_ext);
        } else {
            @error_log("[find_thumb] No direct image and no subdirectories found in: '{$start_path}'");
        }

    } catch (Throwable $e) { // Catch specific exceptions if needed (e.g., UnexpectedValueException)
        @error_log("[find_thumb] ERROR searching in {$start_path}: " . $e->getMessage());
    }
    @error_log("[find_thumb] No thumbnail found for base '{$base_folder_name}' starting from '{$start_path}'");
    return null; // No image found
}

/** Lấy thống kê thư mục đệ quy */
function getFolderStats($folder_path) {
    $total_views = 0;
    $total_downloads = 0;
    $count_file = $folder_path . '/.count';
    $download_count_file = $folder_path . '/.download_count';

    $view_content = 'not_found';
    if (file_exists($count_file)) {
        $view_content = @file_get_contents($count_file);
        $total_views += (int)$view_content;
    }
    error_log("Stat Read [{$folder_path}]: .count exists=" . (file_exists($count_file) ? 'yes' : 'no') . " content='{$view_content}' total_views={$total_views}");

    $download_content = 'not_found';
    if (file_exists($download_count_file)) {
        $download_content = @file_get_contents($download_count_file);
        $total_downloads += (int)$download_content;
    }
    error_log("Stat Read [{$folder_path}]: .download_count exists=" . (file_exists($download_count_file) ? 'yes' : 'no') . " content='{$download_content}' total_downloads={$total_downloads}");

    try {
        $iterator = new DirectoryIterator($folder_path);
        foreach ($iterator as $fileinfo) {
            if ($fileinfo->isDir() && !$fileinfo->isDot()) {
                $sub_dir_path = $fileinfo->getPathname();
                error_log("Stat Recursing into: {$sub_dir_path}");
                $sub_stats = getFolderStats($sub_dir_path); // Recursive call
                $total_views += $sub_stats['views'];
                $total_downloads += $sub_stats['downloads'];
                error_log("Stat Returned from [{$sub_dir_path}]: views={$sub_stats['views']}, downloads={$sub_stats['downloads']}. New totals: views={$total_views}, downloads={$total_downloads}");
            }
        }
    } catch (Exception $e) {
         error_log("Error reading stats recursively in {$folder_path}: " . $e->getMessage());
    }
    return ['views' => $total_views, 'downloads' => $total_downloads];
}

/**
 * Create a thumbnail image using GD library.
 * 
 * @param string $source_path Absolute path to the source image.
 * @param string $cache_path Absolute path to save the thumbnail.
 * @param int $thumb_size Desired width/height of the thumbnail (square).
 * @return bool True on success, false on failure.
 */
function create_thumbnail($source_path, $cache_path, $thumb_size = 150) {
    if (!extension_loaded('gd')) {
        error_log("GD extension is not loaded. Cannot create thumbnail.");
        return false;
    }
    
    try {
        $image_info = @getimagesize($source_path);
        if ($image_info === false) {
             error_log("create_thumbnail: Failed to get image size for: {$source_path}");
             return false;
        }
        $mime = $image_info['mime'];
        $original_width = $image_info[0];
        $original_height = $image_info[1];

        // Create image resource based on mime type
        $source_image = null;
        switch ($mime) {
            case 'image/jpeg':
            case 'image/jpg': // Handle both common mimetypes
                $source_image = @imagecreatefromjpeg($source_path);
                break;
            case 'image/png':
                $source_image = @imagecreatefrompng($source_path);
                break;
            case 'image/gif':
                $source_image = @imagecreatefromgif($source_path);
                break;
            case 'image/webp':
                 if (function_exists('imagecreatefromwebp')) { // Check if webp is supported by GD
                     $source_image = @imagecreatefromwebp($source_path);
                 } else {
                    error_log("create_thumbnail: WebP is not supported by this GD version for: {$source_path}");
                    return false; // Cannot process webp if not supported
                 }
                break;
            // Add other types if needed (bmp?)
            default:
                error_log("create_thumbnail: Unsupported image type '{$mime}' for: {$source_path}");
                return false;
        }

        if ($source_image === false) {
            error_log("create_thumbnail: Failed to create image resource from: {$source_path}");
            return false;
        }

        // Calculate new dimensions while maintaining aspect ratio
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
            error_log("create_thumbnail: Failed to create true color image resource.");
            imagedestroy($source_image); 
            return false;
        }

        // Handle transparency for PNG and GIF
        if ($mime == 'image/png' || $mime == 'image/gif') {
            imagealphablending($thumb_image, false);
            imagesavealpha($thumb_image, true);
            $transparent = imagecolorallocatealpha($thumb_image, 255, 255, 255, 127);
            imagefilledrectangle($thumb_image, 0, 0, $thumb_width, $thumb_height, $transparent);
        }

        // Resize the image
        if (!imagecopyresampled($thumb_image, $source_image, 0, 0, 0, 0, $thumb_width, $thumb_height, $original_width, $original_height)) {
            error_log("create_thumbnail: imagecopyresampled failed.");
            imagedestroy($source_image); 
            imagedestroy($thumb_image);
            return false;
        }

        // Save the thumbnail (output as JPEG for simplicity/consistency in cache)
        // Ensure cache directory exists
        $cache_dir = dirname($cache_path);
        if (!is_dir($cache_dir)) {
            if (!@mkdir($cache_dir, 0775, true)) { // Create recursively with permissions
                 error_log("create_thumbnail: Failed to create cache directory: {$cache_dir}");
                 imagedestroy($source_image); 
                 imagedestroy($thumb_image);
                 return false;
            }
        }

        $save_success = imagejpeg($thumb_image, $cache_path, 85); // Quality 85

        // Clean up resources
        imagedestroy($source_image);
        imagedestroy($thumb_image);

        if (!$save_success) {
             error_log("create_thumbnail: Failed to save thumbnail to: {$cache_path}");
             if (file_exists($cache_path)) @unlink($cache_path); // Delete partial/failed file
             return false;
        }
        
        return true;

    } catch (Throwable $e) {
        error_log("create_thumbnail: Exception while creating thumbnail for {$source_path} -> {$cache_path} : " . $e->getMessage());
        // Clean up potential resources on error
        if (isset($source_image) && is_resource($source_image)) imagedestroy($source_image);
        if (isset($thumb_image) && is_resource($thumb_image)) imagedestroy($thumb_image);
         if (file_exists($cache_path)) @unlink($cache_path); // Delete partial/failed file
        return false;
    }
}

// --- KIỂM TRA BAN ĐẦU ---
@error_log("API Step 11: Initial IMAGE_ROOT check");
if (!IMAGE_ROOT || !is_dir(IMAGE_ROOT) || !is_readable(IMAGE_ROOT)) {
    // Ensure appropriate permissions are set on the server.
    $error_msg = "Lỗi Server: Không thể truy cập thư mục ảnh gốc ('" . IMAGE_ROOT . "'). Check existence and permissions.";
    @error_log($error_msg);
    json_error($error_msg, 500);
}
@error_log("API Step 12: Initial IMAGE_ROOT check passed");

// --- Restore Router and Switch ---

// --- ROUTER XỬ LÝ ACTION ---
@error_log("API Step 13: Attempting to read action from REQUEST");
$action = $_REQUEST['action'] ?? ''; // Use REQUEST again to handle POST actions
@error_log("API Step 14: Action read as '{$action}'");

// --- Process search term (can come from GET) ---
$search_term = $_GET['search'] ?? null;
if ($search_term !== null) {
    $search_term = trim($search_term);
}
@error_log("API Step 15: Search term is '" . ($search_term ?? 'null') . "'");

@error_log("API Step 16: Entering switch statement");
switch ($action) {

    case 'list_dirs':
        try {
            $dirs_data = [];
            $all_dirs_temp = []; // Temporary array to hold all directories
            $iterator = new DirectoryIterator(IMAGE_ROOT);
            
            foreach ($iterator as $fileinfo) {
                if ($fileinfo->isDir() && !$fileinfo->isDot()) {
                    $dir_name = $fileinfo->getFilename();
                    // Collect all directories first
                    $all_dirs_temp[] = $dir_name;
                }
            }

            // Decide whether to filter or show random
            $dirs_to_process = [];
            if ($search_term !== null) {
                // Filter based on search term
                 foreach ($all_dirs_temp as $dir_name) {
                     if (mb_stripos($dir_name, $search_term, 0, 'UTF-8') !== false) {
                         $dirs_to_process[] = $dir_name;
                     }
                 }
                 error_log("[list_dirs] Search found " . count($dirs_to_process) . " directories for term '{$search_term}'");
            } else {
                // No search term: Shuffle and take up to 10 random
                shuffle($all_dirs_temp);
                $dirs_to_process = array_slice($all_dirs_temp, 0, 10); // Take first 10 after shuffling
                 error_log("[list_dirs] Initial load, showing " . count($dirs_to_process) . " random directories.");
            }

            // Process the selected directories (filtered or random)
            $final_dirs_data = [];
            
            foreach ($dirs_to_process as $dir_name) {
                 $dir_path_absolute = IMAGE_ROOT . DIRECTORY_SEPARATOR . $dir_name;
                 $count_file = $dir_path_absolute . '/.count';
                 $views = file_exists($count_file) ? (int)@file_get_contents($count_file) : 0;

                 // --- Thumbnail Logic --- 
                 $thumbnail_relative_path = null;
                 $original_image_relative_path = find_first_image_recursive($dir_path_absolute, $dir_name, $allowed_ext);
                 
                 if ($original_image_relative_path) {
                     $cache_hash = md5($original_image_relative_path); 
                     $cache_filename = $cache_hash . '.jpg';
                     $cache_filepath_relative = 'cache/thumbnails/' . $cache_filename;
                     $cache_filepath_absolute = __DIR__ . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'thumbnails' . DIRECTORY_SEPARATOR . $cache_filename;
                     $original_image_absolute_path = IMAGE_ROOT . DIRECTORY_SEPARATOR . $original_image_relative_path;

                     if (file_exists($cache_filepath_absolute)) {
                         $thumbnail_relative_path = $cache_filepath_relative; 
                     } else {
                          $create_success = create_thumbnail($original_image_absolute_path, $cache_filepath_absolute);
                          if (!$create_success) {
                              error_log("[list_dirs] Failed to create thumbnail for '{$dir_name}'. Setting path to null.");
                              $thumbnail_relative_path = null; 
                          }
                     }
                 } else {
                     error_log("[list_dirs] No original image found for '{$dir_name}'. Setting path to null.");
                     $thumbnail_relative_path = null; 
                 }
                 // --- End Thumbnail Logic ---

                 $final_dirs_data[] = [
                     'name' => $dir_name,
                     'thumbnail' => $thumbnail_relative_path, 
                     'views' => $views
                 ];
            }
            
            usort($final_dirs_data, fn($a, $b) => strnatcasecmp($a['name'], $b['name'])); 
            json_response(['directories' => $final_dirs_data]);

        } catch (Throwable $e) {
             error_log("[list_dirs] FATAL ERROR: " . $e->getMessage() . "\nStack Trace:\n" . $e->getTraceAsString());
             json_error("Không thể lấy danh sách album. Lỗi: " . $e->getMessage(), 500);
        }
        break;

    case 'list_sub_items':
        try {
            @error_log("[list_sub_items] Entering action."); // LSI LOG 1
            $dir_param = $_GET['dir'] ?? '';
            @error_log("[list_sub_items] dir param: '{$dir_param}'"); // LSI LOG 2
            
            @error_log("[list_sub_items] Sanitizing subdir..."); // LSI LOG 3
            $safe_relative_path = sanitize_subdir($dir_param);
            if ($safe_relative_path === null) {
                 @error_log("[list_sub_items] Invalid subdir after sanitization.");
                 json_error("Đường dẫn thư mục không hợp lệ.", 400);
            }
            @error_log("[list_sub_items] Sanitized path: '{$safe_relative_path}'"); // LSI LOG 4

            $full_path = IMAGE_ROOT . (empty($safe_relative_path) ? '' : DIRECTORY_SEPARATOR . $safe_relative_path);
            @error_log("[list_sub_items] Full path: '{$full_path}'"); // LSI LOG 5
            if (!is_dir($full_path)) {
                 @error_log("[list_sub_items] Directory does not exist: '{$full_path}'");
                 json_error("Thư mục không tồn tại.", 404);
            }
            
            $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
            $limit = isset($_GET['limit']) ? max(1, (int)$_GET['limit']) : 50; 
            $offset = ($page - 1) * $limit;
            @error_log("[list_sub_items] Pagination: page={$page}, limit={$limit}, offset={$offset}"); // LSI LOG 6

            // Increment view count only on first page load
            if ($page === 1 && !empty($safe_relative_path)) {
                @error_log("[list_sub_items] Incrementing view count for: '{$full_path}'"); // LSI LOG 7
                $count_file = $full_path . '/.count';
                $current_views = file_exists($count_file) ? (int)@file_get_contents($count_file) : 0;
                if (@file_put_contents($count_file, $current_views + 1) === false) {
                    @error_log("[list_sub_items] WARNING: Failed to write view count to: '{$count_file}'");
                }
            }

            // Check access
            if (!empty($safe_relative_path)) {
                @error_log("[list_sub_items] Checking folder access for: '{$safe_relative_path}'"); // LSI LOG 8
                $access = check_folder_access($safe_relative_path);
                if (!$access['authorized']) {
                    if (!empty($access['password_required'])) {
                        @error_log("[list_sub_items] Access denied, password required for: '{$safe_relative_path}'");
                        json_response(['password_required' => true, 'folder' => $safe_relative_path], 401);
                    }
                    $error_msg = isset($access['error']) ? $access['error'] : 'Không được phép truy cập thư mục này.';
                    @error_log("[list_sub_items] Access denied for '{$safe_relative_path}': {$error_msg}");
                    json_error($error_msg, 403);
                }
                 @error_log("[list_sub_items] Access granted for: '{$safe_relative_path}'"); // LSI LOG 9
            }

            // List items
            $subfolders_data = [];
            $all_image_names = []; // Collect all image filenames first
            $iterator = new DirectoryIterator($full_path);

            foreach ($iterator as $fileinfo) {
                if ($fileinfo->isDot()) continue;
                $item_name = $fileinfo->getFilename();
                $item_relative_path = (empty($safe_relative_path) ? '' : $safe_relative_path . '/') . $item_name;

                if ($fileinfo->isDir()) {
                    if ($page === 1) { // Only list subfolders on the first page
                        $thumbnail_path = find_first_image_recursive($fileinfo->getPathname(), $item_relative_path, $allowed_ext);
                        $subfolders_data[] = [
                            'name' => $item_relative_path,
                            'displayName' => $item_name,
                            'thumbnail' => $thumbnail_path
                         ];
                     }
                } elseif ($fileinfo->isFile() && in_array(strtolower($fileinfo->getExtension()), $allowed_ext, true)) {
                     $all_image_names[] = $item_name; // Collect all image names
                }
            } // End foreach iterator

            // Sort items
            if ($page === 1) {
                 usort($subfolders_data, fn($a, $b) => strcasecmp($a['displayName'], $b['displayName']));
            }
            sort($all_image_names, SORT_STRING | SORT_FLAG_CASE);
            $total_images = count($all_image_names);

            // --- Image Metadata Caching Logic --- 
            $all_images_metadata_cache = null;
            $metadata_file = $full_path . DIRECTORY_SEPARATOR . '.img_metadata.json';
            
            // Try reading cache file
            if (file_exists($metadata_file)) {
                $json_content = @file_get_contents($metadata_file);
                if ($json_content !== false) {
                    $decoded_data = @json_decode($json_content, true);
                    if (is_array($decoded_data)) { // Check if decode was successful and is an array
                         $all_images_metadata_cache = $decoded_data;
                         error_log("[list_sub_items] Successfully read metadata cache for: {$safe_relative_path}");
                    } else {
                         error_log("[list_sub_items] WARNING: Failed to decode JSON metadata cache for: {$safe_relative_path}");
                    }
                } else {
                     error_log("[list_sub_items] WARNING: Failed to read metadata cache file (exists but unreadable?): {$metadata_file}");
                }
            }

            // If cache is missing or invalid, generate it
            if ($all_images_metadata_cache === null) {
                 error_log("[list_sub_items] Metadata cache miss or invalid. Generating for: {$safe_relative_path}");
                 $all_images_metadata_cache = [];
                 foreach ($all_image_names as $img_name) { // Iterate through ALL image names
                    $img_path_absolute = $full_path . DIRECTORY_SEPARATOR . $img_name;
                    $width = 0;
                    $height = 0;
                    $image_size = @getimagesize($img_path_absolute);
                    if ($image_size !== false) {
                        $width = $image_size[0];
                        $height = $image_size[1];
                    } else {
                        error_log("[list_sub_items] WARNING: Failed to get image size during cache generation for: {$img_path_absolute}");
                    }
                    $all_images_metadata_cache[$img_name] = ['width' => $width, 'height' => $height];
                 }
                 
                 // Try to write the cache file
                 $json_to_write = json_encode($all_images_metadata_cache, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                 if ($json_to_write !== false) {
                    if (@file_put_contents($metadata_file, $json_to_write) !== false) {
                         error_log("[list_sub_items] Successfully wrote metadata cache file: {$metadata_file}");
                    } else {
                         error_log("[list_sub_items] ERROR: Failed to write metadata cache file (permissions?): {$metadata_file}");
                    }
                 } else {
                     error_log("[list_sub_items] ERROR: Failed to encode metadata to JSON for: {$safe_relative_path}");
                 }
            }
            // --- End Image Metadata Caching Logic ---

            // Paginate image names *after* sorting
            $image_names_for_page = array_slice($all_image_names, $offset, $limit);

            // Build the response metadata for the current page using the cached data
            $images_metadata_for_page = [];
            foreach ($image_names_for_page as $image_name) {
                $metadata = $all_images_metadata_cache[$image_name] ?? ['width' => 0, 'height' => 0]; // Use cached data, default if somehow missing
                $images_metadata_for_page[] = [
                    'name' => $image_name,
                    'width' => $metadata['width'],
                    'height' => $metadata['height']
                ];
            }
            
            // Build final response
            $response_data = [
                 'images' => $images_metadata_for_page, 
                 'totalImages' => $total_images,
                 'currentPage' => $page,
                 'limit' => $limit
            ];
            if ($page === 1) {
                $response_data['subfolders'] = $subfolders_data;
            }

            json_response($response_data);

        } catch (Throwable $e) {
            error_log("[list_sub_items] FATAL ERROR: " . $e->getMessage() . "\nStack Trace:\n" . $e->getTraceAsString());
            json_error("Không thể đọc nội dung thư mục. Lỗi: " . $e->getMessage(), 500); 
        }
        break;

    case 'download_zip':
        // NOTE: This action outputs a file directly, NOT JSON.
        // It needs to handle output buffering carefully.
        $dir_param = $_GET['dir'] ?? null;
        $safe_relative_path = null;
        $full_path = null;
        $zip_filename = 'download.zip';
        $temp_zip_file = null;

        try {
             @error_log("[download_zip] Entering action. dir='{$dir_param}'"); // DZ LOG 1

             // --- Validation ---
             if ($dir_param === null) throw new Exception("Thiếu tham số thư mục.", 400);
             
             $safe_relative_path = sanitize_subdir($dir_param);
             if ($safe_relative_path === null) throw new Exception("Đường dẫn thư mục không hợp lệ.", 400);
             if (empty($safe_relative_path)) throw new Exception("Không thể tải toàn bộ thư viện.", 400);
             
             $full_path = IMAGE_ROOT . DIRECTORY_SEPARATOR . $safe_relative_path;
             if (!is_dir($full_path)) throw new Exception("Thư mục không tồn tại.", 404);
             @error_log("[download_zip] Validated path: '{$full_path}'"); // DZ LOG 2

             // --- Access Check ---
             @error_log("[download_zip] Checking access for: '{$safe_relative_path}'"); // DZ LOG 3
             $access = check_folder_access($safe_relative_path);
             if (!$access['authorized']) {
                 throw new Exception("Yêu cầu xác thực để tải thư mục này.", 403);
             }
             @error_log("[download_zip] Access granted."); // DZ LOG 4

             // --- Check Zip Extension ---
             if (!extension_loaded('zip')) {
                 @error_log("[download_zip] PHP extension 'zip' is not enabled.");
                 throw new Exception("Tính năng nén file ZIP chưa được kích hoạt trên server.", 501);
             }
             @error_log("[download_zip] PHP zip extension is enabled."); // DZ LOG 5

             // --- Increment Download Count --- 
             $download_count_file = $full_path . '/.download_count';
             $current_downloads = file_exists($download_count_file) ? (int)@file_get_contents($download_count_file) : 0;
             if (@file_put_contents($download_count_file, $current_downloads + 1) === false) {
                 @error_log("[download_zip] WARNING: Failed to write download count to: '{$download_count_file}'");
             } else {
                 @error_log("[download_zip] Incremented download count."); // DZ LOG 6
             }

             // --- Create Zip --- 
             $zip = new ZipArchive();
             $zip_filename = preg_replace('/[^a-zA-Z0-9_-]+/', '_', basename($safe_relative_path)) . '.zip';
             $temp_zip_file = tempnam(sys_get_temp_dir(), 'photozip_');
             if ($temp_zip_file === false) {
                  @error_log("[download_zip] Failed to create temp file using tempnam(). Check sys_temp_dir permissions.");
                  throw new Exception("Không thể tạo file nén tạm.", 500);
             }
             @error_log("[download_zip] Created temp file: '{$temp_zip_file}'"); // DZ LOG 7

             if ($zip->open($temp_zip_file, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
                 @unlink($temp_zip_file); // Clean up failed temp file
                 @error_log("[download_zip] Cannot open temp zip file for writing: {$temp_zip_file}");
                 throw new Exception("Không thể mở file nén tạm để ghi.", 500);
             }
             @error_log("[download_zip] Opened temp zip file successfully."); // DZ LOG 8

             // Add files recursively
             $files_added_count = 0;
             $files = new RecursiveIteratorIterator(
                 new RecursiveDirectoryIterator($full_path, RecursiveDirectoryIterator::SKIP_DOTS),
                 RecursiveIteratorIterator::LEAVES_ONLY
             );
             @error_log("[download_zip] Starting to add files..."); // DZ LOG 9
             foreach ($files as $name => $file) {
                 if (!$file->isDir()) {
                     $filePath = $file->getRealPath();
                     $relativePath = substr($filePath, strlen($full_path) + 1);
                     if ($zip->addFile($filePath, $relativePath)) {
                         $files_added_count++;
                         // @error_log("[download_zip] Added: {$relativePath}"); // Too noisy usually
                     } else {
                          @error_log("[download_zip] WARNING: Failed to add file to zip: " . $filePath . " (Relative: {$relativePath})");
                     }
                 }
             }
             @error_log("[download_zip] Finished adding files. Count: {$files_added_count}"); // DZ LOG 10

             $status = $zip->close();
             if ($status === false) {
                 @unlink($temp_zip_file);
                 @error_log("[download_zip] Failed to close zip archive.");
                 throw new Exception("Không thể hoàn tất file nén.", 500);
             }
              @error_log("[download_zip] Closed zip archive successfully."); // DZ LOG 11

             // --- Send File --- 
             if ($files_added_count > 0 && file_exists($temp_zip_file)) {
                 @error_log("[download_zip] Preparing to send zip file '{$zip_filename}' (Size: " . filesize($temp_zip_file) . ")."); // DZ LOG 12
                 
                 // !!! Crucial: Clear any output buffer BEFORE sending headers !!!
                 if (ob_get_level()) ob_end_clean(); 
                 
                 header('Content-Type: application/zip');
                 header('Content-Disposition: attachment; filename="' . $zip_filename . '"');
                 header('Content-Length: ' . filesize($temp_zip_file));
                 header('Pragma: no-cache'); 
                 header('Expires: 0');
                 header('Cache-Control: must-revalidate'); // Added cache control

                 // Send the file
                 readfile($temp_zip_file);
                 @error_log("[download_zip] File sent successfully."); // DZ LOG 13

                 unlink($temp_zip_file); // Delete temp file after sending
                 exit; // IMPORTANT: Stop script execution after sending file
             } else {
                 @error_log("[download_zip] No files added or temp file missing. Added: {$files_added_count}, Exists: " . file_exists($temp_zip_file));
                 throw new Exception("Không có file nào hợp lệ trong thư mục để nén.", 404);
             }

        } catch (Throwable $e) {
             // Clean up temp file if it exists and an error occurred
             if ($temp_zip_file && file_exists($temp_zip_file)) {
                 @unlink($temp_zip_file);
             }
             
             $http_code = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 500; // Use exception code if it's a valid HTTP status
             $error_message = $e->getMessage();
             @error_log("[download_zip] FATAL ERROR (HTTP {$http_code}): {$error_message}\nStack Trace:\n" . $e->getTraceAsString()); // DZ LOG 14

             // !!! Crucial: Clear buffer before sending error output !!!
             if (ob_get_level()) ob_end_clean();
             
             // Send plain text error for direct download links
             http_response_code($http_code);
             header('Content-Type: text/plain; charset=utf-8');
             die("Lỗi Server khi tạo file ZIP: " . htmlspecialchars($error_message)); // Use die() for non-JSON output
        }
        break;

    case 'verify_password':
        @error_log("[verify_password] Entering action."); // VP LOG 1
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
             @error_log("[verify_password] Invalid method: {$_SERVER['REQUEST_METHOD']}");
             json_error("Phương thức không hợp lệ.", 405);
        }

        $folder_param = $_POST['folder'] ?? null;
        $password_attempt = $_POST['password'] ?? null;
        @error_log("[verify_password] Received folder='{$folder_param}', password provided=" . ($password_attempt !== null ? 'yes' : 'no')); // VP LOG 2

        @error_log("[verify_password] Sanitizing subdir: '{$folder_param}'"); // VP LOG 3
        $safe_relative_path = sanitize_subdir($folder_param);

        if ($safe_relative_path === null || $password_attempt === null || $password_attempt === '') { // Also check password presence
            @error_log("[verify_password] Invalid input: path='{$safe_relative_path}', password empty?=" . empty($password_attempt));
            json_error("Thiếu thông tin thư mục hoặc mật khẩu.", 400);
        }
        @error_log("[verify_password] Sanitized path: '{$safe_relative_path}'"); // VP LOG 4

        try {
            @error_log("[verify_password] Preparing DB query for: '{$safe_relative_path}'"); // VP LOG 5
            $stmt = $pdo->prepare("SELECT password_hash FROM folder_passwords WHERE folder_name = ? LIMIT 1");
            $stmt->execute([$safe_relative_path]);
            $row = $stmt->fetch();
            @error_log("[verify_password] DB query executed. Row found: " . ($row ? 'yes' : 'no')); // VP LOG 6

            if ($row) {
                $correct_hash = $row['password_hash'];
                 @error_log("[verify_password] Hash found. Verifying password..."); // VP LOG 7
                 if (password_verify($password_attempt, $correct_hash)) {
                    @error_log("[verify_password] Password VERIFIED for '{$safe_relative_path}'"); // VP LOG 8
                    $_SESSION['authorized_folders'][$safe_relative_path] = true;
                    json_response(['authorized' => true]);
                 } else {
                    @error_log("[verify_password] Password INCORRECT for '{$safe_relative_path}'"); // VP LOG 9
                    json_response(['authorized' => false, 'error' => 'Mật khẩu không đúng.'], 401);
                 }
            } else {
                // Should not happen if password prompt was shown, but handle defensively
                @error_log("[verify_password] No password hash found in DB for '{$safe_relative_path}', but verification was attempted?"); // VP LOG 10
                json_response(['authorized' => false, 'error' => 'Thư mục này không yêu cầu mật khẩu (lỗi logic?).'], 400); 
            }
        } catch (Throwable $e) {
            @error_log("[verify_password] FATAL ERROR for '{$safe_relative_path}': " . $e->getMessage() . "\nStack Trace:\n" . $e->getTraceAsString()); // VP LOG 11
            json_error("Lỗi server khi xác thực mật khẩu. " . $e->getMessage(), 500);
        }
        break;

    case 'admin_list_folders':
        @error_log("Inside SWITCH, action: admin_list_folders (LOG 1)");
        if (empty($_SESSION['admin_logged_in'])) {
             error_log("Admin not logged in for admin_list_folders.");
             json_error("Yêu cầu đăng nhập Admin.", 403);
        }
        $admin_search_term = $search_term; // Use the already processed search_term
        error_log("Admin search term: '" . ($admin_search_term ?? 'null') . "' (LOG 2)");
        
        try {
            $folders_data = [];
            $protected_status = [];
            error_log("Querying protected folders... (LOG 3)");
            $stmt = $pdo->query("SELECT folder_name FROM folder_passwords");
            while ($row = $stmt->fetchColumn()) {
                $protected_status[$row] = true;
            }
            error_log("Finished querying protected folders. Found: " . count($protected_status) . " (LOG 4)");

            error_log("Starting DirectoryIterator for IMAGE_ROOT: " . IMAGE_ROOT . " (LOG 5)");
            $iterator = new DirectoryIterator(IMAGE_ROOT);
            
            foreach ($iterator as $fileinfo) {
                if ($fileinfo->isDot()) continue;
                
                if ($fileinfo->isDir()) {
                    $dir_name = $fileinfo->getFilename();
                    error_log("Processing directory: {$dir_name} (LOG 6)");
                    if ($admin_search_term !== null && mb_stripos($dir_name, $admin_search_term, 0, 'UTF-8') === false) {
                        error_log("Skipping '{$dir_name}' due to search term.");
                        continue; 
                    }
                    $dir_path = $fileinfo->getPathname();
                    error_log("Getting stats for: {$dir_path} (LOG 7)");
                    $stats = getFolderStats($dir_path); 
                    error_log("Stats for '{$dir_name}': Views={$stats['views']}, Downloads={$stats['downloads']} (LOG 8)");
                    $folders_data[] = [
                        'name' => $dir_name,
                        'protected' => isset($protected_status[$dir_name]),
                        'views' => $stats['views'],
                        'downloads' => $stats['downloads']
                    ];
                } else {
                     // error_log("Skipping non-directory item: " . $fileinfo->getFilename()); // LOG 9 (Optional)
                }
            } // End foreach
            
            error_log("Finished iterating directories. Sorting... (LOG 10)");
            usort($folders_data, fn($a, $b) => strnatcasecmp($a['name'], $b['name'])); 
            
            error_log("Sending JSON response for admin_list_folders. (LOG 11)");
            json_response(['folders' => $folders_data]);
            
        } catch (Throwable $e) { 
            error_log("FATAL ERROR in admin_list_folders: " . $e->getMessage() . "\nStack Trace:\n" . $e->getTraceAsString() . " (LOG 12)");
            json_error("Không thể lấy danh sách thư mục quản lý. Lỗi: " . $e->getMessage(), 500);
        }
        break;

    case 'admin_set_password':
        @error_log("Inside SWITCH, action: admin_set_password");
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            error_log("admin_set_password: Invalid method - {$_SERVER['REQUEST_METHOD']}");
            json_error("Phương thức không hợp lệ.", 405);
        }
        if (empty($_SESSION['admin_logged_in'])) {
            error_log("admin_set_password: Admin not logged in.");
            json_error("Yêu cầu đăng nhập Admin.", 403);
        }

        $folder_param = $_POST['folder'] ?? '';
        $password = $_POST['password'] ?? '';
        $safe_folder_name = basename(str_replace('\\', '/', $folder_param)); 

        error_log("admin_set_password: Received folder='{$folder_param}' (Safe='{$safe_folder_name}'), password provided=" . !empty($password));

        if (empty($safe_folder_name) || !is_dir(IMAGE_ROOT . DIRECTORY_SEPARATOR . $safe_folder_name)) {
             error_log("admin_set_password: Invalid folder name or folder does not exist.");
             json_error("Tên thư mục cấp 1 không hợp lệ hoặc không tồn tại.", 400);
        }
         if ($password === '') {
             error_log("admin_set_password: Password cannot be empty.");
             json_error("Mật khẩu không được để trống.", 400);
         }

        try {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            if ($hash === false) {
                 error_log("admin_set_password: password_hash() failed.");
                 throw new Exception("Không thể tạo hash mật khẩu.");
            }
            $sql = "INSERT OR REPLACE INTO folder_passwords (folder_name, password_hash) VALUES (?, ?)";
            $stmt = $pdo->prepare($sql);
            if ($stmt->execute([$safe_folder_name, $hash])) {
                unset($_SESSION['authorized_folders'][$safe_folder_name]);
                error_log("admin_set_password: Successfully set password for '{$safe_folder_name}'");
                json_response(['success' => true, 'message' => "Đặt/Cập nhật mật khẩu thành công cho thư mục '" . htmlspecialchars($safe_folder_name) . "'."]);
            } else {
                 error_log("admin_set_password: DB execute failed.");
                 throw new Exception("Execute query failed.");
            }
        } catch (Throwable $e) {
            error_log("admin_set_password: Error for '{$safe_folder_name}': " . $e->getMessage());
            json_error("Lỗi server khi đặt mật khẩu. " . $e->getMessage(), 500);
        }
        break;

    case 'admin_remove_password':
        @error_log("[admin_remove_password] Entering action."); // RP LOG 1
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
             @error_log("[admin_remove_password] Invalid method: {$_SERVER['REQUEST_METHOD']}");
             json_error("Phương thức không hợp lệ.", 405);
        }
        if (empty($_SESSION['admin_logged_in'])) {
            @error_log("[admin_remove_password] Admin not logged in.");
            json_error("Yêu cầu đăng nhập Admin.", 403);
        }

        $folder_param = $_POST['folder'] ?? null;
        @error_log("[admin_remove_password] Received folder='{$folder_param}'"); // RP LOG 2
        
        // Use the same validation as set_password
        $safe_folder_name = basename(str_replace('\\', '/', $folder_param));

        if (empty($safe_folder_name)) { // Also check folder existence? No, just delete entry if exists.
             @error_log("[admin_remove_password] Invalid folder name parameter: '{$folder_param}' (Safe='{$safe_folder_name}')");
             json_error("Tên thư mục không hợp lệ.", 400);
        }
        @error_log("[admin_remove_password] Validated folder name: '{$safe_folder_name}'"); // RP LOG 3

        try {
            @error_log("[admin_remove_password] Preparing DB DELETE query for: '{$safe_folder_name}'"); // RP LOG 4
            $sql = "DELETE FROM folder_passwords WHERE folder_name = ?";
            $stmt = $pdo->prepare($sql);
            @error_log("[admin_remove_password] Executing DB DELETE..."); // RP LOG 5
            $success = $stmt->execute([$safe_folder_name]);
            $affected_rows = $stmt->rowCount(); // Check how many rows were deleted
            @error_log("[admin_remove_password] DB DELETE executed. Success: " . ($success ? 'yes' : 'no') . ", Rows affected: {$affected_rows}"); // RP LOG 6

            @error_log("[admin_remove_password] Unsetting session key for: '{$safe_folder_name}'"); // RP LOG 7
            unset($_SESSION['authorized_folders'][$safe_folder_name]);
            
            @error_log("[admin_remove_password] Sending success response."); // RP LOG 8
            json_response(['success' => true, 'message' => "Đã xóa mật khẩu (nếu có) cho thư mục '" . htmlspecialchars($safe_folder_name) . "'. Bị ảnh hưởng: {$affected_rows} dòng."]);
        } catch (Throwable $e) {
            @error_log("[admin_remove_password] FATAL ERROR for '{$safe_folder_name}': " . $e->getMessage() . "\nStack Trace:\n" . $e->getTraceAsString()); // RP LOG 9
            json_error("Lỗi server khi xóa mật khẩu. " . $e->getMessage(), 500);
        }
        break;

    default:
        @error_log("Inside SWITCH, action: default (Invalid action: '{$action}')");
        json_error("Hành động không hợp lệ: '{$action}'.", 400);
        break;
}
@error_log("API Step 17: Switch statement finished or bypassed."); // Should normally not be reached for valid actions

// Clean up buffer if script reaches end without exit (should not happen)
ob_end_flush(); 
?>