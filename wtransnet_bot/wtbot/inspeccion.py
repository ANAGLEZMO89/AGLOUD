"""Fase 1-2: inspección de la interfaz real (sin buscar ni pulsar nada comercial).

Visita los formularios «Buscar carga» / «Buscar camión» y los listados
generales, y guarda:
  - inspeccion_AAAAMMDD_HHMM.json: todos los controles (nombre, id, tipo,
    etiqueta visible, sección, opciones de cada desplegable) y la estructura
    de los listados y de una ficha de ejemplo de cada tipo (sólo etiquetas).
  - mapa_interfaz_AAAAMMDD_HHMM.md: resumen legible con los filtros
    localizados, los no localizados y las diferencias respecto a la
    inspección del 25/09/2026.
No se leen contraseñas ni cookies. No se pulsa «Buscar».
"""
from __future__ import annotations

import datetime as dt
import json
import os

from . import lectura
from .formularios import diagnosticar, leer_controles
from .navegador import URLS, Navegador, texto_empresa_sesion, url_limpia
from .normalizacion import clave

ESPERADO_CARGA = ["fecha_inicial", "fecha_final", "tipo_bolsa", "ida_y_vuelta", "redes", "tipo_vehiculo",
                  "especialidad", "misma_especialidad", "forma_carga", "adr", "doble_conductor",
                  "plataforma_elevadora", "cargas_urgentes", "origen.pais", "origen.provincia",
                  "origen.codigo_postal", "origen.localidad", "origen.ambito", "destino.pais",
                  "destino.provincia", "destino.codigo_postal", "destino.localidad", "destino.ambito"]
ESPERADO_CAMION = [c for c in ESPERADO_CARGA if c not in ("redes", "cargas_urgentes")] + [
    "sirve_frigorifico", "sirve_lateral_bajo"]


def _campo_bot(x):
    k = clave(x["etiqueta"])
    sub = next((c for c, sin in lectura.CAMPOS_UBICACION.items() if k in sin), None)
    bloque = lectura._bloque_seccion(x["seccion"], x["etiqueta"])
    if sub:
        return f"{bloque}.{sub}" if bloque in ("origen", "destino") else "(dato de ubicación fuera de origen/destino: otros datos)"
    return lectura._canon(x["etiqueta"]) or "(sin clasificar: se conserva en «Otros datos»)"


def _detectar(controles, lista):
    return {item: diagnosticar(controles, item) for item in lista}


