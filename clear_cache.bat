@echo off
cd /d C:\hris-laravel-docker\storage\framework\views
for %%F in (*.php) do (
    if not "%%F"==".gitignore" del "%%F"
)
echo View cache cleared.
