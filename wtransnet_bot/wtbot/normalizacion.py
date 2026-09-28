"""Normalización y validación de datos leídos de Wtransnet.

Regla general: se conserva SIEMPRE el texto original y sólo se añade un valor
normalizado cuando la conversión es inequívoca. Si no lo es, el valor
normalizado queda en None y se registra el motivo. Nunca se completa un dato
que no aparezca en la página.
"""
from __future__ import annotations

import datetime as dt
import re
import unicodedata

# ---------------------------------------------------------------- texto


def sin_acentos(texto: str) -> str:
    texto = unicodedata.normalize("NFKD", texto or "")
    return "".join(c for c in texto if not unicodedata.combining(c))


def clave(texto: str) -> str:
    """Forma comparable de una etiqueta: minúsculas, sin acentos ni signos."""
    t = sin_acentos(texto).lower()
    t = t.replace("º", " ").replace("ª", " ")
    t = re.sub(r"[^a-z0-9]+", " ", t)
    return re.sub(r"\s+", " ", t).strip()


def limpiar(texto) -> str:
    if texto is None:
        return ""
    t = str(texto).replace(" ", " ")
    t = re.sub(r"[ \t]+", " ", t)
    t = re.sub(r"\s*\n\s*", "\n", t)
    return t.strip()


# ---------------------------------------------------------------- fechas

_FECHA_RE = re.compile(
    r"\b(\d{1,2})[/.\-](\d{1,2})[/.\-](\d{2,4})\b(?:\s*(?:a las\s*)?(\d{1,2})[:.h](\d{2}))?"
)
_HORA_RE = re.compile(r"\b(\d{1,2})[:.h](\d{2})\b")


def _anio(a: str) -> int:
    n = int(a)
    return 2000 + n if n < 100 else n


def parse_fechas(texto: str) -> list[dt.datetime]:
    """Todas las fechas dd/mm/aa(aa) [hh:mm] presentes en el texto."""
    salida = []
    for d, m, a, hh, mm in _FECHA_RE.findall(texto or ""):
        try:
            f = dt.datetime(_anio(a), int(m), int(d), int(hh or 0), int(mm or 0))
        except ValueError:
            continue
        salida.append(f)
    return salida


def parse_fecha(texto: str) -> dt.datetime | None:
    f = parse_fechas(texto)
    return f[0] if f else None


def tiene_hora(texto: str) -> bool:
    m = _FECHA_RE.search(texto or "")
    return bool(m and m.group(4))


def parse_hora(texto: str) -> str | None:
    m = _HORA_RE.search(texto or "")
    return f"{int(m.group(1)):02d}:{m.group(2)}" if m else None


def fecha_formulario(texto: str) -> str:
    """Convierte una fecha introducida por el usuario al formato dd/mm/aa del formulario."""
    t = limpiar(texto)
    if not t:
        return ""
    f = parse_fecha(t)
    if not f:
        raise ValueError(f"Fecha no válida: {texto!r}. Usa dd/mm/aa o dd/mm/aaaa.")
    return f.strftime("%d/%m/%y")


# ---------------------------------------------------------------- números

_NUM_RE = re.compile(r"(-?\d[\d.,]*)")


def parse_numero(texto: str) -> float | None:
    """Número en formato español o internacional. None si no hay número."""
    m = _NUM_RE.search(texto or "")
    if not m:
        return None
    s = m.group(1).rstrip(".,")
    if "." in s and "," in s:
        if s.rfind(",") > s.rfind("."):
            s = s.replace(".", "").replace(",", ".")
        else:
            s = s.replace(",", "")
    elif "," in s:
        s = s.replace(",", ".") if re.fullmatch(r"-?\d+,\d+", s) else s.replace(",", "")
    elif "." in s:
        if re.fullmatch(r"-?\d{1,3}(\.\d{3})+", s):
            s = s.replace(".", "")
    try:
        return float(s)
    except ValueError:
        return None


