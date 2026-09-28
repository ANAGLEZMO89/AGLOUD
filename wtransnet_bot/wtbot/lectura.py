"""Lectura de listados de resultados y de fichas de oferta / empresa.

La lectura es genérica y basada en lo que se ve: filas de tabla con un enlace
de fecha (así se abre la ficha según la inspección del 25/09/2026) y pares
«etiqueta: valor» en las fichas. Todo el texto visible de cada ficha se guarda
en el progreso local para poder verificar después cada dato exportado.

Los enlaces tel:/mailto: se LEEN como atributo; nunca se pulsan.
"""
from __future__ import annotations

import datetime as dt
import logging
import re
from urllib.parse import parse_qsl, urljoin, urlsplit

from . import normalizacion as N
from .navegador import esperar_carga, url_limpia, verificar_sesion

log = logging.getLogger("wtbot")

JS_LISTADO = r"""() => {
  const norm = s => (s || '').replace(/\s+/g, ' ').trim();
  const reFecha = /^\d{1,2}[\/.\-]\d{1,2}([\/.\-]\d{2,4})?(\s+\d{1,2}[:.]\d{2})?$/;
  const anchors = [...document.querySelectorAll('a')];
  const filas = [];
  const vistas = new Set();
  for (const a of anchors) {
    const t = norm(a.innerText);
    if (!reFecha.test(t)) continue;
    const tr = a.closest('tr');
    if (!tr || vistas.has(tr)) continue;
    vistas.add(tr);
    const tabla = tr.closest('table');
    let cab = [];
    if (tabla) {
      const th = tabla.querySelector('thead tr') || [...tabla.querySelectorAll('tr')].find(r => r.querySelector('th'));
      if (th) cab = [...th.cells].map(c => norm(c.innerText));
    }
    const celdas = [...tr.cells].map(c => norm(c.innerText));
    const iconos = [...tr.querySelectorAll('img,[title]')].map(i => norm(i.getAttribute('alt') || i.getAttribute('title') || '')).filter(Boolean);
    const otros = [...tr.querySelectorAll('a')].filter(x => x !== a).map(x => ({texto: norm(x.innerText), href: x.getAttribute('href') || '', onclick: x.getAttribute('onclick') || ''}));
    filas.push({indice_enlace: anchors.indexOf(a), texto_enlace: t, href: a.href && !a.getAttribute('href').startsWith('javascript') ? a.href : '',
                href_attr: a.getAttribute('href') || '', onclick: a.getAttribute('onclick') || '', target: a.getAttribute('target') || '',
                celdas, cabecera: cab, iconos, otros_enlaces: otros, texto_fila: norm(tr.innerText).slice(0, 600)});
  }
  const pag = anchors.map((a, i) => ({i, t: norm(a.innerText), title: a.getAttribute('title') || '', href: a.getAttribute('href') || ''}))
                     .filter(x => /^(siguiente|p[aá]gina siguiente|sig\.?|>|>>|»)$/i.test(x.t) || /siguiente/i.test(x.title));
  const total = (document.body ? document.body.innerText : '').match(/(\d[\d.]*)\s+(ofertas|resultados|registros)/i);
  return {filas, siguiente: pag.length ? pag[0] : null, total_texto: total ? total[0] : '', url: location.href,
          sin_resultados: /no se (han )?encontr|sin resultados|no hay ofertas|0 ofertas/i.test(document.body ? document.body.innerText : '')};
}"""

