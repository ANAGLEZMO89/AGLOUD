# Laboral · seguimiento del módulo (convenios, nóminas y procesos posteriores)

Documento vivo. Se actualiza al cerrar cada fase. Última actualización: 13/11/2026 (fase 3).

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

## 3. Fase 2 · Modelo de datos de convenios con versiones (hecha)

- **Tablas** (migración `2026_11_19_000260_convenios_versionados`, reversible): `convenios` (del despacho, se introduce una vez), `convenio_versiones` (vigencia del texto y efectos económicos por separado, estado, fuente, reglas), `convenio_categorias` (grupo profesional y nivel del convenio separados del grupo de cotización; salario `decimal(12,4)` que puede quedar vacío = no localizado), `convenio_conceptos` (naturaleza, cálculo, unidad, base, automático, proporcionalidad, en pagas, cotiza/tributa con límite, referencia, redondeo), `convenio_concepto_importes` (importe por concepto y categoría), `convenio_documentos` (PDF en el gestor documental cifrado del despacho), `empresa_convenios` (asignación a la empresa con fechas).
- **Migración de datos**: copia el convenio de cada empresa desde `laboral_config.datos.conv`; el «plus» por categoría pasa a concepto «Plus de convenio» con importe por categoría. El JSON antiguo no se borra (`down()` vuelve atrás) pero ya no se lee.
- **Única fuente**: `App\Services\Convenios`. `Nomina::config($c, $fecha)` toma el convenio de la versión aplicable en la fecha. Cada nómina usa la versión de **su mes** (`Nomina::paraPeriodo`) y guarda qué versión y categoría usó.
- **Prioridad de versiones**: aprobada cuyos efectos cubren la fecha; si no hay, la vigente en borrador o revisión (la pantalla lo indica).
- **Validaciones** (`Convenios::validar`): solapamientos (error), huecos (advertencia), versión sin efectos (advertencia), fechas incoherentes (error), versión sin categorías o categoría sin salario (error), sin grupo de cotización (advertencia).
- **Pantalla**: asignar convenio del despacho o crear uno nuevo (si cambia, el anterior queda para los meses pasados); lista de versiones; nueva versión copiando tablas, conceptos e importes y cerrando la anterior; identificación y publicación; campos de la versión; grupo profesional y nivel; documentos PDF; aviso si lo usan varias empresas.
- **Nómina emitida congelada**: al emitir se guarda la foto del cálculo; editar después el convenio, la ficha o los parámetros no la cambia.

Pruebas: `tests/Feature/ConveniosVersionesTest.php` (9).

## 4. Fase 3 · Editor manual completo por secciones (hecha)

- **9 secciones** en Laboral › Gestión › Convenios, cada una con su formulario, guardado propio, validación en el servidor y error junto al campo (`ConvenioEditorController`, ruta `laboral.convenios.seccion`): identificación y publicación; versión y vigencia; categorías y tablas (código, grupo profesional, nivel, grupo de cotización, salario mensual o diario, plus de convenio, artículo); conceptos retributivos (código, CRA, naturaleza, cálculo importe/porcentaje/fórmula, unidad, importe general o por categoría, base, cotiza/tributa sí/no/con límite y su descripción, redondeo, automático, proporcional a jornada y a días en alta, en pagas, artículo, descripción); pagas extraordinarias (cualquier número, mes de cobro, mensualidad/días/importe, plus y antigüedad incluidos, periodo de devengo, conceptos incluidos, artículo); antigüedad (periodo, € o %, base, tope, cómputo, congelación, «comprobado que no tiene»); jornada, vacaciones y horas (anual, semanal, distribución, vacaciones naturales o laborables, descansos, nocturnidad, festivos, permisos, horas extra con precio o recargo y compensación, complementarias); IT (tramos por contingencia, solo con hospitalización, base, pagas, condiciones); contratación (periodo de prueba, preavisos, cláusulas, extinción).
- **Navegación con estado por sección** (configurada, pendiente, falta), aviso «Cambios sin guardar» por sección y aviso al salir de la página; el botón se bloquea mientras guarda.
- **Validaciones**: nombres repetidos, grupo de cotización 1–11, fechas al revés, aprobar sin categorías con salario, porcentaje sin base, fórmula vacía, concepto sin importe general ni por categoría, límite sin describir, pagas por días o importe sin dato, devengo «otro» sin meses, antigüedad sin valor, horas extra sin precio, tramos de IT al revés.
- **Conexión con la nómina**: importe por categoría, proporcionalidad a jornada y a días en alta, base de antigüedad (salario base o base + plus) y antigüedad congelada ya se calculan. **No se calculaban** al cerrar la fase 3: fórmulas y conceptos con límite (resuelto en la fase 4); unidades «día efectivo», «año» y «por pago» (pendiente). Tampoco el periodo de devengo de las pagas, la IT, el calendario ni las reglas de contratación.
- Se mantiene el formulario antiguo (`laboral.convenios.guardar`) solo como vía de compatibilidad de las pruebas; la pantalla ya no lo usa.

