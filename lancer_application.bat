@echo off
title SONEB - Demarrage des Serveurs
echo ====================================================
echo      SOCIETE NATIONALE DES EAUX DU BENIN (SONEB)
echo             Lancement de l'Application
echo ====================================================
echo.
echo [1/3] Verification de MySQL dans XAMPP...
echo Veuillez vous assurer que le module MySQL est bien demarre
echo (bouton vert 'Start' a cote de MySQL dans XAMPP Control Panel).
echo.
echo [2/3] Demarrage de l'API Backend Laravel (Port 8000)...
start "SONEB Backend API - Port 8000" cmd /k "cd /d %~dp0backend && C:\xampp\php\php.exe artisan serve --port=8000"
echo.
echo [3/3] Demarrage de l'interface Frontend React (Port 5173)...
start "SONEB Frontend - Port 5173" cmd /k "cd /d %~dp0frontend && npm run dev"
echo.
echo ====================================================
echo Application lancee avec succes !
echo Le portail est accessible a l'adresse :
echo   http://localhost:5173
echo.
echo Reference abonné test disponible : 14000010060BFA
echo Identifiants Admin : admin@soneb.bj / admin123
echo ====================================================
timeout /t 5 >nul
start http://localhost:5173
