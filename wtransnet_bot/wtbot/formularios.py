"""Lectura de formularios reales y aplicación verificada de filtros.

No hay selectores inventados: en cada ejecución se leen los controles que hay en
la página (nombre, id, tipo, opciones y el texto visible que los rodea) y cada
filtro se localiza por su etiqueta visible. Si un filtro solicitado no se
encuentra, o una opción no existe literalmente en el desplegable, la búsqueda
se detiene con un mensaje que enumera lo que sí existe. Tras rellenar, se
releen los valores para comprobar que se aplicaron.

El archivo mapa_campos.yaml (opcional) permite fijar un selector concreto para
un filtro si la detección por etiqueta fuera ambigua.
"""
from __future__ import annotations

import logging
import re

from .navegador import esperar_carga
from .normalizacion import clave, codigo_pais, codigo_provincia, fecha_formulario

log = logging.getLogger("wtbot")

JS_CONTROLES = r"""() => {
  const norm = s => (s || '').replace(/\s+/g, ' ').trim();
  const vis = el => !!(el.offsetWidth || el.offsetHeight || el.getClientRects().length);
  const esCtrl = el => ['INPUT','SELECT','TEXTAREA','BUTTON'].includes(el.tagName);
  const txtNodo = n => n.nodeType === 3 ? n.textContent : (n.nodeType === 1 && !esCtrl(n) && !n.querySelector('input,select,textarea,button') ? (n.innerText || n.textContent || '') : null);
  const vecino = (el, dir) => {
    let s = '', n = dir > 0 ? el.nextSibling : el.previousSibling;
    while (n && s.length < 80) {
      const t = txtNodo(n);
      if (t === null) break;
      s = dir > 0 ? s + ' ' + t : t + ' ' + s;
      n = dir > 0 ? n.nextSibling : n.previousSibling;
    }
    return norm(s);
  };
  const esCabecera = el => {
    const t = norm(el.innerText || '');
    if (!t || t.length > 60 || el.querySelector('input,select,textarea,button')) return false;
    if (/^(H[1-6]|LEGEND|TH|CAPTION)$/.test(el.tagName)) return true;
    const c = (el.className || '').toString().toLowerCase();
    if (/(tit|cab|head|seccion|section)/.test(c) && /^(TD|DIV|SPAN|P|B|STRONG)$/.test(el.tagName)) return true;
    if (/^(B|STRONG)$/.test(el.tagName)) return true;
    if (el.tagName === 'TD' && (el.colSpan > 1) ) return true;
    return false;
  };
  const todos = [...document.querySelectorAll('input,select,textarea,button,a')];
  let seccion = '';
  const secc = new Map();
  for (const el of document.body ? document.body.querySelectorAll('*') : []) {
    if (esCabecera(el)) seccion = norm(el.innerText);
    if (esCtrl(el) || el.tagName === 'A') secc.set(el, seccion);
  }
  const unico = (attr, v) => v && document.querySelectorAll(`[${attr}="${CSS.escape(v)}"]`).length === 1;
  return todos.map((el, i) => {
    const tipo = (el.getAttribute('type') || (el.tagName === 'SELECT' ? 'select' : el.tagName === 'TEXTAREA' ? 'textarea' : el.tagName === 'A' ? 'enlace' : 'text')).toLowerCase();
    if (tipo === 'password') return null;  // nunca se leen campos de contraseña
    if (el.tagName === 'A') {
      const t = norm(el.innerText);
      if (!/^(buscar|anotar|borrar anotaci[oó]n)$/i.test(t)) return null;
    }
    let etiqueta = '';
    if (el.id) { const l = document.querySelector(`label[for="${CSS.escape(el.id)}"]`); if (l) etiqueta = norm(l.innerText); }
    if (!etiqueta && el.closest('label')) etiqueta = norm(el.closest('label').innerText);
    const td = el.closest('td,th');
    let celdaAnt = '';
    if (td) { let p = td.previousElementSibling; while (p && !norm(p.innerText)) p = p.previousElementSibling; if (p) celdaAnt = norm(p.innerText).slice(0, 80); }
    const tr = el.closest('tr');
    const fila = tr && tr.cells && tr.cells.length ? norm(tr.cells[0].innerText).slice(0, 80) : '';
    let selector = null;
    if (unico('id', el.id)) selector = `[id="${el.id}"]`;
    else if (el.name && ['radio','checkbox'].includes(tipo)) selector = `[name="${el.name}"][value="${el.value}"]`;
    else if (unico('name', el.name)) selector = `[name="${el.name}"]`;
    const o = {
      indice: i, tag: el.tagName, tipo, name: el.getAttribute('name') || '', id: el.id || '',
      selector, etiqueta, antes: vecino(el, -1), despues: vecino(el, 1), celda_anterior: celdaAnt,
      fila, seccion: secc.get(el) || '', visible: vis(el), deshabilitado: !!el.disabled,
      form: el.form ? (el.form.getAttribute('name') || el.form.id || 'form') : '',
      texto: el.tagName === 'A' || el.tagName === 'BUTTON' ? norm(el.innerText) : '',
    };
    if (tipo === 'checkbox' || tipo === 'radio') { o.value = el.value; o.checked = el.checked; }
    else if (['button','submit','image','reset'].includes(tipo)) { o.value = el.value || ''; }
    else if (el.tagName === 'SELECT') {
      o.value = el.value;
      o.opciones = [...el.options].map(op => ({value: op.value, texto: norm(op.text), seleccionada: op.selected}));
    } else if (tipo !== 'hidden') { o.value = el.value; }
    else { o.value = ''; }
    return o;
  }).filter(Boolean);
}"""


