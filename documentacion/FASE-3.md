# Fase 3 · Facturación y CRM

Publicada en https://app.gestoriaagopyme.es (versión `2026.11.11-fase3`).

## Qué incluye

### Facturación (Áreas › Facturación)
Factura la empresa elegida en «¿Con quién vas a trabajar?»: el despacho o cualquier cliente.

- **Facturas:**
  - **Tipos:** ordinarias (F1), simplificadas (F2, hasta 3.000 €) y rectificativas (R1, que se crean desde la original con el botón «Rectificar»).
  - **Numeración:** correlativa por serie y año (A-2026-0001). No admite fechas futuras ni anteriores a la última factura de la serie.
  - **Líneas:** con IVA del 21, 10, 5, 4 o 0 %, retención IRPF y **suplidos** (fuera de la base y sin IVA, art. 78.Tres.3º LIVA).
  - **Forma de pago:** con vencimiento e IBAN del cliente o de la empresa (se comprueba que sea válido).
  - **Vista previa** en directo y **PDF** desde el navegador, con el **QR tributario**.
- **Inalterables.** Una factura emitida no se modifica ni se borra (obligación de conservación, art. 19 RD 1619/2012). Solo se marca el cobro, se rectifica o se anula su registro.
- **Registro de facturación** (RD 1007/2023 · Orden HAC/1177/2024):
  - Cada alta o anulación guarda la **huella SHA-256 encadenada** con la anterior. El cálculo está comprobado con el **ejemplo oficial de la AEAT**.
  - El botón «Verificar cadena de huellas» detecta cualquier alteración.
  - Se puede exportar.
- **Modo No VERI\*FACTU**, como decidiste: **no se envía nada a la AEAT**.
- **Series y configuración:**
  - series;
  - pie de factura;
  - diseño: modelo, color, esquinas, cabecera, márgenes, tipografía, cuadros, logo y datos registrales;
  - catálogo de productos y servicios, que rellena precio e IVA al escribir.

### CRM (Áreas › CRM)
- **Embudo** de oportunidades en 6 etapas, con importe, origen y fecha de cierre (aviso si va «fuera de plazo»).
- **Cifras del CRM:** embudo abierto, previsión ponderada, ganado en el año y conversión.
- **Presupuestos:**
  - se crean desde la oportunidad o sueltos (P-2026-0001), con validez de 15, 30, 60 o 90 días;
  - se envían por correo (con el texto ya preparado) y se descargan en PDF;
  - al marcarlos aceptados o rechazados, la oportunidad pasa sola a ganada o perdida;
  - **«Convertir en factura»** lleva todas las líneas a una factura nueva.
- **Agenda:** llamadas, reuniones, correos, tareas y visitas, con aviso de «atrasada».

## Cómo probarlo
1. **Completa el NIF del despacho:** Clientes › GA AGOPYME SL › Editar ficha. Sin NIF no deja facturar; ahora mismo no lo tiene.
2. **Configura las facturas:** elige el despacho, ve a **Facturación › Series y configuración**, ajusta el diseño y el pie, y pulsa «Guardar».
3. **Emite una factura:**
   - en **Nueva factura**, escribe un cliente (te propone los tuyos), añade una línea y mira la vista previa;
   - pulsa **Emitir y registrar**.
4. **Revisa el resultado:**
   - **Descargar PDF** y elige «Guardar como PDF»;
   - en **Registro de facturación**, pulsa **Verificar cadena de huellas**.
5. **Recorre el CRM:**
   - **+ Oportunidad**, después **Presupuesto**, **Guardar borrador** y **Enviar**;
   - marca **Aceptado** y pulsa **Convertir en factura**.

> ⚠️ Las facturas de prueba **no se pueden borrar** (así lo exige la normativa). Si haces pruebas, crea una serie solo para ellas, por ejemplo «T», o anula después su registro.

## Decisiones tomadas (confírmalas)
- **No hay «Eliminar factura».** El prototipo lo tenía, pero borrar facturas emitidas incumple la obligación de conservarlas. Solo hay «Anular registro»: la factura se conserva marcada como ANULADA.
- **Exportar.** Un solo botón «Excel / CSV»: el CSV se abre directamente en Excel. El PDF de la lista no está incluido.
- **Contabilidad.** Las facturas aún no pasan a la contabilidad: se enlazarán en esa fase.
- **Portal del cliente.** De momento no factura desde el portal: solo lo hace el despacho.

## Pendiente antes de que sea obligatorio (sociedades: 1/1/2027; resto: 1/7/2027)
Para cumplir del todo el Reglamento en modo No VERI\*FACTU falta:
1. **Firma electrónica (XAdES) de cada registro** con el certificado de la empresa.
2. **Registro de eventos del sistema**: arranques, exportaciones, detección de anomalías…
3. **Declaración responsable** del sistema informático (art. 13 RD 1007/2023).
4. Si se prefiere **VERI\*FACTU**, el envío a la AEAT, solo cuando lo pidas y tras validar los esquemas oficiales vigentes.

Revisa las fechas de obligación por si ha habido cambios normativos después del RDL 15/2025.

## Ampliación (11/11/2026)

### Modalidad de facturación
Está en **Facturación › Parámetros**, por empresa:
- **Facturación normal:** PDF con registros encadenados (No VERI\*FACTU).
- **Factura electrónica:** además del PDF, cada factura se descarga en **Facturae 3.2.2 (XML)**.
  - El XML está **validado contra el esquema oficial** de facturae.gob.es.
  - Admite los códigos DIR3 para FACe (Administraciones Públicas).
  - Se genera **sin firma**: la firma con certificado está pendiente.
- **Acogerse a VERI\*FACTU:** queda elegido, pero el **envío a la AEAT no está activado**. Mientras tanto, las facturas se emiten sin envío y sin la leyenda «VERI\*FACTU», para no inducir a error.

### Pestaña «Parámetros» en todos los módulos
Solo la administración puede cambiarlos; el resto los consulta.

| Módulo | Qué se configura |
|---|---|
| Clientes | Cuota por defecto de los clientes nuevos: periodicidad, IVA, forma de cobro y concepto |
| Expedientes | Tipos de trámite (pasos, documentación, honorarios) y si se ven en el portal por defecto |
| Notificaciones | Avisos; festivos autonómicos, locales y traslados para el cálculo de plazos; tipos de notificación |
| Documentos | Espacio por cliente y días de aviso de caducidad |
| CRM | Probabilidad por etapa, orígenes, validez y pie de los presupuestos |
| Facturación | Modalidad, series, pie, diseño y catálogo (por empresa) |

Los módulos que aún están «en preparación» (Contabilidad, Fiscal, Laboral…) tendrán su pestaña de Parámetros cuando se construyan.
