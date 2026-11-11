# Laboral y SS

Módulo construido a partir del prototipo («Laboral y SS»). Trabaja sobre la empresa elegida.

## Pestañas
- **Trabajadores**: plantilla y ficha (datos personales, afiliación, contrato con comprobación legal y cláusulas, retribución, IRPF · modelo 145, Seguridad Social y SEPE).
- **Integral de nóminas**: nóminas del mes, variables (horas extra, otros devengos, dietas, kilometraje, anticipos, embargos), recibo en 3 modelos, contabilizar el mes y enlace al modelo 111.
- **Seguros sociales**: bases y cuotas (RLC/RNT), tipo de AT y EP, confirmar la liquidación en el SLD.
- **Altas y bajas**: movimientos de afiliación pendientes/comunicados y baja de trabajadores.
- **Parámetros de laboral**: convenio y tablas salariales, modelo de nómina, conexión RED y SEPE, CCC, contratos habilitados y cláusulas por defecto.

## Cálculo (`App\Services\Nomina`)
- Tipos de cotización 2026 del diseño: CC 23,60 + 4,70 %; MEI 0,75 + 0,15 %; desempleo 5,50 + 1,55 % (indefinidos) o 6,70 + 1,60 % (temporales); FOGASA 0,20 %; FP 0,60 + 0,10 %; AT y EP según parámetro.
- Base máxima 5.101,20 € y mínimas por grupo; SMI anual 17.094 €. **Revisar si cambian.**
- IRPF: algoritmo simplificado del prototipo con regularización mensual. Contrastar con la calculadora oficial de la AEAT.
- Asiento de nóminas: 640 / 642 al debe; 476, 4751, 460 (anticipos/embargos) y 465 al haber, con las cuentas de Parámetros de contabilidad.

## Límites
- No se envía ningún fichero a la TGSS ni al SEPE: se abre la sede oficial y se deja constancia del trámite.
- Del certificado RED/SEPE solo se guarda el nombre del fichero.
- El PDF del convenio se lee de las primeras páginas (nombre, código, vigencia, jornada, vacaciones); las tablas salariales se introducen a mano.
