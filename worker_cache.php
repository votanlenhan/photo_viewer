<?php
// worker_cache.php - Script chạy nền để xử lý hàng đợi tạo cache thumbnail

echo "Cache Worker Started - " . date('Y-m-d H:i:s') . "\n";

// --- Thiết lập Môi trường --- 
error_reporting(E_ALL);
ini_set('display_errors', 0); // Không hiển thị lỗi ra output CLI
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/logs/worker_php_error.log'); // Log lỗi riêng cho worker

// Tăng giới hạn thời gian chạy và bộ nhớ cho worker
set_time_limit(0); // Chạy vô hạn (hoặc đặt giới hạn rất lớn)
ini_set('memory_limit', '1024M'); 

// Include các file cần thiết (Đường dẫn tương đối từ vị trí worker)
try {
    require_once __DIR__ . '/db_connect.php'; // Kết nối DB, load config, định nghĩa constants
    if (!$pdo) {
        throw new Exception("Database connection failed in worker.");
    }
    require_once __DIR__ . '/api/helpers.php'; // Load các hàm helper
} catch (Throwable $e) {
    $timestamp = date('Y-m-d H:i:s');
    error_log("[{$timestamp}] [Worker Init Error] Failed to include required files: " . $e->getMessage());
    echo "[{$timestamp}] [Worker Init Error] Worker failed to initialize. Check logs/worker_php_error.log\n";
    exit(1); // Thoát với mã lỗi
}

// +++ NEW: Reset 'processing' jobs to 'pending' on startup +++
try {
    $sql_reset = "UPDATE cache_jobs SET status = 'pending' WHERE status = 'processing'";
    $stmt_reset = $pdo->prepare($sql_reset);
    $affected_rows = $stmt_reset->execute() ? $stmt_reset->rowCount() : 0;
    if ($affected_rows > 0) {
        $reset_timestamp = date('Y-m-d H:i:s');
        $message = "[{$reset_timestamp}] [Worker Startup] Reset {$affected_rows} stuck 'processing' jobs back to 'pending'.";
        echo $message . "\n";
        error_log($message);
    }
} catch (Throwable $e) {
    $reset_fail_timestamp = date('Y-m-d H:i:s');
    $error_message = "[{$reset_fail_timestamp}] [Worker Startup Error] Failed to reset processing jobs: " . $e->getMessage();
    echo $error_message . "\n";
    error_log($error_message);
    // Continue running even if reset fails, but log the error
}
// +++ END NEW +++

// --- Biến Worker --- 
$sleep_interval = 5; // Số giây chờ giữa các lần kiểm tra hàng đợi (giây)
$running = true;

// --- Hàm xử lý tín hiệu (Graceful Shutdown) --- 
// (Hoạt động tốt hơn trên Linux/macOS, có thể không đáng tin cậy trên Windows qua Task Scheduler)
if (function_exists('pcntl_signal')) {
    declare(ticks = 1);
    function signal_handler($signo) {
        global $running;
        $timestamp = date('Y-m-d H:i:s');
        echo "\n[{$timestamp}] [Worker Signal] Received signal {$signo}. Shutting down gracefully...\n";
        error_log("[{$timestamp}] [Worker Signal] Received signal {$signo}. Initiating shutdown.");
        $running = false;
    }
    pcntl_signal(SIGTERM, 'signal_handler'); // Tín hiệu tắt thông thường
    pcntl_signal(SIGINT, 'signal_handler');  // Tín hiệu Ctrl+C
}

