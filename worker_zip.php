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
echo "Entering main ZIP worker loop...\n";
while ($running) {
    $job_id = null; // Reset job_id for each iteration
    $current_job_details_for_log = "N/A"; // Reset for logging
    $fetched_job_data_for_log = "None"; // For detailed logging of what was fetched
    $job = null; // Ensure job is null at the start of each loop iteration

    try {
        // --- DB Connection Check & Reconnect Logic ---
        try {
            // Try a simple query to check connection status
            // $pdo->query("SELECT 1") will throw an exception if connection is lost
            if ($pdo) {
                $pdo->query("SELECT 1"); 
            } else {
                throw new PDOException("PDO object is null.", 2006); // Use 2006 to trigger reconnect
            }
        } catch (PDOException $e) {
            // Error codes: 2006 (MySQL server has gone away), 2013 (Lost connection to MySQL server during query)
            if ($e->getCode() == 2006 || $e->getCode() == 2013 || strpos(strtolower($e->getMessage()), 'mysql server has gone away') !== false) {
                error_log_worker(null, "MySQL connection lost (Error {$e->getCode()}). Attempting to reconnect...");
                echo_worker_status(null, "MySQL connection lost. Attempting to reconnect...");
                $pdo = null; // Clear the old PDO object
                try {
                    // Reuse DSN and credentials from db_connect.php (they should be in scope)
                    // Ensure these are available: $db_dsn, $db_user, $db_pass, $options
                    // These are loaded by require_once __DIR__ . '/db_connect.php'; earlier.
                    if (!isset($db_dsn) || !isset($db_user) || !isset($db_pass) || !isset($options)) {
                         error_log_worker(null, "DB credentials for reconnect not found. Worker cannot continue without DB.");
                         $running = false; // Stop worker if it can't get credentials
                         continue; // Skip to next main loop iteration (which will exit)
                    }
                    $pdo = new PDO($db_dsn, $db_user, $db_pass, $options);
                    error_log_worker(null, "Successfully reconnected to MySQL.");
                    echo_worker_status(null, "Successfully reconnected to MySQL.");
                } catch (PDOException $reconnect_e) {
                    error_log_worker(null, "Failed to reconnect to MySQL: " . $reconnect_e->getMessage() . ". Sleeping before retry or exit.");
                    echo_worker_status(null, "Failed to reconnect. Sleeping...");
                    sleep(30); // Sleep for 30s before next attempt in main loop
                    continue; // Skip to next main loop iteration
                }
            } else {
                throw $e; // Re-throw other PDO exceptions not related to "gone away"
            }
        }
        // If $pdo is still null here after potential reconnect attempt, something is very wrong
        if (!$pdo) {
            error_log_worker(null, "PDO object is still null after connection check/reconnect. Worker will sleep and retry.");
            echo_worker_status(null, "Critical DB connection issue. Sleeping...");
            sleep(60); // Sleep longer if in this state
            continue;
        }
        // --- END DB Connection Check & Reconnect Logic ---


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
            $source_path_from_job = $job['source_path'];
            $items_json_from_job = $job['items_json'] ?? null;
            $zip_filename_hint_from_job = $job['result_message'] ?? null; // Hint stored by API
            $job_id = $job['id']; // Ensure job_id is available
            $job_token = $job['job_token']; // Ensure job_token is available

            $timestamp = date('Y-m-d H:i:s');
            $log_prefix = "[{$timestamp}] [Job {$job_id} ({$job_token})]";

            echo "\n{$log_prefix} Processing job: {$source_path_from_job}\n";
            error_log_worker($job, "Processing job. Path: '{$source_path_from_job}', Items JSON: " . ($items_json_from_job ? 'present (' . strlen($items_json_from_job) . ' bytes)' : 'absent') . ", Filename Hint: '{$zip_filename_hint_from_job}'");



            $files_to_add = []; // Format: ['disk_path' => ABSOLUTE_PATH, 'zip_path' => RELATIVE_PATH_IN_ZIP]
            $total_files_to_zip = 0;
            $zip_filename_base = ''; // To be determined based on job type and hint

            // Determine the overall ZIP filename base first (sanitized, without .zip, token will be added later)
            if (!empty($zip_filename_hint_from_job)) {
                $hint_basename_no_ext = basename($zip_filename_hint_from_job, '.zip');
                $zip_filename_base = preg_replace('/[^a-zA-Z0-9_-]/', '_', $hint_basename_no_ext);
                if (empty($zip_filename_base)) { // If hint was just '.zip' or all invalid chars
                    $zip_filename_base = ($source_path_from_job === '_multiple_selected_') ? 'selected_files' : preg_replace('/[^a-zA-Z0-9_-]/', '_', $source_path_from_job);
                }
            } else { // No hint provided
                $zip_filename_base = ($source_path_from_job === '_multiple_selected_') ? 'selected_files' : preg_replace('/[^a-zA-Z0-9_-]/', '_', $source_path_from_job);
            }
            // Sanitize one last time in case source_path_from_job was the fallback and contained invalid chars (e.g. '/')
             $zip_filename_base = preg_replace('/[^a-zA-Z0-9_-]/', '_', $zip_filename_base);
             if (empty($zip_filename_base)) $zip_filename_base = "zip_file"; // Absolute fallback


            if ($source_path_from_job === '_multiple_selected_' && !empty($items_json_from_job)) {
                error_log_worker($job, "Job type: _multiple_selected_. Processing items_json.");
                $selected_files_list = json_decode($items_json_from_job, true);

                // Determine the base folder name for inside the ZIP from hint or default
                $zip_internal_base_folder = $zip_filename_base; // Use the already derived and sanitized zip name (without token) as internal base
                error_log_worker($job, "Using ZIP internal base folder for selected files: '{$zip_internal_base_folder}'");

                if (is_array($selected_files_list) && !empty($selected_files_list)) {
                    foreach ($selected_files_list as $file_source_prefixed_path) {
                        // Try RAW validation first, then regular validation
                        $file_path_info = validate_raw_source_and_file_path($file_source_prefixed_path);
                        $is_raw_file = ($file_path_info !== null);
                        
                        if (!$file_path_info) {
                            // If RAW validation failed, try regular image validation
                            $file_path_info = validate_source_and_file_path($file_source_prefixed_path);
                        }

                        if ($file_path_info && is_file($file_path_info['absolute_path']) && is_readable($file_path_info['absolute_path'])) {
                            $disk_path = $file_path_info['absolute_path'];
                            // Path inside ZIP: internal_base_folder / filename_only (flat structure)
                            $filename = basename($file_path_info['relative_path']);
                            $path_in_zip = $zip_internal_base_folder . '/' . $filename;
                            $path_in_zip = ltrim(str_replace(DIRECTORY_SEPARATOR, '/', $path_in_zip), '/');
                            $path_in_zip = str_replace('../', '', $path_in_zip);
                            $files_to_add[] = ['disk_path' => $disk_path, 'zip_path' => $path_in_zip];
                            $file_type = $is_raw_file ? 'RAW' : 'regular';
                            error_log_worker($job, "Validated and added selected file ({$file_type}): '{$disk_path}' as '{$path_in_zip}'");
                        } else {
                            $validation_result = $file_path_info ? print_r($file_path_info, true) : 'null (both RAW and regular validation failed)';
                            error_log_worker($job, "Skipping invalid or unreadable selected file: '{$file_source_prefixed_path}'. Validation: {$validation_result}");
                        }
                    }
                    $total_files_to_zip = count($files_to_add);
                } else {
                    error_log_worker($job, "items_json was empty or invalid after decoding for _multiple_selected_ job.");
                    throw new Exception("Invalid or empty items_json for _multiple_selected_ job {$job_id}.");
                }
            } else { // Existing logic for single folder processing
                error_log_worker($job, "Job type: single folder. Path: '{$source_path_from_job}'");
                $path_info = validate_source_and_path($source_path_from_job);

                if (!$path_info || $path_info['is_root'] || $path_info['is_file']) {
                    error_log_worker($job, "Path validation failed for single folder. Path: '{$source_path_from_job}', Details: " . print_r($path_info, true));
                    throw new Exception("Invalid folder path, root path, or file path provided for single folder ZIP: '{$source_path_from_job}' for job {$job_id}.");
                }
                $absolute_folder_path = $path_info['absolute_path'];
                
                // For single folder, the zip_internal_base_folder is derived from the folder's own name or hint.
                // $zip_filename_base already holds the sanitized name (from hint or folder path).
                $zip_internal_base_folder = $zip_filename_base; 
                error_log_worker($job, "Single folder validated. Absolute: '{$absolute_folder_path}', ZIP internal base: '{$zip_internal_base_folder}'");

                $directory_iterator = new RecursiveDirectoryIterator($absolute_folder_path, RecursiveDirectoryIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS);
                $file_iterator = new RecursiveIteratorIterator($directory_iterator, RecursiveIteratorIterator::SELF_FIRST);

                foreach ($file_iterator as $fileinfo) {
                    if ($fileinfo->isFile() && $fileinfo->isReadable()) {
                        $real_file_path = $fileinfo->getRealPath();
                        // Path inside ZIP: internal_base_folder / relative_path_from_scanned_folder_root
                        $relative_path_from_folder = substr($real_file_path, strlen($absolute_folder_path) + 1);
                        $path_in_zip = $zip_internal_base_folder . '/' . $relative_path_from_folder;
                        $path_in_zip = ltrim(str_replace(DIRECTORY_SEPARATOR, '/', $path_in_zip), '/');
                        $files_to_add[] = ['disk_path' => $real_file_path, 'zip_path' => $path_in_zip];
                    }
                }
                $total_files_to_zip = count($files_to_add);
            }

            error_log_worker($job, "Collected {$total_files_to_zip} files to add to ZIP. Final ZIP filename base (before token and .zip): '{$zip_filename_base}'");

            // Update job with total_files
            $sql_update_total = "UPDATE zip_jobs SET total_files = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?";
            $stmt_update_total = $pdo->prepare($sql_update_total);
            $stmt_update_total->execute([$total_files_to_zip, $job_id]);

            if ($total_files_to_zip === 0) {
                error_log_worker($job, "No valid files found to zip for job {$job_id}. Marking job as potentially problematic or empty.");
                // Depending on desired behavior, either fail or create an empty zip
                // For now, let's throw an exception which will mark it as failed.
                throw new Exception("No files found to add to ZIP for job {$job_id}.");
            }

            // --- Create ZIP File ---
            // Append job token to the base name for guaranteed uniqueness on filesystem
            $final_zip_filename = $zip_filename_base . '_' . $job_token . '.zip';
            $zip_filepath = ZIP_CACHE_DIR . $final_zip_filename;

            if (file_exists($zip_filepath)) {
                unlink($zip_filepath); // Delete if exists from a previous failed attempt
            }

            $zip = new ZipArchive();
            if ($zip->open($zip_filepath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
                error_log_worker($job, "Cannot open <{$zip_filepath}> for writing ZIP."); // Added job context
                throw new Exception("Cannot open <{$zip_filepath}> for writing ZIP for job {$job_id}.");
            }

            $processed_files_count = 0;

            foreach ($files_to_add as $file_entry) {
                if (!$running) throw new Exception("Worker shutdown initiated during ZIP creation.");

                $disk_path = $file_entry['disk_path'];
                $zip_path = $file_entry['zip_path'];
                
                if ($zip->addFile($disk_path, $zip_path)) {
                    $processed_files_count++;
                    // Optimized logging can remain here if needed
                    if ($processed_files_count <= 5 || $processed_files_count == $total_files_to_zip || ($processed_files_count % 100 == 0) ) { // Log more frequently for small zips
                        error_log_worker($job, "Added to ZIP: {$disk_path} as {$zip_path} ({$processed_files_count}/{$total_files_to_zip})"); // Added job context
                    }
                } else {
                    error_log_worker($job, "WARNING: Failed to add file to ZIP: {$disk_path} (Job {$job_id})"); // Added job context
                }

                // Update progress after every file is processed by the loop
                $report_processed_count = $processed_files_count;
                
                // Ensure the loop itself doesn't report N/N files.
                // If this update is for the last logical file processed by the loop:
                if ($processed_files_count == $total_files_to_zip && $total_files_to_zip > 0) {
                    // For a 1-file job, report_processed_count remains 1. The "Finalizing" step handles its messaging.
                    // For a multi-file job, report_processed_count becomes total_files_to_zip - 1.
                    $report_processed_count = ($total_files_to_zip == 1) ? 1 : max(0, $total_files_to_zip - 1);
                }
                
                // For progress reporting, use just the filename for _multiple_selected_ or relative path for single folder
                if ($source_path_from_job === '_multiple_selected_') {
                    $current_file_for_report = basename($disk_path);
                } else {
                    $current_file_for_report = isset($absolute_folder_path) ? substr($disk_path, strlen($absolute_folder_path) + 1) : basename($disk_path);
                }
                update_zip_job_progress($pdo, $job_id, $report_processed_count, $current_file_for_report, $log_prefix);
            }

            // Update progress to indicate finalization phase
            $progress_count_for_finalizing = 0; // Default for 0 files
            $message_for_finalizing = "Finalizing archive...";
            if ($total_files_to_zip > 0) {
                 // If total_files_to_zip is 1, this will show 1/1 "Finalizing..."
                 // If total_files_to_zip > 1, this will show (N-1)/N "Finalizing..."
                $progress_count_for_finalizing = ($total_files_to_zip == 1) ? 1 : max(0, $total_files_to_zip - 1);
            }
            update_zip_job_progress($pdo, $job_id, $progress_count_for_finalizing, $message_for_finalizing, $current_job_details_for_log);

            // NOW, close the zip and get filesize, then update DB
            $zip->close(); // SINGLE CLOSE HERE
            $zip_filesize = filesize($zip_filepath); // Ensure this is after zip close
            error_log_worker($job, "ZIP creation successful: {$zip_filepath}, Size: {$zip_filesize} bytes");
            echo_worker_status($job, "ZIP creation successful: {$zip_filepath}");

            // --- ADDED: Verify ZIP file exists and is readable before marking as completed ---
            if (!is_file($zip_filepath) || !is_readable($zip_filepath)) {
                $critical_error_msg = "CRITICAL FAILURE: ZIP file '{$zip_filepath}' does not exist or is not readable after creation. Job ID {$job_id}.";
                error_log_worker($job, $critical_error_msg);
                echo_worker_status($job, $critical_error_msg);
                mark_zip_job_as_failed($pdo, $job_id, $critical_error_msg, ($job ? "[Job {$job['id']} ({$job['job_token']})]" : "[Job ID {$job_id}]"));
                throw new Exception($critical_error_msg);
            }
            // --- END ADDED ---

            // Update DB to completed (WITH RETRY LOGIC)
            $update_final_status_success = false;
            $final_status_update_attempts = 0;
            $max_final_status_update_retries = 5; // Max 5 attempts for final status update
            $retry_final_status_delay_ms = 500;  // Start with 500ms delay, increases each attempt

            // ==> ADDED CHECK: If $zip_filepath is empty here, the ZIP creation effectively failed or path was lost.
            // Do not attempt to mark as 'completed'. Mark as 'failed' instead.
            if (empty($zip_filepath)) {
                $critical_error_msg = "CRITICAL FAILURE: Determined $zip_filepath is EMPTY before attempting to mark job as completed. ZIP file path is missing. Job ID {$job_id}.";
                error_log_worker($job, $critical_error_msg);
                echo_worker_status($job, $critical_error_msg);
                // Use the existing helper to mark as failed. This will also be caught by main try-catch if it throws,
                // but this call itself has retry logic.
                mark_zip_job_as_failed($pdo, $job_id, $critical_error_msg, ($job ? "[Job {$job['id']} ({$job['job_token']})]" : "[Job ID {$job_id}]"));
                // Skip the 'completed' update logic entirely for this job by throwing an exception
                // that the main loop's catch block will handle (it just logs and continues usually).
                // This ensures it doesn't proceed to the $update_final_status_success logic.
                throw new Exception($critical_error_msg); // This will be caught by the outer job processing loop's catch.
            }
            // <== END ADDED CHECK

            while (!$update_final_status_success && $final_status_update_attempts < $max_final_status_update_retries) {
                $final_status_update_attempts++;
                try {
                    $sql_complete = "UPDATE zip_jobs SET " .
                                    "status = 'completed', " .
                                    "zip_filename = ?, " .
                                    "final_zip_path = ?, " .
                                    "zip_filesize = ?, " .
                                    "processed_files = total_files, " .
                                    "current_file_processing = 'Completed', " .
                                    "finished_at = CURRENT_TIMESTAMP, " .
                                    "updated_at = CURRENT_TIMESTAMP " .
                                    "WHERE id = ?";
                    $stmt_complete = $pdo->prepare($sql_complete);
                    
                    // +++ BEGIN DEBUG LOGGING +++
                    error_log_worker($job, "Preparing to mark job as completed. SQL: {$sql_complete}");
                    error_log_worker($job, "Params for completion: [zip_filename => '{$final_zip_filename}', final_zip_path => '{$zip_filepath}', zip_filesize => {$zip_filesize}, job_id => {$job_id}]");
                    // +++ ADDED SAFETY CHECK LOG +++
                    if (empty($zip_filepath)) {
                        error_log_worker($job, "CRITICAL WARNING: $zip_filepath is EMPTY just before final DB update for job completion. This will result in an empty final_zip_path.");
                    }
                    // +++ END ADDED SAFETY CHECK LOG +++
                    // +++ END DEBUG LOGGING ---
                    
                    if ($stmt_complete->execute([$final_zip_filename, $zip_filepath, $zip_filesize, $job_id])) {
                        if ($stmt_complete->rowCount() > 0) {
                            error_log_worker($job, "Job marked as 'completed' after {$final_status_update_attempts} attempt(s).");
                            echo_worker_status($job, "Job marked as 'completed'.");
                            $update_final_status_success = true;

                            // +++ BEGIN READ BACK FOR DEBUG +++
                            try {
                                $stmt_read_back = $pdo->prepare("SELECT id, status, zip_filename, final_zip_path, final_zip_name, zip_filesize, downloaded_at FROM zip_jobs WHERE id = ?");
                                $stmt_read_back->execute([$job_id]);
                                $read_back_row = $stmt_read_back->fetch(PDO::FETCH_ASSOC);
                                if ($read_back_row) {
                                    error_log_worker($job, "READ BACK after update: " . print_r($read_back_row, true));
                                } else {
                                    error_log_worker($job, "READ BACK after update: Failed to fetch row for ID {$job_id}.");
                                }
                            } catch (PDOException $e_read) {
                                error_log_worker($job, "READ BACK after update: PDOException: " . $e_read->getMessage());
                            }
                            // +++ END READ BACK FOR DEBUG +++

                        } else {
                            error_log_worker($job, "Attempt {$final_status_update_attempts}: 'completed' status update SQL execute OK, but rowCount is 0. Job ID: {$job_id}. Job might be gone or already completed by another process.");
                            // If rowCount is 0, it implies the WHERE id = ? condition didn't match, or the status was already what we tried to set it to.
                            // To avoid infinite loops on strange states or if another process correctly updated it, we might check current status.
                            // For now, let this specific attempt be considered a non-fatal issue if execute was true, but we won't set $update_final_status_success = true unless rowCount > 0.
                            // This means it will retry, and if it persists, will fail after max retries.
                        }
                    } else {
                        // This case (execute() returns false but doesn't throw PDOException) is less common for SQLite with well-formed SQL.
                        // It might indicate a more fundamental issue if it happens.
                        error_log_worker($job, "Attempt {$final_status_update_attempts}: SQL execute returned false for 'completed' status update. Job ID: {$job_id}. Check SQLite error info if possible.");
                    }
                } catch (PDOException $e) {
                    error_log_worker($job, "Attempt {$final_status_update_attempts} PDOException during 'completed' status update: " . $e->getMessage() . " (Code: " . ($e->errorInfo[1] ?? $e->getCode()) . ")");
                    // MySQL error codes for lock issues: 1205 (Lock wait timeout), 1213 (Deadlock)
                    // SQLite error message contains 'database is locked'
                    if (!($e->errorInfo[1] == 1205 || $e->errorInfo[1] == 1213) || $final_status_update_attempts >= $max_final_status_update_retries) {
                        // If not a recognized retriable MySQL lock error, or if it is but we've exhausted retries, re-throw.
                        error_log_worker($job, "Non-retriable PDOException or max retries reached for 'completed' status. Re-throwing.");
                        throw $e; 
                    }
                    // If it IS a retriable MySQL lock error and we have retries left, the loop will continue after a delay.
                }

                if (!$update_final_status_success && $final_status_update_attempts < $max_final_status_update_retries) {
                    usleep($retry_final_status_delay_ms * 1000 * $final_status_update_attempts); // Increasing delay for subsequent retries
                }
            }

            if (!$update_final_status_success) {
                $critical_error_msg = "CRITICAL: Failed to mark job as 'completed' in DB after {$max_final_status_update_retries} attempts. ZIP file is ready: {$zip_filepath}. Manual DB intervention may be required for job ID {$job_id}.";
                error_log_worker($job, $critical_error_msg);
                echo_worker_status($job, "CRITICAL: Failed to mark job as 'completed' after multiple retries. ZIP is ready but DB status incorrect.");
                // The job will remain in 'processing' or its last known state before this block.
                // The main exception handler for the job will NOT be triggered if we don't re-throw an exception here.
                // This means the job won't be marked as 'failed' by the generic handler if only this specific final update fails.
                // This is a design choice: the ZIP is ready, so marking the whole job 'failed' might be misleading.
                // However, the client will likely not see it as completed if it polls. 
                // Consider if throwing new Exception("Failed to update final status to completed after retries.") is better, which would then mark job as 'failed'.
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