class FiltroNoAplicable(RuntimeError):
    pass


# Sinónimos de las etiquetas observadas en la interfaz (pantallas del 25/09/2026).
ETIQUETAS = {
    "fecha_inicial": ["fecha inicial", "desde", "fecha desde", "fecha inicio", "disponibilidad desde", "del"],
    "fecha_final": ["fecha final", "hasta", "fecha hasta", "fecha fin", "al"],
    "pais": ["pais"], "provincia": ["provincia", "prov"], "codigo_postal": ["codigo postal", "c postal", "cp", "c p"],
    "localidad": ["localidad", "poblacion", "ciudad"], "ambito": ["ambito"],
    "tipo_vehiculo": ["tipo de vehiculo", "tipo vehiculo", "vehiculo"],
    "especialidad": ["especialidad", "carroceria", "especialidad carroceria"],
    "misma_especialidad": ["misma especialidad"],
    "ida_y_vuelta": ["viajes de ida y vuelta", "ida y vuelta", "ida vuelta"],
    "adr": ["adr"], "doble_conductor": ["doble conductor"],
    "plataforma_elevadora": ["plataforma elevadora", "plataforma"],
    "cargas_urgentes": ["cargas urgentes", "urgentes", "urgente"],
    "sirve_frigorifico": ["sirve frigorifico"], "sirve_lateral_bajo": ["sirve lateral bajo"],
    "forma_carga": ["forma de carga", "forma carga", "carga por"],
    "redes": ["redes", "red"], "tipo_bolsa": ["tipo de bolsa", "bolsa"],
}
OPCIONES_GRUPO = {
    "tipo_bolsa": ["trailers completos", "grupajes", "rigidos completos"],
    "redes": ["wtransnet", "teleroute", "123cargo", "bursa", "123cargo bursa"],
    "forma_carga": ["arriba", "lateral", "detras"],
}


def leer_controles(frame) -> list[dict]:
    return frame.evaluate(JS_CONTROLES)


def frame_formulario(page):
    """Marco que contiene el formulario de búsqueda (el que tiene botón Buscar y más controles)."""
    mejor, puntos = None, -1
    for f in page.frames:
        try:
            ctr = leer_controles(f)
        except Exception:  # noqa: BLE001
            continue
        vis = [c for c in ctr if c["visible"]]
        tiene_buscar = any(clave(c.get("texto") or c.get("value") or "") == "buscar" for c in vis)
        p = len(vis) + (1000 if tiene_buscar else 0)
        if p > puntos:
            mejor, puntos = f, p
    if mejor is None or puntos < 1000:
        raise FiltroNoAplicable("No se encuentra un formulario con botón «Buscar» en la página.")
    return mejor


def _contexto(c) -> str:
    return clave(" ".join([c.get("etiqueta", ""), c.get("antes", ""), c.get("celda_anterior", ""),
                           c.get("fila", "")]))


def _cerca(c) -> str:
    """Texto inmediato del control (etiqueta propia o celda anterior)."""
    return clave(c.get("etiqueta") or c.get("antes") or c.get("celda_anterior") or c.get("fila") or "")