JS_FICHA = r"""() => {
  const norm = s => (s || '').replace(/ /g, ' ').replace(/[ \t]+/g, ' ').replace(/\s*\n\s*/g, '\n').trim();
  const kv = [];
  let seccion = '';
  const enl = el => [...el.querySelectorAll('a')].map(a => ({texto: norm(a.innerText), href: a.getAttribute('href') || '', onclick: a.getAttribute('onclick') || ''}));
  const esEtiq = t => t && t.length <= 45 && /:\s*$/.test(t);
  for (const tr of document.querySelectorAll('tr')) {
    if (tr.querySelector('tr')) continue;  // sólo filas hoja
    const cs = [...tr.cells].filter(c => norm(c.innerText) || c.querySelector('img,a'));
    if (!cs.length) continue;
    if (cs.length === 1) {
      const t = norm(cs[0].innerText);
      const m = t.match(/^([^:\n]{1,45}):\s*([\s\S]+)$/);
      if (m) kv.push({seccion, etiqueta: m[1], valor: m[2], enlaces: enl(cs[0])});
      else if (t.length <= 60 && !t.includes('\n')) seccion = t;
      else if (t) kv.push({seccion, etiqueta: '', valor: t, enlaces: enl(cs[0])});
      continue;
    }
    if (cs.every(c => c.tagName === 'TH')) { seccion = norm(cs.map(c => c.innerText).join(' ')).slice(0, 60); continue; }
    for (let i = 0; i < cs.length; i++) {
      const t = norm(cs[i].innerText);
      const sig = cs[i + 1];
      if ((esEtiq(t) || cs[i].tagName === 'TH') && sig) {
        kv.push({seccion, etiqueta: t.replace(/:\s*$/, ''), valor: norm(sig.innerText), enlaces: enl(sig),
                 imagenes: [...sig.querySelectorAll('img')].map(im => norm(im.getAttribute('alt') || im.getAttribute('title') || ''))});
        i++;
      } else {
        const m = t.match(/^([^:\n]{1,45}):\s*([\s\S]+)$/);
        if (m) kv.push({seccion, etiqueta: m[1], valor: m[2], enlaces: enl(cs[i])});
      }
    }
  }
  for (const dt of document.querySelectorAll('dt')) {
    const dd = dt.nextElementSibling;
    if (dd && dd.tagName === 'DD') kv.push({seccion, etiqueta: norm(dt.innerText).replace(/:\s*$/, ''), valor: norm(dd.innerText), enlaces: enl(dd)});
  }
  const contacto = [...document.querySelectorAll('a[href^="tel:"], a[href^="mailto:"], a[href^="callto:"]')]
                   .map(a => ({tipo: a.getAttribute('href').split(':')[0].toLowerCase(), valor: decodeURIComponent(a.getAttribute('href').split(':').slice(1).join(':')).split('?')[0], texto: norm(a.innerText)}));
  const enlaces = [...document.querySelectorAll('a')].map((a, i) => ({i, texto: norm(a.innerText), href: a.getAttribute('href') || '', abs: a.href || '', onclick: a.getAttribute('onclick') || ''}));
  return {kv, texto: norm(document.body ? document.body.innerText : ''), contacto, enlaces, url: location.href, titulo: document.title};
}"""

