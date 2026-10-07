# AGLOUD

## Mi calendario rosa (app para ordenador y móvil)

La app está en la carpeta [`docs/`](docs/). Es una web instalable (PWA): se abre en el navegador del ordenador y se instala en el móvil como una app más, con icono propio y funcionamiento sin conexión.

### Publicarla (una sola vez)
1. Fusiona esta rama en `main`.
2. En GitHub: **Settings → Pages → Build and deployment → Source: Deploy from a branch → Branch: `main` / carpeta `/docs` → Save**.
3. En 1-2 minutos estará en: `https://anaglezmo89.github.io/AGLOUD/`

### Instalar
- **Ordenador (Chrome o Edge):** abre la dirección y pulsa el icono de instalar de la barra de direcciones (o Ajustes del calendario → «Instalar la app»).
- **Android (Chrome):** menú ⋮ → «Instalar aplicación» / «Añadir a pantalla de inicio».
- **iPhone (Safari):** botón Compartir → «Añadir a pantalla de inicio».

### Qué hace
- Vista mensual, tareas por día con hora, categoría y aviso.
- Marcar hecha (confeti), mover tareas (arrastrando en el ordenador o con el botón 📅 en el móvil), borrar.
- Recordatorios en pantalla y notificaciones del sistema mientras la app está abierta.
- Botones de WhatsApp que abren el mensaje ya escrito para tu número.
- 6 tonos de rosa y 6 fondos.
- Copia de seguridad: descargar / cargar las tareas (para pasarlas entre ordenador y móvil).

### Limitaciones
- Las tareas se guardan en cada dispositivo por separado; no se sincronizan solas.
- Los avisos solo saltan con la app abierta. Para avisos con la app cerrada o envío automático por WhatsApp hace falta un servidor (p. ej. WhatsApp Business Cloud API o Twilio).

### Actualizar la app
Tras cambiar archivos en `docs/`, sube la versión en `docs/sw.js` (`VERSION`) para que los móviles descarguen la nueva.
