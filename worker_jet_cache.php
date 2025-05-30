<?php
// worker_jet_cache.php - Optimized RAW cache worker with dcraw only

require_once 'db_connect.php';

// --- Environment Setup ---
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/logs/worker_jet_php_error.log');

// Increase limits for RAW processing - optimized for 5 concurrent processes
set_time_limit(0);
ini_set('memory_limit', '2048M'); // 2GB for main process (reduced for 5 processes)

// Include necessary files
try {
    if (!$pdo) {
        throw new Exception("Database connection failed in worker.");
    }
    require_once __DIR__ . '/api/helpers.php';
} catch (Throwable $e) {
    $timestamp = date('Y-m-d H:i:s');
    error_log("[{$timestamp}] [Jet Worker Init Error] Failed to include required files: " . $e->getMessage());
    echo "[{$timestamp}] [Jet Worker Init Error] Worker failed to initialize.\n";
    exit(1);
}

// --- Configuration Constants ---
const JPEG_QUALITY = 85;  // Reduced from 90 for speed (Photo Mechanic approach)
const JPEG_STRIP_METADATA = true;

// Photo Mechanic inspired speed optimizations
const USE_EMBEDDED_JPEG_WHEN_POSSIBLE = true;
const EMBEDDED_JPEG_MIN_SIZE = 150;  // Minimum size for embedded JPEG to be useful

// Ultra-fast dcraw processing options (inspired by dcraw-fast)
const DCRAW_QUALITY = 0;         // Quality level 0 = bilinear (fastest)
const DCRAW_USE_EMBEDDED_PROFILE = false;  // Skip color profile for speed
const DCRAW_AUTO_WHITE_BALANCE = false;    // Skip auto white balance for speed
const DCRAW_FAST_INTERPOLATION = true;     // Use fastest interpolation
const DCRAW_HALF_SIZE = true;              // Half-size output for speed (750px is still manageable)

// File validation constants (optimized)
const MIN_RAW_FILE_SIZE = 512 * 1024;  // Reduced from 1MB for speed
const MAX_RAW_FILE_SIZE = 200 * 1024 * 1024; // Reduced from 500MB

// Output validation constants
const MIN_JPEG_OUTPUT_SIZE = 512;      // Reduced for speed
const JPEG_MAGIC_BYTES = [0xFF, 0xD8, 0xFF]; // JPEG file signature

// --- Executable Paths ---
$dcraw_executable_path = __DIR__ . DIRECTORY_SEPARATOR . "exe" . DIRECTORY_SEPARATOR . "dcraw.exe";

// Use system ImageMagick since local one is missing DLL dependencies
$magick_executable_path = "C:\\Program Files\\ImageMagick-7.1.1-Q16-HDRI\\magick.exe";

// Fallback to local if system not available
if (!file_exists($magick_executable_path)) {
$magick_executable_path = __DIR__ . DIRECTORY_SEPARATOR . "exe" . DIRECTORY_SEPARATOR . "magick.exe";
    error_log("[" . date('Y-m-d H:i:s') . "] [Jet Worker] System ImageMagick not found, using local version");
}

// --- Database Reconnect Configuration ---
const MAX_DB_RECONNECT_ATTEMPTS = 1;
const MYSQL_ERROR_CODE_SERVER_GONE_AWAY = 2006;
const MYSQL_ERROR_CODE_LOST_CONNECTION = 2013;

/**
 * Global PDO instance, to be managed by connect/reconnect logic.
 * @var PDO|null $pdo
 */

/**
 * Establishes or re-establishes the database connection.
 * This function relies on db_connect.php to set the global $pdo variable.
 */
function ensure_db_connection() {
    global $pdo;
    if (!$pdo || !$pdo->query('SELECT 1')) { // Check if connection is live
        error_log("[" . date('Y-m-d H:i:s') . "] [Jet Worker DB] Attempting to connect/reconnect to database...");
        // db_connect.php should set the global $pdo
        // Ensure db_connect.php can be re-included or its connection logic re-run
        // For simplicity, assuming it can be re-required if $pdo is not an object or connection dead
        if (file_exists(__DIR__ . '/db_connect.php')) {
            require __DIR__ . '/db_connect.php'; 
        }
        if (!$pdo) {
            $timestamp = date('Y-m-d H:i:s');
            $error_msg = "[{$timestamp}] [Jet Worker DB Error] CRITICAL: Failed to establish database connection after attempt.";
            error_log($error_msg);
            echo $error_msg . "\n";
            // Optionally exit if DB is critical and cannot be re-established
            // exit(1);
            throw new Exception("Database connection failed and could not be re-established.");
        } else {
            error_log("[" . date('Y-m-d H:i:s') . "] [Jet Worker DB] Database connection (re)established successfully.");
        }
    }
}

/**
 * Executes a PDOStatement with retry logic for 'MySQL server has gone away' errors.
 *
 * @param PDOStatement $stmt The PDOStatement to execute.
 * @param array $params An array of parameters to pass to execute().
 * @param bool $is_select For select queries, determines return type.
 * @return mixed The result of fetch/fetchAll for SELECTs, or rowCount for others.
 * @throws PDOException if execution fails after retries.
 */
