@echo off
chcp 65001 >nul
cd /d "%~dp0"
if not exist ".venv\Scripts\python.exe" (
  echo Preparando el entorno por primera vez...
  py -3 -m venv .venv 2>nul || python -m venv .venv
  if errorlevel 1 (
    echo No se encuentra Python 3. Instala Python 3.10 o superior desde python.org y marca "Add to PATH".
    pause & exit /b 1
  )
  ".venv\Scripts\python.exe" -m pip install --upgrade pip -q
  ".venv\Scripts\python.exe" -m pip install -r requirements.txt -q
)
".venv\Scripts\python.exe" -m wtbot %*
if errorlevel 1 pause
