# Fase 1 · Informe

## Qué incluye

### Acceso y seguridad
- Inicio de sesión único para el despacho y los clientes, con la estética del prototipo. Tras entrar, cada rol va a su sitio: Escritorio o Portal.
- 4 roles. El **asesor** ve todos los clientes (opción a). La **administración** gestiona además el equipo, el registro de accesos y las copias.
- Contraseñas con hash bcrypt. **Contraseña provisional obligatoria de cambiar** en el primer acceso. **Recuperación por correo**, con un mensaje idéntico exista o no la cuenta.
- **2FA opcional** en *Mi cuenta › Seguridad* (QR, 8 códigos de recuperación, anti-repetición del código).
- Bloqueo tras 5 intentos por minuto. Usuarios desactivables, con cierre inmediato de sus sesiones. Un cliente «de baja» no entra al portal.
- **HTTPS obligatorio** (aplicación + `.htaccess`), HSTS, CSP estricta y cookies seguras.

### Multi-tenant
- Todo documento lleva `cliente_id`.
- El backend filtra **todas** las consultas de un usuario del portal a su cliente, y una política lo vuelve a comprobar.
- El empleado solo ve **lo que ha subido él** (decisión del punto 8).
- El cliente_admin ve lo compartido por el despacho y lo subido desde su empresa.

### Layout
- Menú negro, Onest/Geist Mono, colores y medidas del README y textos del prototipo.
- **Selector «¿Con quién vas a trabajar?»** en el Escritorio: Clientes activos, Alta en curso y Despacho. Lo elegido se guarda en la sesión y se mantiene en todos los módulos (botón «Cambiar empresa»).
- Los módulos de fases futuras aparecen en el menú y muestran la pantalla **«Módulo en preparación»** del prototipo.
- Funciona en el móvil, con menú desplegable.

### Clientes
- Listado con búsqueda, filtros por tipo, asesor y estado, 4 indicadores, paginación y **Exportar** a CSV para Excel.
- Ficha con pestañas:
  - **Resumen**: datos principales, plan contratado, documentos por revisar y actividad reciente.
  - **Datos fiscales.**
  - **Documentos.**
  - **Datos de alta.**
  - **Accesos al portal.**
- **Alta y edición** en panel lateral con las **9 secciones** del prototipo, según el tipo de cliente:
  - NIF/NIE/CIF validado en vivo y en el servidor, con los mensajes del prototipo.
  - Código postal → provincia.
  - IBAN validado, con entidad y BIC autocompletados.
  - Cuota con IVA y equivalente anual.
  - Actividades con **buscador CNAE 2025 / IAE**.
  - Representantes con control del 100 %.
  - Datos registrales con escritura adjunta.
  - **Certificados digitales**: al subir el .p12/.pfx con su contraseña se leen titular, NIF, emisor y fechas. El estado se muestra como Vigente / Caduca en N días / Caducado.
  - Logo y firma.
- **Invitación al portal**: correo de bienvenida con usuario y contraseña provisional. Se puede reenviar o desactivar.

### Gestor documental
- Subida real, una a una o arrastrando varias (hasta 20 a la vez, 20 MB cada una).
- **«Clasificar antes de guardar»**: nombre con la nomenclatura del despacho (`aaaammdd_tipo_descripcion`) y carpeta propuestos automáticamente, aviso de posible duplicado.
- Las 6 carpetas con sus subcarpetas, búsqueda, filtro por año y filtros: Por revisar, Del cliente, Compartidos y Caducan pronto.
- Vista previa de PDF e imágenes. Descargar, renombrar, mover, **compartir en el portal**, marcar revisado, papelera, restaurar y vaciar.
- **Plazos de conservación** (art. 66 LGT, art. 30 CCom, art. 21 LISOS) mostrados en cada documento, con aviso al borrar uno que aún debe conservarse.
- Índice en CSV.
- **Archivos cifrados** fuera de la carpeta pública, servidos solo a usuarios autorizados. Cada vista o descarga queda registrada.

### RGPD, copias y auditoría
- **Registro de accesos**: inicios de sesión, fallos, bloqueos, descargas, cambios en fichas, invitaciones, 2FA, copias… Con filtros y exportación. No se puede modificar.
- **Copia diaria a las 03:15**: base de datos y documentos en un ZIP cifrado AES-256. Se guarda 7 días en el servidor y **30 días en Google Drive**. Si falla, avisa por correo a la administración. Página *Copias de seguridad* con botón «Hacer copia ahora».
- Tipografías alojadas en el propio servidor: no se envían las IP de los usuarios a Google Fonts.