function execute_pdo_with_retry(PDOStatement $stmt, array $params = [], bool $is_select_fetch = false, bool $is_select_fetchall = false) {
    global $pdo; // Ensure $pdo is accessible
    $attempts = 0;
    while (true) {
        try {
            ensure_db_connection(); // Ensure connection is active before executing
            $stmt->execute($params);
            if ($is_select_fetch) {
                return $stmt->fetch(PDO::FETCH_ASSOC);
            }
            if ($is_select_fetchall) {
                return $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            return $stmt->rowCount(); // For INSERT, UPDATE, DELETE
        } catch (PDOException $e) {
            $attempts++;
            $error_code = $e->errorInfo[1] ?? null;
            $is_gone_away = ($error_code === MYSQL_ERROR_CODE_SERVER_GONE_AWAY || $error_code === MYSQL_ERROR_CODE_LOST_CONNECTION);

            if ($is_gone_away && $attempts <= MAX_DB_RECONNECT_ATTEMPTS) {
                $timestamp = date('Y-m-d H:i:s');
                error_log("[{$timestamp}] [Jet Worker DB Warning] MySQL server has gone away (Code: {$error_code}). Attempting reconnect ({$attempts}/" . MAX_DB_RECONNECT_ATTEMPTS . "). Error: " . $e->getMessage());
                $pdo = null; // Force re-connection by ensure_db_connection()
                // Optional: short delay before reconnecting
                // sleep(1);
                continue; // Retry the loop (which will call ensure_db_connection and then execute)
            } else {
                // Non-recoverable error or max attempts reached
                throw $e; // Re-throw the original or last exception
            }
        }
    }
}

// --- Helper Functions ---

/**
 * Check if dcraw is available and working
 */
function check_dcraw_availability($dcraw_path) {
    if (!file_exists($dcraw_path)) {
        return false;
    }
    
    $test_cmd = "\"{$dcraw_path}\" 2>&1";
    $output = shell_exec($test_cmd);
    return (strpos($output, 'dcraw') !== false || strpos($output, 'Usage') !== false);
}

/**
 * Validates RAW file integrity before processing
 * Based on research recommendations for input validation
 */
function validate_raw_file_integrity($file_path, $timestamp, $job_id) {
    // Check if file exists and is readable
    if (!file_exists($file_path) || !is_readable($file_path)) {
        throw new Exception("RAW file does not exist or is not readable: {$file_path}");
    }
    
    // Check file size constraints
    $file_size = filesize($file_path);
    if ($file_size < MIN_RAW_FILE_SIZE) {
        throw new Exception("RAW file too small ({$file_size} bytes), likely corrupted or incomplete");
    }
    
    if ($file_size > MAX_RAW_FILE_SIZE) {
        throw new Exception("RAW file too large ({$file_size} bytes), exceeds reasonable limits");
    }
    
    // Check basic file signature/magic bytes for common RAW formats
    $handle = fopen($file_path, 'rb');
    if (!$handle) {
        throw new Exception("Cannot open RAW file for validation: {$file_path}");
    }
    
    $header = fread($handle, 32); // Read more bytes for better format detection
    fclose($handle);
    
    if (strlen($header) < 4) {
        throw new Exception("RAW file header too short, likely corrupted");
    }
    
    // Basic RAW format signature validation
    $is_valid_raw = false;
    $format_detected = 'unknown';
    
    // Canon CR3: ISO Media container (ftyp box with "crx " brand)
    if (substr($header, 4, 4) === 'ftyp' && 
        (strpos($header, 'crx ') !== false || strpos($header, 'isom') !== false)) {
        $is_valid_raw = true;
        $format_detected = 'Canon CR3';
    }
    // Canon CR2: "II" + 42 + "CR" 
    elseif (substr($header, 0, 2) === 'II' && ord($header[2]) === 42 && substr($header, 8, 2) === 'CR') {
        $is_valid_raw = true;
        $format_detected = 'Canon CR2';
    }
    // Canon CRW: "HEAPCCDR"
    elseif (substr($header, 6, 8) === 'HEAPCCDR') {
        $is_valid_raw = true;
        $format_detected = 'Canon CRW';
    }
    // Nikon NEF: "MM" + 42
    elseif (substr($header, 0, 2) === 'MM' && ord($header[2]) === 0 && ord($header[3]) === 42) {
        $is_valid_raw = true;
        $format_detected = 'Nikon NEF';
    }
    // Sony ARW: "II" + 42
    elseif (substr($header, 0, 2) === 'II' && ord($header[2]) === 42 && ord($header[3]) === 0) {
        $is_valid_raw = true;
        $format_detected = 'Sony ARW/TIFF-based';
    }
    // Add more format checks as needed...
    
    if (!$is_valid_raw) {
        // Log the header for debugging
        $header_hex = bin2hex($header);
        error_log("[{$timestamp}] [Jet Job {$job_id}] Unrecognized RAW format. Header: {$header_hex}");
        
        // Don't fail completely - libvips might still handle it
        error_log("[{$timestamp}] [Jet Job {$job_id}] Warning: RAW format not recognized, proceeding with caution");
    } else {
        error_log("[{$timestamp}] [Jet Job {$job_id}] RAW format detected: {$format_detected}, size: {$file_size} bytes");
    }
    
    return [
        'size' => $file_size,
        'format' => $format_detected,
        'valid' => $is_valid_raw
    ];
}

/**
 * Validates JPEG output file integrity
 * Based on research recommendations for output validation
 */
function validate_jpeg_output($file_path, $timestamp, $job_id) {
    if (!file_exists($file_path)) {
        throw new Exception("Output JPEG file was not created: {$file_path}");
    }
    
    $file_size = filesize($file_path);
    if ($file_size < MIN_JPEG_OUTPUT_SIZE) {
        throw new Exception("Output JPEG file too small ({$file_size} bytes), likely corrupted");
    }
    
    // Check JPEG magic bytes
    $handle = fopen($file_path, 'rb');
    if (!$handle) {
        throw new Exception("Cannot open output JPEG for validation: {$file_path}");
    }
    
    $header = fread($handle, 3);
    fclose($handle);
    
    $header_bytes = array_values(unpack('C*', $header));
    if ($header_bytes !== JPEG_MAGIC_BYTES) {
        $header_hex = bin2hex($header);
        throw new Exception("Output file does not have valid JPEG signature. Got: {$header_hex}");
    }
    
    // Try to get basic image dimensions to verify it's a valid JPEG
    $image_info = @getimagesize($file_path);
    if (!$image_info || $image_info[2] !== IMAGETYPE_JPEG) {
        throw new Exception("Output file is not a valid JPEG image");
    }
    
    // Enhanced validation: Try to actually decode the entire JPEG to ensure it's not corrupted
    $test_image = @imagecreatefromjpeg($file_path);
    if (!$test_image) {
        throw new Exception("JPEG file exists but cannot be decoded - likely corrupted");
    }
    
    // Get actual decoded dimensions to verify they match header
    $decoded_width = imagesx($test_image);
    $decoded_height = imagesy($test_image);
    imagedestroy($test_image);
    
    if ($decoded_width !== $image_info[0] || $decoded_height !== $image_info[1]) {
        throw new Exception("JPEG header dimensions ({$image_info[0]}x{$image_info[1]}) don't match decoded dimensions ({$decoded_width}x{$decoded_height}) - file corrupted");
    }
    
    error_log("[{$timestamp}] [Jet Job {$job_id}] Output JPEG fully validated: {$file_size} bytes, {$image_info[0]}x{$image_info[1]}, decodable");
    
    return [
        'size' => $file_size,
        'width' => $image_info[0],
        'height' => $image_info[1]
    ];
}

/**
 * Process CR3 file using ImageMagick directly (fallback for dcraw compatibility)
 * CR3 is not supported by dcraw 9.28, so we use ImageMagick's built-in CR3 support
 */
function process_cr3_with_imagemagick($magick_path, $raw_file_path, $output_path, $target_height, $timestamp, $job_id) {
    $escaped_final_cache_path = escapeshellarg($output_path);
    $escaped_raw_file_path = escapeshellarg($raw_file_path);
    
    try {
        // ImageMagick options for CR3 processing
        $magick_options = [];
        $magick_options[] = "-resize x{$target_height}";   // Resize by height
        $magick_options[] = "-quality " . JPEG_QUALITY;
        $magick_options[] = "-sampling-factor 4:2:0";      // Optimize JPEG compression
        $magick_options[] = "-colorspace sRGB";            // Ensure proper color space
        $magick_options[] = "-auto-orient";                // Handle rotation properly
        
        // Speed optimizations for parallel processing
        $magick_options[] = "-define jpeg:optimize-coding=false";  // Skip optimization for speed
        $magick_options[] = "-define jpeg:dct-method=fast";        // Fast DCT method
        $magick_options[] = "-interlace none";                     // No interlacing for speed
        $magick_options[] = "-filter Lanczos";                     // Good quality filtering
        $magick_options[] = "-limit memory 512MB";                 // Increased memory for large files
        $magick_options[] = "-limit map 512MB";                    // Increased memory mapping limits
        $magick_options[] = "-limit disk 2GB";                     // Allow more disk usage if needed
        
        if (JPEG_STRIP_METADATA) {
            $magick_options[] = "-strip";
        }
        
        $magick_opts_string = implode(' ', $magick_options);
        
        // Direct CR3 to JPEG conversion using ImageMagick with error capture
        $magick_cr3_cmd = "\"{$magick_path}\" {$escaped_raw_file_path} {$magick_opts_string} {$escaped_final_cache_path} 2>&1";
        
        echo "[{$timestamp}] [Job {$job_id}] Processing CR3 with ImageMagick directly...\n";
        error_log("[{$timestamp}] [Jet Job {$job_id}] Processing CR3 with ImageMagick. CMD: {$magick_cr3_cmd}");
        
        // Check if output directory exists and is writable
        $output_dir = dirname($output_path);
        if (!is_dir($output_dir)) {
            error_log("[{$timestamp}] [Jet Job {$job_id}] Creating CR3 output directory: {$output_dir}");
            @mkdir($output_dir, 0775, true);
        }
        
        if (!is_writable($output_dir)) {
            error_log("[{$timestamp}] [Jet Job {$job_id}] WARNING: CR3 output directory not writable: {$output_dir}");
        }
        
        // Execute ImageMagick with appropriate priority
        $magick_isolated_cmd = "start /NORMAL /AFFINITY 0x3 /B /WAIT cmd /c \"" . $magick_cr3_cmd . "\"";
        
        error_log("[{$timestamp}] [Jet Job {$job_id}] ImageMagick CR3 CMD: {$magick_isolated_cmd}");
        $start_time = microtime(true);
        $magick_output = shell_exec($magick_isolated_cmd);
        $magick_time = round((microtime(true) - $start_time) * 1000, 2);
        error_log("[{$timestamp}] [Jet Job {$job_id}] ImageMagick CR3 execution time: {$magick_time}ms");
        $trimmed_magick_output = trim((string)$magick_output);
        
        // Enhanced diagnostic logging for CR3
        if (!empty($trimmed_magick_output)) {
            error_log("[{$timestamp}] [Jet Job {$job_id}] ImageMagick CR3 output: {$trimmed_magick_output}");
        }
        
        // Check if output file was created
        if (file_exists($output_path)) {
            $output_size = filesize($output_path);
            error_log("[{$timestamp}] [Jet Job {$job_id}] CR3 output file created with size: {$output_size} bytes");
            
            if ($output_size === 0) {
                error_log("[{$timestamp}] [Jet Job {$job_id}] ERROR: CR3 output file is empty (0 bytes)");
            }
        } else {
            error_log("[{$timestamp}] [Jet Job {$job_id}] ERROR: CR3 output file not created at: {$output_path}");
        }
        
        if (!file_exists($output_path) || filesize($output_path) === 0) {
            $error_details = "ImageMagick CR3 processing failed. ";
            $error_details .= "Output dir writable: " . (is_writable($output_dir) ? "YES" : "NO") . ". ";
            $error_details .= "ImageMagick output: " . ($trimmed_magick_output ?: "EMPTY");
            
            error_log("[{$timestamp}] [Jet Job {$job_id}] ImageMagick CR3 processing FAILED: {$error_details}");
            throw new Exception($error_details);
        }
        
        return "CR3 cache created successfully using ImageMagick direct processing.";
        
    } catch (Exception $e) {
        error_log("[{$timestamp}] [Jet Job {$job_id}] CR3 processing error: " . $e->getMessage());
        throw $e;
    }
}

/**
 * Process RAW file using maximum speed dcraw + ImageMagick pipeline
 * Optimized for maximum speed over quality
 */
function process_raw_with_optimized_dcraw($dcraw_path, $magick_path, $raw_file_path, $output_path, $target_height, $timestamp, $job_id) {
    $escaped_final_cache_path = escapeshellarg($output_path);
    
    // Create optimized temporary TIFF file in memory-efficient location
    $temp_tiff_filename = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'jet_opt_' . uniqid() . '.tiff';
    $escaped_temp_tiff_path = escapeshellarg($temp_tiff_filename);
    
    try {
        // Step 1: Balanced speed dcraw command with proper colors
        $dcraw_options = [];
        
        // Quality vs speed balance
        $dcraw_options[] = "-q 1";     // AHD interpolation (good speed/quality balance)
        $dcraw_options[] = "-a";       // Auto white balance for proper colors
        $dcraw_options[] = "-o 1";     // sRGB color space for normal colors
        $dcraw_options[] = "-w";       // Use camera white balance
        $dcraw_options[] = "-T";       // Output TIFF for better color handling
        
        $dcraw_opts_string = implode(' ', $dcraw_options);
        
        $dcraw_to_tiff_cmd = "\"{$dcraw_path}\" {$dcraw_opts_string} -c \"{$raw_file_path}\" > {$escaped_temp_tiff_path}";
        
        echo "[{$timestamp}] [Job {$job_id}] Executing balanced speed dcraw...\n";
        error_log("[{$timestamp}] [Jet Job {$job_id}] Executing balanced speed dcraw Step 1. CMD: {$dcraw_to_tiff_cmd}");
        
        // Set CPU priority for parallel processing (lower for 10 concurrent processes)
        $dcraw_isolated_cmd = "start /HIGH /AFFINITY 0x3 /B /WAIT cmd /c \"" . $dcraw_to_tiff_cmd . "\"";
        
        // Execute dcraw with maximum hardware utilization
        error_log("[{$timestamp}] [Jet Job {$job_id}] High priority CMD: {$dcraw_isolated_cmd}");
        $start_time = microtime(true);
        $dcraw_output = shell_exec($dcraw_isolated_cmd);
        $dcraw_time = round((microtime(true) - $start_time) * 1000, 2);
        error_log("[{$timestamp}] [Jet Job {$job_id}] dcraw execution time: {$dcraw_time}ms");
        $trimmed_dcraw_output = trim((string)$dcraw_output);
        
        if (!file_exists($temp_tiff_filename) || filesize($temp_tiff_filename) === 0) {
            error_log("[{$timestamp}] [Jet Job {$job_id}] dcraw Step 1 FAILED: TIFF file not created or empty. Output: " . ($trimmed_dcraw_output ?: "EMPTY"));
            throw new Exception("dcraw step 1 failed. Output: " . $trimmed_dcraw_output);
        }
        
        error_log("[{$timestamp}] [Jet Job {$job_id}] dcraw Step 1 SUCCESS: TIFF file created with size: " . filesize($temp_tiff_filename));
        
        // Step 2: Optimized ImageMagick conversion for parallel processing
        $magick_options = [];
        $magick_options[] = "-resize x{$target_height}";   // Resize by height (remove !)
        $magick_options[] = "-quality " . JPEG_QUALITY;
        $magick_options[] = "-sampling-factor 4:2:0";      // Optimize JPEG compression
        $magick_options[] = "-colorspace sRGB";            // Ensure proper color space
        
        // Enhanced speed optimizations with higher memory for large TIFF files (72MB+)
        $magick_options[] = "-define jpeg:optimize-coding=false";  // Skip optimization for speed
        $magick_options[] = "-define jpeg:dct-method=fast";        // Fast DCT method
        $magick_options[] = "-interlace none";                     // No interlacing for speed
        $magick_options[] = "-filter Lanczos";                     // Better quality than Point but still fast
        $magick_options[] = "-limit memory 512MB";                 // Increased memory for large TIFF files
        $magick_options[] = "-limit map 512MB";                    // Increased memory mapping
        $magick_options[] = "-limit disk 2GB";                     // Allow more disk usage if needed
        
        if (JPEG_STRIP_METADATA) {
            $magick_options[] = "-strip";
        }
        
        $magick_opts_string = implode(' ', $magick_options);
        
        // Enhanced command with proper error capture
        $magick_tiff_to_jpg_cmd = "\"{$magick_path}\" {$escaped_temp_tiff_path} {$magick_opts_string} {$escaped_final_cache_path} 2>&1";
        
        echo "[{$timestamp}] [Job {$job_id}] Executing optimized ImageMagick resize...\n";
        error_log("[{$timestamp}] [Jet Job {$job_id}] Executing optimized dcraw Step 2. CMD: {$magick_tiff_to_jpg_cmd}");
        
        // Add diagnostic logging before ImageMagick execution
        if (file_exists($temp_tiff_filename)) {
            $tiff_size = filesize($temp_tiff_filename);
            error_log("[{$timestamp}] [Jet Job {$job_id}] TIFF input file verified: {$tiff_size} bytes");
        }
        
        // Check if output directory exists and is writable
        $output_dir = dirname($output_path);
        if (!is_dir($output_dir)) {
            error_log("[{$timestamp}] [Jet Job {$job_id}] Creating output directory: {$output_dir}");
            @mkdir($output_dir, 0775, true);
        }
        
        if (!is_writable($output_dir)) {
            error_log("[{$timestamp}] [Jet Job {$job_id}] WARNING: Output directory not writable: {$output_dir}");
        }
        
        // Set moderate priority for ImageMagick for parallel processing
        $magick_isolated_cmd = "start /NORMAL /AFFINITY 0x3 /B /WAIT cmd /c \"" . $magick_tiff_to_jpg_cmd . "\"";
        $start_time = microtime(true);
        $magick_output = shell_exec($magick_isolated_cmd);
        $magick_time = round((microtime(true) - $start_time) * 1000, 2);
        error_log("[{$timestamp}] [Jet Job {$job_id}] ImageMagick execution time: {$magick_time}ms");
        $trimmed_magick_output = trim((string)$magick_output);
        
        // Enhanced diagnostic logging
        if (!empty($trimmed_magick_output)) {
            error_log("[{$timestamp}] [Jet Job {$job_id}] ImageMagick output: {$trimmed_magick_output}");
        }
        
        // Check if output file was created
        if (file_exists($output_path)) {
            $output_size = filesize($output_path);
            error_log("[{$timestamp}] [Jet Job {$job_id}] Output file created with size: {$output_size} bytes");
            
            if ($output_size === 0) {
                error_log("[{$timestamp}] [Jet Job {$job_id}] ERROR: Output file is empty (0 bytes)");
            }
        } else {
            error_log("[{$timestamp}] [Jet Job {$job_id}] ERROR: Output file not created at: {$output_path}");
        }
        
        if (!file_exists($output_path) || filesize($output_path) === 0) {
            $error_details = "Final JPEG not created or empty. ";
            $error_details .= "TIFF size: " . (file_exists($temp_tiff_filename) ? filesize($temp_tiff_filename) : "N/A") . " bytes. ";
            $error_details .= "Output dir writable: " . (is_writable($output_dir) ? "YES" : "NO") . ". ";
            $error_details .= "ImageMagick output: " . ($trimmed_magick_output ?: "EMPTY");
            
            error_log("[{$timestamp}] [Jet Job {$job_id}] dcraw Step 2 FAILED: {$error_details}");
            throw new Exception("dcraw step 2 failed. " . $error_details);
        }
        
        echo "[{$timestamp}] [Job {$job_id}] dcraw SUCCESS: Created cache file: {$output_path}\n";
        error_log("[{$timestamp}] [Jet Job {$job_id}] dcraw SUCCESS: Created cache file with size: " . filesize($output_path));
        
        return "RAW cache created successfully using optimized dcraw pipeline.";
        
    } finally {
        // Always clean up temporary file
        if (file_exists($temp_tiff_filename)) {
            @unlink($temp_tiff_filename);
            error_log("[{$timestamp}] [Jet Job {$job_id}] Temporary TIFF file {$temp_tiff_filename} deleted.");
        }
    }
}

/**
 * Test if ImageMagick is working properly
 */
function test_imagemagick_availability($magick_path) {
    // Quick test to see if ImageMagick executes at all
    $test_cmd = "\"{$magick_path}\" -version 2>&1";
    $test_output = shell_exec($test_cmd);
    $trimmed_output = trim($test_output);
    
    // Check if we got any meaningful output
    if (empty($trimmed_output)) {
        return false; // ImageMagick not executing
    }
    
    // Check if output contains version info (indicates successful execution)
    if (stripos($trimmed_output, 'imagemagick') !== false || 
        stripos($trimmed_output, 'version') !== false ||
        stripos($trimmed_output, 'copyright') !== false) {
        return true; // ImageMagick working
    }
    
    return false; // ImageMagick executing but with errors
}

/**
 * Fallback RAW processing using dcraw only (no ImageMagick resize)
 * For when ImageMagick is completely broken
 */
function process_raw_with_dcraw_only($dcraw_path, $raw_file_path, $output_path, $target_height, $timestamp, $job_id) {
    try {
        // Use dcraw to output PPM format, then convert to JPEG using GD
        $temp_ppm_filename = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'jet_fallback_' . uniqid() . '.ppm';
        
        // dcraw command to output PPM
        $dcraw_options = [];
        $dcraw_options[] = "-q 1";     // AHD interpolation
        $dcraw_options[] = "-a";       // Auto white balance
        $dcraw_options[] = "-o 1";     // sRGB color space
        $dcraw_options[] = "-w";       // Use camera white balance
        $dcraw_options[] = "-4";       // 16-bit linear output
        $dcraw_options[] = "-h";       // Half-size output for speed
        
        $dcraw_opts_string = implode(' ', $dcraw_options);
        $dcraw_cmd = "\"{$dcraw_path}\" {$dcraw_opts_string} -c \"{$raw_file_path}\" > \"{$temp_ppm_filename}\"";
        
        echo "[{$timestamp}] [Job {$job_id}] FALLBACK: Using dcraw-only processing...\n";
        error_log("[{$timestamp}] [Jet Job {$job_id}] FALLBACK dcraw command: {$dcraw_cmd}");
        
        $start_time = microtime(true);
        $dcraw_output = shell_exec($dcraw_cmd . " 2>&1");
        $dcraw_time = round((microtime(true) - $start_time) * 1000, 2);
        error_log("[{$timestamp}] [Jet Job {$job_id}] FALLBACK dcraw execution time: {$dcraw_time}ms");
        
        if (!file_exists($temp_ppm_filename) || filesize($temp_ppm_filename) === 0) {
            throw new Exception("dcraw fallback failed: " . trim($dcraw_output));
        }
        
        // Use GD to resize and convert PPM to JPEG
        $image = @imagecreatefromppm($temp_ppm_filename);
        if (!$image) {
            // Try alternative PPM loading
            $image = @imagecreatefromstring(file_get_contents($temp_ppm_filename));
        }
        
        if (!$image) {
            throw new Exception("Failed to load PPM image for fallback processing");
        }
        
        $original_width = imagesx($image);
        $original_height = imagesy($image);
        
        // Calculate new dimensions maintaining aspect ratio
        $new_height = $target_height;
        $new_width = round(($original_width / $original_height) * $new_height);
        
        // Create resized image
        $resized_image = imagecreatetruecolor($new_width, $new_height);
        imagecopyresampled($resized_image, $image, 0, 0, 0, 0, $new_width, $new_height, $original_width, $original_height);
        
        // Save as JPEG
        $success = imagejpeg($resized_image, $output_path, JPEG_QUALITY);
        
        // Cleanup
        imagedestroy($image);
        imagedestroy($resized_image);
        @unlink($temp_ppm_filename);
        
        if (!$success) {
            throw new Exception("Failed to save JPEG in fallback mode");
        }
        
        echo "[{$timestamp}] [Job {$job_id}] FALLBACK SUCCESS: Created cache using dcraw+GD\n";
        error_log("[{$timestamp}] [Jet Job {$job_id}] FALLBACK SUCCESS: Created cache file with size: " . filesize($output_path));
        
        return "RAW cache created successfully using dcraw+GD fallback (ImageMagick unavailable)";
        
    } catch (Exception $e) {
        if (isset($temp_ppm_filename) && file_exists($temp_ppm_filename)) {
            @unlink($temp_ppm_filename);
        }
        throw $e;
    }
}

/**
 * Photo Mechanic inspired: Extract embedded JPEG preview if suitable
 * This is the #1 speed optimization - avoid full RAW processing when possible
 */
function extract_embedded_jpeg_preview($dcraw_path, $raw_file_path, $output_path, $target_height, $timestamp, $job_id) {
    try {
        // Step 1: Extract embedded JPEG with dcraw -e
        $temp_jpeg_filename = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'jet_embedded_' . uniqid() . '.jpg';
        $escaped_temp_jpeg = escapeshellarg($temp_jpeg_filename);
        $escaped_raw_file = escapeshellarg($raw_file_path);
        
        // Extract embedded JPEG
        $extract_cmd = "\"{$dcraw_path}\" -e -c {$escaped_raw_file} > {$escaped_temp_jpeg}";
        
        echo "[{$timestamp}] [Job {$job_id}] Trying embedded JPEG extraction (Photo Mechanic approach)...\n";
        error_log("[{$timestamp}] [Jet Job {$job_id}] Extracting embedded JPEG. CMD: {$extract_cmd}");
        
        $start_time = microtime(true);
        $extract_output = shell_exec($extract_cmd . " 2>&1");
        $extract_time = round((microtime(true) - $start_time) * 1000, 2);
        
        // Check if extraction successful
        if (!file_exists($temp_jpeg_filename) || filesize($temp_jpeg_filename) < 1024) {
            @unlink($temp_jpeg_filename);
            return false; // Fall back to full RAW processing
        }
        
        // Check embedded JPEG dimensions
        $image_info = @getimagesize($temp_jpeg_filename);
        if (!$image_info || $image_info[1] < EMBEDDED_JPEG_MIN_SIZE) {
            @unlink($temp_jpeg_filename);
            return false; // Embedded JPEG too small
        }
        
        error_log("[{$timestamp}] [Jet Job {$job_id}] Embedded JPEG extracted: {$image_info[0]}x{$image_info[1]}, {$extract_time}ms");
        
        // Step 2: Resize embedded JPEG if needed
        if ($image_info[1] <= $target_height * 1.2) {
            // Embedded JPEG is close to target size, just copy it
            copy($temp_jpeg_filename, $output_path);
            @unlink($temp_jpeg_filename);
            
            echo "[{$timestamp}] [Job {$job_id}] EMBEDDED SUCCESS: Used embedded JPEG directly\n";
            error_log("[{$timestamp}] [Jet Job {$job_id}] EMBEDDED SUCCESS: Direct copy, total time: {$extract_time}ms");
            return "Embedded JPEG used directly (Photo Mechanic approach)";
        } else {
            // Need to resize - but still much faster than full RAW processing
            $magick_path = $GLOBALS['magick_executable_path'];
            if (!$GLOBALS['magick_functional']) {
                @unlink($temp_jpeg_filename);
                return false; // Can't resize without ImageMagick
            }
            
            $resize_cmd = "\"{$magick_path}\" \"{$temp_jpeg_filename}\" -resize x{$target_height} -quality " . JPEG_QUALITY . " \"{$output_path}\"";
            
            $resize_start = microtime(true);
            $resize_output = shell_exec($resize_cmd . " 2>&1");
            $resize_time = round((microtime(true) - $resize_start) * 1000, 2);
            $total_time = round(($resize_start + ($resize_time / 1000) - $start_time) * 1000, 2);
            
            @unlink($temp_jpeg_filename);
            
            if (file_exists($output_path) && filesize($output_path) > 0) {
                echo "[{$timestamp}] [Job {$job_id}] EMBEDDED SUCCESS: Resized embedded JPEG\n";
                error_log("[{$timestamp}] [Jet Job {$job_id}] EMBEDDED SUCCESS: Extracted + resized, total time: {$total_time}ms");
                return "Embedded JPEG extracted and resized (Photo Mechanic approach)";
            }
        }
        
        return false;
        
    } catch (Exception $e) {
        if (isset($temp_jpeg_filename) && file_exists($temp_jpeg_filename)) {
            @unlink($temp_jpeg_filename);
        }
        error_log("[{$timestamp}] [Jet Job {$job_id}] Embedded JPEG extraction failed: " . $e->getMessage());
        return false;
    }
}

/**
 * Ultra-fast dcraw processing inspired by dcraw-fast optimizations
 * Optimized for maximum speed over quality for preview generation
 */
function process_raw_ultra_fast($dcraw_path, $magick_path, $raw_file_path, $output_path, $target_height, $timestamp, $job_id) {
    $escaped_final_cache_path = escapeshellarg($output_path);
    
    // Create optimized temporary JPEG file (skip TIFF for speed)
    $temp_jpeg_filename = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'jet_ultra_' . uniqid() . '.jpg';
    $escaped_temp_jpeg_path = escapeshellarg($temp_jpeg_filename);
    
    try {
        // Ultra-fast dcraw options (inspired by dcraw-fast and Photo Mechanic)
        $dcraw_options = [];
        
        // Maximum speed settings
        $dcraw_options[] = "-q 0";     // Bilinear interpolation (fastest)
        $dcraw_options[] = "-h";       // Half-size output (2x speed boost)
        $dcraw_options[] = "-a";       // Auto white balance (simple)
        $dcraw_options[] = "-w";       // Use camera white balance
        $dcraw_options[] = "-r 1 1 1 1"; // No white balance scaling for speed
        $dcraw_options[] = "-g 2.2 0"; // Standard gamma (skip complex curves)
        
        $dcraw_opts_string = implode(' ', $dcraw_options);
        
        // Direct JPEG output for speed
        $dcraw_to_jpeg_cmd = "\"{$dcraw_path}\" {$dcraw_opts_string} -c \"{$raw_file_path}\" | \"{$magick_path}\" - -resize x{$target_height} -quality " . JPEG_QUALITY . " {$escaped_final_cache_path}";
        
        echo "[{$timestamp}] [Job {$job_id}] Executing ULTRA-FAST dcraw pipeline...\n";
        error_log("[{$timestamp}] [Jet Job {$job_id}] Ultra-fast pipeline. CMD: {$dcraw_to_jpeg_cmd}");
        
        // Execute with high priority for speed
        $ultra_fast_cmd = "start /HIGH /AFFINITY 0xF /B /WAIT cmd /c \"" . $dcraw_to_jpeg_cmd . "\"";
        
        error_log("[{$timestamp}] [Jet Job {$job_id}] Ultra-fast CMD: {$ultra_fast_cmd}");
        $start_time = microtime(true);
        $dcraw_output = shell_exec($ultra_fast_cmd);
        $total_time = round((microtime(true) - $start_time) * 1000, 2);
        error_log("[{$timestamp}] [Jet Job {$job_id}] Ultra-fast execution time: {$total_time}ms");
        
        if (!file_exists($output_path) || filesize($output_path) === 0) {
            error_log("[{$timestamp}] [Jet Job {$job_id}] Ultra-fast FAILED: Output not created");
            throw new Exception("Ultra-fast processing failed. Output: " . trim($dcraw_output));
        }
        
        echo "[{$timestamp}] [Job {$job_id}] ULTRA-FAST SUCCESS: Created cache in {$total_time}ms\n";
        error_log("[{$timestamp}] [Jet Job {$job_id}] ULTRA-FAST SUCCESS: Created cache file with size: " . filesize($output_path));
        
        return "Ultra-fast RAW processing (Photo Mechanic inspired)";
        
    } catch (Exception $e) {
        if (isset($temp_jpeg_filename) && file_exists($temp_jpeg_filename)) {
            @unlink($temp_jpeg_filename);
        }
        throw $e;
    }
}

// Reset stuck processing jobs on startup
try {
    ensure_db_connection(); // Ensure connection before this startup task
    $sql_reset = "UPDATE jet_cache_jobs SET status = 'pending' WHERE status = 'processing'";
    $stmt_reset = $pdo->prepare($sql_reset);
    $affected_rows = execute_pdo_with_retry($stmt_reset); // No params, not a select
    if ($affected_rows > 0) {
        $reset_timestamp = date('Y-m-d H:i:s');
        echo "[{$reset_timestamp}] Reset {$affected_rows} stuck 'processing' jobs back to 'pending'.\n";
        error_log("[{$reset_timestamp}] [Jet Worker Startup] Reset {$affected_rows} stuck 'processing' jobs back to 'pending'.");
    }
} catch (Throwable $e) {
    $reset_fail_timestamp = date('Y-m-d H:i:s');
    echo "[{$reset_fail_timestamp}] Failed to reset processing jobs: " . $e->getMessage() . "\n";
    error_log("[{$reset_fail_timestamp}] [Jet Worker Startup Error] Failed to reset processing jobs: " . $e->getMessage());
}

// Check tool availability with enhanced testing
$dcraw_available = check_dcraw_availability($dcraw_executable_path);
$magick_available = file_exists($magick_executable_path);
$magick_functional = false;

echo "=== Photo Mechanic Inspired RAW Cache Worker ===\n";
echo "Tool availability check:\n";
echo "  - dcraw: " . ($dcraw_available ? "AVAILABLE" : "NOT AVAILABLE") . "\n";
echo "  - ImageMagick file: " . ($magick_available ? "AVAILABLE" : "NOT AVAILABLE") . "\n";

if ($magick_available) {
    // Test if ImageMagick actually works
    echo "  - Testing ImageMagick functionality...\n";
    $magick_functional = test_imagemagick_availability($magick_executable_path);
    echo "  - ImageMagick functional: " . ($magick_functional ? "YES" : "NO") . "\n";
    
    if (!$magick_functional) {
        echo "  - ImageMagick detected but not working - FALLBACK MODE enabled\n";
        error_log("[" . date('Y-m-d H:i:s') . "] [Jet Worker Startup] ImageMagick exists but not functional - enabling fallback mode");
    }
}

// Make magick_functional globally accessible
$GLOBALS['magick_functional'] = $magick_functional;
$GLOBALS['magick_executable_path'] = $magick_executable_path;

echo "\n=== Photo Mechanic Speed Optimizations Enabled ===\n";
echo "  - Embedded JPEG extraction: " . (USE_EMBEDDED_JPEG_WHEN_POSSIBLE ? "ENABLED" : "DISABLED") . "\n";
echo "  - Ultra-fast dcraw processing: ENABLED\n";
echo "  - Fallback processing: ENABLED\n";
echo "  - Expected speed improvement: 2x-10x faster\n";
echo "===============================================\n\n";

error_log("[" . date('Y-m-d H:i:s') . "] [Jet Worker Startup] Photo Mechanic optimizations enabled - dcraw: " . ($dcraw_available ? "YES" : "NO") . ", ImageMagick: " . ($magick_available ? "YES" : "NO") . ", ImageMagick functional: " . ($magick_functional ? "YES" : "NO"));

if (!$dcraw_available) {
    echo "ERROR: dcraw is required but not available.\n";
    error_log("[" . date('Y-m-d H:i:s') . "] [Jet Worker Startup Error] dcraw not available - cannot process RAW files.");
    exit(1);
}

if (!$magick_available && !$magick_functional) {
    echo "WARNING: ImageMagick not available or not functional - using dcraw+GD fallback mode\n";
    error_log("[" . date('Y-m-d H:i:s') . "] [Jet Worker Startup Warning] ImageMagick not available - using fallback mode");
}

// Worker variables - optimized for 5 concurrent processes
$sleep_interval = 1; // Polling interval for 5 processes (1 second)
$running = true;
$run_once = isset($_ENV['WORKER_RUN_ONCE']) && $_ENV['WORKER_RUN_ONCE'] === '1';

if ($run_once) {
    echo "Worker running in 'run once' mode - will exit after processing available jobs.\n";
}

// Signal handling for graceful shutdown
if (function_exists('pcntl_signal')) {
    declare(ticks = 1);
    function signal_handler($signo) {
        global $running;
        $timestamp = date('Y-m-d H:i:s');
        echo "\n[{$timestamp}] Received signal {$signo}. Shutting down gracefully...\n";
        error_log("[{$timestamp}] [Jet Worker Signal] Received signal {$signo}. Initiating shutdown.");
        $running = false;
    }
    pcntl_signal(SIGTERM, 'signal_handler');
    pcntl_signal(SIGINT, 'signal_handler');
}

// Main worker loop with parallel processing
echo "Entering Photo Mechanic optimized Jet cache worker loop (5 concurrent processes with speed optimizations)...\n";
while ($running) {
    $jobs = [];
            try {
            ensure_db_connection(); // Ensure DB is connected at the start of each loop iteration
            // Get next 5 pending jobs for parallel processing (reduced for stability)
            $sql_get_jobs = "SELECT * FROM jet_cache_jobs 
                            WHERE status = 'pending' 
                            ORDER BY created_at ASC 
                            LIMIT 5";
            $stmt_get = $pdo->prepare($sql_get_jobs);
            $jobs = execute_pdo_with_retry($stmt_get, [], false, true); // is_select_fetchall = true

                if (!empty($jobs)) {
            $timestamp = date('Y-m-d H:i:s');
            $worker_id = gethostname() . "_" . getmypid();
            $job_count = count($jobs);
            echo "[{$timestamp}] Starting parallel processing of {$job_count} jobs (5 concurrent dcraw processes)...\n";
            
            // Update all jobs to processing status first
            $job_ids = [];
            foreach ($jobs as $job) {
                $job_ids[] = $job['id'];
                try {
                    $sql_update = "UPDATE jet_cache_jobs SET status = 'processing', processed_at = ?, worker_id = ? WHERE id = ?";
                    $stmt_update = $pdo->prepare($sql_update);
                    $stmt_update->execute([time(), $worker_id, $job['id']]);
                } catch (Exception $db_e) {
                    error_log("[{$timestamp}] [Jet Job {$job['id']}] DB update warning: " . $db_e->getMessage());
                }
            }
            
            // Process all jobs in parallel
            $parallel_processes = [];
            foreach ($jobs as $job_index => $job) {
                $job_id = $job['id'];
                $source_key = $job['source_key'];
                $image_relative_path = $job['image_relative_path'];
                $cache_size = $job['cache_size'];
                
                echo "[{$timestamp}] [Job {$job_id}] Starting parallel processing: {$source_key}/{$image_relative_path}\n";
                
                // Validate RAW source
                if (!isset(RAW_IMAGE_SOURCES[$source_key])) {
                    echo "[{$timestamp}] [Job {$job_id}] ERROR: Invalid RAW source key: {$source_key}\n";
                    continue;
                }

                $source_config = RAW_IMAGE_SOURCES[$source_key];
                $base_raw_path = rtrim($source_config['path'], '/\\');
                
                // Construct full file path
                $full_raw_file_path = $base_raw_path . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $image_relative_path);
                $full_raw_file_path_realpath = realpath($full_raw_file_path);

                if (!$full_raw_file_path_realpath || !is_file($full_raw_file_path_realpath)) {
                    echo "[{$timestamp}] [Job {$job_id}] ERROR: RAW file not found: {$full_raw_file_path}\n";
                    continue;
                }

                // Get cache path using helper function
                $cached_preview_full_path = get_jet_cache_path($source_key, $image_relative_path, $cache_size);
                $cache_dir_path = dirname($cached_preview_full_path);

                // Create cache directory if needed
                if (!is_dir($cache_dir_path)) {
                    @mkdir($cache_dir_path, 0775, true);
                }

                // Check if file already exists
                if (file_exists($cached_preview_full_path) && filesize($cached_preview_full_path) > 0) {
                    echo "[{$timestamp}] [Job {$job_id}] Cache file already exists, skipping.\n";
                    
                    // Update job status to completed
                    try {
                        $sql_finish = "UPDATE jet_cache_jobs SET status = 'completed', completed_at = ?, result_message = 'Cache file already exists', final_cache_path = ? WHERE id = ?";
                        $stmt_finish = $pdo->prepare($sql_finish);
                        $stmt_finish->execute([time(), $cached_preview_full_path, $job_id]);
                    } catch (Exception $db_e) {
                        error_log("[{$timestamp}] [Job {$job_id}] DB update warning: " . $db_e->getMessage());
                    }
                    continue;
                }
                
                // Validate RAW file format and determine processing method
                try {
                    $file_validation = validate_raw_file_integrity($full_raw_file_path_realpath, $timestamp, $job_id);
                    $detected_format = $file_validation['format'];
                    
                    // Process using Photo Mechanic inspired optimizations
                    $processing_result = null;
                    $processing_start_time = microtime(true);
                    
                    // SPEED OPTIMIZATION 1: Try embedded JPEG extraction first (Photo Mechanic approach)
                    if (USE_EMBEDDED_JPEG_WHEN_POSSIBLE) {
                        echo "[{$timestamp}] [Job {$job_id}] Attempting Photo Mechanic approach: embedded JPEG extraction\n";
                        $processing_result = extract_embedded_jpeg_preview(
                            $dcraw_executable_path,
                            $full_raw_file_path_realpath,
                            $cached_preview_full_path,
                            $cache_size,
                            $timestamp,
                            $job_id
                        );
                        
                        if ($processing_result !== false) {
                            echo "[{$timestamp}] [Job {$job_id}] SUCCESS: Used embedded JPEG (Photo Mechanic approach)\n";
                        } else {
                            echo "[{$timestamp}] [Job {$job_id}] Embedded JPEG not suitable, falling back to RAW processing\n";
                        }
                    }
                    
                    // SPEED OPTIMIZATION 2: Ultra-fast dcraw processing if embedded JPEG failed
                    // Skip ultra-fast processing for CR3 since dcraw doesn't support CR3
                    if ($processing_result === false && $magick_functional && $detected_format !== 'Canon CR3') {
                        echo "[{$timestamp}] [Job {$job_id}] Attempting ultra-fast RAW processing (dcraw-fast inspired)\n";
                        $processing_result = process_raw_ultra_fast(
                            $dcraw_executable_path,
                            $magick_executable_path,
                            $full_raw_file_path_realpath,
                            $cached_preview_full_path,
                            $cache_size,
                            $timestamp,
                            $job_id
                        );
                        
                        if ($processing_result !== false) {
                            echo "[{$timestamp}] [Job {$job_id}] SUCCESS: Used ultra-fast RAW processing\n";
                        } else {
                            echo "[{$timestamp}] [Job {$job_id}] Ultra-fast processing failed, falling back to standard processing\n";
                        }
                    }
                    
                    // SPEED OPTIMIZATION 3: Direct CR3 processing (skip ultra-fast for CR3)
                    if ($processing_result === false && $detected_format === 'Canon CR3' && $magick_functional) {
                        echo "[{$timestamp}] [Job {$job_id}] Detected CR3 format, using direct ImageMagick processing (skip dcraw)\n";
                        $processing_result = process_cr3_with_imagemagick(
                            $magick_executable_path,
                            $full_raw_file_path_realpath,
                            $cached_preview_full_path,
                            $cache_size,
                            $timestamp,
                            $job_id
                        );
                        
                        if ($processing_result !== false) {
                            echo "[{$timestamp}] [Job {$job_id}] SUCCESS: Used direct CR3 ImageMagick processing\n";
                        }
                    }
                    
                    // FALLBACK: Original processing methods (only if all speed optimizations fail)
                    if ($processing_result === false) {
                        echo "[{$timestamp}] [Job {$job_id}] Using fallback processing methods\n";
                        
                        // CR3 files are already handled above in SPEED OPTIMIZATION 3
                        // Only process non-CR3 RAW formats here
                        if ($detected_format !== 'Canon CR3') {
                            // Other RAW formats - use ImageMagick pipeline if available, fallback if not
                            if ($magick_functional) {
                                echo "[{$timestamp}] [Job {$job_id}] Detected format: {$detected_format}, using dcraw+ImageMagick pipeline\n";
                                $processing_result = process_raw_with_optimized_dcraw(
                                    $dcraw_executable_path,
                                    $magick_executable_path,
                                    $full_raw_file_path_realpath,
                                    $cached_preview_full_path,
                                    $cache_size,
                                    $timestamp,
                                    $job_id
                                );
                            } else {
                                echo "[{$timestamp}] [Job {$job_id}] Detected format: {$detected_format}, ImageMagick not functional - using dcraw+GD fallback\n";
                                $processing_result = process_raw_with_dcraw_only(
                                    $dcraw_executable_path,
                                    $full_raw_file_path_realpath,
                                    $cached_preview_full_path,
                                    $cache_size,
                                    $timestamp,
                                    $job_id
                                );
                            }
                        } else {
                            // CR3 fallback when ImageMagick not functional (rare case)
                            if (!$magick_functional) {
                                echo "[{$timestamp}] [Job {$job_id}] CR3 detected but ImageMagick not functional - using dcraw+GD fallback (may fail)\n";
                                $processing_result = process_raw_with_dcraw_only(
                                    $dcraw_executable_path,
                                    $full_raw_file_path_realpath,
                                    $cached_preview_full_path,
                                    $cache_size,
                                    $timestamp,
                                    $job_id
                                );
                            }
                        }
                    }
                    
                    $processing_time = round((microtime(true) - $processing_start_time) * 1000, 2);
                    
                    // Validate the output
                    if (file_exists($cached_preview_full_path) && filesize($cached_preview_full_path) > 0) {
                        // Additional JPEG validation
                        $jpeg_validation = validate_jpeg_output($cached_preview_full_path, $timestamp, $job_id);
                        
                        echo "[{$timestamp}] [Job {$job_id}] SUCCESS: Completed in {$processing_time}ms, output: {$jpeg_validation['width']}x{$jpeg_validation['height']}\n";
                        
                        // Update job status to completed
                        try {
                            $sql_finish = "UPDATE jet_cache_jobs SET status = 'completed', completed_at = ?, result_message = ?, final_cache_path = ? WHERE id = ?";
                                $stmt_finish = $pdo->prepare($sql_finish);
                            $stmt_finish->execute([time(), $processing_result, $cached_preview_full_path, $job_id]);
                            } catch (Exception $db_e) {
                            error_log("[{$timestamp}] [Job {$job_id}] DB update warning: " . $db_e->getMessage());
                            }
                        } else {
                        throw new Exception("Processing completed but output file was not created or is empty");
                    }
                    
                } catch (Exception $processing_error) {
                    $processing_time = round((microtime(true) - $processing_start_time) * 1000, 2);
                    $error_message = $processing_error->getMessage();
                    
                    echo "[{$timestamp}] [Job {$job_id}] FAILED after {$processing_time}ms: {$error_message}\n";
                    error_log("[{$timestamp}] [Jet Job {$job_id}] Processing failed: {$error_message}");
                    
                            // Update job status to failed
                            try {
                                $sql_fail = "UPDATE jet_cache_jobs SET status = 'failed', completed_at = ?, result_message = ? WHERE id = ?";
                                $stmt_fail = $pdo->prepare($sql_fail);
                        $stmt_fail->execute([time(), "Processing failed: " . $error_message, $job_id]);
                            } catch (Exception $db_e) {
                        error_log("[{$timestamp}] [Job {$job_id}] DB fail update warning: " . $db_e->getMessage());
                    }
                }
            }
            
            echo "[{$timestamp}] Batch processing completed.\n";

        } else {
            // No jobs found
            if ($run_once) {
                echo "No more jobs to process. Exiting (run once mode).\n";
                $running = false;
            } else {
                // Sleep and continue in continuous mode
                sleep($sleep_interval);
            }
        }

    } catch (Throwable $e) {
        $error_timestamp = date('Y-m-d H:i:s');
        echo "[{$error_timestamp}] [Worker Error] " . $e->getMessage() . "\n";
        error_log("[{$error_timestamp}] [Jet Worker Error] " . $e->getMessage());
        
        // If we have jobs, mark them as failed
        if (!empty($jobs)) {
            try {
                ensure_db_connection(); // Ensure connection before this critical update
                foreach ($jobs as $job) {
                    $sql_fail = "UPDATE jet_cache_jobs SET status = 'failed', completed_at = ?, result_message = ? WHERE id = ?";
                    $stmt_fail = $pdo->prepare($sql_fail);
                    $stmt_fail->execute([time(), "Worker error: " . $e->getMessage(), $job['id']]);
                }
            } catch (Throwable $fail_e) {
                error_log("[{$error_timestamp}] [Jet Worker Error] Failed to mark jobs as failed after primary error: " . $fail_e->getMessage());
            }
        }
        
        // Sleep before retrying to avoid rapid error loops
        sleep($sleep_interval);
    }
}

echo "Enhanced Jet Cache Worker Shutting Down - " . date('Y-m-d H:i:s') . "\n";
error_log("[" . date('Y-m-d H:i:s') . "] [Jet Worker] Enhanced worker shutdown completed.");
?> 