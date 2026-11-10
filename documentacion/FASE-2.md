# Fase 2 · Notificaciones y Expedientes

Publicada en https://app.gestoriaagopyme.es (versión `2026.11.11-fase2`).

## Qué incluye

### Notificaciones (Comunicación › Notificaciones)
- **Registro manual.** No hay conexión con DEHú, AEAT ni TGSS, porque no se ha pedido. El despacho anota lo que llega con «Registrar notificación», usando plantillas o rellenando los datos a mano. Se puede adjuntar el PDF.
- **Plazos automáticos:**
  - **Caducidad.** Pasados 10 días naturales desde la puesta a disposición sin abrirla, la notificación se da por rechazada y el plazo empieza a correr (art. 43.2 Ley 39/2015). Se aplica sola cada mañana y también al abrir la pantalla.
  - **Vencimiento.** Se calcula en días hábiles, naturales o meses (de fecha a fecha). Si el último día es inhábil, pasa al siguiente hábil (art. 30 Ley 39/2015).
  - **Festivos que se tienen en cuenta:**
    - nacionales, incluido el Viernes Santo, que se calcula solo;
    - de Andalucía: 28-F y Jueves Santo;
    - locales y traslados de domingo a lunes: van en `FESTIVOS_EXTRA` del `.env`.
- **Detalle de cada notificación:**
  - abrir o rechazar indicando la fecha real;
  - ver en la sede;
  - crear un expediente vinculado;
  - avisar al cliente por WhatsApp con el texto ya redactado;
  - añadir el vencimiento al calendario (archivo `.ics`);
  - descargar o adjuntar el justificante (se guarda cifrado en Documentos › Fiscal › Requerimientos y notificaciones);
  - responsable y nota interna (cifrada);
  - archivar.
- **Avisos:**
  - **Resumen por correo.** Sale cada mañana a las 07:47. A cada asesor le llegan sus notificaciones sin abrir y los plazos que vencen en 7 días.
  - **Aviso en el navegador.** Es opcional y se activa en cada navegador.
  - **Contador en el menú.** Muestra las notificaciones sin abrir.
- **Eliminar un registro anotado por error:** solo administración, y queda en el registro de accesos.

### Expedientes (Gestión › Expedientes)
- **Tablero** con los 4 primeros estados, y vista de **lista**. Filtros por asesor y categoría, y buscador.
- **Indicadores:** abiertos, esperando al cliente, vencen en 7 días y pendiente de facturar.
- **Nuevo expediente** a partir de una plantilla de trámite: los pasos, la documentación que se pide y los honorarios orientativos salen solos.
- **Detalle del expediente:**
  - estados que se pueden pulsar y botón «Pasar a…»;
  - lista de pasos, con el progreso;
  - documentación recibida o pendiente;
  - historial y notas internas (cifradas);
  - plazo con los días que quedan;
  - honorarios: «Se factura al presentar», «Listo para facturar» o «Facturado»;
  - visible o no en el portal del cliente;
  - pedir la documentación que falta por WhatsApp.
- **Ficha del cliente:** pestaña «Expedientes» y botón «+ Nuevo expediente».
- **Portal del cliente:** tarjeta «Tus trámites», solo con los expedientes marcados como visibles y solo para el administrador de la empresa. El cliente no ve las notificaciones.

## Cómo probarlo
1. **Registrar una notificación.**
   - Entra en AG Cloud, elige el despacho y abre **Notificaciones → Registrar notificación**.
   - Elige un cliente, la plantilla «AEAT · Requerimiento de información» y la fecha de puesta a disposición.
   - Pulsa **Registrar**.
2. **Abrirla.**
   - En el detalle, pulsa **Abrir y aceptar** e indica la fecha.
   - Comprueba la fecha de vencimiento. Ejemplo: abierta el 10/11/2026 con 10 días hábiles → vence el **24/11/2026**.
3. **Probar las acciones del detalle:** **Añadir vencimiento al calendario**, **Avisar al cliente** y **Crear expediente**.
4. **Trabajar el expediente:**
   - marca pasos y documentos;
   - escribe una nota;
   - pasa a «Presentado» y verás «Listo para facturar».
5. **Ver el portal.** Entra con un usuario del portal de ese cliente: en Inicio aparece «Tus trámites».

## Decisiones tomadas (confírmalas)
- **Indicador «Última sincronización».** Lo he cambiado por **«Vencen en 7 días»**: sin conexión automática no hay sincronización.
- **Barra negra del certificado.** Ahora tiene accesos directos a DEHú, AEAT y TGSS.
- **Plantillas de expediente.** He añadido **«Otro trámite»**, para lo que no encaje en las 8 del prototipo.
- **Honorarios.** Se pueden **editar** al crear y en el detalle; en el prototipo eran fijos.
- **Festivos.** Se usan los de **Andalucía**. Faltan los **locales de Huelva** y los traslados de domingo a lunes de cada año: dímelos y los añado.

## Pendiente
- Conexión real con DEHú, AEAT o TGSS: solo si la pides, y tras validar los servicios oficiales.
- Calendario interno (fase posterior). Mientras tanto, los vencimientos se exportan en `.ics`.
- Facturación de honorarios (fase Facturación).
