# Fiscal

Módulo en **Áreas › Fiscal**. Trabaja con la empresa elegida en «¿Con quién vas a trabajar?».

## Qué hace
- **Modelos fiscales**: lista de los 19 modelos del prototipo, agrupados (IVA, IRPF, Retenciones, Sociedades, Informativas, Patrimonio, Censales). Filtro «Aplicables a este cliente / Todos» y buscador.
- **Detección**: según el tipo de cliente, sus parámetros fiscales y su contabilidad (alquiler y profesionales con retención, terceros que superan el umbral del 347, IS anterior).
- **Estado**: «Pendiente <periodo>» con su plazo (rojo si queda menos de los días de aviso), «Al día» o «No aplica».
- **Ficha del modelo**: casillas calculadas con el libro diario; las editables se pueden corregir y los totales se recalculan al momento. Guardar borrador, recalcular desde contabilidad, marcar como presentado (con fecha), exportar Excel/CSV y PDF.
- **Presentar en Hacienda ↗**: abre la Sede de la AEAT. **No se genera ni se envía ningún fichero** (pendiente de validar los diseños de registro oficiales vigentes).
- **Parámetros fiscales** (botón «Parámetros», por empresa): declarante, régimen de IVA, SII, ROI, REDEME, estimación del IRPF, tipo de IS y modalidad del 202, comuneros, trabajadores, retenciones, 720/714, IBAN y forma de ingreso.
- **Parámetros generales** (/parametros/fiscal): días de aviso, umbral del 347, % del 130 y del 202.
- **Calendario**: muestra los plazos de los modelos pendientes de la empresa elegida.

## Plazos (art. 30.5 Ley 39/2015: si el último día es inhábil pasa al siguiente hábil)
303/130/131/349: día 20 de abril, julio y octubre; 4T el 30 de enero. 111/115/123: 4T el 20 de enero. 202: 20 de abril, octubre y diciembre. 390: 30 de enero. 190/180/193/184: 31 de enero. 347: último día de febrero. 720: 31 de marzo. 100/714: 30 de junio. 200: 25 de julio.

## Limitaciones conocidas
- Los importes son un **borrador**: hay que revisarlos antes de presentar.
- 303: solo tipos 4/10/21 %; si hay bases a otros tipos se avisa en pantalla.
- 100 (renta): escala general orientativa (no aplica la escala autonómica de Andalucía).
- 200: tipos 25/23/15 %; los tipos reducidos de micro y pymes de la Ley 7/2024 se ajustan a mano en la casilla.
- 349, 123/193, 131, 720, 714: casillas manuales (no se deducen de la contabilidad).
