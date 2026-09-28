"""Carga y validación de criterios.yaml."""
from __future__ import annotations

import copy
import os

import yaml

from .normalizacion import fecha_formulario

POR_DEFECTO = {
    "filtros_wtransnet": {
        "origenes": [], "destinos": [], "ambito_origen": "", "ambito_destino": "",
        "fecha_inicial": "", "fecha_final": "", "tipo_bolsa": [], "ida_y_vuelta": "", "redes": [],
        "tipo_vehiculo": "", "especialidad": "", "misma_especialidad": None, "forma_carga": [],
        "adr": "", "doble_conductor": "", "plataforma_elevadora": "", "cargas_urgentes": False,
    },
    "comprobaciones_bot": {
        "peso_minimo_kg": None, "peso_maximo_kg": None, "largo_maximo_m": None, "volumen_maximo_m3": None,
        "descartar_no_vigentes": True, "leer_ficha_empresa_si_falta_contacto": True,
        "leer_actividad_empresa_camion": False,
    },
    "camiones": {
        "dias_antes_de_la_carga": 1, "filtrar_por_destino": True, "tipo_bolsa": None, "tipo_vehiculo": None,
        "especialidad": None, "misma_especialidad": None, "sirve_frigorifico": "", "sirve_lateral_bajo": "",
        "adr": "", "plataforma_elevadora": "", "doble_conductor": "", "forma_carga": [],
        "max_fichas_leidas_por_carga": 20,
    },
    "limites": {"max_cargas": 10, "max_camiones_por_carga": 10, "empresas_distintas_por_carga": False,
                "max_paginas_listado": 3},
    "equivalencias_carroceria": [],
    "salida": {"carpeta": "resultados"},
    "conexion": {"modo": "conectar", "cdp_url": "http://127.0.0.1:9222", "perfil_bot": "perfil_edge_bot",
                 "empresa_esperada": ""},
    "ritmo": {"pausa_min_s": 3, "pausa_max_s": 6, "reintentos": 2},
}


def _fusion(base, extra):
    out = copy.deepcopy(base)
    for k, v in (extra or {}).items():
        if isinstance(v, dict) and isinstance(out.get(k), dict):
            out[k] = _fusion(out[k], v)
        else:
            out[k] = v
    return out


def cargar(ruta: str) -> dict:
    with open(ruta, encoding="utf-8") as f:
        datos = yaml.safe_load(f) or {}
    conf = _fusion(POR_DEFECTO, datos)
    base = os.path.dirname(os.path.abspath(ruta))
    carpeta = conf["salida"]["carpeta"] or "resultados"
    conf["salida"]["carpeta"] = carpeta if os.path.isabs(carpeta) else os.path.join(base, carpeta)
    return conf


def validar(conf: dict, para_busqueda_real=True) -> list[str]:
    """Errores que impiden una búsqueda real (se piden al usuario)."""
    err = []
    f = conf["filtros_wtransnet"]
    for k in ("fecha_inicial", "fecha_final"):
        try:
            fecha_formulario(f.get(k, ""))
        except ValueError as e:
            err.append(str(e))
    if para_busqueda_real:
        if not f.get("origenes"):
            err.append("Falta al menos un ORIGEN (país y provincia como mínimo). No elijo rutas por ti.")
        if not f.get("fecha_inicial"):
            err.append("Falta la FECHA INICIAL de disponibilidad.")
        if not f.get("tipo_bolsa"):
            err.append("Falta el TIPO DE BOLSA (Trailers Completos, Grupajes o Rígidos Completos).")
    lim = conf["limites"]
    if not (1 <= int(lim["max_cargas"]) <= 10):
        err.append("max_cargas debe estar entre 1 y 10.")
    if not (1 <= int(lim["max_camiones_por_carga"]) <= 10):
        err.append("max_camiones_por_carga debe estar entre 1 y 10.")
    if float(conf["ritmo"]["pausa_min_s"]) < 2:
        err.append("pausa_min_s no puede ser inferior a 2 segundos.")
    return err
