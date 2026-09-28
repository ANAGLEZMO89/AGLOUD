"""Evaluación reproducible de compatibilidad carga ↔ oferta de camión.

Cada criterio obligatorio recibe uno de tres estados:
  COMPATIBLE  = "Compatible según datos publicados"
  INCOMPATIBLE = "Incompatible"
  PENDIENTE   = "Pendiente de confirmar"
Un dato ausente NUNCA se considera favorable.

Estado general:
  - Alguna INCOMPATIBLE  -> "Descartado" (no entra en el ranking; se registra el motivo).
  - Todas COMPATIBLE     -> "Compatible según datos publicados".
  - Resto                -> "Pendiente de confirmar".

Puntuaciones internas del bot (0-100). NO son probabilidad de contratación:
  - Cobertura = suma de pesos de criterios COMPATIBLES / suma de pesos x 100.
    Pesos: fecha 20, origen 20, destino 15, vehículo 15, capacidad 15,
    ADR/equipamiento 5, forma de carga 5, comentarios 5.
  - Información = % de campos clave del camión con dato publicado
    (disponibilidad, origen, destino, vehículo, especialidad, peso, contacto).
Ranking: primero "Compatible", luego "Pendiente"; dentro, por cobertura,
información y fecha de lectura.
"""
from __future__ import annotations

from .normalizacion import clave

COMPATIBLE = "Compatible según datos publicados"
INCOMPATIBLE = "Incompatible"
PENDIENTE = "Pendiente de confirmar"

PESOS = {"fecha": 20, "origen": 20, "destino": 15, "vehiculo": 15, "capacidad": 15,
         "equipamiento": 5, "forma_carga": 5, "comentarios": 5}
NOMBRES = {"fecha": "Fecha / llegada a carga", "origen": "Cobertura origen", "destino": "Cobertura destino",
           "vehiculo": "Vehículo y carrocería", "capacidad": "Capacidad y dimensiones",
           "equipamiento": "ADR y equipamientos", "forma_carga": "Forma de carga",
           "comentarios": "Restricciones en comentarios"}


def _r(estado, motivo):
    return {"estado": estado, "motivo": motivo}


def criterio_fecha(c, t):
    if not c.get("disp_desde") or not t.get("disp_desde"):
        return _r(PENDIENTE, "Falta la disponibilidad de la carga o del camión")
    c_ini, c_fin = c["disp_desde"].date(), (c.get("disp_hasta") or c["disp_desde"]).date()
    t_ini, t_fin = t["disp_desde"].date(), (t.get("disp_hasta") or t["disp_desde"]).date()
    if t_ini > c_fin:
        return _r(INCOMPATIBLE, f"Camión disponible desde {t_ini:%d/%m/%y}, después de la carga ({c_fin:%d/%m/%y})")
    if t_fin < c_ini:
        # Una fecha publicada por el camión no demuestra que deje de estar disponible después.
        return _r(PENDIENTE, f"El camión publica disponibilidad {t_ini:%d/%m/%y}"
                             + (f"-{t_fin:%d/%m/%y}" if t_fin != t_ini else "")
                             + f" y la carga es el {c_ini:%d/%m/%y}: confirmar si sigue libre ese día")
    mismo = _misma_provincia(c.get("origen", {}), t.get("origen", {}))
    if mismo is True:
        return _r(COMPATIBLE, f"Fechas solapadas ({t_ini:%d/%m} - {t_fin:%d/%m}) y camión en la provincia de carga. "
                              "Hora de llegada no verificada")
    return _r(PENDIENTE, "Fechas solapadas, pero no consta que el camión esté en la provincia de carga: "
                         "llegada a tiempo sin comprobar")


def _misma_provincia(a, b):
    if a.get("pais_iso") and b.get("pais_iso") and a["pais_iso"] != b["pais_iso"]:
        return False
    if a.get("provincia_cod") and b.get("provincia_cod"):
        return a["provincia_cod"] == b["provincia_cod"]
    if a.get("provincia") and b.get("provincia"):
        return clave(a["provincia"]) == clave(b["provincia"])
    return None


def criterio_origen(c, t):
    co, to = c.get("origen", {}), t.get("origen", {})
    if not co.get("texto") or not to.get("texto"):
        return _r(PENDIENTE, "Falta el origen de la carga o del camión")
    if co.get("pais_iso") and to.get("pais_iso") and co["pais_iso"] != to["pais_iso"]:
        return _r(PENDIENTE, f"Camión en {to['pais_iso']} y carga en {co['pais_iso']}: requiere desplazamiento sin calcular")
    m = _misma_provincia(co, to)
    if m is True:
        extra = ""
        if co.get("cp") and to.get("cp") and co["cp"] == to["cp"]:
            extra = " (mismo CP)"
        return _r(COMPATIBLE, f"Misma provincia{extra}: «{to['texto']}» / carga «{co['texto']}». No acredita proximidad exacta")
    if m is False:
        amb = to.get("ambito", "")
        return _r(PENDIENTE, f"Provincia distinta ({to['texto']} → {co['texto']})"
                             + (f"; ámbito del camión: «{amb}» (no se traduce a km)" if amb else ""))
    return _r(PENDIENTE, "No se puede determinar la provincia de alguno de los orígenes")