# ------------------------------------------------------------ campos canónicos
# (sección opcional, etiquetas). Se compara con normalizacion.clave().
CAMPOS = {
    "numero_oferta": ["n oferta", "no oferta", "num oferta", "numero oferta", "numero de oferta", "n de oferta",
                      "oferta n", "oferta", "referencia", "ref", "id oferta", "codigo oferta", "cod oferta"],
    "red": ["red", "bolsa de origen", "procedencia", "origen de la oferta"],
    "fecha_modificacion": ["modificada", "modificado", "fecha modificacion", "ultima modificacion", "actualizada",
                           "fecha actualizacion", "fecha de modificacion"],
    "fecha_publicacion": ["publicada", "fecha publicacion", "fecha de publicacion", "alta", "fecha alta"],
    "disponibilidad": ["disponibilidad", "fecha disponibilidad", "fecha de disponibilidad", "fecha de carga",
                       "fecha carga", "disponible", "fechas", "fecha"],
    "hora_limite": ["hora limite", "hora limite de carga", "hora"],
    "fecha_descarga": ["fecha descarga", "fecha de descarga", "descarga", "fecha entrega", "fecha de entrega"],
    "tipo_bolsa": ["tipo de bolsa", "bolsa", "tipo bolsa"],
    "vehiculo": ["vehiculo", "tipo de vehiculo", "tipo vehiculo"],
    "especialidad": ["especialidad", "carroceria", "especialidad carroceria"],
    "peso": ["peso", "peso kg", "peso kgs", "peso tn", "peso t", "toneladas", "kilos", "capacidad", "carga util",
             "peso maximo", "peso total"],
    "volumen": ["volumen", "volumen m3", "m3", "metros cubicos"],
    "largo": ["largo", "longitud", "largo m", "longitud m"],
    "ancho": ["ancho", "anchura", "ancho m"],
    "alto": ["alto", "altura", "alto m"],
    "metros_lineales": ["metros lineales", "ml", "m lineales"],
    "forma_carga": ["forma de carga", "forma carga", "carga por", "tipo de carga lateral"],
    "mercancia": ["mercancia", "tipo de mercancia", "tipo mercancia", "caracteristicas", "caracteristicas de la mercancia",
                  "producto", "descripcion", "descripcion mercancia"],
    "adr": ["adr", "mercancia peligrosa", "mercancias peligrosas"],
    "plataforma_elevadora": ["plataforma elevadora", "plataforma"],
    "doble_conductor": ["doble conductor"],
    "equipamiento": ["equipamiento", "otros equipamientos", "equipamientos", "extras", "otros"],
    "observaciones": ["observaciones", "comentarios", "notas", "comentario", "otras observaciones"],
    "num_viajes": ["viajes", "n viajes", "numero de viajes", "num viajes", "no viajes"],
    "ida_y_vuelta": ["ida y vuelta", "viaje de ida y vuelta", "ida vuelta"],
    "distancia": ["distancia", "km", "kms", "kilometros", "distancia aproximada"],
    "precio": ["precio", "importe", "tarifa", "flete", "precio ofertado"],
    "moneda": ["moneda", "divisa"],
    "plazo_pago": ["plazo pago", "plazo de pago", "dias pago", "dias de pago"],
    "forma_pago": ["forma de pago", "forma pago", "medio de pago"],
    "comentarios_pago": ["comentarios pago", "comentarios de pago", "observaciones pago", "condiciones de pago"],
    "empresa_codigo": ["codigo empresa", "cod empresa", "codigo de empresa", "n empresa", "id empresa", "codigo cliente",
                       "cod cliente", "codigo"],
    "empresa_nombre": ["empresa", "razon social", "nombre empresa", "nombre de la empresa", "anunciante", "ofertante"],
    "contacto": ["contacto", "persona de contacto", "persona contacto", "atiende", "responsable", "nombre contacto"],
    "telefono": ["telefono", "telefonos", "tel", "tfno", "telf", "tlf", "telefono fijo"],
    "movil": ["movil", "moviles", "telefono movil", "celular", "mov"],
    "email": ["email", "e mail", "correo", "correo electronico", "mail", "e mail contacto"],
    "fax": ["fax"],
    "ambito": ["ambito", "ambito geografico", "zona"],
    "actividad": ["actividad", "actividades", "actividad empresarial"],
    "cif": ["cif", "nif", "vat", "identificacion fiscal"],
    "direccion": ["direccion", "domicilio"],
}
CAMPOS_UBICACION = {"pais": ["pais"], "provincia": ["provincia", "prov"],
                    "cp": ["codigo postal", "c postal", "cp", "c p"], "localidad": ["localidad", "poblacion", "ciudad"]}


def _canon(etiqueta: str) -> str | None:
    k = N.clave(etiqueta)
    if any(k in s for s in CAMPOS_UBICACION.values()):
        return None  # país / provincia / CP / localidad: dependen de la sección (origen, destino, empresa)
    for campo, sin in CAMPOS.items():
        if k in sin:
            return campo
    for campo, sin in CAMPOS.items():
        if any(len(s) > 3 and k.startswith(s + " ") for s in sin):
            return campo
    return None


def _bloque_seccion(seccion: str, etiqueta: str) -> str:
    t = N.clave(seccion + " " + etiqueta)
    if re.search(r"\borig|\bcarga en\b|\brecogida", t):
        return "origen"
    if re.search(r"\bdest|\bdescarga en\b|\bentrega en\b", t):
        return "destino"
    if re.search(r"\bempresa|\banunciante|\bcontacto", t):
        return "empresa"
    return ""


# ------------------------------------------------------------ listados

