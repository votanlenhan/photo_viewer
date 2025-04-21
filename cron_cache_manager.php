<?php
// Script chạy bằng Cron Job để dọn dẹp cache thumbnail mồ côi

// --- Basic Setup ---
ini_set('display_errors', 1); // Hiển thị lỗi khi chạy từ CLI
ini_set('log_errors', 1); // Vẫn ghi log lỗi
ini_set('error_log', __DIR__ . '/logs/cron-error.log'); // Log lỗi riêng cho cron
error_reporting(E_ALL);
set_time_limit(0); // Cho phép script chạy không giới hạn thời gian (quan trọng cho cron)
ignore_user_abort(true); // Tiếp tục chạy ngay cả khi kết nối bị ngắt

echo "Cron Thumbnail Cleanup started at: " . date('Y-m-d H:i:s') . "\n";

// --- Constants and Config ---
try {
    define('IMAGE_ROOT', realpath(__DIR__ . '/images'));
    if (!IMAGE_ROOT) {
         throw new Exception("Failed to resolve IMAGE_ROOT path. Check if 'images' directory exists.");
    }
    define('CACHE_THUMB_ROOT', realpath(__DIR__ . '/cache/thumbnails'));
    if (!CACHE_THUMB_ROOT) {
        // Attempt to create cache/thumbnails directory if it doesn't exist
        $cacheBase = __DIR__ . '/cache';
        $thumbDir = $cacheBase . '/thumbnails';
        if (!is_dir($cacheBase)) @mkdir($cacheBase, 0775);
        if (!is_dir($thumbDir)) @mkdir($thumbDir, 0775);
        // Try realpath again
        define('CACHE_THUMB_ROOT_RETRY', realpath($thumbDir));
        if (!defined('CACHE_THUMB_ROOT_RETRY') || !CACHE_THUMB_ROOT_RETRY) { // Check if define worked
             throw new Exception("Failed to resolve or create CACHE_THUMB_ROOT path: {$thumbDir}");
        }
    }
} catch (Throwable $e) {
    error_log("CRON FATAL ERROR: Failed to define paths: " . $e->getMessage());
    echo "CRON FATAL ERROR: Failed to define paths. Check logs.\n";
    exit(1); // Exit with error code
}
$allowed_ext = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp']; // Dùng trong findAllValidImageRelativePaths
define('THUMBNAIL_SIZES', [150, 750]); // Kích thước thumbnail đang sử dụng

// --- Helper Functions ---

/**
 * Create a thumbnail image using GD library.
 * Copied from api.php - make sure to keep in sync if changes are needed.
 * 
 * @param string $source_path Absolute path to the source image.
 * @param string $cache_path Absolute path to save the thumbnail (INCLUDING the size subdirectory).
 * @param int $thumb_size Desired width/height of the thumbnail (square).
 * @return bool True on success, false on failure.
 */
