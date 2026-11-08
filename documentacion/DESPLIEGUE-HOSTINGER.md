# Cómo subir AG Cloud a Hostinger (paso a paso)

Guía pensada para hacerla tú desde hPanel, sin conocimientos técnicos. Tiempo estimado: **1 hora** la primera vez.

> **Antes de empezar, ten a mano:**
> - Acceso a hPanel (hpanel.hostinger.com).
> - La contraseña del buzón `info@gestoriaagopyme.es` (para que la app envíe correos).
> - Un **gestor de contraseñas** (Bitwarden, 1Password, el de tu navegador…). Vas a guardar 3 claves que **no se pueden perder**.
> - Tu cuenta de GitHub (`anaglezmo89`).

> ⚠️ Los nombres de menús de hPanel cambian a veces. Si algo no aparece exactamente así, búscalo con el buscador de hPanel por la palabra clave en **negrita**.

---

## Paso 1 · Comprobar el plan y dónde está el servidor (RGPD)

1. hPanel → **Hosting** → tu plan → **Detalles / Información del plan**.
2. Apunta:
   - **Nombre del plan** (Premium, Business, Cloud…).
   - **Ubicación del servidor**. Tiene que estar en la **Unión Europea** (Lituania, Países Bajos, Francia, Alemania…). Si dice EE. UU., Reino Unido, Brasil o Asia → **para aquí** y pide a Hostinger migrarlo a la UE antes de meter datos de clientes.
3. Contrato de encargado del tratamiento (art. 28 RGPD): Hostinger lo publica como *Data Processing Agreement*. Descárgalo y guárdalo con tu documentación RGPD.

## Paso 2 · PHP 8.3 y sus ajustes

1. hPanel → **Avanzado → Configuración de PHP** (o *PHP Configuration*).
2. **Versión de PHP**: elige **8.3** (o superior) y guarda.
3. Pestaña **Extensiones**: asegúrate de que están marcadas `sodium`, `zip`, `pdo_mysql`, `mbstring`, `openssl`, `fileinfo` e `intl`.
4. Pestaña **Opciones de PHP**:
   | Opción | Valor |
   |---|---|
   | `upload_max_filesize` | `25M` |
   | `post_max_size` | `128M` |
   | `memory_limit` | `256M` |
   | `max_execution_time` | `300` |
   | `display_errors` | **Off** |

## Paso 3 · Crear el subdominio

1. hPanel → **Dominios → Subdominios**.
2. Escribe `app` (quedará **app.gestoriaagopyme.es**) y créalo. Si no te dejara, usa `agcloud`.
3. Apunta la **carpeta** que te indica (suele ser `public_html/app` o `domains/gestoriaagopyme.es/public_html/app`). La usarás en el paso 6.

## Paso 4 · Certificado SSL (HTTPS obligatorio)

1. hPanel → **Seguridad → SSL**.
2. Instala el SSL gratuito para `app.gestoriaagopyme.es` (puede tardar unos minutos en activarse).
3. Activa **Forzar HTTPS** si aparece la opción. (La app además redirige siempre a HTTPS por su cuenta).

## Paso 5 · Base de datos

1. hPanel → **Bases de datos → Gestión** (MySQL).
2. Crea una base de datos nueva:
   - Nombre: `agcloud` → hPanel le añadirá un prefijo, p. ej. `u123456789_agcloud`.
   - Usuario: `agcloud` → p. ej. `u123456789_agcloud`.
   - Contraseña: genera una **larga** (pulsa el botón de generar) y guárdala en tu gestor.
3. Apunta los tres datos **completos, con el prefijo**.

## Paso 6 · Subir la aplicación (por SSH)

Es la forma segura: la aplicación queda **fuera** de la carpeta pública y solo se publica su carpeta `public`.

### 6.1 Activar SSH
1. hPanel → **Avanzado → Acceso SSH** → **Activar**.
2. Verás algo como: `ssh -p 65002 u123456789@123.45.67.89`. Cópialo.
3. Abre el terminal de tu ordenador (Windows: busca **PowerShell**; Mac: **Terminal**), pega el comando y pulsa Intro. La contraseña es la que indica hPanel (puedes cambiarla ahí).

### 6.2 Dar acceso de solo lectura al repositorio de GitHub
En el terminal (ya conectada al servidor), pega:

```bash
ssh-keygen -t ed25519 -C "hostinger-agcloud" -f ~/.ssh/agcloud -N ""
```

```bash
cat ~/.ssh/agcloud.pub
```

Copia la línea que aparece (empieza por `ssh-ed25519`). En GitHub: repositorio **AGLOUD** → **Settings → Deploy keys → Add deploy key** → título `Hostinger`, pega la línea, **NO** marques «Allow write access» → **Add key**.

Después, en el terminal:

```bash
printf 'Host github.com\n  IdentityFile ~/.ssh/agcloud\n  IdentitiesOnly yes\n' >> ~/.ssh/config
```

### 6.3 Descargar el código
> Hazlo cuando hayas aprobado la fase y los cambios estén fusionados en la rama `main` de GitHub (si no, añade `-b claude/determined-heisenberg-ahfu5o` al `git clone`).

Primero averigua tu carpeta de dominios:

```bash
ls ~/domains
```

Debe aparecer `gestoriaagopyme.es`. Entonces:

```bash
cd ~/domains/gestoriaagopyme.es && git clone git@github.com:ANAGLEZMO89/AGLOUD.git agcloud
```

(Si te pregunta «Are you sure you want to continue connecting», escribe `yes`.)

> El código queda en `~/domains/gestoriaagopyme.es/agcloud`, **fuera** de `public_html`.

### 6.4 Publicar solo la carpeta `public`
Sustituye la carpeta del subdominio (la del paso 3) por un enlace a `agcloud/public`. Si tu carpeta era `public_html/app`:

```bash
cd ~/domains/gestoriaagopyme.es && rm -rf public_html/app && ln -s ~/domains/gestoriaagopyme.es/agcloud/public public_html/app
```

> Si en el paso 3 la carpeta del subdominio era otra, cambia `public_html/app` por la tuya. **Ojo:** `rm -rf` borra la carpeta; asegúrate de que es la del subdominio recién creado (estará vacía o solo con un `index`).

## Paso 7 · Configuración (.env)

```bash
cd ~/domains/gestoriaagopyme.es/agcloud && cp .env.example .env && nano .env
```

Se abre un editor. Muévete con las flechas y rellena:

| Línea | Qué poner |
|---|---|
| `APP_URL` | `https://app.gestoriaagopyme.es` |
| `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | Los del paso 5 (con prefijo) |
| `MAIL_PASSWORD` | La contraseña del buzón `info@gestoriaagopyme.es` |
| `BACKUP_ARCHIVE_PASSWORD` | Una contraseña **nueva y larga** (20+ caracteres) solo para las copias |
| `DESPACHO_NIF`, `DESPACHO_TELEFONO` | Los del despacho |

`APP_KEY` y `DOCS_KEY` **déjalas vacías**: se generan solas en el paso siguiente.
Guarda con **Ctrl + O**, Intro, y sal con **Ctrl + X**.

## Paso 8 · Instalar

```bash
bash despliegue/instalar.sh
```

El script comprueba PHP, instala las dependencias, crea las tablas y te pregunta:
- Razón social y NIF del despacho.
- **Tu nombre y tu correo** → serás la administradora (`admin_gestoria`).

Al final muestra:
1. Tu **contraseña provisional** (cópiala).
2. Tres líneas: `APP_KEY=…`, `DOCS_KEY=…` y `BACKUP_ARCHIVE_PASSWORD=…`.

> 🔐 **Guarda esas tres líneas AHORA en tu gestor de contraseñas.** Si el servidor se pierde y no las tienes, **los documentos y las copias no se pueden descifrar. Nadie puede recuperarlos, ni Hostinger ni yo.**

## Paso 9 · Tarea programada (copias y limpieza)

1. En el terminal, averigua la ruta de PHP 8.3:

   ```bash
   which php && php -v
   ```

   Si `php -v` dice 8.3, usa la ruta que muestra `which php` (p. ej. `/usr/bin/php`). Si dice otra versión, prueba `/opt/alt/php83/usr/bin/php -v`, y si funciona, usa esa ruta.
2. hPanel → **Avanzado → Cron Jobs** → **Personalizado**:
   - Comando (cambia `u123456789` por tu usuario y la ruta de PHP si es otra):

     ```bash
     /usr/bin/php /home/u123456789/domains/gestoriaagopyme.es/agcloud/artisan schedule:run >> /dev/null 2>&1
     ```

   - Frecuencia: **cada minuto** (`* * * * *`).
3. Esa única tarea lanza la copia diaria a las **03:15**, la limpieza a las 04:10 y el envío de correos pendientes.

## Paso 10 · Primer acceso

1. Abre **https://app.gestoriaagopyme.es** → entra con tu correo y la contraseña provisional → te obligará a cambiarla.
2. Pulsa tu nombre (abajo a la izquierda) → **Seguridad** → **Activar verificación en dos pasos**. Escanea el QR con Google Authenticator, Microsoft Authenticator o 1Password, escribe el código y **guarda los 8 códigos de recuperación**.
3. **Despacho → Equipo y accesos** → invita a tu equipo (les llega un correo con contraseña provisional).
4. **Despacho → Copias de seguridad** → **Hacer copia ahora** para comprobar que funciona.

## Paso 11 · Copias en Google Drive (copia fuera del servidor)

Una copia que solo está en el mismo servidor no te protege si pierdes la cuenta. Configúralo una vez:

1. Entra en **console.cloud.google.com** con `agopyme@gmail.com`.
2. Arriba: **Seleccionar proyecto → Proyecto nuevo** → nombre `AG Cloud Copias` → Crear.
3. Menú → **APIs y servicios → Biblioteca** → busca **Google Drive API** → **Habilitar**.
4. **APIs y servicios → Pantalla de consentimiento de OAuth** (o *Google Auth Platform*):
   - Tipo **Externo** → nombre de la app `AG Cloud`, correo de asistencia y de contacto: el tuyo.
   - Ámbitos (*Data access*): añade `.../auth/drive.file` (solo archivos creados por la app).
   - Usuarios de prueba: añade `agopyme@gmail.com`.
   - **Publica la app** («Publicar app» / *Publish app* → En producción). Si se queda «En pruebas», Google invalida el permiso a los 7 días y las copias dejarían de subirse.
5. **APIs y servicios → Credenciales → Crear credenciales → ID de cliente de OAuth**:
   - Tipo: **Aplicación web**.
   - URI de redirección autorizado: `https://app.gestoriaagopyme.es/admin/copias/drive/callback`
   - Crear → copia el **ID de cliente** y el **secreto**.