## Cómo probarlo

### En el servidor (después de la guía)
1. Entra con tu usuario, cambia la contraseña y activa la verificación en dos pasos.
2. Da de alta un cliente de prueba con **tu propio correo personal** y marca «Dar acceso al portal».
3. Completa sus secciones, por ejemplo con tu IBAN o con un NIF erróneo para ver los avisos.
4. En **Documentos**, sube un PDF llamado «Modelo 303 3T 20261020.pdf» y comprueba el nombre y la carpeta propuestos. Guárdalo y pulsa **Compartir en el portal**.
5. En otra ventana de incógnito, entra con el correo de bienvenida: el cliente debe ver **solo** ese documento.
6. Sube un documento desde el portal y vuelve a AG Cloud: aparece «Por revisar».
7. *Despacho › Registro de accesos*: verás cada paso.
8. *Despacho › Copias de seguridad › Hacer copia ahora*.
9. Cuando termines, pon el cliente de prueba en estado «Baja» (Editar ficha › Estado): su acceso al portal queda bloqueado. Ver pendiente nº 6.

### En local (pruebas automáticas)

```bash
php artisan test
```

37 tests, todos correctos. Además se ha recorrido la aplicación en un navegador real con 27 comprobaciones de flujo (alta completa, certificado .p12, subida y clasificación, primer acceso del cliente, aislamiento entre clientes, 2FA de principio a fin) y sin errores.

## Decisiones tomadas (revísalas)

1. **Laravel 13 en lugar de 11.** Laravel 11 ya no recibe parches de seguridad y la auditoría mostraba 5 vulnerabilidades sin corregir. Requiere PHP 8.3, que Hostinger ofrece.
2. **Copias sin Spatie.** Usan un volcado propio en PHP, porque Spatie depende de `mysqldump`, que no siempre está disponible en hosting compartido. La subida a Drive se hace con la API oficial, sin librerías.
3. **Interfaz con Blade + JS ligero, sin Livewire.** Es más simple de mantener y subir, y el resultado visual es el mismo.
4. **4º indicador de Documentos.** «Pendientes de recibir» necesita el sistema de peticiones (Notificaciones, fase 2). Mientras tanto muestra «Compartidos en el portal».
5. **«Con documentación pendiente» / «Pendiente» en Clientes.** Ahora cuenta documentos del cliente *por revisar*. Pasará a «documentación que falta» cuando exista el sistema de peticiones.
6. **Pestaña nueva «Accesos al portal» en la ficha.** No estaba en el prototipo, pero hace falta para gestionar los usuarios de cada cliente.
7. **Pestañas Obligaciones, Trabajadores y Expedientes.** Visibles, con aviso de «en preparación».
8. **Contraseña provisional por correo.** Es como en el prototipo.
   > ⚠️ **Riesgo:** el correo queda en el buzón del cliente. Se mitiga con el cambio obligatorio en el primer acceso. Alternativa más segura: enviar un enlace de un solo uso para crear la contraseña. Dime si lo prefieres.
9. **Certificados digitales.** El archivo y su contraseña se guardan cifrados, y la contraseña no se vuelve a mostrar.
   > ⚠️ **Riesgo:** guardar contraseñas de certificados solo está justificado si vas a presentar en nombre del cliente desde la plataforma. Si no, déjala en blanco.

## Pendiente (no incluido en esta fase)

1. Notificaciones (DEHú simulado o real) y Expedientes → **Fase 2**.
2. CRM, Facturación, Contabilidad, Fiscal, Laboral, Control horario, Informes y el resto del Portal → fases 3 a 8.
3. Envío a AEAT/RED/SEPE/DEHú: **no implementado**, por norma del proyecto.
4. Accesos sin contraseña con passkeys: Fortify los permite y se pueden activar más adelante.
5. Antivirus de los archivos subidos: el hosting compartido no incluye ClamAV. Mitigación actual: tipos ejecutables bloqueados, archivos cifrados y nunca ejecutables, y descarga forzada con `sandbox`.
6. Borrado de clientes: ahora se pasan a «Baja». El borrado definitivo con los plazos de retención se añadirá con una pantalla específica.
7. Política de privacidad y aviso legal del portal: textos a redactar por el despacho.
