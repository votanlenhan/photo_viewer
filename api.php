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
} catch (Throwable $e) { // Catch any error/exception during include
    error_log("FATAL ERROR during DB connection: " . $e->getMessage());
    http_response_code(500);
    ob_end_clean();
    echo json_encode(['error' => 'Lỗi kết nối cơ sở dữ liệu.', 'details' => $e->getMessage()]);
    exit;
}

// --- Định nghĩa Hằng số và Biến toàn cục ---
// SECURITY: Ensure IMAGE_ROOT is correctly configured and not pointing outside intended scope.
try {
    define('IMAGE_ROOT', realpath(__DIR__ . '/images'));
    if (!IMAGE_ROOT) {
         throw new Exception("Failed to resolve IMAGE_ROOT path. Check if 'images' directory exists and permissions.");
    }
} catch (Throwable $e) {
    error_log("FATAL ERROR defining IMAGE_ROOT: " . $e->getMessage());
    http_response_code(500);
    ob_end_clean();
    echo json_encode(['error' => 'Lỗi cấu hình đường dẫn ảnh.', 'details' => $e->getMessage()]);
    exit;
}

// OPTIMIZATION: Consider if allowed_ext needs to be dynamic or configurable.
$allowed_ext = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp'];

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
    try {
        // Check read permissions before iterating
        if (!is_readable($start_path)) {
            error_log("[find_thumb] Directory not readable: '{$start_path}'");
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
                return $found_thumb;
            }
            // Priority 2: Remember the first subdirectory encountered
            if ($first_sub_dir_path === null && $fileinfo->isDir()) {
                $first_sub_dir_path = $fileinfo->getPathname();
                $first_sub_dir_name = $fileinfo->getFilename();
            }
        }

        // Priority 3: If no direct image found, search in the first subdirectory recursively
        if ($first_sub_dir_path !== null) {
            return find_first_image_recursive($first_sub_dir_path, $base_folder_name . '/' . $first_sub_dir_name, $allowed_ext);
        } else {
        }

    } catch (Throwable $e) { // Catch specific exceptions if needed (e.g., UnexpectedValueException)
        error_log("[find_thumb] ERROR searching in {$start_path}: " . $e->getMessage());
    }
    return null; // No image found
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