Pruebas: `tests/Feature/ConvenioEditorTest.php` (5).

## 4 bis. Fase 4 · Motor de conceptos y fórmulas (hecha)

- **Decimales exactos** (`App\Support\Dec`): entero escalado a 4 decimales, sin coma flotante; redondeo «la mitad hacia arriba» al céntimo (o al euro si el concepto lo dice) **al final de cada línea**; división por cero y desbordamiento lanzan excepción, nunca un cero.
- **Fórmulas cerradas** (`App\Services\Formula`): números, variables permitidas (`salario_base`, `salario_dia`, `plus_convenio`, `antiguedad`, `jornada`, `horas_semana`, `horas_anuales`, `horas_mes`, `dias_mes`, `dias_alta`, `dias_laborables`, `cantidad`), referencias a otros conceptos `c_CÓDIGO`, `+ − * /`, paréntesis, `min`, `max`, `redondear`. Se analizan a un árbol (sin `eval`) y se validan al guardar: sintaxis, variables, códigos existentes, división por cero escrita en la fórmula y **ciclos** (A → B → A), con el error junto al campo.
- **Orden de cálculo**: primero importes y porcentajes, después las fórmulas en el orden de sus dependencias. Si un dato falta (p. ej. jornada anual sin rellenar para `horas_mes`) la línea **no sale**: no se calcula con cero.
- **Límites de cotización e IRPF**: importe y unidad (día o mes) del límite; la línea se parte en «hasta el límite» (no cotiza / no tributa, según el concepto) y «exceso sobre el límite» (cotiza y tributa). Combinaciones no soportadas se avisan en el concepto con el motivo.
- **Explicación de cada línea** en el editor de nóminas («Cómo se calcula»): cantidad × precio, jornada, días en alta, fórmula con los valores sustituidos y origen (ficha, tabla del convenio con su categoría, versión, artículo).
- **Simulador** en la página del convenio: categoría, mes, jornada, alta, baja, antigüedad y prorrata → líneas explicadas, base de cotización con su explicación y Seguridad Social, **sin guardar nada**. No calcula IRPF (depende del trabajador) y no inventa salario si la categoría no lo tiene.
- Migración `2026_11_19_000270_convenio_conceptos_limite` (`limite_importe`, `limite_unidad`).

Pruebas: `tests/Feature/MotorConceptosTest.php` (7) y `ConvenioEditorTest` actualizado.

Sigue sin calcularse: unidades «hora», «día efectivo», «año» y «por pago» en automático (las horas se meten por cantidad en la nómina; llega en la fase 5 con el calendario e incidencias).

## 4 ter. Fase 5 · Contrato, calendario e incidencias (hecha)

