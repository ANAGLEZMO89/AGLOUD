"""Uso:
  python -m wtbot                       -> abre la ventana
  python -m wtbot inspeccionar          -> fase 1-2: mapa de la interfaz real
  python -m wtbot prueba                -> 1 carga y 1 camión
  python -m wtbot ejecutar              -> hasta 10 cargas x 10 camiones
  python -m wtbot reanudar RUTA.json    -> continúa una ejecución interrumpida
  python -m wtbot exportar RUTA.json    -> Excel a partir de un progreso guardado
Opción: --criterios RUTA (por defecto criterios.yaml junto al programa)
"""
from __future__ import annotations

import argparse
import datetime as dt
import logging
import os
import sys

from . import config as CFG


def _registro_archivo(carpeta):
    os.makedirs(carpeta, exist_ok=True)
    ruta = os.path.join(carpeta, f"registro_{dt.datetime.now():%Y%m%d}.log")
    logging.basicConfig(level=logging.INFO, format="%(asctime)s %(message)s",
                        handlers=[logging.FileHandler(ruta, encoding="utf-8"), logging.StreamHandler(sys.stdout)])
    return lambda m: logging.getLogger("wtbot").info(m)


def main(argv=None):
    base = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
    ap = argparse.ArgumentParser(prog="wtbot")
    ap.add_argument("orden", nargs="?", default="gui",
                    choices=["gui", "inspeccionar", "prueba", "ejecutar", "reanudar", "exportar"])
    ap.add_argument("ruta", nargs="?")
    ap.add_argument("--criterios", default=os.path.join(base, "criterios.yaml"))
    a = ap.parse_args(argv)
    if a.orden == "gui":
        from .gui import main as gui
        return gui(a.criterios)
    conf = CFG.cargar(a.criterios)
    reg = _registro_archivo(conf["salida"]["carpeta"])
    if a.orden == "exportar":
        from . import excel
        from .progreso import Progreso
        print(excel.exportar(Progreso.cargar(a.ruta).datos, conf["salida"]["carpeta"]))
        return 0
    if a.orden == "inspeccionar":
        from .inspeccion import inspeccionar
        inspeccionar(conf, reg, conf["salida"]["carpeta"])
        return 0
    if a.orden in ("prueba", "ejecutar"):
        err = CFG.validar(conf)
        if err:
            print("No se puede ejecutar una búsqueda real. Falta:\n- " + "\n- ".join(err))
            return 2
    from .orquestador import Bot
    Bot(conf, reg).ejecutar(reanudar_de=a.ruta if a.orden == "reanudar" else None, prueba=a.orden == "prueba")
    return 0


if __name__ == "__main__":
    sys.exit(main())