def _coincide(texto: str, sinonimos: list[str]) -> bool:
    t = clave(texto).rstrip(" ")
    return any(t == s or re.search(rf"(^| ){re.escape(s)}( |$)", t) for s in sinonimos)


def _bloque(c) -> str:
    """'origen' / 'destino' / '' según el contexto del control."""
    txt = clave(" ".join([c.get("seccion", ""), c.get("fila", ""), c.get("celda_anterior", ""),
                          c.get("etiqueta", ""), c.get("antes", "")]))
    ident = clave(c.get("name", "") + " " + c.get("id", ""))
    o = bool(re.search(r"\borig", txt)) or bool(re.search(r"ori", ident))
    d = bool(re.search(r"\bdest", txt)) or bool(re.search(r"des", ident))
    if o and not d:
        return "origen"
    if d and not o:
        return "destino"
    return ""


def localizar(controles, campo, bloque="", tipos=None, mapa=None) -> dict:
    """Control único para un filtro. Error si no existe o es ambiguo."""
    if mapa and (campo if not bloque else f"{bloque}.{campo}") in mapa:
        sel = mapa[campo if not bloque else f"{bloque}.{campo}"]
        for c in controles:
            if c.get("selector") == sel or c.get("name") == sel or c.get("id") == sel:
                return c
        raise FiltroNoAplicable(f"El selector fijado en mapa_campos.yaml para {campo} ({sel}) no está en la página.")
    sin = ETIQUETAS[campo]
    cands = [c for c in controles if c["visible"] and not c["deshabilitado"]
             and (tipos is None or c["tipo"] in tipos) and _coincide(_cerca(c), sin)]
    if not cands:
        cands = [c for c in controles if c["visible"] and not c["deshabilitado"]
                 and (tipos is None or c["tipo"] in tipos) and _coincide(_contexto(c), sin)]
    if bloque:
        cands = [c for c in cands if _bloque(c) == bloque] or (
            [c for c in cands if _bloque(c) == ""] if len([c for c in cands if _bloque(c) == ""]) == 2 else [])
        if len(cands) == 2 and bloque in ("origen", "destino") and all(_bloque(c) == "" for c in cands):
            cands = [cands[0] if bloque == "origen" else cands[1]]  # orden visual: origen antes que destino
    if not cands:
        raise FiltroNoAplicable(f"No encuentro el campo «{campo}»{' de ' + bloque if bloque else ''} en el formulario.")
    nombres = {(c["name"], c["id"]) for c in cands}
    if len(nombres) > 1:
        desc = "; ".join(f"{c['name'] or c['id']} ({_cerca(c)})" for c in cands[:5])
        raise FiltroNoAplicable(f"Campo «{campo}» ambiguo: {desc}. Fíjalo en mapa_campos.yaml.")
    return cands[0]


def grupo_opciones(controles, campo, bloque="") -> list[dict]:
    """Casillas/radios de un grupo (p. ej. ADR: Sí/No/Indiferente)."""
    sin = ETIQUETAS[campo]
    grupo = [c for c in controles if c["tipo"] in ("radio", "checkbox") and c["visible"]
             and _coincide(clave(" ".join([c["seccion"], c["fila"], c["celda_anterior"]])), sin)
             and (not bloque or _bloque(c) == bloque)]
    if not grupo and campo in OPCIONES_GRUPO:
        grupo = [c for c in controles if c["tipo"] in ("radio", "checkbox") and c["visible"]
                 and _coincide(_texto_opcion(c), OPCIONES_GRUPO[campo])]
    return grupo


def _texto_opcion(c) -> str:
    return clave(c.get("etiqueta") or c.get("despues") or c.get("antes") or "")


def _opcion_select(c, texto_deseado, cod=None):
    """Opción cuyo texto coincide literalmente (sin mayúsculas/acentos).

    cod=(funcion, codigo): alternativa determinista para país (ISO) y provincia
    (código INE), p. ej. «Alicante/Alacant» ↔ provincia 03. No hay otras equivalencias.
    """
    k = clave(texto_deseado)
    exactas = [o for o in c.get("opciones", []) if clave(o["texto"]) == k]
    if len(exactas) == 1:
        return exactas[0]
    if cod and cod[1]:
        por_cod = [o for o in c.get("opciones", []) if o["texto"] and cod[0](o["texto"]) == cod[1]]
        if len(por_cod) == 1:
            return por_cod[0]
    disponibles = " | ".join(o["texto"] for o in c.get("opciones", []) if o["texto"])
    raise FiltroNoAplicable(
        f"«{texto_deseado}» no coincide literalmente con ninguna opción del desplegable "
        f"{c['name'] or c['id']}. Opciones reales: {disponibles[:900]}")