_UNIDADES_PESO = {
    "kg": 1.0, "kgs": 1.0, "kilos": 1.0, "kilogramos": 1.0, "k": 1.0,
    "t": 1000.0, "tn": 1000.0, "tns": 1000.0, "tm": 1000.0, "ton": 1000.0,
    "tons": 1000.0, "toneladas": 1000.0, "tonelada": 1000.0,
}
_UNIDADES_LONG = {"m": 1.0, "mts": 1.0, "mt": 1.0, "metros": 1.0, "metro": 1.0, "cm": 0.01, "mm": 0.001}
_UNIDADES_VOL = {"m3": 1.0, "m³": 1.0, "mc": 1.0, "metros cubicos": 1.0, "l": 0.001, "litros": 0.001}


def _unidad_tras_numero(texto: str, tabla: dict) -> tuple[float | None, str | None]:
    t = sin_acentos(texto or "").lower()
    m = re.search(r"(-?\d[\d.,]*)\s*([a-z³]+(?:\s+cubicos)?)?", t)
    if not m:
        return None, None
    uni = (m.group(2) or "").strip().rstrip(".")
    if uni in tabla:
        return tabla[uni], uni
    return None, uni or None


def parse_cantidad(texto: str, tipo: str, etiqueta: str = "") -> dict:
    """tipo: 'peso' (kg), 'longitud' (m) o 'volumen' (m3).

    Devuelve {'original', 'valor', 'unidad', 'nota'}. 'valor' queda en None si
    la unidad no es inequívoca (no se supone kg ni toneladas).
    """
    tabla = {"peso": _UNIDADES_PESO, "longitud": _UNIDADES_LONG, "volumen": _UNIDADES_VOL}[tipo]
    base = {"peso": "kg", "longitud": "m", "volumen": "m3"}[tipo]
    original = limpiar(texto)
    res = {"original": original, "valor": None, "unidad": base, "nota": ""}
    if not original:
        res["nota"] = "dato ausente"
        return res
    num = parse_numero(original)
    if num is None:
        res["nota"] = "sin valor numérico"
        return res
    factor, uni = _unidad_tras_numero(original, tabla)
    if factor is None:
        # la unidad puede venir en la etiqueta: "Peso (Kg)"
        m = re.search(r"\(([^)]+)\)", sin_acentos(etiqueta).lower())
        if m and m.group(1).strip() in tabla:
            factor = tabla[m.group(1).strip()]
    if factor is None and tipo == "volumen" and re.search(r"volumen", clave(etiqueta)):
        pass
    if factor is None:
        res["nota"] = "unidad no indicada: no se convierte"
        return res
    res["valor"] = round(num * factor, 3)
    if res["valor"] == 0:
        res["nota"] = "valor cero publicado"
    return res


def parse_precio(texto: str) -> dict:
    original = limpiar(texto)
    res = {"original": original, "valor": None, "moneda": "", "nota": ""}
    if not original:
        res["nota"] = "dato ausente"
        return res
    m = re.search(r"(EUR|€|USD|\$|GBP|£|PLN|RON|CHF)", original, re.I)
    if m:
        res["moneda"] = {"€": "EUR", "$": "USD", "£": "GBP"}.get(m.group(1), m.group(1).upper())
    res["valor"] = parse_numero(original)
    if res["valor"] == 0:
        res["nota"] = "Precio publicado como 0: su significado comercial no está confirmado"
    elif res["valor"] is None:
        res["nota"] = "precio no numérico (se conserva el texto)"
    return res


# ---------------------------------------------------------------- sí / no

def parse_si_no(texto: str) -> bool | None:
    k = clave(texto)
    if not k:
        return None
    if k in {"si", "s", "yes", "y", "x", "true", "1"} or k.startswith("si ") or k.startswith("solo "):
        return True
    if k in {"no", "n", "false", "0"} or k.startswith("no "):
        return False
    return None


# ---------------------------------------------------------------- contactos

EMAIL_RE = re.compile(r"[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}")
TELEFONO_RE = re.compile(r"(?<![\w/])(?:\+|00)?\(?\d[\d\s.\-()]{6,}\d(?![\w/])")


def normalizar_telefono(texto: str) -> str:
    """Conserva el prefijo internacional sólo si aparece; nunca lo añade."""
    t = limpiar(texto)
    t = re.sub(r"^tel:", "", t, flags=re.I)
    mas = t.startswith("+") or t.startswith("00")
    digitos = re.sub(r"\D", "", t)
    if t.startswith("00"):
        digitos = digitos[2:]
    return ("+" if mas else "") + digitos