- **Condiciones con fecha de efecto** (`trabajador_condiciones`, cifradas): al cambiar en la ficha el salario, la jornada, las horas, la categoría, el grupo, el contrato, las pagas o los días de trabajo hay que indicar **desde cuándo** (o marcar «es una corrección»). Cada mes se calcula con las condiciones vigentes al inicio de su periodo; las nóminas emitidas no cambian y se avisa de cuántas hay desde esa fecha. Histórico visible en la ficha › Contrato, con usuario y motivo. Si las condiciones cambian **dentro** del mes se avisa: ese mes usa las del inicio (tramos en la fase 6).
- **Calendario del trabajador** (`App\Services\Calendario`): días de trabajo de la semana (ficha › Contrato; por defecto L-V) + festivos de la empresa (los que ya usaba la aplicación: nacionales, de la comunidad y locales de Parámetros › Notificaciones). Cuenta días en alta, **laborables**, **trabajados** (sin IT ni ausencias no retribuidas) y **efectivos** (sin ninguna ausencia). La unidad «por día efectivo» ya se calcula; «por día trabajado» usa el calendario (antes, lunes a viernes sin festivos); las fórmulas tienen `dias_trabajados` y `dias_efectivos`.
- **Ausencias** (una sola tabla con el control horario, `jornada_ausencias` ampliada): vacaciones, permiso retribuido y asuntos propios no descuentan; IT, permiso no retribuido y ausencia injustificada descuentan 1/30 del salario mensual por día (o el día de salario diario). Las del control horario llegan **pendientes de revisar** y no cuentan hasta «Llevar a la nómina»; una confirmada no se puede borrar desde el control horario. Las ausencias que ya existían quedan pendientes: ninguna nómina cambia sola.
- **IT**: prestación separada de la mejora. Contingencias comunes: días 1-3 sin prestación; 4-15 al 60 % a cargo de la empresa; 16-20 al 60 % y desde el 21 al 75 % en pago delegado. Profesionales: 75 % desde el primer día de baja. Base reguladora diaria: la indicada en la baja o la base de contingencias comunes de la nómina **emitida** del mes anterior a la baja / 30 (o / días del mes si cobra por día). Sin base reguladora: **error que impide emitir**. Mejora del convenio por tramos y contingencia (con o sin hospitalización) hasta el % sobre la base reguladora; si el convenio la calcula sobre otra base, se avisa y no se calcula. Los días de IT cotizan por la base reguladora (sumada en la base y explicada).
- **Horas por cantidad** (`laboral_horas`): extraordinarias (precio del convenio o recargo sobre la hora ordinaria = salario anual / jornada anual; error a tiempo parcial, aviso por encima de 80 h/año o por debajo de la hora ordinaria; si se compensan con descanso no se pagan), complementarias (solo a tiempo parcial, a la hora ordinaria) y conceptos del convenio por hora (importe general, por categoría o fórmula; con su límite si lo tiene).
- **Propuesta desde el control horario**: días cerrados en que lo fichado supera la jornada del día en 15 minutos o más; se marcan y se llevan como horas (extra o complementarias), con nota de lo fichado. Nada entra sin revisión.
- **Pantalla**: pestaña «Calendario» del Integral (mes con colores y leyenda accesible, resumen de días, ausencias con su estado y datos de IT, horas, propuesta de fichajes). Bloqueada con la nómina emitida o el mes contabilizado. Errores (rojo), avisos (ámbar) e información (azul) en el borrador; los errores impiden emitir.
- Migración `2026_11_20_000280_laboral_calendario`.

Pruebas: `tests/Feature/CalendarioLaboralTest.php` (10). Casos de la lista cubiertos: 2 (meses de 28, 30 y 31 días), 8 (días trabajados y efectivos), 9 (vacaciones, ausencias e IT), parte del 4 (jornada parcial; cambio dentro del mes solo avisado) y 17 (otra empresa).

Pendiente de verificar en fuente oficial: porcentajes y tramos de la prestación de IT (generales del Régimen General) y tratamiento de cotización de permisos no retribuidos.

## 4 quater. Fase 6 · Cálculo y emisión protegida (hecha)