// --- KIỂM TRA BAN ĐẦU ---
if (!IMAGE_ROOT || !is_dir(IMAGE_ROOT) || !is_readable(IMAGE_ROOT)) {
    // Ensure appropriate permissions are set on the server.
    $error_msg = "Lỗi Server: Không thể truy cập thư mục ảnh gốc ('" . IMAGE_ROOT . "'). Check existence and permissions.";
    error_log($error_msg);
    json_error($error_msg, 500);
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
            } else {
                // No search term: Shuffle and take up to 10 random
                shuffle($all_dirs_temp);
                $dirs_to_process = array_slice($all_dirs_temp, 0, 10); // Take first 10 after shuffling
            }

            // Process the selected directories (filtered or random)
            $final_dirs_data = [];
            $folder_stats = []; // Map folder_name => stats

            // Fetch stats for the directories to process in one query
            if (!empty($dirs_to_process)) {
                try {
                    $placeholders = implode(',', array_fill(0, count($dirs_to_process), '?'));
                    $sql = "SELECT folder_name, views FROM folder_stats WHERE folder_name IN ({$placeholders})";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($dirs_to_process);
                    $folder_stats = $stmt->fetchAll(PDO::FETCH_KEY_PAIR); // folder_name => views
                } catch (PDOException $e) {
                    error_log("[list_dirs] Failed to fetch folder stats: " . $e->getMessage());
                    // Continue without stats if query fails
                }
            }
            
            foreach ($dirs_to_process as $dir_name) {
                 $dir_path_absolute = IMAGE_ROOT . DIRECTORY_SEPARATOR . $dir_name;
                 $views = $folder_stats[$dir_name] ?? 0; // Get views from DB result, default 0

                 // --- Thumbnail Logic --- 
                 $thumbnail_relative_path = null;
                 $original_image_relative_path = find_first_image_recursive($dir_path_absolute, $dir_name, $allowed_ext);
                 $thumb_size = 150; // Explicitly define size for directory thumbs
                 
                 if ($original_image_relative_path) {
                     $cache_hash = md5($original_image_relative_path); 
                     $cache_filename = $cache_hash . '.jpg';
                     $cache_filepath_relative = 'cache/thumbnails/' . $thumb_size . '/' . $cache_filename;
                     $cache_filepath_absolute = __DIR__ . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'thumbnails' . DIRECTORY_SEPARATOR . $thumb_size . DIRECTORY_SEPARATOR . $cache_filename;
                     $original_image_absolute_path = IMAGE_ROOT . DIRECTORY_SEPARATOR . $original_image_relative_path; // Need this again

                     // Check if the cached thumbnail exists
                     if (file_exists($cache_filepath_absolute)) {
                         $thumbnail_relative_path = $cache_filepath_relative; 
                     } else {
                         // Attempt to create thumbnail on-the-fly
                         error_log("[list_dirs] Thumbnail MISSING for '{$dir_name}'. Attempting on-the-fly creation...");
                         if (is_readable($original_image_absolute_path)) { 
                             if (create_thumbnail($original_image_absolute_path, $cache_filepath_absolute, $thumb_size)) {
                                  $thumbnail_relative_path = $cache_filepath_relative;
                                  // Log creation success already happens inside create_thumbnail
                             } else {
                                  // Log creation failure already happens inside create_thumbnail
                                  // $thumbnail_relative_path remains null
                             }
                         } else {
                              error_log("[list_dirs] WARNING: Original image not readable, cannot create thumbnail: {$original_image_absolute_path}");
                              // $thumbnail_relative_path remains null
                         }
                     }
                 } else {
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
            $dir_param = $_GET['dir'] ?? '';
            
            $safe_relative_path = sanitize_subdir($dir_param);
            if ($safe_relative_path === null) {
                 json_error("Đường dẫn thư mục không hợp lệ.", 400);
            }

            $full_path = IMAGE_ROOT . (empty($safe_relative_path) ? '' : DIRECTORY_SEPARATOR . $safe_relative_path);
            if (!is_dir($full_path)) {
                 json_error("Thư mục không tồn tại.", 404);
            }
            
            $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
            $limit = isset($_GET['limit']) ? max(1, (int)$_GET['limit']) : 50; 
            $offset = ($page - 1) * $limit;

            // Increment view count only on first page load
            if ($page === 1 && !empty($safe_relative_path)) {
                try {
                    $sql = "INSERT INTO folder_stats (folder_name, views) VALUES (?, 1) 
                            ON CONFLICT(folder_name) DO UPDATE SET views = views + 1";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([$safe_relative_path]);
                } catch (PDOException $e) {
                    error_log("[list_sub_items] WARNING: Failed to increment view count in DB for '{$safe_relative_path}': " . $e->getMessage());
                }
            }

            // Check access
            if (!empty($safe_relative_path)) {
                $access = check_folder_access($safe_relative_path);
                if (!$access['authorized']) {
                    if (!empty($access['password_required'])) {
                        json_response(['password_required' => true, 'folder' => $safe_relative_path], 401);
                    }
                    $error_msg = isset($access['error']) ? $access['error'] : 'Không được phép truy cập thư mục này.';
                    json_error($error_msg, 403);
                }
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
            $total_images = count($all_image_names); // Total images based on initial scan

            // --- Removed Image Metadata Caching Logic --- 

            // Paginate image names *using initially scanned list* after sorting
            $image_names_for_page = array_slice($all_image_names, $offset, $limit);

            // Build the response metadata for the current page, getting image size on-the-fly
            $images_metadata_for_page = [];
            foreach ($image_names_for_page as $image_name) {
                // Get image size directly
                $width = 0;
                $height = 0;
                $img_path_absolute = $full_path . DIRECTORY_SEPARATOR . $image_name;
                $image_size = false;
                try {
                     if (is_readable($img_path_absolute)) {
                         $image_size = @getimagesize($img_path_absolute);
                     } else {
                          error_log("[list_sub_items] WARNING: Image not readable, cannot get size: {$img_path_absolute}");
                     }
                } catch (Exception $e) {
                     error_log("[list_sub_items] Exception getting image size for {$img_path_absolute}: " . $e->getMessage());
                }
                if ($image_size !== false) {
                    $width = $image_size[0];
                    $height = $image_size[1];
                } else {
                    // Logged above if specific error occurred
                }
                
                // --- Thumbnail Generation/Retrieval Logic (Restored: Check + Create) ---
                $original_relative_path = (empty($safe_relative_path) ? '' : $safe_relative_path . '/') . $image_name;
                $original_absolute_path = $img_path_absolute; // Already have absolute path
                $cache_hash = md5($original_relative_path);
                $cache_filename = $cache_hash . '.jpg'; 
                $generated_thumb_path = null; 
                $thumb_size = 750; 
                $thumbnail_relative_cache_path = 'cache/thumbnails/' . $thumb_size . '/' . $cache_filename;
                $thumbnail_absolute_cache_path = __DIR__ . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'thumbnails' . DIRECTORY_SEPARATOR . $thumb_size . DIRECTORY_SEPARATOR . $cache_filename;

                // Check if the cached thumbnail exists
                if (file_exists($thumbnail_absolute_cache_path)) {
                    $generated_thumb_path = $thumbnail_relative_cache_path;
                } else {
                    // Attempt to create thumbnail on-the-fly
                    error_log("[list_sub_items] Thumbnail MISSING for '{$original_relative_path}'. Attempting on-the-fly creation...");
                    if (is_readable($original_absolute_path)) {
                        if (create_thumbnail($original_absolute_path, $thumbnail_absolute_cache_path, $thumb_size)) {
                            $generated_thumb_path = $thumbnail_relative_cache_path;
                            // Log creation success already happens inside create_thumbnail
                        } else {
                             // Log creation failure already happens inside create_thumbnail
                             // $generated_thumb_path remains null
                        }
                    } else {
                        error_log("[list_sub_items] WARNING: Original image not readable, cannot create thumbnail: {$original_absolute_path}");
                         // $generated_thumb_path remains null
                    }
                }
                // --- End Thumbnail Logic ---
                
                $images_metadata_for_page[] = [
                    'name' => $image_name,
                    'width' => $width, // Width obtained directly
                    'height' => $height, // Height obtained directly
                    'thumb_path' => $generated_thumb_path
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
             $safe_relative_path = sanitize_subdir($dir_param);
             if ($safe_relative_path === null) throw new Exception("Đường dẫn thư mục không hợp lệ.", 400);
             if (empty($safe_relative_path)) throw new Exception("Không thể tải toàn bộ thư viện.", 400);
             
             $full_path = IMAGE_ROOT . DIRECTORY_SEPARATOR . $safe_relative_path;
             if (!is_dir($full_path)) throw new Exception("Thư mục không tồn tại.", 404);

             // --- Access Check ---
             $access = check_folder_access($safe_relative_path);
             if (!$access['authorized']) {
                 throw new Exception("Yêu cầu xác thực để tải thư mục này.", 403);
             }

             // --- Check Zip Extension ---
             if (!extension_loaded('zip')) {
                 error_log("[download_zip] PHP extension 'zip' is not enabled.");
                 throw new Exception("Tính năng nén file ZIP chưa được kích hoạt trên server.", 501);
             }

             // --- Increment Download Count in DB --- 
             try {
                 $sql = "INSERT INTO folder_stats (folder_name, downloads) VALUES (?, 1) 
                         ON CONFLICT(folder_name) DO UPDATE SET downloads = downloads + 1";
                 $stmt = $pdo->prepare($sql);
                 $stmt->execute([$safe_relative_path]);
             } catch (PDOException $e) {
                 error_log("[download_zip] WARNING: Failed to increment download count in DB for '{$safe_relative_path}': " . $e->getMessage());
             }

             // --- Create Zip --- 
             $zip = new ZipArchive();
             $zip_filename = preg_replace('/[^a-zA-Z0-9_-]+/', '_', basename($safe_relative_path)) . '.zip';
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

             // Add files recursively
             $files_added_count = 0;
             $files = new RecursiveIteratorIterator(
                 new RecursiveDirectoryIterator($full_path, RecursiveDirectoryIterator::SKIP_DOTS),
                 RecursiveIteratorIterator::LEAVES_ONLY
             );
             foreach ($files as $name => $file) {
                 if (!$file->isDir()) {
                     $filePath = $file->getRealPath();
                     $relativePath = substr($filePath, strlen($full_path) + 1);
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
                 if (ob_get_level()) ob_end_clean(); 
                 
                 header('Content-Type: application/zip');
                 header('Content-Disposition: attachment; filename="' . $zip_filename . '"');
                 header('Content-Length: ' . filesize($temp_zip_file));
                 header('Pragma: no-cache'); 
                 header('Expires: 0');
                 header('Cache-Control: must-revalidate'); // Added cache control

                 // Send the file
                 readfile($temp_zip_file);

                 unlink($temp_zip_file); // Delete temp file after sending
                 exit; // IMPORTANT: Stop script execution after sending file
             } else {
                 error_log("[download_zip] No files added or temp file missing. Added: {$files_added_count}, Exists: " . file_exists($temp_zip_file));
                 throw new Exception("Không có file nào hợp lệ trong thư mục để nén.", 404);
             }

        } catch (Throwable $e) {
             // Clean up temp file if it exists and an error occurred
             if ($temp_zip_file && file_exists($temp_zip_file)) {
                 @unlink($temp_zip_file);
             }
             
             $http_code = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 500; // Use exception code if it's a valid HTTP status
             $error_message = $e->getMessage();
             error_log("[download_zip] FATAL ERROR (HTTP {$http_code}): {$error_message}\nStack Trace:\n" . $e->getTraceAsString()); // DZ LOG 14

             // !!! Crucial: Clear buffer before sending error output !!!
             if (ob_get_level()) ob_end_clean();
             
             // Send plain text error for direct download links
             http_response_code($http_code);
             header('Content-Type: text/plain; charset=utf-8');
             die("Lỗi Server khi tạo file ZIP: " . htmlspecialchars($error_message)); // Use die() for non-JSON output
        }
        break;

    case 'verify_password':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
             json_error("Phương thức không hợp lệ.", 405);
        }

        $folder_param = $_POST['folder'] ?? null;
        $password_attempt = $_POST['password'] ?? null;

        $safe_relative_path = sanitize_subdir($folder_param);

        if ($safe_relative_path === null || $password_attempt === null || $password_attempt === '') { // Also check password presence
            json_error("Thiếu thông tin thư mục hoặc mật khẩu.", 400);
        }

        try {
            $stmt = $pdo->prepare("SELECT password_hash FROM folder_passwords WHERE folder_name = ? LIMIT 1");
            $stmt->execute([$safe_relative_path]);
            $row = $stmt->fetch();

            if ($row) {
                $correct_hash = $row['password_hash'];
                 if (password_verify($password_attempt, $correct_hash)) {
                    $_SESSION['authorized_folders'][$safe_relative_path] = true;
                    json_response(['authorized' => true]);
                 } else {
                    json_response(['authorized' => false, 'error' => 'Mật khẩu không đúng.'], 401);
                 }
            } else {
                error_log("[verify_password] No password hash found in DB for '{$safe_relative_path}', but verification was attempted?");
                json_response(['authorized' => false, 'error' => 'Thư mục này không yêu cầu mật khẩu (lỗi logic?).'], 400); 
            }
        } catch (Throwable $e) {
            error_log("[verify_password] FATAL ERROR for '{$safe_relative_path}': " . $e->getMessage() . "\nStack Trace:\n" . $e->getTraceAsString());
            json_error("Lỗi server khi xác thực mật khẩu. " . $e->getMessage(), 500);
        }
        break;

    case 'admin_list_folders':
        if (empty($_SESSION['admin_logged_in'])) {
             error_log("Admin not logged in for admin_list_folders.");
             json_error("Yêu cầu đăng nhập Admin.", 403);
        }
        $admin_search_term = $search_term; // Use the already processed search_term
        
        try {
            $folders_data = [];
            $protected_status = [];
            $stmt = $pdo->query("SELECT folder_name FROM folder_passwords");
            while ($row = $stmt->fetchColumn()) {
                $protected_status[$row] = true;
            }

            // Get stats from DB
            $folder_stats = [];
            try {
                // Fetch stats only for top-level directories
                // We can identify top-level by checking if folder_name does not contain '/' 
                // Or simply fetch all and filter later if needed, but let's try to filter in SQL
                $stmt = $pdo->query("SELECT folder_name, views, downloads FROM folder_stats WHERE INSTR(folder_name, '/') = 0"); 
                while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $folder_stats[$row['folder_name']] = ['views' => $row['views'], 'downloads' => $row['downloads']];
                }
            } catch (PDOException $e) {
                error_log("ERROR fetching folder stats for admin: " . $e->getMessage());
                // Continue without stats if DB query fails
            }

            $iterator = new DirectoryIterator(IMAGE_ROOT);
            
            foreach ($iterator as $fileinfo) {
                if ($fileinfo->isDot()) continue;
                
                if ($fileinfo->isDir()) {
                    $dir_name = $fileinfo->getFilename();
                    if ($admin_search_term !== null && mb_stripos($dir_name, $admin_search_term, 0, 'UTF-8') === false) {
                        continue; 
                    }
                    $dir_path = $fileinfo->getPathname();
                    // Get stats from the DB results fetched earlier
                    $stats = $folder_stats[$dir_name] ?? ['views' => 0, 'downloads' => 0];
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
            
            usort($folders_data, fn($a, $b) => strnatcasecmp($a['name'], $b['name'])); 
            
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

        $folder_param = $_POST['folder'] ?? '';
        $password = $_POST['password'] ?? '';
        $safe_folder_name = basename(str_replace('\\', '/', $folder_param)); 

        if (empty($safe_folder_name) || !is_dir(IMAGE_ROOT . DIRECTORY_SEPARATOR . $safe_folder_name)) {
             json_error("Tên thư mục cấp 1 không hợp lệ hoặc không tồn tại.", 400);
        }
         if ($password === '') {
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
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
             json_error("Phương thức không hợp lệ.", 405);
        }
        if (empty($_SESSION['admin_logged_in'])) {
            json_error("Yêu cầu đăng nhập Admin.", 403);
        }

        $folder_param = $_POST['folder'] ?? null;
        
        // Use the same validation as set_password
        $safe_folder_name = basename(str_replace('\\', '/', $folder_param));

        if (empty($safe_folder_name)) { // Also check folder existence? No, just delete entry if exists.
             json_error("Tên thư mục không hợp lệ.", 400);
        }

        try {
            $sql = "DELETE FROM folder_passwords WHERE folder_name = ?";
            $stmt = $pdo->prepare($sql);
            $success = $stmt->execute([$safe_folder_name]);
            $affected_rows = $stmt->rowCount(); // Check how many rows were deleted

            unset($_SESSION['authorized_folders'][$safe_folder_name]);
            
            json_response(['success' => true, 'message' => "Đã xóa mật khẩu (nếu có) cho thư mục '" . htmlspecialchars($safe_folder_name) . "'. Bị ảnh hưởng: {$affected_rows} dòng."]);
        } catch (Throwable $e) {
            error_log("[admin_remove_password] FATAL ERROR for '{$safe_folder_name}': " . $e->getMessage() . "\nStack Trace:\n" . $e->getTraceAsString());
            json_error("Lỗi server khi xóa mật khẩu. " . $e->getMessage(), 500);
        }
        break;

    default:
        json_error("Hành động không hợp lệ: '{$action}'.", 400);
        break;
}

// Clean up buffer if script reaches end without exit (should not happen)
ob_end_flush(); 
?>