def criterio_destino(c, t):
    cd, td = c.get("destino", {}), t.get("destino", {})
    if not cd.get("texto"):
        return _r(PENDIENTE, "Falta el destino de la carga")
    if not td.get("texto"):
        return _r(PENDIENTE, "El camión no publica destino")
    if clave(td["texto"]) in ("indiferente", "cualquiera", "todos", "todas"):
        return _r(COMPATIBLE, f"Destino del camión publicado como «{td['texto']}»")
    if cd.get("pais_iso") and td.get("pais_iso") and cd["pais_iso"] != td["pais_iso"]:
        return _r(INCOMPATIBLE, f"Camión hacia {td['pais_iso']}, carga hacia {cd['pais_iso']}")
    m = _misma_provincia(cd, td)
    if m is True:
        return _r(COMPATIBLE, f"Misma provincia de destino: «{td['texto']}»")
    if m is None and td.get("pais_iso") and not td.get("provincia_cod") and not td.get("provincia") \
            and cd.get("pais_iso") == td["pais_iso"] and clave(td["texto"]) in (clave(td.get("pais", "")), "espana", td["pais_iso"].lower()):
        return _r(COMPATIBLE, f"Camión acepta destino país completo «{td['texto']}»")
    if m is False:
        amb = td.get("ambito", "")
        return _r(PENDIENTE, f"Destino del camión «{td['texto']}» distinto del de la carga «{cd['texto']}»"
                             + (f"; ámbito «{amb}»" if amb else ""))
    return _r(PENDIENTE, "No se puede comparar el destino con los datos publicados")


def criterio_vehiculo(c, t, equivalencias):
    ce, te = clave(c["campos"].get("especialidad", "")), clave(t["campos"].get("especialidad", ""))
    cv, tv = clave(c["campos"].get("vehiculo", "")), clave(t["campos"].get("vehiculo", ""))
    if not ce and not cv:
        return _r(PENDIENTE, "La carga no indica vehículo ni especialidad")
    if not te and not tv:
        return _r(PENDIENTE, "El camión no indica vehículo ni especialidad")
    for eq in equivalencias or []:
        a, b, res = clave(eq.get("carga", "")), clave(eq.get("camion", "")), eq.get("resultado", "")
        if a and b and a == ce and b == te:
            if clave(res) == "compatible":
                return _r(COMPATIBLE, f"Equivalencia declarada por el usuario: {eq['carga']} ↔ {eq['camion']}")
            if clave(res) == "incompatible":
                return _r(INCOMPATIBLE, f"Incompatibilidad declarada por el usuario: {eq['carga']} ↔ {eq['camion']}")
    esp_ok = (ce == te) if ce and te else None
    veh_ok = (cv == tv) if cv and tv else None
    if esp_ok and veh_ok is not False:
        return _r(COMPATIBLE, f"Misma especialidad «{t['campos'].get('especialidad')}»"
                              + ("" if veh_ok else "; vehículo no comparado literalmente"))
    if esp_ok is False or veh_ok is False:
        return _r(PENDIENTE, f"Carrocería/vehículo distintos (carga: {c['campos'].get('especialidad') or c['campos'].get('vehiculo')}; "
                             f"camión: {t['campos'].get('especialidad') or t['campos'].get('vehiculo')}). "
                             "No se suponen equivalencias: declárala en criterios si procede")
    return _r(PENDIENTE, "Datos de vehículo insuficientes para comparar")


def criterio_capacidad(c, t):
    faltan, motivos = [], []
    for dim, nombre, unidad in (("peso", "Peso", "kg"), ("volumen", "Volumen", "m3"), ("largo", "Largo", "m"),
                                ("ancho", "Ancho", "m"), ("alto", "Alto", "m")):
        cv, tv = c.get(dim, {}).get("valor"), t.get(dim, {}).get("valor")
        if cv is None:
            continue  # la carga no lo exige
        if tv is None:
            faltan.append(nombre)
            continue
        if tv < cv:
            return _r(INCOMPATIBLE, f"{nombre}: el camión indica {tv:g} {unidad} y la carga requiere {cv:g} {unidad}")
        motivos.append(f"{nombre} {tv:g} ≥ {cv:g} {unidad}")
    if c.get("peso", {}).get("valor") is None:
        faltan.insert(0, "peso de la carga")
    if faltan:
        return _r(PENDIENTE, "Sin dato para comparar: " + ", ".join(faltan)
                  + (f" (comprobado: {'; '.join(motivos)})" if motivos else ""))
    return _r(COMPATIBLE, "; ".join(motivos) + ". Capacidad indicada, no MMA")


