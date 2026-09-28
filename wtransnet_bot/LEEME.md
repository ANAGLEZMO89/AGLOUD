# Bot Wtransnet: cargas y camiones a Excel (sólo lectura)

Este bot busca cargas en Wtransnet con tus criterios, abre sus fichas, busca camiones que encajen con cada carga, las relaciona con reglas explicadas y genera un Excel. Las llamadas, la negociación y la contratación son siempre tuyas.

## 1. Qué hace y qué no hace

**Hace:** buscar, navegar, leer los datos que tu cuenta ve y guardar archivos en tu equipo (Excel, progreso y registro).

**No hace nunca** (está bloqueado en tres capas: qué botones puede pulsar, qué textos tiene prohibidos y qué direcciones no puede abrir):
- Publicar, ofertar, aceptar ni contratar.
- Llamar, enviar emails, WhatsApp, SMS, mensajes o chat. Tampoco pulsa enlaces `tel:` o `mailto:`: los teléfonos y emails se leen del texto de la página.
- Marcar ofertas de interés, archivar, añadir contactos, pedir documentación ni contratar garantías de cobro.
- Cambiar preferencias o configuración, ni leer contraseñas o cookies.
- Cerrar tu Edge. Sólo cierra las pestañas auxiliares que abre él mismo.

Si aparece un CAPTCHA, una pantalla de inicio de sesión o la sesión caduca, el bot **se detiene**, guarda lo que lleva leído y te pide que intervengas.

Los textos de las ofertas se tratan como datos. Si un comentario dice «pulsa X», el bot no lo obedece.

## 2. Instalación (una sola vez)

1. Instala **Python 3.10 o posterior** desde python.org y marca «Add Python to PATH».
2. Copia la carpeta `wtransnet_bot` a tu PC.
3. Haz doble clic en `INICIAR_BOT.bat`. La primera vez crea el entorno e instala las librerías: Playwright, openpyxl y PyYAML. No descarga ningún navegador, porque usa tu Edge.

## 3. Conexión con Edge (importante)

Un programa sólo puede controlar un Edge que se haya abierto con el **puerto de depuración** activado. Tu Edge normal (perfil TRABAJO) no lo tiene activado, y en las versiones recientes de Edge y Chrome ese puerto no se admite con el perfil habitual del navegador. Por eso se usa esta vía:

1. Doble clic en **`INICIAR_EDGE_WTRANSNET.bat`**. Se abre una ventana de Edge con un **perfil propio del bot**. Tu perfil TRABAJO no se modifica.
2. En esa ventana **inicias sesión tú** en Wtransnet. La sesión se conserva en ese perfil para la próxima vez.
3. Deja esa pestaña abierta y abre `INICIAR_BOT.bat`.

Mientras esa ventana está abierta, otros programas de tu PC podrían controlarla por el puerto 9222 (sólo desde tu propio equipo). Ciérrala cuando acabes.

**Alternativa:** en la ventana del bot elige «Conexión: lanzar». Playwright abre Edge con la carpeta `perfil_edge_bot` y tú inicias sesión en ella.

## 4. Uso: los tres botones en orden

| Paso | Botón | Qué hace |
|---|---|---|
| 1 | **Inspeccionar interfaz** | Abre los formularios y listados, no pulsa «Buscar» y guarda `mapa_interfaz_*.md` con los campos reales, todas las opciones de los desplegables y las etiquetas de una ficha. Comprueba ahí que todo sale «localizado». |
| 2 | **Prueba (1 carga + 1 camión)** | Hace una búsqueda real con tus criterios, pero sólo con 1 carga y 1 camión. Abre el Excel y compara cada dato con la ficha en Wtransnet. |
| 3 | **Ejecutar** | Hasta 10 cargas y 10 camiones por carga (o el límite que pongas). |

- **DETENER**: para en el siguiente paso seguro y genera el Excel con lo que ya se ha leído.
- **Reanudar…**: elige el `_progreso_*.json` de la carpeta de resultados. Sigue donde se quedó y no vuelve a leer lo ya leído.
- **Exportar progreso a Excel…**: rehace el Excel a partir de un progreso guardado.

### Criterios

