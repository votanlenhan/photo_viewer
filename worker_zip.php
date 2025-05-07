<?php
// worker_zip.php - Background script to process the ZIP creation queue

echo "ZIP Worker Started - " . date('Y-m-d H:i:s') . "\\n";

// --- Environment Setup ---
error_reporting(E_ALL);
ini_set('display_errors', 0); // Do not display errors to CLI output
ini_set('log_errors', 1);
// Ensure logs directory exists or create it if you have permissions
if (!is_dir(__DIR__ . '/logs')) {
    mkdir(__DIR__ . '/logs', 0755, true);
}
ini_set('error_log', __DIR__ . '/logs/worker_zip_error.log'); // Separate log for zip worker

// Increase execution time and memory limits for the worker
set_time_limit(0); // Run indefinitely (or set a very large limit)
ini_set('memory_limit', '1024M'); // Adjust as needed

// --- Include Necessary Files ---
try {
    // db_connect.php should define $pdo and constants like IMAGE_SOURCES
    require_once __DIR__ . '/db_connect.php'; 
    if (!isset($pdo) || !$pdo instanceof PDO) {
        throw new Exception("Database connection (\$pdo) not established in worker. Check db_connect.php.");
    }
    // helpers.php contains validate_source_and_path
    require_once __DIR__ . '/api/helpers.php'; 
} catch (Throwable $e) {
    $timestamp = date('Y-m-d H:i:s');
    error_log("[{$timestamp}] [Worker Init Error] Failed to include required files: " . $e->getMessage());
    echo "[{$timestamp}] [Worker Init Error] Worker failed to initialize. Check logs/worker_zip_error.log\\n";
    exit(1); // Exit with an error code
}

// +++ NEW HELPER FUNCTIONS FOR LOGGING +++
function error_log_worker($job_details_array, $message) {
    $prefix = "[Worker]";
    if ($job_details_array && isset($job_details_array['id']) && isset($job_details_array['job_token'])) {
        $prefix = "[Job {" . $job_details_array['id'] . "} (" . $job_details_array['job_token'] . ")]";
    }
    $timestamp = date('Y-m-d H:i:s');
    error_log("[{$timestamp}] {$prefix} {$message}");
}

function echo_worker_status($job_details_array, $message) {
    $prefix = "[Worker]";
    if ($job_details_array && isset($job_details_array['id']) && isset($job_details_array['job_token'])) {
        $prefix = "[Job {" . $job_details_array['id'] . "} (" . $job_details_array['job_token'] . ")]";
    }
    $timestamp = date('Y-m-d H:i:s');
    echo "[{$timestamp}] {$prefix} {$message}\\n";
}
// --- END NEW HELPER FUNCTIONS ---

// --- Global Worker Variables (Moved here for clarity, check if used by helpers indirectly via $pdo) ---
// Define these before they might be used, e.g. in retry logic if it were outside the main loop
// For now, $max_retries_status_update and $retry_delay_ms are used within the loop, so their placement is fine.
// However, defining them here makes them more globally visible if needed elsewhere.
$max_retries_status_update = 3;
$retry_delay_ms = 300; // Milliseconds

// --- Reset 'processing' jobs to 'pending' on startup (safety net) ---
try {
    $sql_reset = "UPDATE zip_jobs SET status = 'pending' WHERE status = 'processing'";
    $stmt_reset = $pdo->prepare($sql_reset);
    $affected_rows = $stmt_reset->execute() ? $stmt_reset->rowCount() : 0;
    if ($affected_rows > 0) {
        $reset_timestamp = date('Y-m-d H:i:s');
        $message = "[{$reset_timestamp}] [Worker Startup] Reset {$affected_rows} stuck 'processing' ZIP jobs back to 'pending'.";
        echo $message . "\\n";
        error_log($message);
    }
} catch (Throwable $e) {
    $reset_fail_timestamp = date('Y-m-d H:i:s');
    $error_message = "[{$reset_fail_timestamp}] [Worker Startup Error] Failed to reset processing ZIP jobs: " . $e->getMessage();
    echo $error_message . "\\n";
    error_log($error_message);
    // Continue running even if reset fails
}