function create_thumbnail($source_path, $cache_path, $thumb_size = 150) {
    if (!extension_loaded('gd')) {
        error_log("[Cron Thumbs] GD extension is not loaded. Cannot create thumbnail.");
        return false;
    }
    
    // Add check if cache path already exists - no need to recreate
    if (file_exists($cache_path)) {
        return true; // Already exists
    }

    try {
        // Check source readability here as well
        if (!is_readable($source_path)) {
             error_log("[Cron Thumbs] Source image not readable: {$source_path}");
             return false;
        }
        
        $image_info = @getimagesize($source_path);
        if ($image_info === false) {
             error_log("[Cron Thumbs] create_thumbnail: Failed to get image size for: {$source_path}");
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
                    error_log("[Cron Thumbs] create_thumbnail: WebP is not supported by this GD version for: {$source_path}");
                    return false; 
                 }
                break;
            default:
                error_log("[Cron Thumbs] create_thumbnail: Unsupported image type '{$mime}' for: {$source_path}");
                return false;
        }

        if ($source_image === false) {
            error_log("[Cron Thumbs] create_thumbnail: Failed to create image resource from: {$source_path}");
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
            error_log("[Cron Thumbs] create_thumbnail: Failed to create true color image resource.");
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
            error_log("[Cron Thumbs] create_thumbnail: imagecopyresampled failed.");
            imagedestroy($source_image); 
            imagedestroy($thumb_image);
            return false;
        }

        // Save the thumbnail
        $cache_dir = dirname($cache_path);
        if (!is_dir($cache_dir)) {
            if (!@mkdir($cache_dir, 0775, true)) {
                 error_log("[Cron Thumbs] create_thumbnail: Failed to create cache directory: {$cache_dir}");
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
             error_log("[Cron Thumbs] create_thumbnail: Failed to save thumbnail to: {$cache_path}");
             if (file_exists($cache_path)) @unlink($cache_path); 
             return false;
        }
        
        echo "[Cron Thumbs] Created thumbnail: {$cache_path}\n"; // Add log for created thumbs
        return true;

    } catch (Throwable $e) {
        error_log("[Cron Thumbs] create_thumbnail: Exception while creating thumbnail for {$source_path} -> {$cache_path} : " . $e->getMessage());
        if (isset($source_image) && is_resource($source_image)) imagedestroy($source_image);
        if (isset($thumb_image) && is_resource($thumb_image)) imagedestroy($thumb_image);
         if (file_exists($cache_path)) @unlink($cache_path);
        return false;
    }
}

/**
 * Tìm tất cả các file ảnh gốc hợp lệ đệ quy và trả về đường dẫn tương đối.
 * @param string $startPath Đường dẫn bắt đầu quét.
 * @param string $basePath Đường dẫn gốc (IMAGE_ROOT) để tính đường dẫn tương đối.
 * @return array Danh sách đường dẫn tương đối của các file ảnh hợp lệ (dùng /).
 */
function findAllValidImageRelativePaths(string $startPath, string $basePath): array {
    global $allowed_ext;
    $validImagePaths = [];
    if (!is_dir($startPath) || !is_readable($startPath)) {
        return [];
    }
    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($startPath, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($iterator as $fileinfo) {
            if ($fileinfo->isFile()) {
                $extension = strtolower($fileinfo->getExtension());
                if (in_array($extension, $allowed_ext, true)) {
                    $fullPath = $fileinfo->getRealPath();
                    $relativePath = substr($fullPath, strlen($basePath));
                    $relativePath = ltrim(str_replace(DIRECTORY_SEPARATOR, '/', $relativePath), '/');
                    $validImagePaths[] = $relativePath;
                }
            }
        }
    } catch (Exception $e) {
        error_log("[Cron Cleanup] Error finding all valid images in {$startPath}: " . $e->getMessage());
    }
    return $validImagePaths;
}

/**
 * Dọn dẹp các file thumbnail mồ côi trong thư mục cache.
 */