Puedes rellenarlos en la ventana o en `criterios.yaml`, que tiene comentarios. Están separados en tres grupos:
- **Filtros de Wtransnet**: se escriben en el formulario y el bot comprueba después que el formulario los conserva.
- **Comprobaciones del bot**: peso y dimensiones, vigencia. Wtransnet no ofrece esos filtros, así que se comprueban al abrir cada ficha.
- **Camiones**: el origen y el destino salen de cada carga; el resto se hereda de tus filtros o lo fijas tú.

Los textos de los desplegables (vehículo, especialidad, ámbito…) deben escribirse **tal cual aparecen** en Wtransnet. Si no coinciden, el bot se para y te enumera las opciones reales. El bot no supone equivalencias entre carrocerías; si decides que dos son compatibles, anótalo en `equivalencias_carroceria`.

Sin **origen**, **fecha inicial** y **tipo de bolsa**, el bot no hace una búsqueda real. Tampoco elige rutas por ti.

## 5. El Excel

Nombre: `Wtransnet_cargas_camiones_AAAAMMDD_HHMM.xlsx`. Hojas:
- **Resumen**: criterios, filtros realmente aplicados, recuentos, cuenta verificada, advertencias y la fórmula de la puntuación.
- **Cargas** y **Camiones**: una fila por oferta, sin duplicados. El texto original se conserva al lado del valor normalizado. Los CP y teléfonos van como texto.
- **Matching**: una fila por pareja carga–camión, con todo lo necesario para llamar (empresas, contactos, teléfonos, emails, avisos del tipo «no contestan correos»), el estado de cada criterio y enlaces a las dos fichas.
- **Incidencias**: cargas excluidas y su motivo, candidatos descartados, posibles duplicados entre redes (se marcan pero no se fusionan), fichas no leídas y los motivos por los que hay menos de 10 resultados.

### Cómo se decide la compatibilidad (reglas fijas, sin IA)

Hay 8 criterios. Cada uno queda como *Compatible según datos publicados*, *Incompatible* o *Pendiente de confirmar*. **Un dato que falta nunca cuenta como favorable.**

| Criterio | Compatible si… | Incompatible si… |
|---|---|---|
| Fecha / llegada | las fechas se solapan **y** el camión está en la provincia de carga | el camión sólo está disponible después de la fecha de carga |
| Origen | misma provincia (código INE o CP) | — (una provincia distinta queda pendiente; no se calculan km) |
| Destino | misma provincia, o el camión acepta todo el país | los países son distintos |
| Vehículo | misma especialidad literal o una equivalencia que hayas declarado tú | incompatibilidad que hayas declarado tú |
| Capacidad | la capacidad indicada (no la MMA) es mayor o igual en peso, volumen y medidas | la capacidad indicada es menor |
| ADR / equipamiento | lo que exige la carga, el camión lo indica | la carga lo exige y el camión dice que no |
| Forma de carga | hay una forma en común | no hay ninguna en común |
| Comentarios | no hay comentarios publicados | — (si hay comentarios, queda pendiente de leerlos) |

- **Estado general:** si hay alguna incompatibilidad, el candidato queda **descartado** (va a Incidencias). Si los 8 criterios son compatibles, **Compatible**. En otro caso, **Pendiente**.
- **Cobertura (0-100):** es una puntuación interna. Pesos: fecha 20, origen 20, destino 15, vehículo 15, capacidad 15, ADR 5, forma 5, comentarios 5.
- **Información (0-100):** porcentaje de 7 datos clave que publica el camión.

Ninguna de las dos puntuaciones es una probabilidad de contratación.

## 6. Si caduca la sesión

1. El bot se detiene y genera el Excel parcial. Lo leído queda en `resultados/_progreso_*.json`.
2. Vuelve a iniciar sesión tú en la ventana de Edge del bot.
3. En la ventana del bot pulsa **Reanudar…** y elige ese `_progreso_*.json`.

## 7. Uso responsable

- El ritmo es de 3 a 6 segundos entre páginas, con un máximo de 3 páginas de listado y 20 fichas de camión por carga. No hay concurrencia.
- **Antes de usarlo de forma repetida o desatendida, revisa las condiciones de uso de Wtransnet** o pregunta a tu gestor de cuenta si permiten la automatización y si tienen una API. Eso **no** se ha podido comprobar (ver ESTADO.md).
