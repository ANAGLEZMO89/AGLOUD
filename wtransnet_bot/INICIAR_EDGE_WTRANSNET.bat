@echo off
chcp 65001 >nul
REM Abre una ventana de Microsoft Edge con un PERFIL PROPIO DEL BOT y el puerto
REM de depuracion 9222 escuchando solo en este equipo (127.0.0.1).
REM - No modifica tu perfil TRABAJO ni su configuracion de seguridad.
REM - En esa ventana inicias sesion en Wtransnet TU MISMA (el bot no toca credenciales).
REM - Mientras esta ventana este abierta, cualquier programa de este PC podria
REM   controlarla: cierrala cuando termines.
set "PERFIL=%LOCALAPPDATA%\WtransnetBot\PerfilEdge"
set "EDGE=%ProgramFiles(x86)%\Microsoft\Edge\Application\msedge.exe"
if not exist "%EDGE%" set "EDGE=%ProgramFiles%\Microsoft\Edge\Application\msedge.exe"
if not exist "%EDGE%" (
  echo No encuentro msedge.exe. Revisa la ruta de instalacion de Edge.
  pause & exit /b 1
)
start "" "%EDGE%" --remote-debugging-port=9222 --user-data-dir="%PERFIL%" --no-first-run "https://app.wtransnet.com/WTNWEB/"
echo Edge abierto. Inicia sesion en Wtransnet en esa ventana y despues abre INICIAR_BOT.bat
timeout /t 8 >nul