// --- Worker Variables ---
$sleep_interval = 10; // Seconds to wait between checking the queue
$running = true;
// Use DIRECTORY_SEPARATOR for consistency, though PHP on Windows is usually flexible
define('ZIP_CACHE_DIR', rtrim(__DIR__, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'zips' . DIRECTORY_SEPARATOR);

if (!is_dir(ZIP_CACHE_DIR)) {
    if (!mkdir(ZIP_CACHE_DIR, 0775, true) && !is_dir(ZIP_CACHE_DIR)) {
         $init_error_msg = "[Worker Init Error] Failed to create ZIP cache directory: " . ZIP_CACHE_DIR;
         error_log($init_error_msg);
         echo $init_error_msg . "\\n";
         exit(1);
    }
}


// --- Signal Handling (for graceful shutdown, mainly for Linux/macOS) ---
if (function_exists('pcntl_signal')) {
    declare(ticks = 1);
    function signal_handler_zip($signo) {
        global $running;
        $timestamp = date('Y-m-d H:i:s');
        $log_msg = "[{$timestamp}] [Worker Signal] Received signal {$signo}. Shutting down ZIP worker gracefully...";
        echo "\\n" . $log_msg . "\\n";
        error_log($log_msg);
        $running = false;
    }
    pcntl_signal(SIGTERM, 'signal_handler_zip'); // Standard kill signal
    pcntl_signal(SIGINT, 'signal_handler_zip');  // Ctrl+C
}

// --- Main Worker Loop ---
echo "Entering main ZIP worker loop...\\n";
while ($running) {
    $job_id = null; // Reset job_id for each iteration
    $current_job_details_for_log = "N/A"; // Reset for logging
    $fetched_job_data_for_log = "None"; // For detailed logging of what was fetched

    try {
        // --- Fetch Job from Queue ---
        $fetch_time = date('Y-m-d H:i:s.u'); // High precision time
        error_log_worker(null, "[{$fetch_time}] About to query for pending jobs. SQL: SELECT * FROM zip_jobs WHERE status = 'pending' ORDER BY created_at ASC LIMIT 1");
        // echo_worker_status(null, "Checking for pending jobs..."); // Already logged by error_log_worker

        $sql_get_job = "SELECT * FROM zip_jobs WHERE status = 'pending' ORDER BY created_at ASC LIMIT 1";
        $stmt_get = $pdo->prepare($sql_get_job);
        $stmt_get->execute();
        $job = $stmt_get->fetch(PDO::FETCH_ASSOC);

        if ($job) {
            // Log exactly what was fetched
            $fetched_job_data_for_log = print_r($job, true);
            error_log_worker($job, "[{$fetch_time}] Fetched a job. Data: {$fetched_job_data_for_log}");

            $job_id = $job['id']; // Assign job_id here
            $current_job_details_for_log = "[Job {$job_id} ({$job['job_token']})]"; // For logging within this job's context

            error_log_worker($job, "Found job. Processing folder: " . ($job['source_path'] ?? 'N/A'));
            echo_worker_status($job, "Found job. Processing folder: " . ($job['source_path'] ?? 'N/A'));

            // Brief delay to potentially avoid immediate lock contention
            usleep(100000); // 100ms

            // --- Update status to 'processing' --- (With Retries)
            $update_status_success = false;
            $job_was_stale_or_claimed = false; // Flag to indicate if job was not 'pending' or gone

            for ($attempt = 1; $attempt <= $max_retries_status_update; $attempt++) {
                try {
                    $sql_update_status = "UPDATE zip_jobs SET status = 'processing', updated_at = CURRENT_TIMESTAMP WHERE id = ? AND status = 'pending'";
                    $stmt_update = $pdo->prepare($sql_update_status);
                    if ($stmt_update->execute([$job_id])) {
                        if ($stmt_update->rowCount() > 0) {
                            $update_status_success = true;
                            error_log_worker($job, "Status updated to 'processing'.");
                            echo_worker_status($job, "Status updated to 'processing'.");
                            break; // Success
                        } else {
                            // Job was no longer 'pending' or didn't exist with that ID when we tried to claim it.
                            error_log_worker($job, "Attempt {$attempt}/{$max_retries_status_update}: Could not claim job for processing (status no longer 'pending' or job disappeared).");
                            echo_worker_status($job, "Attempt {$attempt}/{$max_retries_status_update}: Could not claim job (no longer 'pending' or gone).");
                            $job_was_stale_or_claimed = true; // Mark as stale/claimed by another
                            $update_status_success = false; // Ensure we don't proceed with this job
                            break; // Stop trying to claim this job
                        }
                    }
                } catch (PDOException $e) {
                    error_log_worker($job, "Attempt {$attempt}/{$max_retries_status_update}: DB lock or error when setting status to 'processing': " . $e->getMessage());
                    echo_worker_status($job, "Attempt {$attempt}/{$max_retries_status_update}: DB lock on status update.");
                    if ($attempt == $max_retries_status_update) {
                        // All retries failed due to exception
                        $update_status_success = false; // Ensure it's marked as not successful
                    }
                }
                if ($attempt < $max_retries_status_update) {
                    usleep($retry_delay_ms * 1000 * $attempt); // Exponential backoff might be too much, simple incremental delay
                }
            }

            if ($job_was_stale_or_claimed) {
                // Job was not 'pending' or was claimed by another worker. Log and skip.
                error_log_worker($job, "Job was stale or claimed by another worker. Skipping this job and looking for a new one.");
                echo_worker_status($job, "Job was stale or claimed. Skipping.");
                continue; // Continue to the next iteration of the main while($running) loop
            }

            if (!$update_status_success) {
                // This means all retries failed, likely due to persistent DB locks (and it wasn't stale).
                throw new Exception("Failed to set job to 'processing' after all retries (persistent DB lock or other error).");
            }

            // --- If we reach here, job status is 'processing' ---
            $folder_source_prefixed_path = $job['source_path'];
            $timestamp = date('Y-m-d H:i:s');
            $log_prefix = "[{$timestamp}] [Job {$job_id} ({$job['job_token']})]";

            echo "\\n{$log_prefix} Found job for folder: {$folder_source_prefixed_path}\\n";
            error_log("{$log_prefix} Processing job for folder: {$folder_source_prefixed_path}");

            // Brief delay to potentially avoid immediate lock contention if multiple workers start
            usleep(100000); // 100ms

            // --- Validate Path and Get Absolute Path ---
            error_log("{$log_prefix} Validating path: {$folder_source_prefixed_path}");
            $path_info = validate_source_and_path($folder_source_prefixed_path); // From helpers.php

            if (!$path_info || $path_info['is_root']) {
                throw new Exception("Invalid or root folder path provided: '{$folder_source_prefixed_path}'. Path validation details: " . print_r($path_info, true));
            }
            $absolute_folder_path = $path_info['absolute_path'];
            $source_key = $path_info['source_key'];
            // Use the validated relative path for a cleaner ZIP structure if possible
            $zip_internal_base_folder = !empty($path_info['relative_path']) ? basename($path_info['relative_path']) : $source_key;


            error_log("{$log_prefix} Path validated. Absolute: '{$absolute_folder_path}', ZIP base: '{$zip_internal_base_folder}'");

            // --- Count Total Files ---
            $total_files_to_zip = 0;
            $files_to_add = []; // Store [absolute_path_on_disk, path_inside_zip]
            
            $directory_iterator = new RecursiveDirectoryIterator($absolute_folder_path, RecursiveDirectoryIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS);
            $file_iterator = new RecursiveIteratorIterator($directory_iterator, RecursiveIteratorIterator::SELF_FIRST); // SELF_FIRST to get directories too if needed for structure

            foreach ($file_iterator as $fileinfo) {
                if ($fileinfo->isFile() && $fileinfo->isReadable()) {
                    $total_files_to_zip++;
                    $real_file_path = $fileinfo->getRealPath();
                    // Path inside ZIP: relative to the folder being zipped
                    $path_in_zip = $zip_internal_base_folder . '/' . substr($real_file_path, strlen($absolute_folder_path) + 1);
                    $path_in_zip = ltrim(str_replace(DIRECTORY_SEPARATOR, '/', $path_in_zip), '/');
                    $files_to_add[] = ['disk_path' => $real_file_path, 'zip_path' => $path_in_zip];
                }
            }
            error_log("{$log_prefix} Counted {$total_files_to_zip} files to zip.");

            // Update job with total_files
            $sql_update_total = "UPDATE zip_jobs SET total_files = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?";
            $stmt_update_total = $pdo->prepare($sql_update_total);
            $stmt_update_total->execute([$total_files_to_zip, $job_id]);

            // --- Create ZIP File ---
            $zip_filename_base = preg_replace('/[^a-zA-Z0-9_-]/', '_', $path_info['source_prefixed_path']) . '_' . $job['job_token'];
            $zip_filepath = ZIP_CACHE_DIR . $zip_filename_base . '.zip';

            if (file_exists($zip_filepath)) {
                unlink($zip_filepath); // Delete if exists from a previous failed attempt
            }

            $zip = new ZipArchive();
            if ($zip->open($zip_filepath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
                throw new Exception("Cannot open <{$zip_filepath}> for writing ZIP.");
            }

            $processed_files_count = 0;
            $last_progress_update_time = time(); // Initialize with current time
            $progress_update_interval_seconds = 5; // Max seconds between DB updates
            $progress_update_interval_files = max(1, (int)($total_files_to_zip / 20)); // Update roughly every 5% or at least 1 file

            foreach ($files_to_add as $file_entry) {
                if (!$running) throw new Exception("Worker shutdown initiated during ZIP creation.");

                $disk_path = $file_entry['disk_path'];
                $zip_path = $file_entry['zip_path'];
                
                if ($zip->addFile($disk_path, $zip_path)) {
                    $processed_files_count++;
                    // Optimized logging: Log first few, last few, and periodically, not every single file for large archives.
                    if ($processed_files_count <= 5 || $processed_files_count == $total_files_to_zip || ($processed_files_count % 100 == 0) ) {
                        error_log("{$log_prefix} Added to ZIP: {$disk_path} as {$zip_path} ({$processed_files_count}/{$total_files_to_zip})");
                    }
                } else {
                    error_log("{$log_prefix} WARNING: Failed to add file to ZIP: {$disk_path}");
                }

                // Update progress in DB periodically
                $now = time();
                if (($processed_files_count % $progress_update_interval_files == 0) || 
                    ($now - $last_progress_update_time >= $progress_update_interval_seconds) || 
                    $processed_files_count == $total_files_to_zip) {
                    $current_file_rel_path = substr($disk_path, strlen($absolute_folder_path) + 1);
                    update_zip_job_progress($pdo, $job_id, $processed_files_count, $current_file_rel_path, $log_prefix);
                    $last_progress_update_time = $now;
                }
            }

            // Final progress update to 100% before marking as completed and closing zip
            update_zip_job_progress($pdo, $job_id, $total_files_to_zip, $total_files_to_zip, "All files processed.", $current_job_details_for_log);

            // NOW, close the zip and get filesize, then update DB
            $zip->close(); // SINGLE CLOSE HERE
            $zip_filesize = filesize($zip_filepath);
            error_log_worker($job, "ZIP creation successful: {$zip_filepath}, Size: {$zip_filesize} bytes");
            echo_worker_status($job, "ZIP creation successful: {$zip_filepath}");

            // Update DB to completed
            $sql_complete = "UPDATE zip_jobs SET status = 'completed', zip_filename = ?, zip_filesize = ?, processed_files = total_files, current_file_processing = 'Completed', finished_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = ?";
            $stmt_complete = $pdo->prepare($sql_complete);
            if (!$stmt_complete->execute([basename($zip_filepath), $zip_filesize, $job_id])) {
                 error_log_worker($job, "CRITICAL: Failed to mark job as 'completed' in DB. ZIP file is ready: {$zip_filepath}");
                 echo_worker_status($job, "CRITICAL: Failed to mark job as 'completed'.");
            } else {
                error_log_worker($job, "Job marked as 'completed'.");
                echo_worker_status($job, "Job marked as 'completed'.");
            }

        } else { // No job found in queue
            error_log_worker(null, "No pending jobs found. Sleeping for {$sleep_interval} seconds.");
            echo_worker_status(null, "No pending jobs found. Sleeping for {$sleep_interval} seconds.");
            sleep($sleep_interval);
        }
    } catch (Exception $e) {
        $error_message_full = "Error processing ZIP job " . ($job_id ? $job_id . " ({$job['job_token']})" : 'unknown') . ": " . $e->getMessage() . "\\nTrace: " . $e->getTraceAsString();
        $error_timestamp = date('Y-m-d H:i:s');
        // Ensure logging uses the specific job context if available
        $log_prefix_for_failure = $job_id ? $current_job_details_for_log : "[Job ID Unknown]";

        error_log("[$error_timestamp] {$log_prefix_for_failure} {$error_message_full}"); // Log to main PHP error log or as configured
        echo "[{$error_timestamp}] {$log_prefix_for_failure} {$error_message_full}\\n"; // Echo for CLI visibility

        if ($job_id) {
            // Use helper function with retry for marking as failed
            mark_zip_job_as_failed($pdo, $job_id, $e->getMessage(), $log_prefix_for_failure);
        }
        // Optionally, implement a backoff strategy or specific error handling for the worker itself
        sleep(5); // Brief pause before trying to fetch next job
    } finally {
        // Ensure pcntl signals are dispatched if pending
        if (function_exists('pcntl_signal_dispatch')) {
            pcntl_signal_dispatch();
        }
    }
}

$shutdown_timestamp = date('Y-m-d H:i:s');
echo "[{$shutdown_timestamp}] ZIP Worker shutting down normally.\\n";
error_log("[{$shutdown_timestamp}] ZIP Worker shutting down normally.");


// --- Helper function to update job progress in DB with retries ---
function update_zip_job_progress($pdo, $job_id, $processed_count, $current_file, $log_prefix_outer) {
    $max_retries = 3;
    $retry_delay_ms = 300; // Milliseconds
    $timestamp = date('Y-m-d H:i:s');
    $log_prefix = "[{$timestamp}] {$log_prefix_outer} [ProgressUpdate Job {$job_id}]";

    for ($attempt = 1; $attempt <= $max_retries; $attempt++) {
        try {
            $sql = "UPDATE zip_jobs SET processed_files = ?, current_file_processing = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            
            $execute_result = $stmt->execute([$processed_count, $current_file, $job_id]);

            if ($execute_result) {
                error_log("{$log_prefix} Progress updated: {$processed_count} files, current: '{$current_file}'.");
                return true; // Success
            } else {
                error_log("{$log_prefix} Attempt {$attempt}: execute() returned false for progress update. Rows affected: " . $stmt->rowCount());
                if ($attempt < $max_retries) {
                    usleep($retry_delay_ms * 1000 * $attempt); // Increasing delay
                }
            }
        } catch (PDOException $e) {
            error_log("{$log_prefix} Attempt {$attempt} PDOException during progress update: " . $e->getMessage() . " (Code: " . $e->getCode() . ")");
            if ($attempt == $max_retries || !in_array($e->getCode(), ['HY000', '40001'])) { // HY000 general error, 40001 deadlock/serialization failure
                 error_log("{$log_prefix} Final attempt failed or non-retriable error. Giving up on this progress update.");
                return false; // Give up after max retries or for non-retriable errors
            }
            usleep($retry_delay_ms * 1000 * $attempt); // Increasing delay
        } catch (Throwable $e) {
            // Catch any other throwable error during progress update
            error_log("{$log_prefix} Attempt {$attempt} Throwable during progress update: " . $e->getMessage());
            if ($attempt == $max_retries) {
                error_log("{$log_prefix} Final attempt failed for general throwable. Giving up on this progress update.");
                return false;
            }
            usleep($retry_delay_ms * 1000 * $attempt); // Increasing delay
        }
    }
    error_log("{$log_prefix} All attempts to update progress failed.");
    return false; // All retries failed
}

// --- Helper function to mark job as failed with retries ---
function mark_zip_job_as_failed($pdo, $job_id, $error_message_text, $log_prefix_outer) {
    $max_retries = 3;
    $retry_delay_ms = 250; // Milliseconds
    $timestamp = date('Y-m-d H:i:s');
    // Use a slightly different log prefix to distinguish this action
    $log_prefix = $log_prefix_outer ? $log_prefix_outer : "[{$timestamp}] [MarkFailed Job {$job_id}]"; 

    for ($attempt = 1; $attempt <= $max_retries; $attempt++) {
        try {
            $sql_fail = "UPDATE zip_jobs SET status = 'failed', error_message = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?";
            $stmt_fail = $pdo->prepare($sql_fail);
            if ($stmt_fail->execute([$error_message_text, $job_id])) {
                error_log("{$log_prefix} Marked as 'failed' in DB. Error: {$error_message_text}");
                return true; // Success
            }
            error_log("{$log_prefix} Attempt {$attempt}: execute() returned false for marking as failed.");
        } catch (PDOException $e) {
            error_log("{$log_prefix} Attempt {$attempt} PDOException during marking as failed: " . $e->getMessage() . " (Code: " . $e->getCode() . ")");
            if ($attempt == $max_retries || !in_array($e->getCode(), ['HY000', '40001'])) {
                error_log("{$log_prefix} Final attempt failed or non-retriable error for marking as failed. Error: {$error_message_text}");
                return false; // Give up
            }
        }
        if ($attempt < $max_retries) {
             usleep($retry_delay_ms * 1000 * $attempt); // Increasing delay
        }
    }
    error_log("{$log_prefix} All attempts to mark job as failed failed for job_id {$job_id}. Error: {$error_message_text}");
    return false; // All retries failed
}

?> 