- **Tramos dentro del mes**: si las condiciones cambian a mitad de mes, cada tramo se calcula con las suyas (salario, plus, antigüedad, pluses del convenio, IT, horas) y las líneas llevan «(del 1 al 14)». Mes comercial de 30 días: con el mes completo, el último tramo completa hasta 30 (14 + 16 en octubre). Pagas en su mes, variables, fijos y deducciones, una sola vez. Si cambian el grupo o la jornada, la base mínima usa los del último tramo y se avisa.
- **Pagas extra por periodo de devengo** (anual: los 12 meses que terminan en su mes de cobro; semestral: los 6; otro: del mes inicial al final del convenio). En su mes se paga lo devengado: días en alta sin IT ni ausencias no retribuidas / días del periodo, con la explicación. Un alta a mitad de año ya no cobra la paga entera. Las prorrateadas siguen igual (1/12 al mes).
- **Atrasos**: el recibo «Atrasos» recalcula cada mes anterior del año con la nómina emitida, con el convenio y las condiciones vigentes hoy para ese mes (revisión retroactiva), y paga la diferencia neta por mes con el detalle por concepto. Si saliera menos, avisa y no descuenta. Recuerda que cotizan en liquidación complementaria.
- **Finiquito**: partes proporcionales con el periodo de devengo de cada paga hasta la baja, **menos lo ya pagado** de ese periodo en nóminas o recibos de paga extra emitidos. Si se cobró de más, avisa (no descuenta solo).
- **Rectificación**: reabrir una nómina emitida exige motivo y guarda la versión emitida (fecha, usuario, motivo, importes y líneas) en un historial visible en el editor.
- **Sin dobles emisiones**: el servidor bloquea la fila en una transacción y comprueba de nuevo (índice único por trabajador, mes y recibo); el navegador desactiva los botones al enviar.
- **Diferencias con el mes anterior** en el borrador (totales y conceptos que cambian).
- **Emisión del mes en bloque** desde el resumen: emite las nóminas no emitidas (borrador guardado o automática), salta las que tienen errores y lo dice.
- **Conceptos fijos con mes de inicio y fin** (ficha › Retribución): limitar o quitar; al guardar un mes no se pierden los fijos que no aplican a ese mes.

Pruebas: `tests/Feature/EmisionNominaTest.php` (6), más casos en `CalendarioLaboralTest` (tramos) y `LaboralTest` (pagas proporcionales, reabrir con motivo). Casos de la lista cubiertos: 1, 4, 6, 7, 12, 13, 15, 16.

Pendiente de criterio jurídico (avisado en la nómina): devengo de las pagas durante la IT (por defecto no generan paga a cargo de la empresa salvo que el convenio diga otra cosa).

## 4 quinquies. Fase 7 · PDF e IA con revisión previa (hecha)

- Dos vías que rellenan la misma estructura: editor manual (fases 2-4) y lectura del PDF, con la API si hay clave configurada en el servidor o, sin coste, en claude.ai con la suscripción del despacho (copiar instrucciones, pegar la respuesta). Las dos pasan por la misma validación y la misma revisión.
- **PDF guardado como documento del convenio** en las dos vías (en la de claude.ai, adjuntándolo al pegar la respuesta) y **análisis del PDF** (`App\Support\PdfInfo`): páginas, texto seleccionable o **escaneado** (solo imágenes: se avisa de revisar las cifras una a una) o protegido.
- **Esquema ampliado**: estado de cada apartado (encontrado / **no localizado** / **no existe** / no aplicable) y **artículo y página** de cada dato. Las instrucciones dicen que el contenido del PDF son datos y que se ignore cualquier instrucción que contenga. No se envían datos de trabajadores.
- **Revisión del servidor** antes de enseñarla: descarta filas sin salario (no las pone a cero), detecta categorías repetidas con importes distintos (contradicción), grupos de cotización no válidos, pagas sin cifra, estados que no cuadran con los datos y tablas de años anteriores.
- **Pantalla de revisión**: por apartado, «ahora» frente a «propuesta» con su artículo y página y casilla para aplicarlo o no; **tabla salarial editable** (nombre, grupo, salario, unidad, plus) con comparación (nueva / cambia / igual) y **trabajadores afectados** («quedaría por debajo»); categorías actuales que no vienen en la lectura, que se conservan salvo que se marquen para quitar.
- **Destino**: la versión en borrador o una **versión nueva** (copia de la vigente) con nombre y fecha de efectos. Una versión aprobada nunca se sobrescribe desde la lectura. La versión queda con `fuente = ia` y en `referencias` el documento, la fecha, quién la aprobó, el análisis del PDF y artículo/página por dato.
- Reintentar no duplica (todo se actualiza por nombre) y **los conceptos creados a mano no se borran** al aplicar pluses leídos.

