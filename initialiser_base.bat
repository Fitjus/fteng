@echo off
title SONEB - Migration et Importation des Donnees
echo ====================================================
echo      SONEB - Initialisation de la Base de Donnees
echo ====================================================
echo.
cd /d %~dp0backend
echo Execution des migrations et creation des comptes...
C:\xampp\php\php.exe artisan migrate --force
C:\xampp\php\php.exe artisan db:seed --force
echo.
echo ====================================================
echo Base de donnees prete !
echo ====================================================
pause