def leer_listado(page) -> dict:
    """Resultados visibles en el marco con más filas de oferta."""
    mejor = None
    for f in page.frames:
        try:
            r = f.evaluate(JS_LISTADO)
        except Exception:  # noqa: BLE001
            continue
        r["_frame"] = f
        if mejor is None or len(r["filas"]) > len(mejor["filas"]):
            mejor = r
    return mejor or {"filas": [], "siguiente": None, "sin_resultados": True, "_frame": page.main_frame}


def resumen_fila(fila: dict) -> dict:
    """Datos del listado por nombre de columna (si hay cabecera)."""
    d = {}
    cab = fila.get("cabecera") or []
    if len(cab) == len(fila["celdas"]):
        for c, v in zip(cab, fila["celdas"]):
            if c:
                d[c] = v
    else:
        d = {f"col{i + 1}": v for i, v in enumerate(fila["celdas"])}
    return d


def red_de_fila(fila: dict) -> str:
    texto = N.clave(" ".join(fila.get("iconos", [])) + " " + fila.get("texto_fila", ""))
    for red, pat in (("Teleroute", r"teleroute"), ("123Cargo/Bursa", r"123cargo|bursa"), ("Wtransnet", r"wtransnet")):
        if re.search(pat, texto):
            return red
    return ""


# ------------------------------------------------------------ fichas

def abrir_ficha(nav, fila: dict, frame_listado):
    """Abre la ficha. Con URL real: pestaña auxiliar del bot (misma sesión),
    devuelve (pestaña, True). Con enlace javascript: clic en el propio marco y
    devuelve (pestaña de trabajo, marco pulsado) para volver después."""
    if fila.get("href"):
        p = nav.nueva_pestana_bot()
        nav.ir(fila["href"], page=p)
        return p, True
    loc = frame_listado.locator("a").nth(fila["indice_enlace"])
    nav.clic(frame_listado, loc, "ficha")
    return nav.page, frame_listado


def volver_listado(nav, frame_ficha=None, espera_s=15):
    """Vuelve al listado tras una ficha abierta por clic en el propio marco.

    1) enlace «Volver a la lista» de la ficha; 2) si no existe, historial del
    marco que cambió (no de toda la pestaña). Se da por bueno sólo si vuelven a
    verse ofertas. Nunca se retrocede dos veces.
    """
    import time

    def listado_visible():
        fin = time.time() + espera_s
        while time.time() < fin:
            nav.comprobar_parada()
            try:
                if leer_listado(nav.page)["filas"]:
                    return True
            except Exception:  # noqa: BLE001
                pass
            time.sleep(0.3)
        return False

    for f in nav.page.frames:
        loc = f.get_by_text(re.compile(r"^\s*Volver a la lista\s*$", re.I))
        try:
            hay = loc.count()
        except Exception:  # noqa: BLE001
            continue
        if hay:
            nav.clic(f, loc.first, "volver")
            if listado_visible():
                return
            raise RuntimeError("Tras «Volver a la lista» no aparece el listado de resultados.")
    objetivo = frame_ficha if frame_ficha is not None and not frame_ficha.is_detached() else nav.page.main_frame
    try:
        objetivo.evaluate("() => history.back()")
    except Exception:  # noqa: BLE001
        pass
    if listado_visible():
        verificar_sesion(nav.page)
        return
    raise RuntimeError("No se pudo volver al listado de resultados.")


class ListadoPerdido(RuntimeError):
    """La ficha se leyó, pero no se pudo regresar al listado."""

    def __init__(self, registro):
        super().__init__("No se pudo volver al listado de resultados tras leer la ficha")
        self.registro = registro


def leer_pagina_ficha(page) -> dict:
    mejor = None
    for f in page.frames:
        try:
            r = f.evaluate(JS_FICHA)
        except Exception:  # noqa: BLE001
            continue
        if mejor is None or len(r["kv"]) > len(mejor["kv"]):
            mejor = r
    if not mejor:
        raise RuntimeError("La ficha no tiene contenido legible.")
    return mejor


