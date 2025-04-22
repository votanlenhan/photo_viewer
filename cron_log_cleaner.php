<?php

// Enhanced Log Cleaner
// - Deletes .log files older than maxAgeDays.
// - Rotates active .log files exceeding maxSizeBytes by renaming them with a timestamp.
// - Deletes rotated log files (*.log.YYYYMMDD_HHMMSS) older than maxAgeDays.

error_reporting(E_ALL);
ini_set('display_errors', 0); // Don't display errors to stdout (they'll go to php-error.log if configured)
ini_set('log_errors', 1);

// --- Configuration (Load from central config) ---
$config = require_once __DIR__ . '/config.php';
if (!$config) {
    echo "ERROR: Failed to load config.php in cron_log_cleaner.php\n";
    error_log("CRITICAL CONFIG ERROR: Failed to load config.php in cron_log_cleaner.php");
    exit(1);
}

$logDirectory = __DIR__ . '/logs'; // Keep log directory relative to script for now
$maxAgeDays = $config['log_max_age_days'] ?? 30; // Use config value or default
$maxSizeBytes = $config['log_max_size_bytes'] ?? (50 * 1024 * 1024); // Use config value or default (50MB)
$logFilePattern = '*.log';        // Pattern for active log files
$rotatedFilePattern = '*.log.*';   // Pattern for rotated log files (e.g., file.log.20231027_103000)
// ---------------------

$maxAgeSeconds = $maxAgeDays * 24 * 60 * 60;
$now = time();
$cutoffTime = $now - $maxAgeSeconds;
$rotationSuffix = date('Ymd_His');

echo "--- Log Cleaner Started: " . date('Y-m-d H:i:s') . " ---\n";
echo "Log Directory: {$logDirectory}\n";
echo "Max Age: {$maxAgeDays} days (Cutoff: " . date('Y-m-d H:i:s', $cutoffTime) . ")\n";
echo "Max Size for Rotation: " . round($maxSizeBytes / 1024 / 1024, 2) . " MB\n";

if (!is_dir($logDirectory)) {
    echo "ERROR: Log directory not found: {$logDirectory}\n";
    exit(1);
}

// --- Phase 1: Process Active Log Files (*.log) ---
echo "\nProcessing active log files ({$logFilePattern}):\n";
$activeLogFiles = glob($logDirectory . '/' . $logFilePattern);
$rotatedCount = 0;
$deletedActiveCount = 0;

if ($activeLogFiles === false) {
    echo "ERROR: Failed to scan for active log files.\n";
} elseif (empty($activeLogFiles)) {
    echo "No active log files found.\n";
} else {
    foreach ($activeLogFiles as $file) {
        if (!is_file($file)) continue;

        $filemtime = @filemtime($file);
        $filesize = @filesize($file);

        if ($filemtime === false) {
            echo "ERROR: Could not get modification time for {$file}\n";
            continue;
        }

        // Option 1: Delete if old
        if ($filemtime < $cutoffTime) {
            echo "Deleting old active log: " . basename($file) . " (Last modified: " . date('Y-m-d H:i:s', $filemtime) . ")\n";
            if (@unlink($file)) {
                $deletedActiveCount++;
            } else {
                echo "ERROR: Failed to delete file: " . basename($file) . "\n";
            }
        }
        // Option 2: Rotate if too large (and not old enough to delete)
        elseif ($filesize !== false && $filesize > $maxSizeBytes) {
            $newName = $file . '.' . $rotationSuffix;
            echo "Rotating large active log: " . basename($file) . " to " . basename($newName) . " (Size: " . round($filesize / 1024 / 1024, 2) . " MB)\n";
            if (@rename($file, $newName)) {
                $rotatedCount++;
                // Try to ensure the original file can be recreated immediately
                @touch($file);
                @chmod($file, 0666); // Adjust permissions as needed
            } else {
                echo "ERROR: Failed to rotate file: " . basename($file) . "\n";
            }
        } elseif ($filesize === false) {
             echo "ERROR: Could not get size for file: " . basename($file) . "\n";
        }
    }
    if ($deletedActiveCount > 0) echo "Deleted {$deletedActiveCount} old active log file(s).\n";
    if ($rotatedCount > 0) echo "Rotated {$rotatedCount} large active log file(s).\n";
    if ($deletedActiveCount == 0 && $rotatedCount == 0) echo "No active logs needed deletion or rotation.\n";
}

// --- Phase 2: Process Rotated Log Files (*.log.*) ---
echo "\nProcessing rotated log files ({$rotatedFilePattern}):\n";
$rotatedLogFiles = glob($logDirectory . '/' . $rotatedFilePattern);
$deletedRotatedCount = 0;

if ($rotatedLogFiles === false) {
    echo "ERROR: Failed to scan for rotated log files.\n";
} elseif (empty($rotatedLogFiles)) {
    echo "No rotated log files found.\n";
} else {
    foreach ($rotatedLogFiles as $file) {
        if (!is_file($file)) continue;

        $filemtime = @filemtime($file);

        if ($filemtime !== false && $filemtime < $cutoffTime) {
            echo "Deleting old rotated log: " . basename($file) . " (Last modified: " . date('Y-m-d H:i:s', $filemtime) . ")\n";
            if (@unlink($file)) {
                $deletedRotatedCount++;
            } else {
                echo "ERROR: Failed to delete file: " . basename($file) . "\n";
            }
        } elseif ($filemtime === false) {
            echo "ERROR: Could not get modification time for rotated file: " . basename($file) . "\n";
        }
    }
     if ($deletedRotatedCount > 0) {
        echo "Deleted {$deletedRotatedCount} old rotated log file(s).\n";
    } else {
        echo "No old rotated logs found to delete.\n";
    }
}

echo "\n--- Log Cleaner Finished: " . date('Y-m-d H:i:s') . " ---\n";
exit(0);

?> 