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
            // No need to mkdir here, API will create if needed, or cleanup doesn't care
            $thumbPath = $sizeDir . DIRECTORY_SEPARATOR . $cacheFilename;
            $validThumbnailAbsolutePaths[$thumbPath] = true;
        }
    }
    echo "Found " . count($validOriginalRelativePaths) . " original images, expecting max " . count($validThumbnailAbsolutePaths) . " potential thumbnails.\n"; // Updated message

    $deletedCount = 0;
    $scannedCount = 0;
    try {
        // Check if cache root exists before iterating
        if (!is_dir($cacheThumbRoot)) {
             echo "[Cron Cleanup] Cache root directory does not exist. Nothing to scan.\n";
             return; // Exit function early
        }
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

// --- Main Execution ---
cleanupOrphanedThumbnails();

echo "\nCron Thumbnail Cleanup finished at: " . date('Y-m-d H:i:s') . "\n";

exit(0); // Exit successfully
?> 