function cleanupOrphanedThumbnails(): void {
    echo "Starting orphaned thumbnail cleanup...\n";
    $cacheThumbRoot = defined('CACHE_THUMB_ROOT_RETRY') ? CACHE_THUMB_ROOT_RETRY : CACHE_THUMB_ROOT;
    if (!$cacheThumbRoot || !is_dir($cacheThumbRoot) || !is_readable($cacheThumbRoot)) {
        error_log("[Cron Cleanup] Thumbnail cache root directory not found or not readable: " . ($cacheThumbRoot ?: 'N/A'));
        echo "[Cron Cleanup] Thumbnail cache root directory not found or not readable. Skipping cleanup.\n";
        return;
    }

    $validOriginalRelativePaths = findAllValidImageRelativePaths(IMAGE_ROOT, IMAGE_ROOT);
    if (empty($validOriginalRelativePaths)) {
        echo "[Cron Cleanup] No valid original images found in IMAGE_ROOT. Potentially cleaning all thumbnails.\n";
    }

    $validThumbnailAbsolutePaths = [];
    foreach ($validOriginalRelativePaths as $relativePath) {
        $cacheHash = md5($relativePath);
        $cacheFilename = $cacheHash . '.jpg';
        foreach (THUMBNAIL_SIZES as $size) {
            $sizeDir = $cacheThumbRoot . DIRECTORY_SEPARATOR . $size;
            if (!is_dir($sizeDir)) @mkdir($sizeDir, 0775, true);
            $thumbPath = $sizeDir . DIRECTORY_SEPARATOR . $cacheFilename;
            $validThumbnailAbsolutePaths[$thumbPath] = true;
        }
    }
    echo "Found " . count($validOriginalRelativePaths) . " original images, expecting max " . count($validThumbnailAbsolutePaths) . " thumbnails.\n";

    $deletedCount = 0;
    $scannedCount = 0;
    try {
        $cacheIterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($cacheThumbRoot, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($cacheIterator as $fileinfo) {
            if ($fileinfo->isFile()) {
                $scannedCount++;
                $cachedFilePath = $fileinfo->getRealPath();
                if (!isset($validThumbnailAbsolutePaths[$cachedFilePath])) {
                    echo "[Cron Cleanup] Deleting orphaned thumbnail: {$cachedFilePath}\n";
                    if (@unlink($cachedFilePath)) {
                        $deletedCount++;
                    } else {
                        error_log("[Cron Cleanup] Failed to delete orphaned thumbnail: {$cachedFilePath}");
                        echo "[Cron Cleanup] FAILED to delete orphaned thumbnail: {$cachedFilePath}\n";
                    }
                }
            }
        }
    } catch (Exception $e) {
        error_log("[Cron Cleanup] Error scanning thumbnail cache directory: " . $e->getMessage());
        echo "[Cron Cleanup] Error scanning thumbnail cache directory. Check logs.\n";
    }
    echo "Scanned {$scannedCount} files in cache. Deleted {$deletedCount} orphaned thumbnails.\n";
    echo "Orphaned thumbnail cleanup finished.\n";
}

/**
 * Quét ảnh gốc và tạo các thumbnail còn thiếu.
 */
function warmupThumbnailCache(): void {
    echo "Starting thumbnail cache warmup...\n";
    $createdCount = 0;
    $failedCount = 0;
    $skippedCount = 0;
    $cacheThumbRoot = defined('CACHE_THUMB_ROOT_RETRY') ? CACHE_THUMB_ROOT_RETRY : CACHE_THUMB_ROOT;
    if (!$cacheThumbRoot) {
        echo "[Cron Warmup] Cache thumb root invalid. Skipping warmup.\n";
        return;
    }

    $validOriginalRelativePaths = findAllValidImageRelativePaths(IMAGE_ROOT, IMAGE_ROOT);
    echo "Found " . count($validOriginalRelativePaths) . " original images to check for warmup.\n";

    foreach ($validOriginalRelativePaths as $relativePath) {
        $originalAbsolutePath = IMAGE_ROOT . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $cacheHash = md5($relativePath);
        $cacheFilename = $cacheHash . '.jpg';

        foreach (THUMBNAIL_SIZES as $size) {
            $sizeDir = $cacheThumbRoot . DIRECTORY_SEPARATOR . $size;
            $thumbPathAbsolute = $sizeDir . DIRECTORY_SEPARATOR . $cacheFilename;

            if (!file_exists($thumbPathAbsolute)) {
                // Attempt to create thumbnail
                if (create_thumbnail($originalAbsolutePath, $thumbPathAbsolute, $size)) {
                    $createdCount++;
                } else {
                    $failedCount++;
                }
            } else {
                $skippedCount++;
            }
        }
    }
    echo "Thumbnail warmup finished. Created: {$createdCount}, Failed: {$failedCount}, Skipped (already exist): {$skippedCount}.\n";
}

// --- Main Execution ---
// Removed metadata update call

cleanupOrphanedThumbnails();

warmupThumbnailCache(); // Add warmup call

echo "\nCron Thumbnail Cleanup & Warmup finished at: " . date('Y-m-d H:i:s') . "\n";

exit(0); // Exit successfully
?> 