def extraer_telefonos(texto: str) -> list[str]:
    salida = []
    for m in TELEFONO_RE.findall(texto or ""):
        if parse_fecha(m):  # evita fechas
            continue
        n = normalizar_telefono(m)
        if 9 <= len(n.lstrip("+")) <= 15 and n not in salida:
            salida.append(n)
    return salida


def extraer_emails(texto: str) -> list[str]:
    salida = []
    for e in EMAIL_RE.findall(texto or ""):
        e = e.strip(".").lower()
        if e not in salida:
            salida.append(e)
    return salida


_AVISO_CONTACTO_RE = re.compile(
    r"[^.\n]*\b(no\s+(?:se\s+)?(?:contest|respond|atend|leemos|mir)\w*|solo\s+(?:por\s+)?(?:tel|llamad|whats|mail|correo)\w*"
    r"|s[oó]lo\s+(?:por\s+)?(?:tel|llamad|whats|mail|correo)\w*|no\s+(?:enviar|mandar)\s+\w*|llamar\s+(?:a|al|s[oó]lo)\b"
    r"|no\s+(?:llamar|emails?|correos?|mails?)\b)[^.\n]*",
    re.I,
)


def avisos_contacto(texto: str) -> list[str]:
    """Frases literales de los comentarios que condicionan cómo contactar."""
    salida = []
    for m in _AVISO_CONTACTO_RE.finditer(texto or ""):
        frase = limpiar(m.group(0))
        if frase and frase not in salida:
            salida.append(frase)
    return salida


# ---------------------------------------------------------------- ubicación

# Códigos INE de provincia (= dos primeras cifras del código postal español).
PROVINCIAS_ES = {
    "01": ["alava", "araba", "araba alava", "alava araba"], "02": ["albacete"],
    "03": ["alicante", "alacant", "alicante alacant"], "04": ["almeria"], "05": ["avila"],
    "06": ["badajoz"], "07": ["baleares", "illes balears", "islas baleares", "balears"],
    "08": ["barcelona"], "09": ["burgos"], "10": ["caceres"], "11": ["cadiz"],
    "12": ["castellon", "castello", "castellon castello"], "13": ["ciudad real"],
    "14": ["cordoba"], "15": ["a coruna", "la coruna", "coruna"], "16": ["cuenca"],
    "17": ["girona", "gerona"], "18": ["granada"], "19": ["guadalajara"],
    "20": ["gipuzkoa", "guipuzcoa"], "21": ["huelva"], "22": ["huesca"], "23": ["jaen"],
    "24": ["leon"], "25": ["lleida", "lerida"], "26": ["la rioja", "rioja"], "27": ["lugo"],
    "28": ["madrid"], "29": ["malaga"], "30": ["murcia"], "31": ["navarra", "nafarroa"],
    "32": ["ourense", "orense"], "33": ["asturias"], "34": ["palencia"],
    "35": ["las palmas", "palmas"], "36": ["pontevedra"], "37": ["salamanca"],
    "38": ["santa cruz de tenerife", "s c tenerife", "tenerife"], "39": ["cantabria"],
    "40": ["segovia"], "41": ["sevilla"], "42": ["soria"], "43": ["tarragona"],
    "44": ["teruel"], "45": ["toledo"], "46": ["valencia"], "47": ["valladolid"],
    "48": ["bizkaia", "vizcaya"], "49": ["zamora"], "50": ["zaragoza"], "51": ["ceuta"],
    "52": ["melilla"],
}
_PROV_POR_NOMBRE = {n: c for c, ns in PROVINCIAS_ES.items() for n in ns}