def inspeccionar(conf: dict, registro, carpeta: str, parar_evento=None) -> tuple[str, str]:
    nav = Navegador(conf, registro, parar_evento)
    ahora = dt.datetime.now()
    salida = {"fecha": ahora.isoformat(timespec="seconds"), "paginas": {}}
    try:
        nav.abrir()
        for nombre, esperado in (("buscar_carga", ESPERADO_CARGA), ("buscar_camion", ESPERADO_CAMION)):
            registro(f"Inspeccionando formulario {nombre}")
            nav.ir(URLS[nombre])
            marcos = []
            for f in nav.page.frames:
                try:
                    ctr = leer_controles(f)
                except Exception:  # noqa: BLE001
                    continue
                marcos.append({"url": url_limpia(f.url), "controles": ctr})
            principal = max(marcos, key=lambda m: len([c for c in m["controles"] if c["visible"]]), default={"controles": []})
            if nombre == "buscar_carga":
                salida["cabecera_sesion"] = texto_empresa_sesion(nav.page)[:1500]
            salida["paginas"][nombre] = {"url_final": url_limpia(nav.page.url), "marcos": marcos,
                                         "deteccion": _detectar(principal["controles"], esperado)}
            nav.pausa()
        for nombre, tipo in (("todas_cargas", "carga"), ("todos_camiones", "camion")):
            registro(f"Inspeccionando listado {nombre}")
            nav.ir(URLS[nombre])
            lst = lectura.leer_listado(nav.page)
            info = {"url_final": url_limpia(nav.page.url), "filas_visibles": len(lst["filas"]),
                    "cabecera": lst["filas"][0]["cabecera"] if lst["filas"] else [],
                    "paginacion_siguiente": bool(lst.get("siguiente")),
                    "enlace_ficha": ("URL" if lst["filas"] and lst["filas"][0]["href"] else "javascript/clic") if lst["filas"] else ""}
            if lst["filas"]:
                nav.pausa()
                p, propia = lectura.abrir_ficha(nav, lst["filas"][0], lst["_frame"])
                bruto = lectura.leer_pagina_ficha(p)
                reg = lectura.interpretar_ficha(bruto, tipo, lst["filas"][0])
                info["ficha_ejemplo"] = {
                    "etiquetas": [{"seccion": x["seccion"], "etiqueta": x["etiqueta"],
                                   "campo_bot": _campo_bot(x)} for x in bruto["kv"]],
                    "enlaces_contacto_detectados": len(bruto["contacto"]),
                    "campos_reconocidos": sorted(reg["campos"].keys()),
                    "numero_oferta_detectado": bool(reg["numero_oferta"]),
                    "textos_de_enlaces": sorted({e["texto"] for e in bruto["enlaces"] if e["texto"]})[:80],
                }
                if propia is True:
                    p.close()
                else:
                    lectura.volver_listado(nav, propia)
            salida["paginas"][nombre] = info
            nav.pausa()
    finally:
        nav.cerrar()
    os.makedirs(carpeta, exist_ok=True)
    rj = os.path.join(carpeta, f"inspeccion_{ahora:%Y%m%d_%H%M}.json")
    with open(rj, "w", encoding="utf-8") as f:
        json.dump(salida, f, ensure_ascii=False, indent=1)
    rm = os.path.join(carpeta, f"mapa_interfaz_{ahora:%Y%m%d_%H%M}.md")
    with open(rm, "w", encoding="utf-8") as f:
        f.write(informe(salida))
    registro(f"Inspección guardada: {rm}")
    return rj, rm


def informe(s: dict) -> str:
    L = [f"# Mapa de la interfaz de Wtransnet — {s['fecha']}", "",
         "## Cabecera de la sesión (para verificar la empresa)", "", "```", s.get("cabecera_sesion", "")[:800], "```", ""]
    for nombre in ("buscar_carga", "buscar_camion"):
        p = s["paginas"].get(nombre)
        if not p:
            continue
        L += [f"## Formulario {nombre}", "", f"URL final: {p['url_final']}", "",
              "| Filtro | Estado | Tipo | Texto junto al control | Opciones (primeras) |", "|---|---|---|---|---|"]
        for k, v in p["deteccion"].items():
            ops = ", ".join(o or "(vacía)" for o in v.get("opciones", [])[:12])
            L.append(f"| {k} | {v['estado']} | {v.get('tipo', '')} | {v.get('texto_cercano', v.get('detalle', ''))} | {ops} |")
        L.append("")
        for m in p["marcos"]:
            for c in m["controles"]:
                if c["tipo"] == "select" and c["visible"] and len(c.get("opciones", [])) > 12:
                    L += [f"### Desplegable completo «{c.get('etiqueta') or c.get('celda_anterior') or c['name']}» ({c['name']})", "",
                          "; ".join(o["texto"] for o in c["opciones"]), ""]
    for nombre in ("todas_cargas", "todos_camiones"):
        p = s["paginas"].get(nombre)
        if not p:
            continue
        L += [f"## Listado {nombre}", "", f"- Filas visibles: {p['filas_visibles']}",
              f"- Columnas: {' | '.join(p['cabecera'])}", f"- Paginación «siguiente»: {p['paginacion_siguiente']}",
              f"- Apertura de ficha: {p['enlace_ficha']}", ""]
        fe = p.get("ficha_ejemplo")
        if fe:
            L += ["### Etiquetas de la ficha de ejemplo", "", "| Sección | Etiqueta | Campo del bot |", "|---|---|---|"]
            L += [f"| {x['seccion']} | {x['etiqueta']} | {x['campo_bot']} |" for x in fe["etiquetas"]]
            L += ["", f"Número de oferta detectado: {fe['numero_oferta_detectado']}. "
                      f"Enlaces tel/mailto detectados (sin pulsar): {fe['enlaces_contacto_detectados']}", ""]
    return "\n".join(L)