Pruebas: `tests/Feature/ConvenioIATest.php` (5; una nueva con estados, contradicciones, PDF escaneado, corrección manual, destino y procedencia).

## 4 sexies. Fase 8 · Procesos posteriores (hecha)

- **Fuente única: lo emitido** (`App\Services\Posteriores`). Seguros sociales, CRA, remesa, asiento y modelos 111 y 190 toman la foto del cálculo de cada recibo emitido; lo no emitido aparece como **provisional** y se nombra. Una rectificación (reabrir con motivo y volver a emitir) cambia la foto y con ella todos los procesos.
- **Asiento**: solo con todos los recibos del mes emitidos y bloqueando el mes (dos peticiones a la vez no crean dos asientos). Cuentas: 640 sueldos (sin la IT en pago delegado ni las indemnizaciones), cuenta de especie configurable, 641 indemnizaciones, **471 IT en pago delegado** (a compensar con la Seguridad Social), 642, 476 (cuotas de empresa y trabajador), 4751, 460 anticipos, embargos, **otras deducciones (555 por defecto)**, **especie en el haber (755 por defecto)** y 465 líquido. Antes la especie y las deducciones fijas descuadraban o se mezclaban con los embargos. Con el asiento hecho no se puede reabrir ni cambiar el mes.
- **Seguros sociales**: suman la nómina, el **finiquito** y los salarios de tramitación del mes (antes solo la nómina); la paga extra en recibo aparte no (ya cotiza con la prorrata) y los **atrasos se listan aparte por mes de devengo** para su liquidación complementaria. Muestra la **IT en pago delegado a compensar** y lo que queda a ingresar. Si después de anotar la liquidación como confirmada se emiten o rectifican recibos, avisa para regularizar. El botón pasa a «Anotar que está confirmada en el SLD»: AG Cloud no se conecta ni recibe respuesta.
- **CRA**: por línea; usa el **código CRA del concepto del convenio** y, si no tiene, el de la tabla de la empresa (ahora también para IT, especie e indemnizaciones).
- **Remesa SEPA**: solo paga recibos emitidos.
- **Modelos 111 y 190**: avisan de los recibos sin emitir y de que rectificar un periodo presentado exige complementaria o sustitutiva. El 111 de Fiscal sale de la contabilidad (cuenta 4751), que ahora solo recoge lo emitido.
- **Estado real de cada proceso** en su pantalla: preparar datos, generar fichero, descargar, presentar, respuesta oficial y conciliar, diciendo qué hace AG Cloud y qué se hace en la sede o en el banco. No se envía nada a la AEAT, la TGSS, el SEPE ni la DEHú.

Pruebas: `tests/Feature/PosterioresTest.php` (4) y `LaboralTest` actualizado (contabilizar y remesa exigen emitir).

## 5. Fase 9 · Verificación: los 18 casos (hecha)

Batería completa: 183 pruebas, 2.120 comprobaciones, todas correctas. Cada caso comprueba importes, estados y persistencia, no solo que la página cargue.