def _id_de_url(url: str) -> str:
    q = dict(parse_qsl(urlsplit(url).query))
    inner = q.get("URL", "")
    if inner:
        q.update(dict(parse_qsl(urlsplit(inner).query)))
    for k in ("idOferta", "idoferta", "id_oferta", "oferta", "numOferta", "numoferta", "nof", "codigo", "cod", "id"):
        if q.get(k):
            return q[k]
    return ""


def interpretar_ficha(bruto: dict, tipo: str, fila: dict | None = None, leido_en: dt.datetime | None = None) -> dict:
    """Convierte la lectura bruta en un registro con campos canónicos.

    Cada valor procede literalmente de la ficha (o del listado, marcado como tal).
    Lo que no se reconoce se conserva en 'otros_datos'.
    """
    leido_en = leido_en or dt.datetime.now()
    r = {"tipo": tipo, "leido_en": leido_en, "url": url_limpia(bruto.get("url", "")), "campos": {},
         "origen": {}, "destino": {}, "otros_datos": [], "avisos": [], "texto_ficha": bruto.get("texto", "")}
    ub = {"origen": {}, "destino": {}}
    for par in bruto.get("kv", []):
        etq, val, sec = par.get("etiqueta", ""), N.limpiar(par.get("valor", "")), par.get("seccion", "")
        if not val and par.get("imagenes"):
            val = ", ".join(i for i in par["imagenes"] if i)
        if not etq:
            continue
        bloque = _bloque_seccion(sec, etq)
        k = N.clave(etq)
        sub = next((c for c, s in CAMPOS_UBICACION.items() if k in s), None)
        if bloque in ("origen", "destino") and sub:
            ub[bloque].setdefault(sub, val)
            continue
        if sub:
            r["otros_datos"].append(f"{(sec + ' › ') if sec else ''}{etq}: {val}")
            continue
        if k in ("origen", "lugar de carga", "carga en", "recogida"):
            ub["origen"].setdefault("texto", val)
            continue
        if k in ("destino", "lugar de descarga", "descarga en", "entrega en"):
            ub["destino"].setdefault("texto", val)
            continue
        campo = _canon(etq)
        if campo == "ambito" and bloque in ("origen", "destino"):
            ub[bloque].setdefault("ambito", val)
            continue
        if campo:
            if campo in r["campos"] and r["campos"][campo] != val:
                # mismo campo con valores distintos: se conservan ambos literalmente
                r["campos"][campo] = r["campos"][campo] + " | " + val
            else:
                r["campos"].setdefault(campo, val)
            if campo in ("empresa_nombre",) and par.get("enlaces"):
                r["enlace_empresa"] = par["enlaces"][0]
        else:
            r["otros_datos"].append(f"{(sec + ' › ') if sec else ''}{etq}: {val}")
    # listado como respaldo (marcado)
    if fila:
        r["red"] = red_de_fila(fila)
        r["listado"] = resumen_fila(fila)
    r["red"] = r["campos"].get("red") or (fila and red_de_fila(fila)) or ""
    r["numero_oferta"] = N.limpiar(r["campos"].get("numero_oferta", "")) or _id_de_url(bruto.get("url", ""))
    if not r["numero_oferta"]:
        r["avisos"].append("Número de oferta no visible: se identifica por el enlace de la ficha")
    for b in ("origen", "destino"):
        d = ub[b]
        u = N.parse_ubicacion(d.get("texto", ""), d.get("pais", ""), d.get("provincia", ""), d.get("cp", ""), d.get("localidad", ""))
        u["ambito"] = d.get("ambito", "")
        if not u["texto"] and fila:
            # respaldo: columna Origen/Destino del listado (puede estar recortada)
            for c, v in resumen_fila(fila).items():
                if N.clave(c).startswith(b[:4]):
                    u = N.parse_ubicacion(v)
                    u["ambito"] = ""
                    u["origen_datos"].append("tomado del listado (puede estar recortado)")
                    r["avisos"].append(f"{b.capitalize()} tomado del listado: revisar ficha")
        r[b] = u
    _contactos(r, bruto)
    _cantidades(r)
    r["id"] = identificador(r)
    return r


