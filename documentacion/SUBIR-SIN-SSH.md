# Subir AG Cloud a Hostinger · camino fácil (sin SSH ni comandos)

Todo se hace desde **hPanel** y el navegador. Tiempo: unos 40 minutos.

**Necesitas:**
- El archivo **`agcloud-fase1.zip`** que te he enviado.
- La contraseña del buzón `info@gestoriaagopyme.es`.
- Un gestor de contraseñas.

> Si un menú no se llama exactamente así, usa el buscador de hPanel con la palabra en **negrita**.

---

## 1 · Comprobaciones (5 min)

1. hPanel → **Hosting** → tu plan → **Detalles**. La **ubicación del servidor** debe estar en la UE. Si no lo está, para y pide a Hostinger que lo migre.
2. hPanel → **Avanzado → Configuración de PHP**:
   - **Versión 8.3** (o superior).
   - Extensiones marcadas: `sodium`, `zip`, `pdo_mysql`, `mbstring`, `openssl`, `fileinfo`, `intl`.
   - Opciones: `upload_max_filesize = 25M`, `post_max_size = 128M`, `memory_limit = 256M`, `max_execution_time = 300`.

## 2 · Subdominio y SSL (5 min)

1. hPanel → **Dominios → Subdominios** → crea **`app`** (quedará `app.gestoriaagopyme.es`). Apunta la **carpeta** que te muestra, normalmente `public_html/app`.
2. hPanel → **Seguridad → SSL** → instala el SSL gratuito para `app.gestoriaagopyme.es`. Si aparece, activa **Forzar HTTPS**.

## 3 · Base de datos (3 min)

hPanel → **Bases de datos → Gestión** → crea una nueva:
- nombre `agcloud`;
- usuario `agcloud`;
- contraseña: pulsa **generar** y guárdala en tu gestor.

Apunta los tres datos **completos**, con el prefijo `u123456789_`.

## 4 · Subir el ZIP (10 min)

1. hPanel → **Archivos → Administrador de archivos**.
2. Entra en la carpeta del subdominio (p. ej. `public_html/app`). Si hay un `index.php` o `default.php` de ejemplo, **bórralo**.
3. Pulsa **Subir** (icono de flecha hacia arriba) y elige `agcloud-fase1.zip`.
4. Clic derecho sobre el ZIP → **Extraer** → en esa misma carpeta.
5. Comprueba que dentro de `app` ves carpetas como `app`, `public`, `vendor`, `storage`… directamente, no dentro de otra carpeta. Después borra el ZIP.
6. Renombra **`htaccess-raiz-sin-ssh.txt`** a **`.htaccess`** (clic derecho → Renombrar).
   > ⚠️ **Este paso es imprescindible para la seguridad.** Impide que nadie pueda abrir desde internet tus archivos internos.

## 5 · Configuración: el archivo `.env` (5 min)

1. En la misma carpeta: clic derecho sobre **`.env.example`** → **Copiar** → nombre **`.env`**.
   > Si no ves los archivos que empiezan por punto, activa «Mostrar archivos ocultos» en los ajustes del Administrador.
2. Clic derecho en `.env` → **Editar**. Cambia estas líneas:

   | Línea | Qué poner |
   |---|---|
   | `APP_URL=` | `https://app.gestoriaagopyme.es` |
   | `DB_DATABASE=` · `DB_USERNAME=` · `DB_PASSWORD=` | Los del paso 3 |
   | `MAIL_PASSWORD=` | La contraseña de `info@gestoriaagopyme.es` |
   | `BACKUP_ARCHIVE_PASSWORD=` | Una contraseña **nueva y larga** (20+ caracteres) solo para las copias. Guárdala. |

3. Añade **al final** una línea con una clave de instalación que inventes (mínimo 16 caracteres):

   ```
   INSTALL_TOKEN=mi-clave-instalacion-2026
   ```

4. **Guardar**.

## 6 · Instalar desde el navegador (2 min)

1. Abre **https://app.gestoriaagopyme.es/instalar.php**.
2. Si te avisa de algo (versión de PHP, extensiones, permisos…), corrígelo en hPanel y recarga.
3. Rellena el formulario:
   - la clave de instalación del paso 5;
   - la razón social y el NIF del despacho;
   - **tu nombre y tu correo**.
   - Después pulsa **Instalar**.
4. La pantalla final muestra:
   - tu **contraseña provisional**;
   - **tres claves**: `APP_KEY`, `DOCS_KEY` y `BACKUP_ARCHIVE_PASSWORD`.

   > 🔐 **Guarda las tres claves AHORA en tu gestor de contraseñas.** Sin ellas, los documentos cifrados y las copias **no se pueden recuperar nunca**.

5. El instalador se borra solo y ya no se puede volver a usar.

## 7 · Tarea programada (3 min)

hPanel → **Avanzado → Cron Jobs** → **Personalizado** → frecuencia **cada minuto**. El comando es este (cambia `u123456789` por tu usuario, que aparece en la ruta del Administrador de archivos):

```bash
/usr/bin/php /home/u123456789/domains/gestoriaagopyme.es/public_html/app/artisan schedule:run >> /dev/null 2>&1
```

Así se hacen la copia diaria a las 03:15, la limpieza y el envío de correos.

## 8 · Entrar (2 min)

1. **https://app.gestoriaagopyme.es** → tu correo y la contraseña provisional → te pedirá una nueva.
2. Pulsa tu nombre (abajo a la izquierda) → **Seguridad** → activa la **verificación en dos pasos** y guarda los códigos de recuperación.
3. **Despacho → Copias de seguridad → Hacer copia ahora** para comprobar que funciona.
4. Para que las copias se guarden también fuera del servidor, configura Google Drive con el paso 11 de `DESPLIEGUE-HOSTINGER.md`.

---

## Si algo falla

| Síntoma | Qué hacer |
|---|---|
| Error 500 o página en blanco | Administrador de archivos → `storage/logs/` → abre el último `laravel-….log` y mándame las últimas líneas. Comprueba también que `storage` y `bootstrap/cache` tienen permisos **755**. |
| `instalar.php` dice que no conecta con la base de datos | Revisa los tres datos `DB_` del `.env`, con el prefijo `u123456789_`. |
| Aparece un listado de archivos o el `.env` | No se renombró `htaccess-raiz-sin-ssh.txt` a `.htaccess` (paso 4.6). |
| Los cambios del `.env` no se aplican | Borra los archivos de `bootstrap/cache/` cuyo nombre empieza por `config`. |