| # | Caso | Pruebas |
|---|------|---------|
| 1 | Alta y baja a mitad de mes | `LaboralPeriodoTest::test_alta_a_mitad_de_mes_con_salario_diario`, `::test_meses_de_28_29_30_y_31_dias_y_baja_a_mitad_de_mes`, `VerificacionLaboralTest::test_caso_3…` |
| 2 | Meses de 28, 29, 30 y 31 días | `LaboralPeriodoTest::test_meses_de_28_29_30_y_31_dias…`, `CalendarioLaboralTest::test_calendario_con_dias_de_trabajo…` |
| 3 | Salario diario y mensual | `VerificacionLaboralTest::test_caso_3_salario_diario_y_mensual`, `LaboralTest::test_grupo_10_y_salario_diario` |
| 4 | Jornada parcial y cambio de jornada en el periodo | `CalendarioLaboralTest::test_cambio_de_condiciones…` (tramos), `::test_horas_extra_complementarias…`, `MotorConceptosTest` (media jornada) |
| 5 | Convenio sin categorías | `VerificacionLaboralTest::test_caso_5_convenio_sin_categorias`, `LaboralPeriodoTest::test_convenio_sin_categorias_no_da_falsos_positivos` |
| 6 | Extras prorrateadas y no prorrateadas | `LaboralTest::test_calculo_de_la_nomina…`, `PosterioresTest` (prorrata), `EmisionNominaTest::test_pagas_extra…` |
| 7 | Periodos y fórmulas de devengo de extras | `EmisionNominaTest::test_pagas_extra_segun_su_periodo_de_devengo` |
| 8 | Pluses por días trabajados | `CalendarioLaboralTest::test_plus_por_dia_efectivo_y_por_dia_trabajado`, `ConvenioEditorTest` |
| 9 | Vacaciones, ausencias e IT | `CalendarioLaboralTest` (vacaciones, no retribuidas, IT con prestación y mejora, IT sin base reguladora) |
| 10 | Antigüedad por tramos | `VerificacionLaboralTest::test_caso_10_antiguedad_por_tramos`, `ConvenioIATest` |
| 11 | Ajustes de bases con explicación | `VerificacionLaboralTest::test_caso_11…`, `LaboralPeriodoTest::test_base_minima_prorrateada_y_explicada`, `CalendarioLaboralTest` (IT) |
| 12 | Revisión retroactiva y atrasos | `EmisionNominaTest::test_atrasos_por_revision_salarial_retroactiva`, `PosterioresTest` (complementaria) |
| 13 | Finiquito y cantidades ya abonadas | `EmisionNominaTest::test_finiquito_con_lo_ya_pagado` |
| 14 | Importación de IA incompleta, contradictoria o duplicada | `VerificacionLaboralTest::test_caso_14…`, `ConvenioIATest::test_revision_de_la_propuesta…`, `::test_lectura_gratuita…` |
| 15 | Nómina emitida inalterada al cambiar el convenio | `ConveniosVersionesTest::test_nomina_emitida_no_cambia_al_editar_el_convenio` |
| 16 | Sin emisiones ni asientos duplicados | `EmisionNominaTest::test_emision_sin_duplicados…`, `::test_emision_del_mes_en_bloque…`, `PosterioresTest::test_asiento_solo_de_lo_emitido…` |
| 17 | Aislamiento entre empresas | `LaboralTest::test_aislamiento_entre_empresas_y_portal`, `CalendarioLaboralTest::test_pantalla_y_bloqueo…`, `ConveniosVersionesTest::test_documento_del_convenio_y_aislamiento` |
| 18 | Coincidencia entre cálculo, PDF y exportaciones | `VerificacionLaboralTest::test_caso_18…` (recibo, editor, exportaciones, RNT, remesa, asiento y 111) |

## 6. Parámetros normativos pendientes de verificar

Constantes de `App\Services\Nomina` con valores de 2026 tomados del prototipo, **sin fuente oficial contrastada**: tipos de cotización (`TIPOS`), bases mínimas por grupo (`BASE_MIN`, `BASE_MIN_DIA` = mensual/30), base máxima (`BASE_MAX`), SMI (`SMI_ANUAL`), escala y límites de IRPF. Deben pasar a una tabla de parámetros con fecha de efecto y fuente (Orden de cotización, Real Decreto del SMI, Reglamento del IRPF).

## 7. Alcance no soportado (hoy)

- Regímenes y sistemas especiales (agrario por jornadas, empleados de hogar, artistas, mar…) y contratos formativos con reglas propias de cotización: se calculan como contrato ordinario; hay que revisarlos.
- IT: recaídas, regímenes especiales y prórrogas; devengo de pagas durante la IT según convenio (aviso).
- Cotización de permisos no retribuidos y huelga (aviso); base mínima con cambio de grupo o jornada dentro del mes (aviso).
- Festivos propios del centro de trabajo (se usan los de la empresa); inactividad de fijos discontinuos.
- Unidades «por año» y «por pago» de los conceptos del convenio (se añaden a mano).
- Atrasos de años anteriores (solo los del mismo año).
- Generación de ficheros oficiales y presentación en TGSS (RED/SLD, CRA, afiliación), SEPE (Contrat@, Certific@2), AEAT (111, 190) y DEHú: no se hace desde AG Cloud; las pantallas dicen qué parte es de AG Cloud y qué parte se hace en la sede.
