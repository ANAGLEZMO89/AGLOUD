"""Barreras de seguridad: el bot sólo busca, navega y lee.

Tres capas independientes:
1. Lista blanca de clics: sólo se pulsa un control cuyo texto coincide con
   una acción de lectura permitida (Buscar, Anotar, Volver a la lista,
   paginación, enlace de ficha o de empresa).
2. Lista negra de textos/destinos: aunque un texto pase la capa 1, se bloquea
   si el control o su destino contiene términos de acciones comerciales
   (ofertar, aceptar, contratar, enviar, chat, interés, archivar...), o enlaces
   tel:, mailto:, whatsapp, sms.
3. Bloqueo de red en las páginas que maneja el bot: se abortan peticiones cuyo
   destino contenga esos términos. Los diálogos de confirmación se cancelan.

Los textos de las ofertas son datos de terceros: nunca se interpretan como
órdenes. Nada de lo que se lee en una ficha modifica el comportamiento del bot.
"""
from __future__ import annotations

import logging
import re
from urllib.parse import unquote

from .normalizacion import clave

log = logging.getLogger("wtbot")

ACCIONES_PERMITIDAS = {
    "buscar": [r"^buscar$", r"^buscar ofertas$", r"^iniciar busqueda$"],
    "anotar": [r"^anotar$"],
    "borrar_anotacion": [r"^borrar anotacion$"],
    "volver": [r"^volver a la lista$", r"^volver al listado$", r"^volver$"],
    "paginar": [r"^siguiente$", r"^pagina siguiente$", r"^sig\.?$", r"^>$", r"^>>$", r"^»$", r"^\d{1,3}$"],
    # enlace de fecha: «30/09/26», «30/09/26 10:00» (tras clave(): «30 09 26», «30 09 26 10 00»)
    "ficha": [r"^\d{1,2}[ /.\-]\d{1,2}([ /.\-]\d{2,4})?([ ]\d{1,2}[ :.]\d{2})?$"],
    "empresa": [r".+"],  # sólo se usa sobre el enlace de empresa de una ficha; la capa 2 sigue activa
}

TERMINOS_PROHIBIDOS = [
    "ofertar", "publicar", "aceptar", "contratar", "reservar", "asignar", "adjudicar",
    "enviar", "mensaje", "chat", "conversacion", "llamar", "whatsapp", "sms",
    "interes", "archivar", "anadir contacto", "agregar contacto", "favorito",
    "solicitar", "documentacion", "garantia", "cobro", "seguro", "pagar",
    "preferencias", "configuracion", "guardar", "grabar", "eliminar", "borrar oferta",
    "baja", "alta", "modificar", "editar", "valorar", "denunciar",
]

PROTOCOLOS_PROHIBIDOS = ("tel:", "mailto:", "sms:", "whatsapp:", "callto:", "skype:", "sip:")

# Patrones de URL que nunca deben solicitarse desde las páginas del bot.
RED_PROHIBIDA = re.compile(
    r"(accion=(alta|grabar|guardar|borrar|eliminar|enviar|contratar|aceptar|interes|archivar|"
    r"ofertar|publicar|modificar|baja|reservar)\b|mensaj|/chat|whatsapp|wa\.me|mailto:|tel:|"
    r"interes\b|archivar|garantia|documentacion)",
    re.I,
)


class AccionBloqueada(RuntimeError):
    pass


def texto_control(info: dict) -> str:
    return clave(" ".join(str(info.get(k) or "") for k in ("texto", "value", "title", "alt", "aria")))


def comprobar_clic(info: dict, accion: str) -> None:
    """Lanza AccionBloqueada si el control no es una acción de lectura permitida.

    info: {'texto','value','title','alt','aria','href','onclick','tag','type'}
    """
    if accion not in ACCIONES_PERMITIDAS:
        raise AccionBloqueada(f"Acción no permitida: {accion}")
    texto = texto_control(info)
    href = unquote(str(info.get("href") or "")).lower().strip()
    onclick = str(info.get("onclick") or "").lower()
    if href.startswith(PROTOCOLOS_PROHIBIDOS) or any(p in onclick for p in PROTOCOLOS_PROHIBIDOS):
        raise AccionBloqueada(f"Enlace de contacto bloqueado ({href[:20]}...)")
    destino = clave(href + " " + onclick)
    for t in TERMINOS_PROHIBIDOS:
        if re.search(rf"\b{t}\b", texto) or (accion != "empresa" and re.search(rf"\b{t}\b", destino)):
            raise AccionBloqueada(f"Control bloqueado por contener «{t}»: {texto[:60]!r}")
    if RED_PROHIBIDA.search(unquote(str(info.get("href") or "")) + " " + onclick):
        raise AccionBloqueada(f"Destino bloqueado: {href[:80]!r}")
    if str(info.get("type") or "").lower() == "submit" and accion not in ("buscar", "anotar", "borrar_anotacion"):
        raise AccionBloqueada("Botón de envío no permitido para esta acción")
    crudo = " ".join(str(info.get(k) or "") for k in ("texto", "value")).strip().lower()
    if not any(re.search(p, texto) or re.search(p, crudo) for p in ACCIONES_PERMITIDAS[accion]):
        raise AccionBloqueada(f"El texto del control {texto[:60]!r} no corresponde a la acción «{accion}»")


def url_permitida(url: str) -> bool:
    u = unquote(url or "")
    if u.lower().startswith(PROTOCOLOS_PROHIBIDOS):
        return False
    return not RED_PROHIBIDA.search(u)


def instalar_barreras(page, registro) -> None:
    """Instala el bloqueo de red y la cancelación de diálogos en una pestaña del bot.

    Sólo afecta a esa pestaña (page.route), no al resto del navegador.
    """
    def _ruta(route, request):
        if url_permitida(request.url):
            return route.continue_()
        registro(f"BLOQUEADA petición {request.method} a destino no permitido: {request.url[:120]}")
        return route.abort()

    def _dialogo(dialog):
        registro(f"Diálogo del navegador cancelado automáticamente: {dialog.message[:150]!r}")
        try:
            dialog.dismiss()
        except Exception:  # noqa: BLE001
            pass

    page.route("**/*", _ruta)
    page.on("dialog", _dialogo)


def quitar_barreras(page) -> None:
    try:
        page.unroute("**/*")
    except Exception:  # noqa: BLE001
        pass
