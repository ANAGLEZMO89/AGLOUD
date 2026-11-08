# Handoff: AG Cloud — Plataforma de gestión para GA AGOPYME

## Overview
Plataforma web para una gestoría española (GA AGOPYME). Dos aplicaciones:
- **AG Cloud (asesor)**: el equipo de la gestoría gestiona a todos sus clientes (empresas y autónomos).
- **Portal Cliente**: cada cliente accede con su propio usuario y ve/gestiona solo sus datos.

Módulos: CRM, Contabilidad (OCR de facturas, plan de cuentas, conciliación bancaria, amortizaciones, balance y PyG), Fiscal (303, 111, 115, 130), Laboral/Nóminas, Facturación, Notificaciones, Gestor documental, Control horario, Informes, Expedientes y Calendario.

## About the Design Files
Los archivos de `prototipo/` son **referencias de diseño en HTML**: prototipos que muestran el aspecto y comportamiento deseados, **no código de producción**. Todos los datos son de ejemplo y viven en memoria (se pierden al recargar). La tarea es **reconstruir esta aplicación como software real** con backend, base de datos y autenticación, replicando fielmente pantallas y flujos.

Los `.dc.html` usan un runtime propio (`support.js`): plantilla HTML con huecos `{{ }}` + una clase `Component` con `state` y `renderVals()` (equivalente a un componente de clase React). Ábrelos en el navegador para ver el prototipo; lee la clase `Component` al final de cada archivo para entender la lógica, cálculos y estructuras de datos.

