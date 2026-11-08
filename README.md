# AG Cloud · GA AGOPYME

Plataforma de gestión de la gestoría: **AG Cloud** (equipo del despacho) y **Portal del cliente**.
Reconstrucción como aplicación real del prototipo `AG Cloud.dc.html`.

| | |
|---|---|
| Estado | **Fase 1** (base, clientes y gestor documental). Ver [`documentacion/FASE-1.md`](documentacion/FASE-1.md) |
| Despliegue | Hostinger · [`documentacion/DESPLIEGUE-HOSTINGER.md`](documentacion/DESPLIEGUE-HOSTINGER.md) |
| Stack | PHP 8.3 · Laravel 13 · MySQL/MariaDB · Blade + CSS/JS propios (sin Node en el servidor) |

## Seguridad (resumen)

- **Roles**: `admin_gestoria`, `asesor` (despacho) · `cliente_admin`, `cliente_empleado` (portal). Comprobados en el servidor en cada petición (`app/Http/Middleware/Rol.php`).
- **Multi-tenant**: todo dato de cliente lleva `cliente_id`. Un filtro global (`app/Models/Concerns/PerteneceACliente.php`, `Cliente::booted`, `Documento::booted`) limita **cualquier** consulta de un usuario del portal a su cliente, y una política (`app/Policies/DocumentoPolicy.php`) lo vuelve a comprobar. Tests en `tests/Feature/AislamientoTest.php`.
- **Contraseñas** con bcrypt; recuperación por correo; **2FA TOTP** opcional con códigos de recuperación; bloqueo tras 5 intentos; cambio obligatorio de la contraseña provisional.
- **Documentos cifrados en reposo** (XChaCha20-Poly1305, libsodium) en `storage/app/privado`, fuera de la carpeta pública, servidos solo por controlador tras comprobar permisos. Clave propia `DOCS_KEY`.
- **Datos sensibles de la ficha** (IBAN, socios, certificados y sus contraseñas) cifrados en la base de datos.
- **Registro de accesos** (`audit_logs`), solo inserción.
- **HTTPS obligatorio**, HSTS, CSP estricta (sin scripts en línea), cookies de sesión cifradas y `Secure`.
- **Copias diarias** (BD + documentos) en ZIP AES-256, en el servidor y en Google Drive.

## Desarrollo local

```bash
composer install
cp .env.example .env   # APP_ENV=local, DB_CONNECTION=sqlite, MAIL_MAILER=log, SESSION_SECURE_COOKIE=false
php artisan key:generate
touch database/database.sqlite && php artisan migrate
php artisan agcloud:instalar            # genera DOCS_KEY, despacho y administrador
php artisan db:seed --class=DemoSeeder  # SOLO pruebas: usuarios @demo.test, contraseña Prueba2026
php artisan serve
php artisan test                        # 37 tests (aislamiento, acceso, cifrado, copias)
```

## Estructura

```
app/Http/Controllers   Escritorio, Clientes (+ secciones y accesos), Documentos, Cuenta, Admin/, Portal/
app/Services           AlmacenDocumentos (cifrado), Auditoria, AccesosPortal, CopiaSeguridad, GoogleDrive
app/Support            Formato (es-ES), Validacion (NIF/IBAN), ClasificadorDocumentos, SeccionesAlta
config/agcloud.php     Catálogos del prototipo: carpetas, plazos de conservación, regímenes, menú…
resources/views        Vistas Blade fieles al prototipo
public/css|js|fonts    Estilos, JS y tipografías Onest/Geist Mono autoalojadas (sin Google Fonts: RGPD)
despliegue/            instalar.sh y actualizar.sh para Hostinger
```

## Reglas del proyecto

- Formato español en importes y fechas (`1.234,56 €` · `dd/mm/aaaa`).
- Sin datos de ejemplo en producción (`DemoSeeder` se niega a ejecutarse en `production`).
- **No** hay envío de ficheros a AEAT, RED, SEPE ni DEHú: solo se hará cuando se pida expresamente y tras validar los diseños de registro oficiales vigentes.