class Rellenador:
    """Aplica filtros sobre el formulario real y verifica cada valor."""

    def __init__(self, nav, frame, mapa=None):
        self.nav, self.frame, self.mapa = nav, frame, mapa or {}
        self.aplicados: list[str] = []

    def _loc(self, c):
        if c.get("selector"):
            return self.frame.locator(c["selector"]).first
        return self.frame.locator("input,select,textarea,button,a").nth(c["indice"])

    def _refrescar(self):
        esperar_carga(self.nav.page)
        # algunos desplegables recargan el formulario: localizar de nuevo el marco
        if self.frame.is_detached():
            self.frame = frame_formulario(self.nav.page)
        return leer_controles(self.frame)

    def select(self, campo, texto, bloque="", cod=None):
        if not texto:
            return
        ctr = self._refrescar()
        c = localizar(ctr, campo, bloque, tipos={"select"}, mapa=self.mapa)
        op = _opcion_select(c, texto, cod)
        self._loc(c).select_option(value=op["value"])
        ctr = self._refrescar()
        c2 = next((x for x in ctr if x["name"] == c["name"] and x["id"] == c["id"] and x["tipo"] == "select"), None)
        if not c2 or c2["value"] != op["value"]:
            raise FiltroNoAplicable(f"El desplegable {campo} no conservó «{texto}».")
        self.aplicados.append(f"{bloque + ' ' if bloque else ''}{campo} = {op['texto']}")

    def texto(self, campo, valor, bloque=""):
        if not valor:
            return
        ctr = self._refrescar()
        c = localizar(ctr, campo, bloque, tipos={"text", "search", "tel", "number", "date", ""}, mapa=self.mapa)
        loc = self._loc(c)
        loc.fill("")
        loc.fill(valor)
        loc.evaluate("el => el.dispatchEvent(new Event('change', {bubbles: true}))")
        leido = loc.input_value()
        if leido.strip() != valor.strip():
            raise FiltroNoAplicable(f"El campo {campo} muestra «{leido}» en lugar de «{valor}».")
        self.aplicados.append(f"{bloque + ' ' if bloque else ''}{campo} = {valor}")

    def opcion(self, campo, texto, bloque=""):
        """Radio o desplegable de tipo Sí/No/Indiferente."""
        if texto in (None, ""):
            return
        ctr = self._refrescar()
        grupo = [c for c in grupo_opciones(ctr, campo, bloque) if c["tipo"] == "radio"]
        if grupo:
            k = clave(texto)
            elegido = [c for c in grupo if _texto_opcion(c) == k or _texto_opcion(c).startswith(k)]
            if len(elegido) != 1:
                reales = ", ".join(sorted({_texto_opcion(c) for c in grupo}))
                raise FiltroNoAplicable(f"Opción «{texto}» no encontrada para {campo}. Opciones: {reales}")
            self._loc(elegido[0]).check()
            ok = self._loc(elegido[0]).is_checked()
            if not ok:
                raise FiltroNoAplicable(f"No se pudo marcar {campo} = {texto}")
            self.aplicados.append(f"{campo} = {texto}")
            return
        try:
            self.select(campo, texto, bloque)
        except FiltroNoAplicable:
            # Casilla única del tipo "Sólo cargas ADR" / "Plataforma elevadora"
            casillas = [c for c in ctr if c["tipo"] == "checkbox" and c["visible"]
                        and _coincide(_texto_opcion(c) + " " + _cerca(c), ETIQUETAS[campo])]
            if len(casillas) != 1:
                raise
            marcar = clave(texto) in ("si", "solo", "s") or clave(texto).startswith("solo")
            if clave(texto) == "indiferente":
                marcar = False
            self._loc(casillas[0]).set_checked(marcar)
            self.aplicados.append(f"{campo} = {'marcado' if marcar else 'sin marcar'}")

    def casillas(self, campo, opciones: list[str]):
        """Marca exactamente las opciones pedidas de un grupo (tipo de bolsa, redes, forma de carga)."""
        if not opciones:
            return
        ctr = self._refrescar()
        grupo = [c for c in grupo_opciones(ctr, campo) if c["tipo"] == "checkbox"]
        if not grupo:
            raise FiltroNoAplicable(f"No encuentro las casillas de «{campo}».")
        pedidas = {clave(o) for o in opciones}
        encontradas = set()
        for c in grupo:
            t = _texto_opcion(c)
            marcar = any(t == p or t.startswith(p) for p in pedidas)
            if marcar:
                encontradas.update(p for p in pedidas if t == p or t.startswith(p))
            self._loc(c).set_checked(marcar)
        faltan = pedidas - encontradas
        if faltan:
            reales = ", ".join(_texto_opcion(c) for c in grupo)
            raise FiltroNoAplicable(f"Opciones no encontradas en «{campo}»: {', '.join(faltan)}. Existentes: {reales}")
        self.aplicados.append(f"{campo} = {', '.join(opciones)}")

    def casilla(self, campo, valor: bool | None):
        if valor is None:
            return
        ctr = self._refrescar()
        c = [x for x in ctr if x["tipo"] == "checkbox" and x["visible"]
             and _coincide(_texto_opcion(x) + " " + _cerca(x), ETIQUETAS[campo])]
        if len(c) != 1:
            raise FiltroNoAplicable(f"No encuentro una casilla única para «{campo}».")
        self._loc(c[0]).set_checked(bool(valor))
        self.aplicados.append(f"{campo} = {'sí' if valor else 'no'}")

    def boton(self, texto, accion, bloque=""):
        ctr = self._refrescar()
        k = clave(texto)
        b = [c for c in ctr if c["visible"] and clave(c.get("texto") or c.get("value") or "") == k
             and (c["tipo"] in ("button", "submit", "image", "enlace") or c["tag"] == "BUTTON")]
        if len(b) > 1 and bloque:
            b = [c for c in b if _bloque(c) == bloque] or b
        if len(b) > 1:
            forms = [c["form"] for c in ctr if c["tipo"] == "select" and c["visible"]]
            principal = max(set(forms), key=forms.count) if forms else ""
            b = [c for c in b if c["form"] == principal] or b
        if not b:
            raise FiltroNoAplicable(f"No encuentro el botón «{texto}».")
        if len(b) > 1:
            raise FiltroNoAplicable(f"Hay {len(b)} botones «{texto}» y no sé cuál corresponde a {bloque or 'la búsqueda'}.")
        self.nav.clic(self.frame, self._loc(b[0]), accion)

    def ubicacion(self, u: dict, bloque: str):
        pais = u.get("pais", "") or u.get("pais_iso", "")
        self.select("pais", pais, bloque, (codigo_pais, u.get("pais_iso") or codigo_pais(pais)))
        prov = u.get("provincia", "") or u.get("provincia_cod", "")
        self.select("provincia", prov, bloque, (codigo_provincia, u.get("provincia_cod") or codigo_provincia(prov)))
        self.texto("codigo_postal", u.get("codigo_postal", ""), bloque)
        self.texto("localidad", u.get("localidad", ""), bloque)