// --- Vòng lặp Chính của Worker --- 
echo "Entering main worker loop...\n";
while ($running) {
    $job = null;
    try {
        // --- Lấy Công việc từ Hàng đợi --- 
        $sql_get_job = "SELECT * FROM cache_jobs 
                        WHERE status = 'pending' 
                        ORDER BY created_at ASC 
                        LIMIT 1";
        $stmt_get = $pdo->prepare($sql_get_job);
        $stmt_get->execute();
        $job = $stmt_get->fetch(PDO::FETCH_ASSOC);

        if ($job) {
            $job_id = $job['id'];
            $folder_path_param = $job['folder_path'];
            $timestamp = date('Y-m-d H:i:s');
            echo "\n[{$timestamp}] [Job {$job_id}] Found job for folder: {$folder_path_param}\n";
            error_log("[{$timestamp}] [Job {$job_id}] Processing job for folder: {$folder_path_param}");

            // +++ ADD SMALL DELAY to potentially avoid immediate lock contention +++
            usleep(100000); // Sleep for 100 milliseconds (0.1 seconds)

            // --- Cập nhật trạng thái thành 'processing' --- 
            $sql_update_status = "UPDATE cache_jobs SET status = 'processing', processed_at = ? WHERE id = ?";
            error_log("[{$timestamp}] [Job {$job_id}] Preparing status update query...");
            $stmt_update = $pdo->prepare($sql_update_status);
            error_log("[{$timestamp}] [Job {$job_id}] Attempting to execute status update...");
            if ($stmt_update->execute([time(), $job_id])) {
                error_log("[{$timestamp}] [Job {$job_id}] Status updated to processing.");
            } else {
                error_log("[{$timestamp}] [Job {$job_id}] FAILED to execute status update to processing.");
                throw new Exception("Failed to execute status update query for job {$job_id}");
            }

            // --- Thực hiện Tạo Cache --- 
            error_log("[{$timestamp}] [Job {$job_id}] Validating path...");
            $path_info = validate_source_and_path($folder_path_param); // Xác thực lại đường dẫn
            if (!$path_info || $path_info['is_root']) {
                 error_log("[{$timestamp}] [Job {$job_id}] Path validation failed or is root.");
                 throw new Exception("Invalid or root folder path retrieved from job: {$folder_path_param}");
            }
            error_log("[{$timestamp}] [Job {$job_id}] Path validated. Absolute path: " . ($path_info['absolute_path'] ?? 'N/A'));
            
            $source_key = $path_info['source_key'];
            $absolute_folder_path = $path_info['absolute_path'];
            
            $created_count = 0;
            $skipped_count = 0;
            $error_count = 0;
            $files_processed = 0;
            $job_success = true; // Assume success initially for this job
            $job_result_message = '';
            
            try {
                error_log("[{$timestamp}] [Job {$job_id}] Creating RecursiveDirectoryIterator...");
                $directory = new RecursiveDirectoryIterator($absolute_folder_path, RecursiveDirectoryIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS);
                $iterator = new RecursiveIteratorIterator($directory, RecursiveIteratorIterator::LEAVES_ONLY);
                error_log("[{$timestamp}] [Job {$job_id}] Iterator created. Starting file loop...");
                
                $allowed_ext = ALLOWED_EXTENSIONS; // Hằng số từ db_connect.php
                // LẤY KÍCH THƯỚC LỚN NHẤT TỪ CẤU HÌNH
                $all_configured_sizes = THUMBNAIL_SIZES; // Lấy mảng kích thước từ db_connect.php
                if (empty($all_configured_sizes)) {
                    error_log("[{$timestamp}] [Job {$job_id}] No thumbnail sizes configured. Skipping thumbnail creation.");
                    continue; // Bỏ qua xử lý file này nếu không có kích thước nào
                }
                $large_thumb_size = max($all_configured_sizes); // Chỉ lấy kích thước lớn nhất

                foreach ($iterator as $fileinfo) {
                    error_log("[{$timestamp}] [Job {$job_id}] Processing item: " . $fileinfo->getPathname());
                    if ($fileinfo->isFile() && $fileinfo->isReadable() && in_array(strtolower($fileinfo->getExtension()), $allowed_ext, true)) {
                        $files_processed++;
                        $image_absolute_path = $fileinfo->getRealPath();
                        $image_relative_to_source = ltrim(substr($image_absolute_path, strlen(IMAGE_SOURCES[$source_key]['path'])), '\\/');
                        $image_source_prefixed_path = $source_key . '/' . str_replace('\\', '/', $image_relative_to_source);

                        // CHỈ TẠO KÍCH THƯỚC LỚN:
                        $size = $large_thumb_size;
                        $thumb_filename_safe = sha1($image_source_prefixed_path) . '_' . $size . '.jpg';
                        $cache_dir_for_size = CACHE_THUMB_ROOT . DIRECTORY_SEPARATOR . $size;
                        $cache_absolute_path = $cache_dir_for_size . DIRECTORY_SEPARATOR . $thumb_filename_safe;

                        if (!is_dir($cache_dir_for_size)) {
                            if(!@mkdir($cache_dir_for_size, 0775, true)) { // Giữ lại @ ở mkdir vì nó ít quan trọng hơn GD
                                error_log("[{$timestamp}] [Job {$job_id}] Failed to create cache subdir: {$cache_dir_for_size}");
                                $error_count++;
                                $job_success = false;
                                continue; // Bỏ qua file này nếu không tạo được thư mục cache
                            }
                        }

                        if (file_exists($cache_absolute_path)) {
                            $skipped_count++;
                        } else {
                            error_log("[{$timestamp}] [Job {$job_id}] Calling create_thumbnail for: " . $image_absolute_path . " -> " . $cache_absolute_path);
                            if (create_thumbnail($image_absolute_path, $cache_absolute_path, $size)) {
                                $created_count++;
                            } else {
                                error_log("[{$timestamp}] [Job {$job_id}] create_thumbnail returned false for: " . $image_absolute_path);
                                $error_count++;
                                $job_success = false; 
                            }
                        }
                        // Kết thúc xử lý kích thước lớn cho file này

                    } else {
                         error_log("[{$timestamp}] [Job {$job_id}] Skipping item (not a valid/readable image file): " . $fileinfo->getPathname());
                    }
                     // Check for shutdown signal periodically inside the loop if needed
                     if (function_exists('pcntl_signal_dispatch')) pcntl_signal_dispatch();
                     if (!$running) break; // Exit inner loop if shutdown requested
                } // End foreach iterator
                error_log("[{$timestamp}] [Job {$job_id}] File loop finished.");

                 if (!$running) {
                     echo "[{$timestamp}] [Job {$job_id}] Shutdown requested during processing. Marking as failed.\n";
                     throw new Exception("Worker shutdown requested during processing.");
                 }

                $job_result_message = sprintf("Hoàn thành: %d ảnh xử lý, %d thumb tạo, %d bỏ qua, %d lỗi.", 
                                                $files_processed, $created_count, $skipped_count, $error_count);
                echo "[{$timestamp}] [Job {$job_id}] Processing finished. Result: {$job_result_message}\n";
                error_log("[{$timestamp}] [Job {$job_id}] Result: {$job_result_message}");

                // Update final job status
                $final_status = ($error_count === 0) ? 'completed' : 'failed';
                $sql_finish = "UPDATE cache_jobs SET status = ?, completed_at = ?, result_message = ? WHERE id = ?";
                $stmt_finish = $pdo->prepare($sql_finish);
                $stmt_finish->execute([$final_status, time(), $job_result_message, $job_id]);
                echo "[{$timestamp}] [Job {$job_id}] Marked job as {$final_status}.\n";
                
                // Cập nhật last_cached_fully_at nếu thành công hoàn toàn
                if ($final_status === 'completed') {
                    try {
                        $sql_update_stats = "INSERT INTO folder_stats (folder_name, views, downloads, last_cached_fully_at) VALUES (?, 0, 0, ?) 
                                       ON CONFLICT(folder_name) DO UPDATE SET 
                                           last_cached_fully_at = excluded.last_cached_fully_at,
                                           views = folder_stats.views, 
                                           downloads = folder_stats.downloads 
                                       WHERE folder_stats.folder_name = excluded.folder_name";
                        $stmt_update_stats = $pdo->prepare($sql_update_stats);
                        $current_timestamp = time(); 
                        if ($stmt_update_stats->execute([$folder_path_param, $current_timestamp])) {
                           error_log("[{$timestamp}] [Job {$job_id}] Successfully updated last_cached_fully_at for '{$folder_path_param}'.");
                        } else {
                            error_log("[{$timestamp}] [Job {$job_id}] Failed to update last_cached_fully_at (execute returned false) for '{$folder_path_param}'."); 
                        }
                    } catch (PDOException $e) {
                        error_log("[{$timestamp}] [Job {$job_id}] PDOException failed to update last_cached_fully_at for '{$folder_path_param}': " . $e->getMessage());
                    }
                }
                
            } catch (Throwable $e) {
                // Lỗi trong quá trình xử lý một công việc cụ thể
                $timestamp = date('Y-m-d H:i:s');
                $error_message = "Error processing job {$job_id} for '{$folder_path_param}': " . $e->getMessage();
                echo "[{$timestamp}] [Job {$job_id}] {$error_message}\n";
                error_log("[{$timestamp}] [Job {$job_id}] {$error_message}");
                error_log("[{$timestamp}] [Job {$job_id}] Stack Trace: \n" . $e->getTraceAsString());
                
                // Cập nhật trạng thái công việc thành 'failed'
                try {
                    $sql_fail = "UPDATE cache_jobs SET status = 'failed', completed_at = ?, result_message = ? WHERE id = ?";
                    $stmt_fail = $pdo->prepare($sql_fail);
                    $stmt_fail->execute([time(), $error_message, $job_id]);
                    echo "[{$timestamp}] [Job {$job_id}] Marked job as failed due to error.\n";
                } catch (PDOException $pdo_e) {
                     error_log("[{$timestamp}] [Job {$job_id}] CRITICAL: Failed to mark job as failed after error: " . $pdo_e->getMessage());
                }
                // Không cần throw lại, tiếp tục vòng lặp để xử lý job tiếp theo (nếu có)
            }

        } else {
            // Không có công việc nào đang chờ
            if ($running) { // Chỉ sleep nếu worker vẫn đang chạy
                // echo "."; // In dấu chấm để biết worker còn sống
                sleep($sleep_interval);
                 // Kiểm tra tín hiệu tắt trong lúc sleep
                 if (function_exists('pcntl_signal_dispatch')) pcntl_signal_dispatch();
            }
        }
        
        // Kiểm tra tín hiệu tắt sau mỗi vòng lặp
        if (function_exists('pcntl_signal_dispatch')) pcntl_signal_dispatch();
        
    } catch (Throwable $e) {
        // Lỗi nghiêm trọng trong vòng lặp chính của worker (ví dụ: mất kết nối DB)
        $timestamp = date('Y-m-d H:i:s');
        error_log("[{$timestamp}] [Worker Main Loop Error] " . $e->getMessage());
        error_log("[{$timestamp}] [Worker Main Loop Error] Stack Trace: \n" . $e->getTraceAsString());
        echo "\n[{$timestamp}] [Worker Main Loop Error] Worker encountered a critical error. Check logs. Attempting to reconnect/restart loop after delay...\n";
        // Cố gắng đóng kết nối cũ (nếu có thể) và chờ trước khi thử lại
        $pdo = null; 
        sleep(30); // Chờ 30 giây trước khi thử kết nối lại
        try {
             require __DIR__ . '/db_connect.php'; // Thử kết nối lại
             if (!$pdo) throw new Exception("Reconnect failed.");
              echo "[{$timestamp}] [Worker Main Loop Error] Reconnected to DB successfully.\n";
        } catch (Throwable $reconnect_e) {
             error_log("[{$timestamp}] [Worker Main Loop Error] Failed to reconnect DB after error. Shutting down worker.");
             echo "[{$timestamp}] [Worker Main Loop Error] Failed to reconnect. Worker shutting down.\n";
             $running = false; // Dừng worker
        }
    }
} // End while($running)

$timestamp = date('Y-m-d H:i:s');
echo "[{$timestamp}] [Worker Shutdown] Worker loop exited.\n";
error_log("[{$timestamp}] [Worker Shutdown] Worker loop finished.");
exit(0); // Kết thúc thành công
?> 