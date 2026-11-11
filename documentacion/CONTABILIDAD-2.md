# Contabilidad · segunda entrega

## Menú
Resumen · Bandeja de facturas · Libro diario · Mantenimiento de apuntes · **Listados contables ▾** · **PGC** · **Conciliador bancario** · **Registro Mercantil ▾** · Ejercicios · Parámetros.

- **Listados contables**: Extracto de cuenta, Cuenta de explotación (PyG por trimestres), Balance de sumas y saldos, Listado diario de movimientos, Listado mayor de cuentas (rango de cuentas) y Balances oficiales. Todos con Excel/CSV e «Imprimir / PDF».
- **PGC**: todas las cuentas a 9 dígitos. Alta de subcuentas (572.1 → 572000001), baja de cuentas del plan sin movimientos (y reactivación) y eliminación de subcuentas propias. Ligado a clientes (430…), proveedores (400…), acreedores (410…) y a las cuentas bancarias de la ficha de la empresa (572…, se crean solas).

## Bandeja de facturas con OCR
- Zona para arrastrar facturas y tiques (PDF/JPG/PNG, varios a la vez). Se guardan en Documentos › Contable › Facturas recibidas.
- Cada factura se abre en una ventana con el documento a la izquierda y los datos a la derecha. «Leer con OCR» rellena NIF, nombre, número, fecha, base, IVA y retención; el tercero se reconoce por su NIF y, si no existe, se da de alta al contabilizar.
- Las emitidas desde Facturación se revisan (sus datos no se pueden cambiar) y se contabilizan igual.
- El OCR funciona en el navegador (pdf.js + tesseract.js alojados en el servidor): las facturas no se envían a terceros.

## Mantenimiento de apuntes
- Tipo **Manual** (a la antigua: cuenta, concepto, debe, haber, documento), **Factura** (recibida o emitida, con subida y OCR, o a mano) y **Nómina** (asiento con todas las partidas y cuentas laborales de Parámetros, o leyendo la nómina con OCR; el neto se calcula solo).
- Desplegable de **plantillas predefinidas** (21 asientos habituales).
- Debajo se ve el **asiento haciéndose** con el cuadre; al guardar aparece el asiento guardado. Pestaña «Últimos asientos» para editarlos.

## Conciliador bancario
- Importa extractos en Excel (.xlsx) o CSV con columnas Fecha, Concepto, Importe (o Cargo/Abono) y Saldo opcional. No duplica movimientos ya importados.
- Conciliación automática: apunte del banco con el mismo importe (±7 días) o, si no lo hay, factura pendiente de cobro o pago por el mismo total (la marca cobrada/pagada y asienta el cobro/pago con esa cuenta bancaria).
- Manual: conciliar con un apunte propuesto o crear el asiento contra la cuenta elegida (se proponen cuentas por el concepto: TGSS→476, AEAT→4750, comisiones→626…). Se puede deshacer.
- **Pendiente**: el formato exacto de Excel de cada banco (lo defines tú).

## Registro Mercantil
- **Plantillas e informes**: certificado de aprobación de cuentas, acta de la junta, propuesta de aplicación del resultado, declaración de titularidad real, instancia de presentación e informe de gestión, rellenados con la ficha y la contabilidad.
- **Legalia**: libros a legalizar, plazo (30/04, art. 18 Ley 14/2013), enlaces a los listados para imprimirlos y estados preparado/presentado.
- **D2**: documentación del depósito, plazos (aprobación 30/06 art. 164 LSC; depósito un mes después, art. 279 LSC) y estado depositado.
- No se generan los ficheros oficiales de Legalia ni del D2: se preparan con los programas del Colegio de Registradores.