def aplicar_filtros(nav, frame, filtros: dict, tipo: str, mapa=None) -> list[str]:
    """Rellena el formulario de búsqueda (tipo 'carga' o 'camion') y devuelve lo aplicado."""
    r = Rellenador(nav, frame, mapa)
    r.texto("fecha_inicial", fecha_formulario(filtros.get("fecha_inicial", "")))
    r.texto("fecha_final", fecha_formulario(filtros.get("fecha_final", "")))
    r.casillas("tipo_bolsa", filtros.get("tipo_bolsa") or [])
    if tipo == "carga":
        r.casillas("redes", filtros.get("redes") or [])
    r.opcion("ida_y_vuelta", filtros.get("ida_y_vuelta"))
    r.select("tipo_vehiculo", filtros.get("tipo_vehiculo", ""))
    r.select("especialidad", filtros.get("especialidad", ""))
    r.casilla("misma_especialidad", filtros.get("misma_especialidad"))
    if tipo == "camion":
        r.opcion("sirve_frigorifico", filtros.get("sirve_frigorifico"))
        r.opcion("sirve_lateral_bajo", filtros.get("sirve_lateral_bajo"))
    r.casillas("forma_carga", filtros.get("forma_carga") or [])
    r.opcion("adr", filtros.get("adr"))
    r.opcion("doble_conductor", filtros.get("doble_conductor"))
    r.opcion("plataforma_elevadora", filtros.get("plataforma_elevadora"))
    if tipo == "carga" and filtros.get("cargas_urgentes"):
        r.casilla("cargas_urgentes", True)
    for bloque, clave_lista, clave_amb in (("origen", "origenes", "ambito_origen"), ("destino", "destinos", "ambito_destino")):
        lista = filtros.get(clave_lista) or []
        for i, u in enumerate(lista):
            r.ubicacion(u, bloque)
            if len(lista) > 1:
                r.boton("Anotar", "anotar", bloque)  # anota este origen/destino antes de añadir el siguiente
        if filtros.get(clave_amb):
            r.select("ambito", filtros[clave_amb], bloque)
    return r.aplicados


