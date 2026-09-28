"""Flujo completo: cargas → fichas → camiones por carga → compatibilidad → Excel."""
from __future__ import annotations

import datetime as dt
import logging
import os

from . import compatibilidad as C
from . import excel, lectura
from .duplicados import clave_empresa
from .formularios import FiltroNoAplicable, aplicar_filtros, frame_formulario
from .navegador import URLS, Detenido, Navegador, SesionInterrumpida, texto_empresa_sesion
from .normalizacion import clave, nombre_provincia
from .progreso import Progreso

log = logging.getLogger("wtbot")


class Bot:
    def __init__(self, conf: dict, registro=None, parar_evento=None, mapa=None):
        self.conf = conf
        self.registro = registro or (lambda m: log.info(m))
        self.parar = parar_evento
        self.mapa = mapa or {}
        self.nav: Navegador | None = None
        self.prog: Progreso | None = None

    # ------------------------------------------------------------------ API
    def ejecutar(self, reanudar_de: str | None = None, prueba=False) -> str | None:
        carpeta = self.conf["salida"]["carpeta"]
        os.makedirs(carpeta, exist_ok=True)
        if reanudar_de:
            self.prog = Progreso.cargar(reanudar_de)
            # se reanuda con los MISMOS criterios de la ejecución original
            self.conf = dict(self.conf, **{k: v for k, v in self.prog.datos["criterios"].items() if k != "limites"})
            self.registro(f"Reanudando desde {reanudar_de}")
        else:
            ruta = os.path.join(carpeta, f"_progreso_{dt.datetime.now():%Y%m%d_%H%M%S}.json")
            self.prog = Progreso(ruta)
            self.prog.datos["criterios"] = {k: self.conf[k] for k in ("filtros_wtransnet", "comprobaciones_bot", "camiones", "limites")}
            if prueba:
                self.prog.datos["criterios"]["limites"] = dict(self.conf["limites"], max_cargas=1, max_camiones_por_carga=1)
        self.prog.datos["estado"] = "en curso"
        self.prog.guardar()
        lim = self.prog.datos["criterios"]["limites"]
        self.nav = Navegador(self.conf, self.registro, self.parar)
        try:
            self.nav.abrir()
            self._verificar_cuenta()
            if len(self.prog.datos["orden_cargas"]) < int(lim["max_cargas"]):
                self._buscar_cargas(int(lim["max_cargas"]))
            for cid in list(self.prog.datos["orden_cargas"]):
                if cid in self.prog.datos["cargas_completadas"]:
                    continue
                self._camiones_para(cid, lim)
                self.prog.datos["cargas_completadas"].append(cid)
                self.prog.guardar()
            self.prog.datos["estado"] = "completada"
        except Detenido as e:
            self.prog.datos["estado"] = "detenida por el usuario (resultados parciales)"
            self._incidencia("Detención", str(e))
        except SesionInterrumpida as e:
            self.prog.datos["estado"] = "interrumpida: requiere intervención (resultados parciales)"
            self._incidencia("Sesión", str(e))
            self.registro("⚠ " + str(e))
        except FiltroNoAplicable as e:
            self.prog.datos["estado"] = "detenida: filtro no aplicable"
            self._incidencia("Filtro", str(e))
            self.registro("⚠ " + str(e))
        except Exception as e:  # noqa: BLE001
            self.prog.datos["estado"] = f"error: {type(e).__name__}"
            self._incidencia("Error", f"{type(e).__name__}: {e}")
            self.registro(f"⚠ Error: {e}")
            log.exception("Error en la ejecución")
        finally:
            self.prog.guardar()
            if self.nav:
                self.nav.cerrar()
        ruta = excel.exportar(self.prog.datos, carpeta)
        self.registro(f"Excel generado: {ruta}")
        self.registro(f"Progreso guardado en: {self.prog.ruta} (sirve para reanudar)")
        return ruta

    # ------------------------------------------------------------------ pasos
    def _incidencia(self, tipo, detalle, oferta="", enlace=""):
        self.prog.incidencia(tipo, detalle, oferta, enlace)
        self.registro(f"Incidencia [{tipo}] {oferta}: {detalle[:200]}")

    def _verificar_cuenta(self):
        texto = texto_empresa_sesion(self.nav.page)
        esperada = (self.conf["conexion"].get("empresa_esperada") or "").strip()
        if esperada and clave(esperada) not in clave(texto):
            # la pestaña puede estar en una página sin cabecera: se carga el formulario de búsqueda
            self.nav.ir(URLS["buscar_carga"])
            texto = texto_empresa_sesion(self.nav.page)
        if esperada:
            if clave(esperada) not in clave(texto):
                raise SesionInterrumpida(
                    f"La cuenta esperada «{esperada}» no aparece en la sesión abierta. Revisa qué empresa "
                    "tiene iniciada la sesión en Edge antes de continuar.")
            self.prog.datos["empresa_sesion"] = f"{esperada} (verificada en la cabecera de la página)"
        else:
            self.prog.datos["empresa_sesion"] = "No verificada: indica 'empresa_esperada' en criterios.yaml"
            self.registro("Aviso: no se ha indicado empresa_esperada; la cuenta de la sesión no se verifica.")

    def _buscar(self, tipo: str, filtros: dict) -> list[str]:
        self.nav.ir(URLS["buscar_carga" if tipo == "carga" else "buscar_camion"])
        frame = frame_formulario(self.nav.page)
        aplicados = aplicar_filtros(self.nav, frame, filtros, tipo, self.mapa.get(tipo))
        self.registro(f"Filtros aplicados y verificados ({tipo}): " + "; ".join(aplicados))
        from .formularios import Rellenador
        Rellenador(self.nav, frame_formulario(self.nav.page), self.mapa.get(tipo)).boton("Buscar", "buscar")
        return aplicados

    def _recorrer_listado(self, max_paginas: int):
        """Genera (fila, frame, url_listado) respetando la paginación y el límite de páginas."""
        for n_pag in range(max_paginas):
            lst = lectura.leer_listado(self.nav.page)
            if not lst["filas"]:
                if n_pag == 0:
                    self.registro("El listado no contiene ofertas" + (" (la página indica que no hay resultados)" if lst.get("sin_resultados") else ""))
                return
            self.registro(f"Página {n_pag + 1} del listado: {len(lst['filas'])} ofertas visibles {lst.get('total_texto', '')}")
            for i in range(len(lst["filas"])):
                lst_actual = lectura.leer_listado(self.nav.page)  # índices frescos tras volver de una ficha
                if i >= len(lst_actual["filas"]):
                    break
                yield lst_actual["filas"][i], lst_actual["_frame"]
            lst = lectura.leer_listado(self.nav.page)
            sig = lst.get("siguiente")
            if not sig or n_pag + 1 >= max_paginas:
                return
            loc = lst["_frame"].locator("a").nth(sig["i"])
            self.nav.clic(lst["_frame"], loc, "paginar")
            self.nav.pausa()

    def _leer_oferta(self, fila, frame, tipo) -> dict | None:
        self.nav.comprobar_parada()
        pagina, propia = lectura.abrir_ficha(self.nav, fila, frame)
        reg = None
        try:
            bruto = lectura.leer_pagina_ficha(pagina)
            reg = lectura.interpretar_ficha(bruto, tipo, fila)
            cb = self.conf["comprobaciones_bot"]
            if (not reg["contacto_fuente"] and cb.get("leer_ficha_empresa_si_falta_contacto")) or \
                    (tipo == "camion" and not reg["campos"].get("actividad") and cb.get("leer_actividad_empresa_camion")):
                self._ficha_empresa(bruto, reg)
            for f in lectura.verificar_trazabilidad(reg):
                reg["avisos"].append(f)
        finally:
            if propia is True:
                pagina.close()
            else:
                try:
                    lectura.volver_listado(self.nav, propia)
                except (Detenido, SesionInterrumpida):
                    raise
                except Exception:  # noqa: BLE001
                    if reg is not None:
                        raise lectura.ListadoPerdido(reg) from None
                    raise
        self.nav.pausa()
        return reg

    def _ficha_empresa(self, bruto, reg):
        url = lectura.enlace_empresa(bruto, reg)
        if not url:
            return
        p = self.nav.nueva_pestana_bot()
        try:
            self.nav.ir(url, page=p)
            emp = lectura.interpretar_ficha(lectura.leer_pagina_ficha(p), "empresa")
            reg["empresa_ficha"] = {"actividad": emp["campos"].get("actividad", ""), "url": emp["url"]}
            if not reg["contacto_fuente"] and (emp["telefonos"] or emp["moviles"] or emp["emails"]):
                reg["telefonos"], reg["moviles"], reg["emails"] = emp["telefonos"], emp["moviles"], emp["emails"]
                reg["contacto"] = reg["contacto"] or emp["contacto"]
                reg["contacto_fuente"] = "empresa"
                reg["avisos"].append("Contacto tomado de la ficha GENERAL de la empresa (la oferta no mostraba contacto)")
            if emp["campos"].get("actividad"):
                reg["campos"].setdefault("actividad", emp["campos"]["actividad"])
        except (Detenido, SesionInterrumpida):
            raise
        except Exception as e:  # noqa: BLE001
            self._incidencia("Ficha de empresa no leída", str(e)[:300], reg["id"])
        finally:
            p.close()
            self.nav.pausa(0.5)

    # ------------------------------------------------------------------ cargas
    def _buscar_cargas(self, maximo):
        f = self.conf["filtros_wtransnet"]
        self.prog.datos["filtros_aplicados"]["carga"] = self._buscar("carga", f)
        self.nav.pausa()
        vistos = set(self.prog.datos.get("filas_vistas", []))
        for fila, frame in self._recorrer_listado(int(self.conf["limites"]["max_paginas_listado"])):
            if len(self.prog.datos["orden_cargas"]) >= maximo:
                break
            huella = fila.get("href") or fila["texto_fila"]
            if huella in vistos:
                continue
            perdido = False
            try:
                reg = self._leer_oferta(fila, frame, "carga")
            except lectura.ListadoPerdido as e:
                reg, perdido = e.registro, True
            except (Detenido, SesionInterrumpida):
                raise  # la fila NO se marca como vista: se leerá al reanudar
            except Exception as e:  # noqa: BLE001
                self._incidencia("Ficha de carga no leída", str(e)[:300], fila["texto_fila"][:80], fila.get("href", ""))
                reg = None
            vistos.add(huella)
            self.prog.datos["filas_vistas"] = sorted(vistos)
            if reg is None:
                self.prog.guardar()
                continue
            if reg["id"] in self.prog.datos["cargas"]:
                self._incidencia("Duplicado", "La misma carga (red y número) aparece varias veces en el listado", reg["id"])
                continue
            motivo = self._comprobar_carga(reg)
            if motivo:
                self.prog.datos["descartadas_carga"].append(reg["id"])
                self._incidencia("Carga excluida por comprobación del bot", motivo, reg["id"], reg["url"])
            else:
                self.prog.datos["cargas"][reg["id"]] = reg
                self.prog.datos["orden_cargas"].append(reg["id"])
                self.registro(f"Carga {len(self.prog.datos['orden_cargas'])}/{maximo}: {reg['id']} "
                              f"{reg['origen'].get('texto')} → {reg['destino'].get('texto')}")
            self.prog.guardar()
            if perdido:
                self._incidencia("Listado interrumpido", "No se pudo volver al listado tras una ficha; se continúa con lo leído")
                break
        n = len(self.prog.datos["orden_cargas"])
        if n < maximo:
            self._incidencia("Menos cargas de las pedidas",
                                 f"Se han obtenido {n} cargas válidas de {maximo}: el listado no ofrecía más ofertas "
                                 "que cumplieran los filtros y comprobaciones dentro del límite de páginas.")

    def _comprobar_carga(self, r) -> str:
        cb = self.conf["comprobaciones_bot"]
        hoy = dt.date.today()
        if cb.get("descartar_no_vigentes") and r.get("disp_hasta") and r["disp_hasta"].date() < hoy:
            return f"No vigente: disponibilidad hasta {r['disp_hasta']:%d/%m/%y}"
        if not r.get("disp_desde"):
            r["avisos"].append("Disponibilidad no legible en la ficha: vigencia sin comprobar")
        p = r["peso"]["valor"]
        if p is not None:
            if cb.get("peso_maximo_kg") is not None and p > float(cb["peso_maximo_kg"]):
                return f"Peso {r['peso']['original']} supera el máximo pedido ({cb['peso_maximo_kg']} kg)"
            if cb.get("peso_minimo_kg") is not None and p < float(cb["peso_minimo_kg"]):
                return f"Peso {r['peso']['original']} inferior al mínimo pedido ({cb['peso_minimo_kg']} kg)"
        elif cb.get("peso_maximo_kg") is not None or cb.get("peso_minimo_kg") is not None:
            r["avisos"].append("Peso no publicado o sin unidad: límite de peso sin comprobar")
        for dim, lim_k, nombre in (("largo", "largo_maximo_m", "Largo"), ("volumen", "volumen_maximo_m3", "Volumen")):
            v = r[dim]["valor"]
            if cb.get(lim_k) is not None:
                if v is None:
                    r["avisos"].append(f"{nombre} no publicado: límite sin comprobar")
                elif v > float(cb[lim_k]):
                    return f"{nombre} {r[dim]['original']} supera el máximo pedido ({cb[lim_k]})"
        f = self.conf["filtros_wtransnet"]
        for k, nombre in (("especialidad", "Especialidad"), ("tipo_vehiculo", "Vehículo")):
            pub = r["campos"].get("especialidad" if k == "especialidad" else "vehiculo", "")
            if f.get(k) and pub and clave(pub) != clave(f[k]):
                r["avisos"].append(f"{nombre} publicada «{pub}» distinta de la filtrada «{f[k]}» (Wtransnet la devolvió; revisar)")
        for bloque, lista, amb in (("origen", f.get("origenes"), f.get("ambito_origen")),
                                   ("destino", f.get("destinos"), f.get("ambito_destino"))):
            if not lista or amb:
                continue
            from .normalizacion import codigo_provincia
            pedidos = {codigo_provincia(u.get("provincia", "")) for u in lista if u.get("provincia")} - {None}
            cod = r[bloque].get("provincia_cod")
            if pedidos and cod and cod not in pedidos:
                return f"{bloque.capitalize()} en provincia {nombre_provincia(cod)}, distinta de la solicitada"
        return ""

    # ------------------------------------------------------------------ camiones
    def _filtros_camion(self, carga) -> dict:
        fc, cam = self.conf["filtros_wtransnet"], self.conf["camiones"]
        o, d = carga["origen"], carga["destino"]

        def ubic(u):
            if not (u.get("pais") or u.get("pais_iso")):
                return None
            return {"pais": u.get("pais") or "", "pais_iso": u.get("pais_iso"),
                    "provincia": u.get("provincia") or "", "provincia_cod": u.get("provincia_cod")}
        desde = carga.get("disp_desde")
        hasta = carga.get("disp_hasta") or desde
        filtros = {
            "origenes": [x for x in [ubic(o)] if x],
            "destinos": [x for x in [ubic(d)] if x] if cam.get("filtrar_por_destino") else [],
            "fecha_inicial": (desde - dt.timedelta(days=int(cam.get("dias_antes_de_la_carga") or 0))).strftime("%d/%m/%y") if desde else fc.get("fecha_inicial", ""),
            "fecha_final": hasta.strftime("%d/%m/%y") if hasta else fc.get("fecha_final", ""),
        }
        for k in ("tipo_bolsa", "tipo_vehiculo", "especialidad", "misma_especialidad"):
            filtros[k] = cam.get(k) if cam.get(k) is not None else fc.get(k)
        for k in ("sirve_frigorifico", "sirve_lateral_bajo", "adr", "plataforma_elevadora", "doble_conductor", "forma_carga"):
            filtros[k] = cam.get(k)
        if not filtros["origenes"]:
            raise FiltroNoAplicable(f"La carga {carga['id']} no tiene país de origen legible: no se puede buscar camión.")
        return filtros

    def _camiones_para(self, cid, lim):
        carga = self.prog.datos["cargas"][cid]
        maximo = int(lim["max_camiones_por_carga"])
        tope_fichas = int(self.conf["camiones"].get("max_fichas_leidas_por_carga", 20))
        self.registro(f"Buscando camiones para {cid}")
        try:
            filtros = self._filtros_camion(carga)
        except FiltroNoAplicable as e:
            self._incidencia("Búsqueda de camión no realizada", str(e), cid)
            return
        self.prog.datos["filtros_aplicados"][cid] = self._buscar("camion", filtros)
        self.nav.pausa()
        rels = self.prog.datos["relaciones"].setdefault(cid, [])
        ids = {r["camion"] for r in rels}
        empresas = {clave_empresa(self.prog.datos["camiones"][r["camion"]]) for r in rels if r["estado"] != "Descartado"}
        leidas = 0
        for fila, frame in self._recorrer_listado(int(self.conf["limites"]["max_paginas_listado"])):
            validos = [r for r in rels if r["estado"] != "Descartado"]
            if len(validos) >= maximo or leidas >= tope_fichas:
                break
            perdido = False
            try:
                cam = self._leer_oferta(fila, frame, "camion")
                leidas += 1
            except lectura.ListadoPerdido as e:
                cam, perdido = e.registro, True
                leidas += 1
            except (Detenido, SesionInterrumpida):
                raise
            except Exception as e:  # noqa: BLE001
                self._incidencia("Ficha de camión no leída", str(e)[:300], fila["texto_fila"][:80], fila.get("href", ""))
                continue
            if cam["id"] in ids:
                continue  # misma oferta repetida en el listado de esta carga
            self.prog.datos["camiones"].setdefault(cam["id"], cam)
            ev = C.evaluar(carga, cam, self.conf.get("equivalencias_carroceria"))
            emp = clave_empresa(cam)
            if lim.get("empresas_distintas_por_carga") and ev["estado"] != "Descartado":
                if emp and emp in empresas:
                    self._incidencia("Omitido por empresa repetida", "Se pidieron empresas distintas por carga", f"{cid} ↔ {cam['id']}", cam["url"])
                    continue
                if not emp:
                    cam["avisos"].append("Empresa no identificada: no se puede garantizar que sea distinta")
            ids.add(cam["id"])
            advert = list(cam.get("avisos", []))
            if any(cam["id"] in [r["camion"] for r in rs] for k, rs in self.prog.datos["relaciones"].items() if k != cid):
                advert.append("Este camión también figura como candidato de otra carga: no está reservado")
            rels.append({"camion": cam["id"], "posicion": None, **ev, "advertencias": advert})
            if ev["estado"] != "Descartado" and emp:
                empresas.add(emp)
            self.registro(f"  Camión {cam['id']}: {ev['estado']} (cobertura {ev['cobertura']})")
            self.prog.guardar()
            if perdido:
                self._incidencia("Listado interrumpido", "No se pudo volver al listado de camiones; se continúa con lo leído", cid)
                break
        # ranking reproducible
        camiones = self.prog.datos["camiones"]
        validos = sorted([r for r in rels if r["estado"] != "Descartado"],
                         key=lambda r: C.orden_ranking(r, camiones[r["camion"]]))[:maximo]
        for i, r in enumerate(validos, 1):
            r["posicion"] = i
        self.prog.datos["relaciones"][cid] = validos + [r for r in rels if r["estado"] == "Descartado"]
        if len(validos) < maximo:
            self._incidencia("Menos camiones de los pedidos",
                                 f"{len(validos)} candidatos válidos de {maximo} tras leer {leidas} fichas "
                                 f"(límite {tope_fichas}); el resto del listado no existía o fue descartado.", cid)
        self.prog.guardar()
