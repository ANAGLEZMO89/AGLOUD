# Estado del proyecto: comprobado, pendiente y propuesto

Fecha: 28/09/2026. Desarrollado en un entorno en la nube, **sin acceso a tu PC ni a Wtransnet**.

## Fase 1. Acceso a tu Edge y a Wtransnet

| Comprobación | Resultado |
|---|---|
| Acceso a la pestaña de Edge abierta en tu PC | **No es posible desde este entorno.** Trabajo en un contenedor en la nube, sin conexión con tu equipo ni con tu navegador. |
| Acceso directo a `app.wtransnet.com` | **Bloqueado.** La red del contenedor rechaza la conexión (el proxy devuelve 403). No he podido ver la web ni la página de inicio de sesión. |
| Empresa de la sesión (FEMABEL, S.L. o Transruter Logística) | **Sin verificar.** El bot la comprueba en tu PC si indicas `empresa_esperada`. Si no coincide, se detiene antes de buscar. |
| Condiciones de uso o API de Wtransnet | **Sin verificar**, porque no he podido acceder a la web. No supongo que exista API ni que no exista. |

Por tanto, **no he leído ninguna oferta real** y **no entrego un Excel de Wtransnet**: sería inventado. Las fases 4 y 5 las tienes que ejecutar tú en tu PC, con los pasos de LEEME.md.

## Fase 2. Mapa de navegación y campos

- Se ha usado el mapa que me diste (25/09/2026): Bolsa → Buscar carga / Buscar camión → formulario → Buscar → listado → enlace de fecha → ficha.
- Diferencias con la interfaz actual: **no se han podido comprobar.** El botón «Inspeccionar interfaz» genera `mapa_interfaz_*.md` en tu PC con la interfaz real: campos, todas las opciones de los desplegables, columnas del listado y etiquetas de una ficha, e indica qué filtros localiza el bot y cuáles no.
- Los selectores no están escritos a mano: en cada ejecución el bot lee los controles de la página (nombre, id, etiqueta visible, sección) y localiza cada filtro por su etiqueta. Si un filtro es ambiguo o no aparece, se para y lo explica.

## Fase 3. Implementación: HECHA y probada contra una maqueta

Componentes separados, en `wtbot/`:

| Módulo | Función |
|---|---|
| `navegador.py` | Conexión a Edge por CDP, pestaña existente, esperas, reintentos, detección de login, CAPTCHA o sesión caducada |
| `seguridad.py` | Lista blanca de clics, lista negra de acciones y bloqueo de red y de diálogos |
| `formularios.py` | Lectura de controles reales, aplicación de filtros y verificación de que se conservan |
| `lectura.py` | Listados, paginación, fichas, contactos, ficha de empresa |
| `normalizacion.py` | Fechas, unidades, teléfonos, CP como texto, provincia por código INE |
| `duplicados.py` | Identificación red + número y marcado de posibles duplicados sin fusionarlos |
| `compatibilidad.py` | 8 criterios con reglas fijas y dos puntuaciones internas documentadas |
| `excel.py` | 5 hojas, protección frente a fórmulas, enlaces sin datos de sesión |
| `progreso.py` | Guardado tras cada ficha, para reanudar |
| `orquestador.py` | Flujo completo con límites |
| `gui.py` | Ventana en español con botón DETENER |
| `inspeccion.py` | Mapa de la interfaz real |

### Qué verifican las pruebas automáticas (16 pruebas, todas en verde)

Se ejecutan con `python -m pytest tests`. Usan una **maqueta local**: un servidor que imita la estructura observada (marcos `central?URL=`, formularios en tablas, enlace de fecha, ficha con etiquetas), con **datos ficticios que sólo existen en las pruebas**. La maqueta incluye trampas: botón «Ofertar», enlaces tel:, mailto: y de chat, «Marcar de interés» y un comentario que da órdenes al bot.

Comprobado con un Chromium real conectado por CDP, igual que se conectará a tu Edge:
- El bot se engancha a un navegador **ya abierto** y usa su pestaña. No cierra el navegador.
- Los filtros llegan de verdad a la petición de búsqueda: fechas, provincia, tipo de bolsa, redes y ADR.
- Si la opción pedida no existe en el desplegable, **no busca** y enumera las opciones reales.
- Ninguna petición a alta, interés, chat, tel: ni mailto:. El comentario con órdenes se conserva como texto y no se ejecuta.
- Se excluye la oferta caducada. El «0 EUR» se conserva con su advertencia. Se conserva el aviso «No contestamos correos».
- El «Camión 3,5 T. MMA» con 1 Tn de capacidad queda descartado frente a una carga de 24.000 kg.
- Se marca como posible duplicado la misma oferta publicada en dos redes, sin fusionarlas.
- DETENER conserva lo leído. Reanudar continúa sin duplicar ofertas.
- Si la empresa de la sesión no es la esperada, el bot se detiene antes de buscar.
- Las fichas se abren tanto por URL (en una pestaña auxiliar) como por enlace JavaScript (clic y «Volver a la lista»).
- El Excel tiene 5 hojas, filtros, cabecera inmovilizada, CP y teléfonos como texto, fechas reales y enlaces.

**No verificado:** que los selectores y etiquetas encajen con el HTML real de Wtransnet. Es lo primero que muestra «Inspeccionar interfaz».

## Pendiente (tienes que hacerlo tú en tu PC)

1. Abrir Edge con `INICIAR_EDGE_WTRANSNET.bat`, iniciar sesión y pulsar **Inspeccionar interfaz**. Revisa en `mapa_interfaz_*.md` que todos los filtros salen «localizado». Si alguno sale «NO LOCALIZADO» o «ambiguo», envíame ese archivo y lo ajusto: el archivo sólo contiene la estructura de la web, sin credenciales.
2. Darme **criterios reales** para la prueba: origen (país y provincia), fechas y tipo de bolsa como mínimo. No elijo rutas por ti.
3. **Prueba** con 1 carga y 1 camión, y comparar el Excel con las fichas.
4. Revisar las **condiciones de uso de Wtransnet** sobre automatización antes de usarlo de forma repetida o desatendida.

## Riesgos y dudas abiertas sobre la interfaz real

- **Marcos, JavaScript o recargas del formulario:** si al elegir país se recarga el formulario, el bot vuelve a leerlo. Aun así, hay que confirmarlo con la inspección.
- **Botón «Anotar» con varios orígenes:** no sé si Wtransnet exige pulsarlo también con el último origen. El bot lo pulsa tras cada origen cuando hay más de uno. Hay que confirmarlo en la prueba.
- **Número de oferta y red:** si la ficha no los muestra con una etiqueta reconocible, el identificador se toma del enlace y queda un aviso.
- **Sinónimos de etiquetas:** los de las fichas son una lista razonable, pero no verificada. Lo que no se reconoce no se pierde: va a «Otros datos de la ficha».
- **Controles ocultos o deshabilitados:** el bot los ignora, así que no puede usarlos para saltarse restricciones.

## Propuestas (no implementadas)

- **API oficial:** si Wtransnet ofrece API o exportación, sería más estable y seguro que automatizar el navegador. Pregúntalo a tu gestor de cuenta.
- **Distancias:** para calcular kilómetros en vacío haría falta una fuente identificada (por ejemplo un servicio de rutas con licencia) y coordenadas. No se han calculado.
- **Provincias colindantes:** una tabla oficial permitiría valorar mejor «Provincia y colindantes». Hoy ese caso queda como pendiente.
