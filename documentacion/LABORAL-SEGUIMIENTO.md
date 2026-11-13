# Laboral · seguimiento del módulo (convenios, nóminas y procesos posteriores)

Documento vivo. Se actualiza al cerrar cada fase. Última actualización: 13/11/2026 (fase 1).

## 1. Diagnóstico del código real (antes de la fase 1)

| Pieza | Dónde | Estado real |
|---|---|---|
| Trabajadores y ficha | `trabajadores` (ficha cifrada en `datos`), `LaboralController::ficha/guardar` | **Implementado.** Una sola fila por trabajador: no distingue persona, relación laboral y contratos sucesivos. Sin histórico con fecha de efecto (salario, jornada y categoría se sobrescriben). |
| Convenio | `laboral_config.datos.conv` (JSON por empresa) | **Parcial.** Una sola versión, sin vigencias ni tramos, sin documentos. Tablas: categoría, grupo, base (mes o día) y un plus. Pagas extra, antigüedad, pluses, IT y horas extra como listas simples. |
| Lectura del PDF | `ConvenioIA` (API de Anthropic) y «pegar respuesta de claude.ai» | **Parcial y con riesgo.** Valida el formato, pero **aplica directamente** los datos y sube salarios sin revisión previa. Pendiente de rehacer en la fase 7. |
| Motor de nómina | `App\Services\Nomina` (`conceptos`, `computar`, `extras`, `antiguedad`) | **Implementado, con límites.** Cálculo determinista en PHP con `float` redondeado a 2 decimales. Sin fórmulas configurables ni trazabilidad por línea más allá del origen. Tipos y bases 2026 fijos en constantes (`TIPOS`, `BASE_MIN`, `BASE_MIN_DIA`, `BASE_MAX`, `SMI_ANUAL`) **sin fuente ni fecha de efecto verificadas**. |
| Calendario e incidencias | `laboral_meses.incidencias` (importes en €: horas extra, otros, dietas, km, anticipos, embargos) y `jornada_ausencias` (control horario) | **Parcial.** Variables por importe global, no por cantidades y fechas. Las ausencias del control horario no entran en la nómina (salvo vacaciones disfrutadas en el finiquito). Sin festivos en el cálculo. **IT no implementada.** |
| Nóminas guardadas | `nominas` (borrador / emitida, `datos` con las líneas) | **Implementado.** Una emitida conserva sus líneas; el recálculo de bases sí usa la ficha actual. Sin rectificación controlada ni motivo de cambios. |
| Recibos | `_recibo-cuerpo` (4 modelos) | **Implementado** (visual). |
| CRA | `LaboralGestionController::cra` | **Prepara datos y exporta CSV/Excel.** No genera el fichero oficial ni se presenta. |
| Seguros sociales / remesa / asiento | `seguros`, `remesa`, `contabilizar` | **Prepara datos.** El asiento evita duplicados por mes (`laboral_meses.asiento_id`). Sin efecto de rectificaciones. |
| Modelos 111 y 190 | `LaboralGestionController::anual` y módulo Fiscal | **Prepara importes** desde las nóminas. No presenta. |
| Sistema RED, SEPE (Contrat@, Certific@2), afiliación | Enlaces y datos de autorizado | **Solo interfaz.** No hay conexión ni envío. |

## 2. Fase 1 · Fallos críticos (hecha)

| Fallo observado | Causa en el código | Corrección |
|---|---|---|
| A. Alta el 10/11 cobra 30 días | `conceptos()` usaba siempre los días del mes; el editor pintaba «01/11–30/11» y «30» fijos | `Nomina::periodo()` recorta por inicio de contrato y baja. Salario diario: días en alta × precio. Mensual: mes completo íntegro; mes parcial días/30. Plus, antigüedad, pluses automáticos y prorrata, en proporción. Periodo y días reales en el editor y en los 4 recibos. |
| B. «Sin categorías en el convenio» validada como categoría | El texto de relleno era una opción real del desplegable y se guardaba; `comprobar()` daba por buena la categoría si no la encontraba | El desplegable tiene una opción vacía; el texto antiguo se trata como vacío; solo se guardan categorías del convenio. La comprobación distingue error (falta o no existe) y advertencia (convenio sin tablas). |
| C. «Calculada desde el convenio» sin categoría | Texto fijo | El estado dice qué se ha usado: ficha + tabla del convenio, o solo la ficha (con el motivo). El origen de cada línea es `convenio` solo si la categoría existe en la tabla. |
| D. Base 1.424,40 sin explicación | Era el ajuste a la base mínima del grupo, sin mostrarlo; además no se prorrateaba en altas a mitad de mes | Bases mínima y máxima prorrateadas por días en alta; explicación desplegable en el editor («Cómo se ha calculado la base de cotización»), con el aviso de que los parámetros 2026 están pendientes de contrastar. |
| E. Convenio «aplicado» sin tablas | No había indicador | `Nomina::estadoConvenio()`: 8 apartados con estado (configurado, pendiente, falta) en Convenios, y aviso en todas las pantallas de Laboral si faltan las tablas. |

