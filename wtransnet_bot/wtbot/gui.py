"""Ventana en español para introducir criterios, ejecutar, detener y exportar."""
from __future__ import annotations

import logging
import os
import queue
import threading
import tkinter as tk
from tkinter import filedialog, messagebox, ttk

import yaml

from . import config as CFG
from . import excel, inspeccion
from .orquestador import Bot
from .progreso import Progreso

SN = ["", "Sí", "No", "Indiferente"]


def _ubic_a_texto(lista):
    return "\n".join("; ".join([u.get("pais", ""), u.get("provincia", ""), u.get("codigo_postal", ""), u.get("localidad", "")]).rstrip("; ")
                     for u in lista or [])


def _texto_a_ubic(txt):
    salida = []
    for linea in txt.strip().splitlines():
        p = [x.strip() for x in linea.split(";")] + ["", "", "", ""]
        if any(p[:4]):
            salida.append({"pais": p[0], "provincia": p[1], "codigo_postal": p[2], "localidad": p[3]})
    return salida


class App:
    def __init__(self, ruta_criterios: str):
        self.ruta = ruta_criterios
        self.conf = CFG.cargar(ruta_criterios)
        self.parar = threading.Event()
        self.cola: queue.Queue = queue.Queue()
        self.hilo = None
        self.root = tk.Tk()
        self.root.title("Bot Wtransnet — búsqueda de cargas y camiones (sólo lectura)")
        self.root.geometry("1050x800")
        self._construir()
        self._cargar_en_formulario()
        self.root.after(200, self._vaciar_cola)

    # ------------------------------------------------------------ interfaz
    def _construir(self):
        nb = ttk.Notebook(self.root)
        nb.pack(fill="both", expand=True, padx=6, pady=6)
        f1, f2, f3 = ttk.Frame(nb), ttk.Frame(nb), ttk.Frame(nb)
        nb.add(f1, text="Filtros de Wtransnet")
        nb.add(f2, text="Comprobaciones del bot y límites")
        nb.add(f3, text="Registro")
        self.v = {}

        def fila(frame, r, etiqueta, clave_v, tipo="entry", valores=None, ancho=40, ayuda=""):
            ttk.Label(frame, text=etiqueta).grid(row=r, column=0, sticky="nw", padx=4, pady=3)
            if tipo == "entry":
                self.v[clave_v] = tk.StringVar()
                ttk.Entry(frame, textvariable=self.v[clave_v], width=ancho).grid(row=r, column=1, sticky="w")
            elif tipo == "combo":
                self.v[clave_v] = tk.StringVar()
                ttk.Combobox(frame, textvariable=self.v[clave_v], values=valores, width=ancho - 10).grid(row=r, column=1, sticky="w")
            elif tipo == "texto":
                t = tk.Text(frame, height=3, width=ancho)
                t.grid(row=r, column=1, sticky="w")
                self.v[clave_v] = t
            elif tipo == "checks":
                cont = ttk.Frame(frame)
                cont.grid(row=r, column=1, sticky="w")
                for val in valores:
                    self.v[f"{clave_v}:{val}"] = tk.BooleanVar()
                    ttk.Checkbutton(cont, text=val, variable=self.v[f"{clave_v}:{val}"]).pack(side="left", padx=4)
            elif tipo == "check":
                self.v[clave_v] = tk.BooleanVar()
                ttk.Checkbutton(frame, variable=self.v[clave_v]).grid(row=r, column=1, sticky="w")
            if ayuda:
                ttk.Label(frame, text=ayuda, foreground="#666").grid(row=r, column=2, sticky="w", padx=4)

        r = 0
        for et, k, t, vals, ay in [
            ("Orígenes (uno por línea)", "origenes", "texto", None, "País; Provincia; CP; Localidad"),
            ("Destinos (uno por línea)", "destinos", "texto", None, "País; Provincia; CP; Localidad"),
            ("Ámbito origen", "ambito_origen", "combo", ["", "Comunidad Autónoma", "Provincia y colindantes", "Misma provincia"], "texto exacto del desplegable"),
            ("Ámbito destino", "ambito_destino", "combo", ["", "Comunidad Autónoma", "Provincia y colindantes", "Misma provincia"], ""),
            ("Fecha inicial", "fecha_inicial", "entry", None, "dd/mm/aa"),
            ("Fecha final", "fecha_final", "entry", None, "dd/mm/aa"),
            ("Tipo de bolsa", "tipo_bolsa", "checks", ["Trailers Completos", "Grupajes", "Rígidos Completos"], ""),
            ("Redes", "redes", "checks", ["Wtransnet", "Teleroute", "123Cargo/Bursa"], "texto según formulario"),
            ("Ida y vuelta", "ida_y_vuelta", "combo", SN, ""),
            ("Tipo de vehículo", "tipo_vehiculo", "entry", None, "texto EXACTO del desplegable (ver mapa_interfaz)"),
            ("Especialidad / carrocería", "especialidad", "entry", None, "texto EXACTO del desplegable"),
            ("Misma especialidad", "misma_especialidad", "combo", ["", "Sí", "No"], ""),
            ("Forma de carga", "forma_carga", "checks", ["Arriba", "Lateral", "Detrás"], ""),
            ("ADR", "adr", "combo", ["", "Sólo cargas ADR", "No", "Indiferente"], ""),
            ("Doble conductor", "doble_conductor", "combo", ["", "Sólo cargas doble conductor", "No", "Indiferente"], ""),
            ("Plataforma elevadora", "plataforma_elevadora", "combo", ["", "Sí", "No"], ""),
            ("Sólo cargas urgentes", "cargas_urgentes", "check", None, ""),
        ]:
            fila(f1, r, et, k, t, vals, ayuda=ay)
            r += 1
        r = 0
        for et, k, t, vals, ay in [
            ("Peso mínimo de la carga (kg)", "peso_minimo_kg", "entry", None, "comprobación posterior, no es filtro de Wtransnet"),
            ("Peso máximo de la carga (kg)", "peso_maximo_kg", "entry", None, ""),
            ("Largo máximo (m)", "largo_maximo_m", "entry", None, ""),
            ("Volumen máximo (m3)", "volumen_maximo_m3", "entry", None, ""),
            ("Máximo de cargas (1-10)", "max_cargas", "entry", None, ""),
            ("Máximo de camiones por carga (1-10)", "max_camiones_por_carga", "entry", None, ""),
            ("Exigir empresas distintas por carga", "empresas_distintas_por_carga", "check", None, ""),
            ("Carpeta del Excel", "carpeta", "entry", None, ""),
            ("Empresa esperada en la sesión", "empresa_esperada", "entry", None, "p. ej. la razón social que ves en Wtransnet"),
            ("Conexión", "modo", "combo", ["conectar", "lanzar"], "conectar = Edge abierto con INICIAR_EDGE_WTRANSNET.bat"),
        ]:
            fila(f2, r, et, k, t, vals, ayuda=ay)
            r += 1
        ttk.Button(f2, text="Elegir carpeta…", command=self._elegir_carpeta).grid(row=7, column=3)

        self.log = tk.Text(f3, wrap="word")
        self.log.pack(fill="both", expand=True)

        barra = ttk.Frame(self.root)
        barra.pack(fill="x", padx=6, pady=6)
        for txt, cmd in [("Guardar criterios", self.guardar), ("1. Inspeccionar interfaz", self.inspeccionar),
                         ("2. Prueba (1 carga + 1 camión)", lambda: self.ejecutar(prueba=True)),
                         ("3. Ejecutar", self.ejecutar), ("Reanudar…", self.reanudar),
                         ("Exportar progreso a Excel…", self.exportar_progreso), ("Abrir carpeta", self.abrir_carpeta)]:
            ttk.Button(barra, text=txt, command=cmd).pack(side="left", padx=3)
        self.btn_parar = tk.Button(barra, text="DETENER", bg="#c00000", fg="white", command=self.detener, state="disabled")
        self.btn_parar.pack(side="right", padx=3)
        self.nb = nb

    def _cargar_en_formulario(self):
        f, cb, lim = self.conf["filtros_wtransnet"], self.conf["comprobaciones_bot"], self.conf["limites"]
        for k in ("origenes", "destinos"):
            self.v[k].delete("1.0", "end")
            self.v[k].insert("1.0", _ubic_a_texto(f.get(k)))
        for k in ("ambito_origen", "ambito_destino", "fecha_inicial", "fecha_final", "ida_y_vuelta", "tipo_vehiculo",
                  "especialidad", "adr", "doble_conductor", "plataforma_elevadora"):
            self.v[k].set(f.get(k) or "")
        self.v["misma_especialidad"].set({True: "Sí", False: "No"}.get(f.get("misma_especialidad"), ""))
        self.v["cargas_urgentes"].set(bool(f.get("cargas_urgentes")))
        for grupo in ("tipo_bolsa", "redes", "forma_carga"):
            marcados = {x.lower() for x in f.get(grupo) or []}
            for k in self.v:
                if k.startswith(grupo + ":"):
                    self.v[k].set(k.split(":", 1)[1].lower() in marcados)
        for k in ("peso_minimo_kg", "peso_maximo_kg", "largo_maximo_m", "volumen_maximo_m3"):
            self.v[k].set("" if cb.get(k) is None else str(cb[k]))
        self.v["max_cargas"].set(str(lim["max_cargas"]))
        self.v["max_camiones_por_carga"].set(str(lim["max_camiones_por_carga"]))
        self.v["empresas_distintas_por_carga"].set(bool(lim.get("empresas_distintas_por_carga")))
        self.v["carpeta"].set(self.conf["salida"]["carpeta"])
        self.v["empresa_esperada"].set(self.conf["conexion"].get("empresa_esperada", ""))
        self.v["modo"].set(self.conf["conexion"].get("modo", "conectar"))

    def _leer_formulario(self) -> dict:
        with open(self.ruta, encoding="utf-8") as fh:
            datos = yaml.safe_load(fh) or {}
        f = datos.setdefault("filtros_wtransnet", {})
        f["origenes"] = _texto_a_ubic(self.v["origenes"].get("1.0", "end"))
        f["destinos"] = _texto_a_ubic(self.v["destinos"].get("1.0", "end"))
        for k in ("ambito_origen", "ambito_destino", "fecha_inicial", "fecha_final", "ida_y_vuelta", "tipo_vehiculo",
                  "especialidad", "adr", "doble_conductor", "plataforma_elevadora"):
            f[k] = self.v[k].get().strip()
        f["misma_especialidad"] = {"Sí": True, "No": False}.get(self.v["misma_especialidad"].get())
        f["cargas_urgentes"] = self.v["cargas_urgentes"].get()
        for grupo in ("tipo_bolsa", "redes", "forma_carga"):
            f[grupo] = [k.split(":", 1)[1] for k in self.v if k.startswith(grupo + ":") and self.v[k].get()]
        cb = datos.setdefault("comprobaciones_bot", {})
        for k in ("peso_minimo_kg", "peso_maximo_kg", "largo_maximo_m", "volumen_maximo_m3"):
            t = self.v[k].get().strip().replace(",", ".")
            cb[k] = float(t) if t else None
        lim = datos.setdefault("limites", {})
        lim["max_cargas"] = int(self.v["max_cargas"].get() or 10)
        lim["max_camiones_por_carga"] = int(self.v["max_camiones_por_carga"].get() or 10)
        lim["empresas_distintas_por_carga"] = self.v["empresas_distintas_por_carga"].get()
        datos.setdefault("salida", {})["carpeta"] = self.v["carpeta"].get().strip()
        con = datos.setdefault("conexion", {})
        con["empresa_esperada"] = self.v["empresa_esperada"].get().strip()
        con["modo"] = self.v["modo"].get().strip() or "conectar"
        return datos

    # ------------------------------------------------------------ acciones
    def guardar(self, avisar=True) -> bool:
        try:
            datos = self._leer_formulario()
        except ValueError as e:
            messagebox.showerror("Dato no válido", str(e))
            return False
        with open(self.ruta, "w", encoding="utf-8") as fh:
            yaml.safe_dump(datos, fh, allow_unicode=True, sort_keys=False)
        self.conf = CFG.cargar(self.ruta)
        if avisar:
            self.escribir("Criterios guardados en " + self.ruta)
        return True

    def escribir(self, msg):
        self.cola.put(msg)

    def _vaciar_cola(self):
        while not self.cola.empty():
            m = self.cola.get()
            if callable(m):
                m()
                continue
            self.log.insert("end", m + "\n")
            self.log.see("end")
        self.root.after(200, self._vaciar_cola)

    def _lanzar(self, objetivo):
        if self.hilo and self.hilo.is_alive():
            messagebox.showinfo("En marcha", "Ya hay una ejecución en curso.")
            return
        self.parar.clear()
        self.btn_parar.config(state="normal")
        self.nb.select(2)

        def envoltura():
            try:
                objetivo()
            except Exception as e:  # noqa: BLE001
                self.escribir(f"⚠ {e}")
            finally:
                self.cola.put(lambda: self.btn_parar.config(state="disabled"))
        self.hilo = threading.Thread(target=envoltura, daemon=True)
        self.hilo.start()

    def inspeccionar(self):
        if not self.guardar(avisar=False):
            return
        self._lanzar(lambda: inspeccion.inspeccionar(self.conf, self.escribir, self.conf["salida"]["carpeta"], self.parar))

    def ejecutar(self, prueba=False, reanudar_de=None):
        if not self.guardar(avisar=False):
            return
        errores = CFG.validar(self.conf) if not reanudar_de else []
        if errores:
            messagebox.showwarning("Faltan criterios", "\n".join(errores))
            return
        bot = Bot(self.conf, self.escribir, self.parar)
        self._lanzar(lambda: bot.ejecutar(reanudar_de=reanudar_de, prueba=prueba))

    def reanudar(self):
        ruta = filedialog.askopenfilename(initialdir=self.conf["salida"]["carpeta"], title="Progreso a reanudar",
                                          filetypes=[("Progreso", "_progreso_*.json")])
        if ruta:
            self.ejecutar(reanudar_de=ruta)

    def exportar_progreso(self):
        ruta = filedialog.askopenfilename(initialdir=self.conf["salida"]["carpeta"], filetypes=[("Progreso", "_progreso_*.json")])
        if ruta:
            x = excel.exportar(Progreso.cargar(ruta).datos, self.conf["salida"]["carpeta"])
            self.escribir("Excel generado: " + x)

    def detener(self):
        self.parar.set()
        self.escribir("Deteniendo… se conservará lo leído y se generará el Excel parcial.")

    def abrir_carpeta(self):
        c = self.conf["salida"]["carpeta"]
        os.makedirs(c, exist_ok=True)
        if hasattr(os, "startfile"):
            os.startfile(c)  # noqa: S606 (Windows)
        else:
            self.escribir(c)

    def _elegir_carpeta(self):
        c = filedialog.askdirectory()
        if c:
            self.v["carpeta"].set(c)

    def run(self):
        self.root.mainloop()


def main(ruta_criterios: str):
    logging.basicConfig(level=logging.INFO)
    App(ruta_criterios).run()
