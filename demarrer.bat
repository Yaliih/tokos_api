@echo off
REM Lance le serveur Sosa pour qu'il soit joignable depuis les telephones du meme reseau (Wi-Fi ou partage de connexion).
cd /d "%~dp0"
echo.
echo Adresses de ce PC (a mettre dans l'app : http://ADRESSE:8000) :
for /f "tokens=2 delims=:" %%a in ('ipconfig ^| findstr /c:"IPv4"') do for /f "tokens=1 delims=( " %%b in ("%%a") do echo    http://%%b:8000
echo.
echo Serveur lance. Laisse cette fenetre ouverte (Ctrl+C pour arreter).
php artisan serve --host=0.0.0.0 --port=8000