TIPOS_TEXTO = {"text", "search", "tel", "number", "date", ""}
CLASE_CAMPO = {
    "fecha_inicial": "texto", "fecha_final": "texto", "codigo_postal": "texto", "localidad": "texto",
    "tipo_vehiculo": "select", "especialidad": "select", "pais": "select", "provincia": "select", "ambito": "select",
    "misma_especialidad": "casilla", "cargas_urgentes": "casilla",
    "tipo_bolsa": "grupo", "redes": "grupo", "forma_carga": "grupo",
    "ida_y_vuelta": "opcion", "adr": "opcion", "doble_conductor": "opcion", "plataforma_elevadora": "opcion",
    "sirve_frigorifico": "opcion", "sirve_lateral_bajo": "opcion",
}


def diagnosticar(controles, item: str, mapa=None) -> dict:
    """Cómo resolvería el bot un filtro en este formulario (misma lógica que Rellenador, sin actuar)."""
    bloque, campo = item.split(".") if "." in item else ("", item)
    clase = CLASE_CAMPO[campo]
    try:
        if clase in ("texto", "select"):
            c = localizar(controles, campo, bloque, tipos=TIPOS_TEXTO if clase == "texto" else {"select"}, mapa=mapa)
            return {"estado": "localizado", "control": c["selector"] or f"#{c['indice']}", "tipo": c["tipo"],
                    "texto_cercano": _cerca(c), "opciones": [o["texto"] for o in c.get("opciones", [])]}
        if clase == "grupo":
            g = [c for c in grupo_opciones(controles, campo) if c["tipo"] == "checkbox"]
            if not g:
                raise FiltroNoAplicable(f"No encuentro las casillas de «{campo}».")
            return {"estado": "localizado", "tipo": "casillas", "opciones": [_texto_opcion(c) for c in g]}
        if clase == "casilla":
            c = [x for x in controles if x["tipo"] == "checkbox" and x["visible"]
                 and _coincide(_texto_opcion(x) + " " + _cerca(x), ETIQUETAS[campo])]
            if len(c) != 1:
                raise FiltroNoAplicable(f"No encuentro una casilla única para «{campo}».")
            return {"estado": "localizado", "tipo": "casilla", "control": c[0]["selector"], "texto_cercano": _texto_opcion(c[0])}
        g = [c for c in grupo_opciones(controles, campo, bloque) if c["tipo"] == "radio"]
        if g:
            return {"estado": "localizado", "tipo": "radio", "opciones": [_texto_opcion(c) for c in g]}
        try:
            c = localizar(controles, campo, bloque, tipos={"select"}, mapa=mapa)
            return {"estado": "localizado", "tipo": "select", "control": c["selector"],
                    "opciones": [o["texto"] for o in c.get("opciones", [])]}
        except FiltroNoAplicable:
            c = [x for x in controles if x["tipo"] == "checkbox" and x["visible"]
                 and _coincide(_texto_opcion(x) + " " + _cerca(x), ETIQUETAS[campo])]
            if len(c) == 1:
                return {"estado": "localizado", "tipo": "casilla única", "control": c[0]["selector"],
                        "texto_cercano": _texto_opcion(c[0])}
            raise
    except FiltroNoAplicable as e:
        return {"estado": "NO LOCALIZADO", "detalle": str(e)}
