# Conectar Hostinger con GitHub (despliegue automático)

Se hace **una sola vez** y lleva unos 10 minutos. A partir de entonces:

1. Claude sube los cambios aprobados a la rama **`produccion`** de GitHub.
2. Hostinger se los descarga solo, en segundos.
3. La tarea programada aplica sola los cambios de base de datos en el minuto siguiente.

Es el mismo sistema que usa tu otra web.

> Si en hPanel ya tienes conectada la otra web por Git, la pantalla te resultará conocida: es la misma, pero con otro repositorio y otra carpeta.

## 1 · Subdominio, SSL, PHP y base de datos

Si aún no lo has hecho, sigue los pasos 1, 2 y 3 de [`SUBIR-SIN-SSH.md`](SUBIR-SIN-SSH.md):

- PHP 8.3 con sus extensiones;
- subdominio `app` y su certificado SSL;
- base de datos.

**No subas el ZIP**: con Git ya no hace falta.

La carpeta del subdominio debe estar **vacía**. Si Hostinger creó un `index.php` o un `default.php` de ejemplo, bórralo desde el Administrador de archivos.

## 2 · Conectar el repositorio

1. hPanel → **Avanzado → GIT**.
2. En **Crear un nuevo repositorio**:
   - **Repositorio**: `git@github.com:ANAGLEZMO89/AGLOUD.git`
   - **Rama**: `produccion`
   - **Directorio**: la carpeta del subdominio, por ejemplo `app` (Hostinger la toma dentro de `public_html`).
3. Como el repositorio es privado, hPanel muestra una **clave SSH de despliegue**. Cópiala.
4. En GitHub: repositorio **AGLOUD** → **Settings → Deploy keys → Add deploy key**:
   - título `Hostinger app`;
   - pega la clave;
   - **NO** marques «Allow write access»;
   - **Add key**.
5. Vuelve a hPanel y pulsa **Crear**. Después pulsa **Desplegar** para la primera descarga.

## 3 · Activar el despliegue automático

1. En la misma pantalla de GIT, en tu repositorio pulsa **Despliegue automático** (Auto deployment) y copia la **URL del webhook**.
2. En GitHub: **AGLOUD → Settings → Webhooks → Add webhook**:
   - **Payload URL**: la URL que copiaste;
   - **Content type**: `application/json`;
   - **Which events**: *Just the push event*;
   - **Add webhook**.

## 4 · Configurar e instalar

Sigue los pasos 5 a 8 de [`SUBIR-SIN-SSH.md`](SUBIR-SIN-SSH.md):

- archivo `.env` con `INSTALL_TOKEN`;
- `instalar.php`;
- tarea programada (cron) cada minuto;
- primer acceso y verificación en dos pasos.

El `.htaccess` de seguridad ya viene puesto en la rama `produccion`: **no** tienes que renombrar nada.

## Cómo comprobar que las actualizaciones llegan

- **Despacho → Registro de accesos**, filtrando por acción: aparece «Versión aplicada: …» cada vez que se instala una versión nueva.
- En GitHub, **Settings → Webhooks**: cada envío aparece con un ✓ verde.

## Si algo falla

| Síntoma | Qué hacer |
|---|---|
| hPanel dice que la carpeta no está vacía | Borra el contenido de la carpeta del subdominio (es nueva) y vuelve a pulsar **Desplegar**. |
| «Permission denied (publickey)» | La clave de despliegue no está en GitHub o se copió mal (paso 2.4). |
| No se actualiza sola | Revisa el webhook (paso 3). Mientras tanto, pulsa **Desplegar** a mano en hPanel. |
