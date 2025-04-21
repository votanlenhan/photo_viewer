@echo off
cd /d "%~dp0"
"C:\xampp\php\php.exe" cron_cache_manager.php >> logs\cron-output.log 2>&1 