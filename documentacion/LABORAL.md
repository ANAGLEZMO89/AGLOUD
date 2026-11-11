# Laboral y SS

Módulo construido a partir del prototipo («Laboral y SS»). Trabaja sobre la empresa elegida.

## Menú
- **Trabajadores** · **Integral de nóminas**
- **Seguridad Social ▾**: Cret@ - Sistema de Liquidación Directa (seguros sociales), CRA - Conceptos Retributivos Abonados (con los códigos CRA que asigne el despacho), AFI - Régimen Especial Agrario Jornadas (trabajadores marcados en su ficha), AFI - Reduc. contribuciones planes de pensiones, AFI - Altas, bajas y variaciones.
- **Gestión ▾**: Remesa transferencia de nóminas (fichero SEPA pain.001.001.03, categoría SALA), CCC, Comunic@ (comunicación de contratos al SEPE, plazo de 10 días), Convenios (convenio y tablas salariales), Cálculo de nóminas (todas las nóminas con filtros, exportación e impresión en bloque).
- **Modelos tributarios ▾**: 145 (por trabajador), 111 (casillas 01-03 por trimestre desde las nóminas), 190 (resumen anual por perceptor, clave A; Excel, sin fichero oficial).
- **Parámetros laboral**: modelo de nómina, firma, conexión RED y SEPE.
- **Parámetros contrato**: texto del contrato por modalidad con marcadores {así} y **negrita**, cláusulas por defecto por modalidad, cláusulas propias, contratos que usa la empresa. Cada trabajador puede tener su propio texto (ficha › Seguridad Social y SEPE › Editar el texto del contrato).

## Cálculo (`App\Services\Nomina`)
- Tipos de cotización 2026 del diseño: CC 23,60 + 4,70 %; MEI 0,75 + 0,15 %; desempleo 5,50 + 1,55 % (indefinidos) o 6,70 + 1,60 % (temporales); FOGASA 0,20 %; FP 0,60 + 0,10 %; AT y EP según parámetro.
- Base máxima 5.101,20 € y mínimas por grupo; SMI anual 17.094 €. **Revisar si cambian.**
- IRPF: algoritmo simplificado del prototipo con regularización mensual. Contrastar con la calculadora oficial de la AEAT.
- Asiento de nóminas: 640 / 642 al debe; 476, 4751, 460 (anticipos/embargos) y 465 al haber, con las cuentas de Parámetros de contabilidad.

## Límites
- No se envía ningún fichero a la TGSS ni al SEPE: se abre la sede oficial y se deja constancia del trámite.
- Del certificado RED/SEPE solo se guarda el nombre del fichero.
- El PDF del convenio se lee de las primeras páginas (nombre, código, vigencia, jornada, vacaciones); las tablas salariales se introducen a mano.
