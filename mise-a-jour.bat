@echo off
REM Sosa : mise a jour (admin Filament, tables, formats des videos)
cd /d "%~dp0"
if exist "app\Models\preference.php" ren "app\Models\preference.php" "Preference.php"
php -m | findstr /i "intl" >nul || (echo [!] Active l'extension intl dans php.ini ^(ligne extension=intl^), puis relance ce fichier. & pause & exit /b 1)
REM Admin (Filament 5) : installe une seule fois, ne fait rien si c'est deja la
php -r "exit(is_dir('vendor/filament/filament') ? 0 : 1);" || call composer require "filament/filament:~5.10" -W --no-interaction
php artisan migrate --force
if not exist "public\storage" php artisan storage:link
php artisan media:dimensions
php artisan filament:assets
php artisan optimize:clear
where ffmpeg >nul 2>nul || echo [i] ffmpeg introuvable : les photos sont allegees, mais pas encore les videos et audios (voir FFMPEG_BINARY dans .env).
echo.
echo Termine. Pour ouvrir l'admin : php artisan admin:promote TON_NUMERO  puis  /admin
pause