| Importar o guardar un convenio subía salarios de la plantilla | `aplicarConvenio()` y `guardarConvenios()` llamaban a `actualizarPlantilla()` | **Eliminado.** Nunca se modifica una ficha como efecto de una importación. Se muestra la lista de trabajadores por debajo del convenio con enlace a su ficha. |
| La lectura del PDF se aplicaba sin revisión | `leerConvenioIA()` y `pegarConvenioIA()` escribían directamente el convenio | La lectura queda como **propuesta** (`laboral_config.datos.convPropuesta`): resumen con categorías nuevas, cambiadas y que desaparecen, datos «no localizados», dudas y trabajadores afectados. Solo se aplica con «Aprobar» (queda registrado quién y cuándo); se puede descartar. Reintentar sustituye la propuesta, no duplica. |

Pruebas: `tests/Feature/LaboralPeriodoTest.php` (alta y baja a mitad de mes, meses de 28 a 31 días, diario y mensual, base mínima explicada, convenio sin categorías, categoría inexistente).

## 3. Plan por fases (pendiente)

2. **Modelo de datos de convenios con versiones**: tablas propias (convenio, versión, tramo de vigencia, categoría con grupo profesional y grupo de cotización separados, concepto retributivo con naturaleza, unidad, base, tratamiento de cotización e IRPF y fuente), documentos vinculados, estados (borrador, revisión, aprobado, sustituido). Migración del JSON actual sin perder datos.
3. **Editor manual completo** por secciones, con guardado parcial, validaciones de solapamientos y huecos, y avisos de cambios sin guardar.
4. **Motor de conceptos y fórmulas**: decimales con redondeo explícito, expresiones controladas (sin `eval`), dependencias y ciclos, explicación por línea.
5. **Contrato, calendario e incidencias**: histórico con fecha de efecto, festivos, vacaciones, permisos, IT, horas por cantidad; propuesta desde el control horario con revisión.
6. **Cálculo y emisión protegida**: tramos dentro del mes, extras con periodo de devengo, atrasos, finiquito con lo ya abonado, rectificación, motivo y usuario, sin dobles emisiones.
7. **PDF e IA con revisión previa**: ya hay propuesta y aprobación (fase 1). Falta: guardar el PDF como documento del convenio, detectar PDF escaneado (OCR), artículo y página por dato, comparación campo a campo y edición de la propuesta antes de aprobarla.
8. **Procesos posteriores**: CRA, cotización, 111/190 y asiento coherentes con rectificaciones.
9. **Verificación** completa con los 18 casos de prueba pedidos.

## 4. Parámetros normativos pendientes de verificar

Constantes de `App\Services\Nomina` con valores de 2026 tomados del prototipo, **sin fuente oficial contrastada**: tipos de cotización (`TIPOS`), bases mínimas por grupo (`BASE_MIN`, `BASE_MIN_DIA` = mensual/30), base máxima (`BASE_MAX`), SMI (`SMI_ANUAL`), escala y límites de IRPF. Deben pasar a una tabla de parámetros con fecha de efecto y fuente (Orden de cotización, Real Decreto del SMI, Reglamento del IRPF).

## 5. Alcance no soportado (hoy)

IT y sus mejoras; tramos con cambio de condiciones dentro del mes; extras con periodo de devengo distinto del año; atrasos y revisiones retroactivas automáticas; regímenes especiales (agrario por jornadas, hogar, artistas…); contratos de formación con reglas propias de cotización; presentación de ficheros a TGSS, SEPE o AEAT.
