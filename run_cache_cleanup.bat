@echo off
cd /d "%~dp0"

echo === Running Cache Cleanup: %date% %time% === >> logs\cron-output.log
"C:\xampp\php\php.exe" cron_cache_manager.php >> logs\cron-output.log 2>&1

echo === Running Log Cleanup: %date% %time% === >> logs\cron-output.log
"C:\xampp\php\php.exe" cron_log_cleaner.php >> logs\cron-output.log 2>&1

echo === Finished Maintenance Tasks: %date% %time% === >> logs\cron-output.log 