PAISES = {
    "ES": ["espana", "spain", "es", "e"], "PT": ["portugal", "pt", "p"],
    "FR": ["francia", "france", "fr", "f"], "DE": ["alemania", "germany", "deutschland", "de", "d"],
    "IT": ["italia", "italy", "it", "i"], "BE": ["belgica", "belgium", "be", "b"],
    "NL": ["holanda", "paises bajos", "netherlands", "nl"], "GB": ["reino unido", "united kingdom", "gb", "uk"],
    "PL": ["polonia", "poland", "pl"], "MA": ["marruecos", "morocco", "ma"],
    "AD": ["andorra", "ad"], "CH": ["suiza", "switzerland", "ch"], "AT": ["austria", "at", "a"],
    "RO": ["rumania", "rumanía", "romania", "ro"], "CZ": ["republica checa", "chequia", "cz"],
}
_PAIS_POR_NOMBRE = {n: c for c, ns in PAISES.items() for n in ns}


def codigo_pais(texto: str) -> str | None:
    return _PAIS_POR_NOMBRE.get(clave(texto))


def codigo_provincia(texto: str) -> str | None:
    """Código INE por nombre. Admite nombres bilingües «Alicante/Alacant» si ambas
    partes corresponden a la misma provincia."""
    k = clave(texto)
    if k in _PROV_POR_NOMBRE:
        return _PROV_POR_NOMBRE[k]
    if re.fullmatch(r"\d{2}", k) and k in PROVINCIAS_ES:
        return k
    partes = [clave(p) for p in re.split(r"[/]", texto or "") if clave(p)]
    if len(partes) > 1:
        cods = {_PROV_POR_NOMBRE.get(p) for p in partes}
        if len(cods) == 1 and None not in cods:
            return cods.pop()
    return None


def parse_ubicacion(texto: str = "", pais: str = "", provincia: str = "", cp: str = "",
                    localidad: str = "") -> dict:
    """Ubicación con el texto original y los componentes que constan.

    Componentes rotulados (pais/provincia/cp/localidad) tienen prioridad. Del
    texto libre sólo se deducen: código postal (5 cifras) y país/provincia si el
    texto coincide exactamente con un nombre conocido o con un prefijo ISO
    ('ES-28', '(ES)'). La provincia española se deduce del CP porque sus dos
    primeras cifras son el código INE de provincia.
    """
    original = limpiar(texto) or " / ".join(x for x in (pais, provincia, cp, localidad) if x)
    u = {
        "texto": original, "pais": limpiar(pais), "pais_iso": None,
        "provincia": limpiar(provincia), "provincia_cod": None,
        "cp": limpiar(cp), "localidad": limpiar(localidad), "origen_datos": [],
    }
    if u["pais"]:
        u["pais_iso"] = codigo_pais(u["pais"]) or (u["pais"].upper() if re.fullmatch(r"[A-Za-z]{2}", u["pais"]) else None)
    if not u["cp"]:
        m = re.search(r"\b(\d{5})\b", original)
        if m:
            u["cp"] = m.group(1)
            u["origen_datos"].append("CP leído del texto")
    if not u["pais_iso"]:
        m = re.match(r"^\(?([A-Z]{2})\)?(?:[\s\-:]|$)", original)
        if m and m.group(1) in PAISES:
            u["pais_iso"] = m.group(1)
            u["origen_datos"].append("país por prefijo ISO")
        else:
            for trozo in re.split(r"[/,;()\-]", original):
                c = codigo_pais(trozo)
                if c and len(clave(trozo)) > 2:
                    u["pais_iso"] = c
                    u["origen_datos"].append("país leído del texto")
                    break
    if u["provincia"]:
        u["provincia_cod"] = codigo_provincia(u["provincia"])
    if not u["provincia_cod"] and u["pais_iso"] in (None, "ES"):
        for trozo in re.split(r"[/,;()\-]", original):
            c = codigo_provincia(re.sub(r"\d+", " ", trozo))
            if c:
                u["provincia_cod"] = c
                u["origen_datos"].append("provincia leída del texto")
                break
    if not u["provincia_cod"] and u["cp"] and re.fullmatch(r"\d{5}", u["cp"]) and u["pais_iso"] == "ES":
        if u["cp"][:2] in PROVINCIAS_ES:
            u["provincia_cod"] = u["cp"][:2]
            u["origen_datos"].append("provincia por CP (código INE)")
    return u


def nombre_provincia(cod: str | None) -> str:
    return PROVINCIAS_ES.get(cod or "", [""])[0].title() if cod else ""
