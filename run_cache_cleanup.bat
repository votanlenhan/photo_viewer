@echo off
echo === Starting Batch Job: %date% %time% === >> logs\cron-debug.log
echo Current Directory Before CD: %CD% >> logs\cron-debug.log
cd /d "%~dp0"
echo Current Directory After CD: %CD% >> logs\cron-debug.log

:: Auto-detect PHP executable path
set PHP_EXE=
if exist "C:\xampp\php\php.exe" (
    set PHP_EXE="C:\xampp\php\php.exe"
    echo Found PHP at: %PHP_EXE% >> logs\cron-debug.log
) else if exist "D:\xampp\php\php.exe" (
    set PHP_EXE="D:\xampp\php\php.exe"
    echo Found PHP at: %PHP_EXE% >> logs\cron-debug.log
)

if not defined PHP_EXE (
    echo ERROR: Could not find php.exe in C:\xampp\php or D:\xampp\php. Please check XAMPP installation path. >> logs\cron-output.log
    echo ERROR: PHP Path detection failed. >> logs\cron-debug.log
    echo PHP Path Detection Exit Code: 1 >> logs\cron-debug.log
    goto :eof
)

echo === Running Cache Cleanup: %date% %time% === >> logs\cron-output.log
%PHP_EXE% "%~dp0cron_cache_manager.php" >> logs\cron-output.log 2>&1
echo Cache Cleanup Exit Code: %errorlevel% >> logs\cron-debug.log

echo === Running Log Cleanup: %date% %time% === >> logs\cron-output.log
%PHP_EXE% "%~dp0cron_log_cleaner.php" >> logs\cron-output.log 2>&1
echo Log Cleanup Exit Code: %errorlevel% >> logs\cron-debug.log

echo === Finished Maintenance Tasks: %date% %time% === >> logs\cron-output.log
echo === Batch Job Finished: %date% %time% === >> logs\cron-debug.log 