@echo off
rem Nettoyage de tokos_api : supprime les fichiers que le projet nutilise plus,
rem puis met la base de donnees a jour (colonnes views_count, cover_type, invitations par numero).
rem Tout reste recuperable avec git (git checkout -- <fichier>).
cd /d "%~dp0"
echo Suppression des fichiers inutilises...
del /q "app\Console\kernel.php" 2>nul
del /q "app\Models\CandidateComment.php" 2>nul
del /q "app\Models\CandidateCommentLike.php" 2>nul
del /q "resources\views\welcome.blade.php" 2>nul
del /q "resources\css\app.css" 2>nul
del /q "resources\js\app.js" 2>nul
rmdir "resources\css" 2>nul
rmdir "resources\js" 2>nul
del /q "vite.config.js" 2>nul
del /q "package.json" 2>nul
del /q ".npmrc" 2>nul
echo.
echo Mise a jour de la base de donnees...
php artisan migrate --force
php artisan optimize:clear
echo.
echo Termine. Tu peux supprimer ce fichier nettoyage.bat.
pause
