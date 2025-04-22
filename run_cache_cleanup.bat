@echo off
echo === Starting Batch Job: %date% %time% === >> logs\cron-debug.log
echo Current Directory Before CD: %CD% >> logs\cron-debug.log
cd /d "%~dp0"
echo Current Directory After CD: %CD% >> logs\cron-debug.log

echo === Running Cache Cleanup: %date% %time% === >> logs\cron-output.log
php.exe cron_cache_manager.php >> logs\cron-output.log 2>&1 
echo Cache Cleanup Exit Code: %errorlevel% >> logs\cron-debug.log

echo === Running Log Cleanup: %date% %time% === >> logs\cron-output.log
php.exe cron_log_cleaner.php >> logs\cron-output.log 2>&1
echo Log Cleanup Exit Code: %errorlevel% >> logs\cron-debug.log

echo === Finished Maintenance Tasks: %date% %time% === >> logs\cron-output.log 
echo === Batch Job Finished: %date% %time% === >> logs\cron-debug.log 