def _contactos(r: dict, bruto: dict):
    c = r["campos"]
    tel = []
    for campo in ("telefono", "movil"):
        for t in N.extraer_telefonos(c.get(campo, "")):
            if all(t != x[1] for x in tel):
                tel.append((campo, t))
    for enl in bruto.get("contacto", []):
        if enl["tipo"] in ("tel", "callto"):
            t = N.normalizar_telefono(enl["valor"])
            if t and all(t != x[1] for x in tel):
                tel.append(("telefono", t))
    emails = N.extraer_emails(c.get("email", ""))
    for enl in bruto.get("contacto", []):
        if enl["tipo"] == "mailto":
            for e in N.extraer_emails(enl["valor"]):
                if e not in emails:
                    emails.append(e)
    r["telefonos"] = [t for k, t in tel if k == "telefono"]
    r["moviles"] = [t for k, t in tel if k == "movil"]
    r["emails"] = emails
    r["contacto"] = N.limpiar(c.get("contacto", ""))
    r["empresa_nombre"] = N.limpiar(c.get("empresa_nombre", ""))
    r["empresa_codigo"] = N.limpiar(c.get("empresa_codigo", ""))
    r["contacto_fuente"] = "oferta" if (r["telefonos"] or r["moviles"] or r["emails"]) else ""
    textos = " \n".join(c.get(k, "") for k in ("observaciones", "comentarios_pago", "equipamiento"))
    r["avisos_contacto"] = N.avisos_contacto(textos)


def _cantidades(r: dict):
    c = r["campos"]
    r["peso"] = N.parse_cantidad(c.get("peso", ""), "peso", "peso")
    r["volumen"] = N.parse_cantidad(c.get("volumen", ""), "volumen", "volumen")
    for d in ("largo", "ancho", "alto"):
        r[d] = N.parse_cantidad(c.get(d, ""), "longitud", d)
    r["precio"] = N.parse_precio(c.get("precio", ""))
    if r["precio"]["nota"].startswith("Precio publicado como 0"):
        r["avisos"].append(r["precio"]["nota"])
    fechas = N.parse_fechas(c.get("disponibilidad", ""))
    r["disp_desde"] = fechas[0] if fechas else None
    r["disp_hasta"] = fechas[1] if len(fechas) > 1 else (fechas[0] if fechas else None)
    r["fecha_modificacion"] = N.parse_fecha(c.get("fecha_modificacion", ""))
    r["fecha_descarga"] = N.parse_fecha(c.get("fecha_descarga", ""))
    r["hora_limite"] = N.parse_hora(c.get("hora_limite", "")) or ""
    r["adr"] = N.parse_si_no(c.get("adr", ""))
    r["plataforma_elevadora"] = N.parse_si_no(c.get("plataforma_elevadora", ""))
    r["doble_conductor"] = N.parse_si_no(c.get("doble_conductor", ""))
    fc = N.clave(c.get("forma_carga", ""))
    r["forma_carga"] = sorted({f for f in ("arriba", "lateral", "detras") if f in fc})


def identificador(r: dict) -> str:
    red = r.get("red") or "red no indicada"
    num = r.get("numero_oferta") or ("url:" + r.get("url", ""))
    return f"{red}#{num}"


def verificar_trazabilidad(r: dict) -> list[str]:
    """Comprueba que los datos de contacto exportados aparecen en la ficha leída."""
    texto = r.get("texto_ficha", "")
    solo_digitos = re.sub(r"\D", "", texto)
    fallos = []
    for t in r.get("telefonos", []) + r.get("moviles", []):
        if t.lstrip("+") not in solo_digitos and t.lstrip("+")[-9:] not in solo_digitos:
            fallos.append(f"Teléfono {t} no localizado literalmente en el texto de la ficha (procede de un enlace)")
    low = texto.lower()
    for e in r.get("emails", []):
        if e not in low:
            fallos.append(f"Email {e} no visible en el texto de la ficha (procede de un enlace)")
    return fallos


def enlace_empresa(bruto: dict, r: dict) -> str:
    e = r.get("enlace_empresa")
    if e and e.get("href") and not e["href"].lower().startswith("javascript"):
        return urljoin(bruto.get("url", ""), e["href"])
    return ""