def criterio_equipamiento(c, t):
    motivos, pend = [], []
    for k, nombre in (("adr", "ADR"), ("plataforma_elevadora", "Plataforma elevadora"), ("doble_conductor", "Doble conductor")):
        req, tiene = c.get(k), t.get(k)
        if req is True:
            if tiene is False:
                return _r(INCOMPATIBLE, f"La carga exige {nombre} y el camión indica que no")
            if tiene is None:
                pend.append(nombre)
            else:
                motivos.append(f"{nombre} sí")
        elif req is None:
            pend.append(f"{nombre} (la carga no lo indica)")
    if pend:
        return _r(PENDIENTE, "Sin confirmar: " + ", ".join(pend))
    return _r(COMPATIBLE, ", ".join(motivos) or "La carga no exige ADR, plataforma ni doble conductor")


def criterio_forma(c, t):
    req, tiene = set(c.get("forma_carga") or []), set(t.get("forma_carga") or [])
    if not req:
        return _r(PENDIENTE, "La carga no indica forma de carga")
    if not tiene:
        return _r(PENDIENTE, "El camión no indica forma de carga")
    if req & tiene:
        return _r(COMPATIBLE, f"Forma de carga en común: {', '.join(sorted(req & tiene))}")
    return _r(INCOMPATIBLE, f"Carga por {', '.join(sorted(req))}; camión sólo {', '.join(sorted(tiene))}")


def criterio_comentarios(c, t):
    textos = [(x, x_obj["campos"].get("observaciones", "")) for x, x_obj in (("carga", c), ("camión", t))]
    con = [f"{x}: «{v[:160]}»" for x, v in textos if v.strip()]
    if con:
        return _r(PENDIENTE, "Hay comentarios publicados que deben leerse antes de llamar: " + " | ".join(con))
    return _r(COMPATIBLE, "Sin comentarios publicados en ninguna de las dos ofertas")


def informacion(t) -> int:
    campos = [bool(t.get("disp_desde")), bool(t.get("origen", {}).get("texto")), bool(t.get("destino", {}).get("texto")),
              bool(t["campos"].get("vehiculo")), bool(t["campos"].get("especialidad")),
              t.get("peso", {}).get("valor") is not None, bool(t.get("telefonos") or t.get("moviles") or t.get("emails"))]
    return round(100 * sum(campos) / len(campos))


def evaluar(carga: dict, camion: dict, equivalencias=None) -> dict:
    res = {
        "fecha": criterio_fecha(carga, camion),
        "origen": criterio_origen(carga, camion),
        "destino": criterio_destino(carga, camion),
        "vehiculo": criterio_vehiculo(carga, camion, equivalencias),
        "capacidad": criterio_capacidad(carga, camion),
        "equipamiento": criterio_equipamiento(carga, camion),
        "forma_carga": criterio_forma(carga, camion),
        "comentarios": criterio_comentarios(carga, camion),
    }
    estados = [v["estado"] for v in res.values()]
    if INCOMPATIBLE in estados:
        general = "Descartado"
    elif all(e == COMPATIBLE for e in estados):
        general = COMPATIBLE
    else:
        general = PENDIENTE
    cobertura = round(100 * sum(PESOS[k] for k, v in res.items() if v["estado"] == COMPATIBLE) / sum(PESOS.values()))
    return {
        "criterios": res, "estado": general, "cobertura": cobertura, "informacion": informacion(camion),
        "motivo": "; ".join(f"{NOMBRES[k]}: {v['motivo']}" for k, v in res.items() if v["estado"] == COMPATIBLE),
        "pendiente": "; ".join(f"{NOMBRES[k]}: {v['motivo']}" for k, v in res.items() if v["estado"] == PENDIENTE),
        "descarte": "; ".join(f"{NOMBRES[k]}: {v['motivo']}" for k, v in res.items() if v["estado"] == INCOMPATIBLE),
    }


def orden_ranking(ev: dict, camion: dict):
    prioridad = {COMPATIBLE: 0, PENDIENTE: 1, "Descartado": 2}[ev["estado"]]
    return (prioridad, -ev["cobertura"], -ev["informacion"], camion.get("leido_en"))
