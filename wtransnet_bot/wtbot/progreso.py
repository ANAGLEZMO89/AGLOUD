"""Progreso local (JSON) para reanudar y exportar resultados parciales.

Se guarda tras cada ficha. No contiene cookies, contraseñas ni tokens: sólo los
datos visibles de las ofertas, enlaces limpios y el registro de incidencias.
"""
from __future__ import annotations

import datetime as dt
import json
import os
import tempfile


def _def(o):
    if isinstance(o, dt.datetime):
        return {"__dt__": o.isoformat()}
    if isinstance(o, set):
        return sorted(o)
    raise TypeError(type(o))


def _hook(d):
    if "__dt__" in d and len(d) == 1:
        return dt.datetime.fromisoformat(d["__dt__"])
    return d


class Progreso:
    def __init__(self, ruta: str):
        self.ruta = ruta
        self.datos = {
            "inicio": dt.datetime.now(), "criterios": {}, "filtros_aplicados": {}, "cargas": {},
            "orden_cargas": [], "camiones": {}, "relaciones": {}, "incidencias": [],
            "cargas_completadas": [], "descartadas_carga": [], "estado": "en curso", "empresa_sesion": "",
        }

    @classmethod
    def cargar(cls, ruta: str) -> "Progreso":
        p = cls(ruta)
        with open(ruta, encoding="utf-8") as f:
            p.datos = json.load(f, object_hook=_hook)
        return p

    def guardar(self):
        os.makedirs(os.path.dirname(os.path.abspath(self.ruta)), exist_ok=True)
        fd, tmp = tempfile.mkstemp(dir=os.path.dirname(os.path.abspath(self.ruta)), suffix=".tmp")
        with os.fdopen(fd, "w", encoding="utf-8") as f:
            json.dump(self.datos, f, ensure_ascii=False, indent=1, default=_def)
        os.replace(tmp, self.ruta)

    def incidencia(self, tipo: str, detalle: str, oferta: str = "", enlace: str = ""):
        self.datos["incidencias"].append({"momento": dt.datetime.now(), "tipo": tipo, "oferta": oferta,
                                          "detalle": detalle, "enlace": enlace})
