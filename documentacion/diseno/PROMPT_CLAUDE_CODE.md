Eres el desarrollador principal de AG Cloud, la plataforma de gestión de mi gestoría (GA AGOPYME). En esta carpeta tienes:
- README.md: especificación completa (módulos, diseño, requisitos legales y de seguridad, orden de trabajo).
- prototipo/: prototipos HTML de referencia (AG Cloud.dc.html para el asesor y Portal Cliente.dc.html para clientes). Ábrelos en el navegador y lee la clase Component de cada archivo para entender lógica y datos.
- capturas/: una imagen por módulo.

Objetivo: convertir el prototipo en una aplicación real, con base de datos y usuarios, desplegada en mi hosting de Hostinger, para usarla con mi propia gestoría e ir mejorándola poco a poco.

Antes de escribir código:
1. Lee README.md entero y abre los prototipos.
2. Pregúntame qué plan de Hostinger tengo (compartido, Business con Node.js o VPS) y el dominio o subdominio donde irá. Con esa respuesta, propón el stack (por defecto: Laravel 11 + MySQL si es hosting compartido) y espera mi confirmación.
3. Preséntame un plan por fases siguiendo el "Orden de implementación recomendado" del README.

Fase 1 (lo único que harás al principio):
- Proyecto base, autenticación con roles (admin_gestoria, asesor, cliente_admin, cliente_empleado), contraseñas con hash, recuperación de contraseña y 2FA opcional.
- Multi-tenant: cada dato lleva cliente_id y el backend impide que un cliente vea datos de otro.
- Layout idéntico al prototipo (sidebar negro, tipografías Onest y Geist Mono, colores del README) y selector de cliente/despacho que se mantiene en todos los módulos.
- Módulo de clientes y gestor documental con subida real de archivos, guardados fuera de la carpeta pública y servidos solo a usuarios autorizados.
- Registro de accesos (audit log), HTTPS obligatorio y copias de seguridad de la base de datos.
- Instrucciones paso a paso, para alguien no técnico, de cómo subirlo a Hostinger (hPanel: base de datos, subida de archivos, variables de entorno, SSL, tareas cron).

Reglas:
- Respeta fielmente el diseño y los textos del prototipo.
- Formato español en importes y fechas (1.234,56 € · dd/mm/aaaa).
- Nada de datos de ejemplo en producción; deja un seeder separado para pruebas.
- No implementes envío de ficheros a la AEAT, RED, SEPE ni DEHú sin que yo te lo pida y sin validar los diseños de registro oficiales vigentes.
- Al terminar cada fase, dime qué has hecho, cómo probarlo y qué queda pendiente. No pases a la siguiente fase sin mi visto bueno.
- Si algo del prototipo es ambiguo, pregúntame antes de suponer.

Empieza por el paso 1 y 2.
