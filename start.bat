@echo off
cd /d "%~dp0"
"C:\php83\php.exe" artisan serve --host=127.0.0.1 --port=8000