## Hosting: Hostinger (restricción del proyecto)
La propietaria tiene contratado Hostinger. Antes de elegir stack, **comprobar el plan contratado**:
- **Hosting compartido / Premium / Business (hPanel)**: PHP 8.x + MySQL/MariaDB. Stack recomendado: **Laravel 11 + MySQL**, frontend con Blade + Livewire o Inertia + Vue/React compilado (build local, subir `public/`). Sin procesos Node persistentes.
- **Plan con Node.js (Business/Cloud con app Node) o VPS**: posible Next.js / Node + MySQL o PostgreSQL. En VPS, configurar Nginx, SSL (Let's Encrypt), backups y firewall.
- Alternativa: frontend estático en Hostinger + backend gestionado (p. ej. Supabase en región UE). Verificar contrato de encargado del tratamiento.

Desplegar en subdominio (p. ej. `app.gestoriaagopyme.es`), con HTTPS obligatorio.

## Requisitos no funcionales (obligatorios antes de usar con datos reales)
1. **Autenticación real** con roles: `admin_gestoria`, `asesor`, `cliente_admin`, `cliente_empleado`. Contraseñas con hash (bcrypt/argon2), 2FA recomendado, recuperación por email.
2. **Multi-tenant estricto**: cada registro pertenece a un `cliente_id`. Un usuario de portal nunca puede leer datos de otro cliente (aplicar en backend, no solo en UI).
3. **RGPD**: servidor en la UE, cifrado en tránsito (TLS) y de documentos en reposo, registro de accesos (audit log), copias de seguridad diarias, borrado/retención según plazos legales (ya definidos en el gestor documental del prototipo).
4. **Persistencia de todo**: clientes, asientos, facturas, nóminas, modelos, documentos, fichajes, notificaciones, CRM.
5. **Ficheros AEAT**: los generadores de 303/111/115/130 del prototipo son aproximados. Implementarlos según los **diseños de registro oficiales vigentes de la AEAT** y validar contra el servicio de validación de la AEAT antes de usar.
6. **Integraciones** (fase posterior, con credenciales reales): SMTP para emails, OCR (p. ej. servicio de terceros), Sistema RED/SILTRA, SEPE (Contrat@), DEHú, certificado digital. En el prototipo están **simuladas**.
7. **Control horario**: hora real del servidor (el prototipo usa un día simulado, 4 nov). Registro inalterable con conservación 4 años (art. 34.9 ET).

## Orden de implementación recomendado
1. Base: auth, roles, multi-tenant, modelo de clientes, layout (sidebar + selector de cliente).
2. Gestor documental + Notificaciones (menor riesgo, uso inmediato).
3. CRM + Facturación.
4. Contabilidad (plan de cuentas, asientos, bandeja OCR, conciliación).
5. Fiscal (cálculo desde contabilidad + ficheros AEAT validados).
6. Laboral/Nóminas + Control horario.
7. Portal Cliente (reutiliza los mismos endpoints con permisos de cliente).
8. Informes.

## Fidelity
**Alta fidelidad (hifi)**. Reproducir colores, tipografía, espaciado y comportamiento.

## Screens / Views
Layout general (ambas apps): grid de 2 columnas `248px | 1fr`, alto 100vh. Sidebar negro `#0B0B0B` con texto `#F4F4F0`, padding 20px 14px, gap 18px; contenido sobre `#F4F4F0`, padding 28px, con scroll propio.

Regla clave: **el contexto (cliente/despacho seleccionado) se mantiene en todos los módulos**; no repetir selectores de empresa o ejercicio dentro de cada módulo.

### AG Cloud (asesor) — `prototipo/AG Cloud.dc.html`
- **Panel / Dashboard**: resumen de plazos, notificaciones, tareas.
- **Clientes / Expedientes**: listado, ficha, detalle de expediente con migas (`Expedientes / …`).
- **Calendario**: vista mensual, celdas min-height 118px, eventos como etiquetas de color.
- **CRM**: pipeline de 6 etapas, presupuestos → conversión a factura, agenda de llamadas/reuniones/tareas.
- **Contabilidad**: bandeja OCR (propone cuenta de gasto y nivel de confianza; "Contabilizar las de confianza alta"), revisión de factura (proveedor, NIF, nº, fecha, base, IVA, total, cuenta), libro diario, mayor, plan de cuentas, conciliación bancaria, inmovilizado y amortizaciones, PyG y balance.
- **Fiscal**: 303, 111, 115, 130 calculados desde contabilidad; editables, borrador, marcar como presentado, generar fichero.
- **Laboral**: empleados, modelo 145 → cálculo IRPF, nóminas mensuales (3 plantillas), convenios, contratos, RED/SEPE.
- **Facturación**: 3 plantillas, branding, búsqueda de productos, autocompletado de cliente y cuentas bancarias, QR. Al emitir, la factura va a la **bandeja pendiente de contabilidad** (no se contabiliza automáticamente). Borrar factura o asiento elimina en ambos lados.
- **Notificaciones**: plazo legal de 10 días (DEHú), filtros por organismo (AEAT/TGSS/DGT…), estados, archivo.
- **Documentos**: carpetas fiscal/laboral/contable/mercantil/identidad, clasificación automática por nombre de archivo, plazos de conservación, vista previa, compartir al portal.
- **Control horario**: fichaje entrada/salida, resumen semanal y mensual, ausencias/vacaciones, exportación con líneas de firma.
- **Informes**: PyG, ventas por cliente, antigüedad de saldos, gasto por cuenta, IVA por trimestre, horas, pipeline.
- **Acceso y ajustes**: invitaciones al portal y permisos de qué ve el cliente (pendiente de aplicar en el menú del portal).

### Portal Cliente — `prototipo/Portal Cliente.dc.html`
Login propio. Misma estética que AG Cloud. Contabilidad, fiscal y nóminas **solo lectura**; puede crear/editar facturas, gestionar sus clientes/proveedores, subir documentos, CRM, control horario, informes y chat con su asesor.

### Dashboard alternativo — `prototipo/AG Cloud Dashboard.dc.html`
Versión anterior del panel principal, como referencia.

## Interactions & Behavior
- Sincronización del plan de cuentas: cualquier cuenta creada aparece en todos los desplegables de cuenta de la app.
- Números en formato español (`1.234,56`); el parser debe aceptar coma decimal y punto de miles.
- Botones primarios: pastilla negra `#0B0B0B`, texto blanco, alto 36–38px, radio = mitad del alto.
- Estados/etiquetas: pastillas de radio 14–20px, p. ej. éxito `#E6F6A8` sobre texto `#2E3A00`.
- Tablas: filas separadas por `1px solid #F1F1EC`; en móvil, scroll horizontal con `min-width` de fila (440–600px).
- Formularios: grid `repeat(auto-fit, minmax(min(100%,180px),1fr))`, gap 12px; label 12.5px/600 sobre input.

## State Management
Ver `state` y `renderVals()` en cada archivo para las estructuras de datos (clientes, asientos, facturas, documentos, empleados, nóminas, notificaciones, oportunidades CRM, fichajes). Todo ello debe pasar a tablas en la base de datos con `cliente_id`, `created_by` y marcas de tiempo.

## Design Tokens
- Colores: tinta `#0B0B0B`, fondo `#F4F4F0`, superficie `#FFFFFF`, texto secundario `#6A6A65`, texto terciario `#3C3C3C`, separadores `#F1F1EC`, acento lima `#C8F031` (ítem activo del menú, tarjetas destacadas, puntos de estado), lima suave `#E6F6A8` / `#2E3A00`, ámbar `#F5A524`, gris inactivo `#B4B4AE`.
- Tipografía: **Onest** (400–800) para la UI; **Geist Mono** (400–600) para importes, cuentas, NIF, fechas y periodos. Google Fonts.
- Tamaños: cuerpo 13.5px, secundario 12.5px, cabecera de tabla 11px/600 mayúsculas con `letter-spacing .06em`, títulos de tarjeta 14–15px/700.
- Radios: tarjetas 18px, campos 9px, bloques internos 12px, pastillas 14–20px.
- Espaciado: tarjetas padding 16–22px; gaps 6/8/10/12/14/16/18px.

## Assets
- `prototipo/assets/logo-ga.png`: logotipo de GA AGOPYME.

## Capturas
`capturas/01-escritorio.jpg` … `15-informes.jpg`: una por módulo del menú lateral de AG Cloud (vista de escritorio, parte superior de cada pantalla). Para ver el resto, abrir el prototipo.

## Files
- `prototipo/AG Cloud.dc.html`: aplicación del asesor (todos los módulos).
- `prototipo/Portal Cliente.dc.html`: portal del cliente.
- `prototipo/AG Cloud Dashboard.dc.html`: panel alternativo.
- `prototipo/actividades-data.js`: datos de actividades (CNAE/IAE) usados en fichas.
- `prototipo/support.js`: runtime necesario para abrir los prototipos en el navegador.
