"""Detección de duplicados.

- Identidad exacta: red + número de oferta (nunca se fusionan redes distintas).
- Posible duplicado (sólo se marca, no se fusiona): ofertas de redes distintas,
  o sin número, con misma empresa, mismo origen, mismo destino y misma fecha.
"""
from __future__ import annotations

from .normalizacion import clave


def huella(r: dict) -> tuple:
    return (clave(r.get("empresa_nombre", "")), clave(r.get("origen", {}).get("texto", "")),
            clave(r.get("destino", {}).get("texto", "")),
            r["disp_desde"].date().isoformat() if r.get("disp_desde") else "")


def posibles_duplicados(registros: list[dict]) -> list[tuple[str, str, str]]:
    vistos: dict[tuple, dict] = {}
    salida = []
    for r in registros:
        h = huella(r)
        if not all(h):
            continue
        if h in vistos and vistos[h]["id"] != r["id"]:
            salida.append((vistos[h]["id"], r["id"], "Misma empresa, origen, destino y fecha: posible misma oferta en otra red o republicada"))
        else:
            vistos[h] = r
    return salida


def clave_empresa(r: dict) -> str:
    return clave(r.get("empresa_codigo") or r.get("empresa_nombre") or "")