6. En el servidor:

   ```bash
   cd ~/domains/gestoriaagopyme.es/agcloud && nano .env
   ```

   Rellena `GOOGLE_DRIVE_CLIENT_ID` y `GOOGLE_DRIVE_CLIENT_SECRET`. Guarda y luego:

   ```bash
   php artisan optimize
   ```

7. En AG Cloud: **Despacho → Copias de seguridad → Conectar con Google Drive** → acepta. Google te avisará de que la app no está verificada: es normal porque la app es tuya. Pulsa *Configuración avanzada → Ir a AG Cloud*.
8. Pulsa **Hacer copia ahora** y comprueba que aparece en tu Drive la carpeta «AG Cloud · Copias de seguridad».

## Actualizar a una versión nueva

Cuando te diga que hay cambios aprobados:

```bash
cd ~/domains/gestoriaagopyme.es/agcloud && bash despliegue/actualizar.sh
```

Hace una copia previa, pone la web en mantenimiento un minuto, descarga los cambios, actualiza la base de datos y la vuelve a abrir.

## Restaurar una copia (emergencia)

1. Descarga el ZIP de Drive o de **Copias de seguridad**. Ábrelo con **7-Zip** (Windows) o **Keka** (Mac) usando `BACKUP_ARCHIVE_PASSWORD`.
2. Dentro hay `base-de-datos.sql` (impórtalo en hPanel → Bases de datos → **phpMyAdmin → Importar**) y la carpeta `documentos/` (súbela a `agcloud/storage/app/privado/`).
3. Pon en el `.env` las mismas `APP_KEY` y `DOCS_KEY` que tenías. Sin ellas, los documentos y los datos cifrados no se pueden leer.

---

## Plan B · Si tu plan no tiene SSH

Se puede, pero es menos limpio: todo el proyecto queda dentro de la carpeta pública, protegido por un `.htaccess`.

1. En tu ordenador, en una carpeta con el código, ejecuta `composer install --no-dev -o`. Si no sabes hacerlo, pídemelo y te preparo el ZIP.
2. Comprímelo en ZIP **sin** el `.env`.
3. hPanel → **Archivos → Administrador de archivos** → carpeta del subdominio → **Subir** el ZIP → **Extraer**.
4. Renombra `htaccess-raiz-sin-ssh.txt` a `.htaccess` (en la raíz de esa carpeta).
5. Crea el `.env` desde el administrador de archivos (copia `.env.example`) y rellénalo como en el paso 7.
6. Sin SSH no puedes ejecutar `instalar.sh`. En ese caso avísame y lo resolvemos con una pantalla de instalación de un solo uso.

## Problemas frecuentes

| Síntoma | Solución |
|---|---|
| Página en blanco o «500» | Revisa `storage/logs/laravel-AAAA-MM-DD.log`. Comprueba que `storage` y `bootstrap/cache` tienen permisos de escritura: `chmod -R ug+rwX storage bootstrap/cache`. |
| «Falta DOCS_KEY» | Ejecuta `php artisan agcloud:instalar`. |
| No llegan correos | Comprueba `MAIL_PASSWORD`. Prueba con `php artisan tinker --execute "Mail::raw('prueba', fn(\$m) => \$m->to('tu@correo.es')->subject('Prueba'));"` |
| La copia falla | Mírala en **Copias de seguridad** (muestra el motivo). Si dice `BACKUP_ARCHIVE_PASSWORD`, rellénala en `.env` y ejecuta `php artisan optimize`. |
| Tras editar `.env` no cambia nada | Ejecuta `php artisan optimize` (la configuración queda en caché). |
