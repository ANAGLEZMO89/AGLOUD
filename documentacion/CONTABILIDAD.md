# Contabilidad (PGC-PYMES)

Módulo en **Áreas › Contabilidad**. Trabaja siempre sobre la empresa elegida en «¿Con quién vas a trabajar?» y sobre el ejercicio elegido en el desplegable de la cabecera.

## Pestañas

| Pestaña | Qué hace |
|---|---|
| Resumen | Ingresos, gastos, resultado, tesorería, facturas pendientes de contabilizar y últimos asientos. |
| Bandeja de facturas | Facturas emitidas (Facturación) y recibidas (Proveedores/Acreedores) sin asiento. Se marcan y se contabilizan de golpe. |
| Libro diario | Asientos del ejercicio, con filtros por fechas y texto. Exportación a CSV (separador «;», formato español). Borrado de asientos manuales/facturas mientras el ejercicio esté abierto y no bloqueado. |
| Mantenimiento de apuntes | Alta de asientos manuales (partida doble). Indica si cuadra; la tecla «=» en un importe pone la diferencia. Alta de subcuentas propias (bancos, etc.). |
| Mayor | Movimientos y saldo acumulado de una cuenta o grupo de cuentas (p. ej. 430 = todos los clientes). |
| Sumas y saldos | Balance de comprobación a 1, 2, 3 dígitos o subcuenta. |
| PyG y balance | Modelos abreviados PGC-PYMES **orientativos**. |
| Ejercicios | Abrir ejercicio, bloquear hasta una fecha, cerrar (regularización + cierre + apertura del siguiente) y reabrir (solo administración). |
| Parámetros | Cuentas por defecto de ventas, compras, gastos, IVA, retenciones, suplidos y banco. |

## Asientos automáticos

**Factura emitida:** 430 (Debe, total) · 473 (Debe, retención) / 705 (Haber, base) · 477 (Haber, cuota) · 555 (Haber, suplidos).
A cada cliente se le crea su subcuenta 430000001, 430000002… por NIF.

**Factura recibida:** gasto o 600 (Debe, base, más cuota si el IVA no es deducible) · 472 (Debe, cuota) / 4751 (Haber, retención) · 400/410 del tercero (Haber, total).

**Anulación de factura emitida contabilizada:** contraasiento automático.

Una factura recibida contabilizada no se puede editar ni borrar: primero hay que borrar su asiento.

## Controles

- Un asiento no se graba si no cuadra, si usa cuentas que no existen en el PGC, si cae en un ejercicio cerrado o en fecha bloqueada.
- No se puede cerrar un ejercicio con facturas pendientes en la bandeja.
- Numeración de asientos correlativa por ejercicio.

## Pendiente (siguiente entrega)

Amortizaciones, conciliación bancaria, cartera de cobros y pagos (asientos de cobro/pago), lectura OCR de facturas, cuentas anuales y libros para el Registro Mercantil, impuesto sobre sociedades (módulo Fiscal).
