@echo off
cd /d "%~dp0"
echo Starting local Node.js server: http://localhost:8000
echo Press Ctrl+C in this window to stop it.
node local-server.js
pause
