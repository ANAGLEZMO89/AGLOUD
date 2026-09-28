"""Conexión a Microsoft Edge y navegación segura por Wtransnet.

Modos de conexión:
- "conectar": se engancha por CDP a un Edge YA ABIERTO que se haya iniciado con
  --remote-debugging-port (ver INICIAR_EDGE_WTRANSNET.bat). Es la única vía para
  trabajar sobre la pestaña donde ya iniciaste sesión.
- "lanzar": Playwright abre Edge (channel="msedge") con un perfil propio del bot
  (carpeta perfil_edge_bot). Inicias sesión a mano la primera vez.

El bot nunca lee contraseñas ni cookies, ni rellena el inicio de sesión.
"""
from __future__ import annotations

import logging
import random
import re
import time
from urllib.parse import parse_qsl, urlencode, urlsplit, urlunsplit

from . import seguridad

log = logging.getLogger("wtbot")

DOMINIO = "app.wtransnet.com"
BASE = "https://app.wtransnet.com/WTNWEB/"
URLS = {
    "buscar_carga": BASE + "servlet/central?URL=/servlet/fhoOfertas%3Faccion=form%26cgcm=CG%26opcion=10%26nueva=s",
    "buscar_camion": BASE + "servlet/central?URL=/servlet/fhoOfertas%3Faccion=form%26cgcm=CM%26opcion=10%26nueva=s",
    "todas_cargas": BASE + "servlet/central?URL=/servlet/fhoOfertas%3Faccion=listar%26cgcm=CG%26opcion=61%26ntimes=1",
    "todos_camiones": BASE + "servlet/central?URL=/servlet/fhoOfertas%3Faccion=listar%26cgcm=CM%26opcion=61%26ntimes=1",
}

# Parámetros de URL que podrían identificar la sesión: se eliminan de enlaces y registros.
PARAMS_SESION = re.compile(r"^(jsessionid|sid|session|sessionid|token|auth|ticket|t|_)$", re.I)


class SesionInterrumpida(RuntimeError):
    """Login, CAPTCHA o sesión caducada: requiere intervención humana."""


class Detenido(RuntimeError):
    """El usuario pulsó Detener."""


def url_limpia(url: str) -> str:
    """Quita ';jsessionid=...' y parámetros de sesión de un enlace."""
    if not url:
        return ""
    url = re.sub(r";jsessionid=[^?#]*", "", url, flags=re.I)
    p = urlsplit(url)
    q = [(k, v) for k, v in parse_qsl(p.query, keep_blank_values=True) if not PARAMS_SESION.match(k)]
    return urlunsplit((p.scheme, p.netloc, p.path, urlencode(q, safe="/?=&%"), ""))


class Navegador:
    def __init__(self, conf: dict, registro, parar_evento=None):
        self.conf = conf
        self.registro = registro
        self.parar = parar_evento
        self._pw = None
        self.browser = None
        self.context = None
        self.page = None
        self._paginas_propias = []

    # ------------------------------------------------------------ conexión
    def abrir(self):
        from playwright.sync_api import sync_playwright

        c = self.conf.get("conexion", {})
        modo = c.get("modo", "conectar")
        self._pw = sync_playwright().start()
        if modo == "conectar":
            url = c.get("cdp_url", "http://127.0.0.1:9222")
            try:
                self.browser = self._pw.chromium.connect_over_cdp(url, timeout=15000)
            except Exception as e:  # noqa: BLE001
                raise ConnectionError(
                    f"No se pudo conectar con Edge en {url}. Edge debe haberse abierto con "
                    "INICIAR_EDGE_WTRANSNET.bat (puerto de depuración local). Detalle: "
                    f"{str(e).splitlines()[0]}"
                ) from None
            if not self.browser.contexts:
                raise ConnectionError("Edge no expone ningún contexto de navegación.")
            self.context = self.browser.contexts[0]
            self.page = self._buscar_pestana()
        elif modo == "lanzar":
            perfil = c.get("perfil_bot", "perfil_edge_bot")
            self.context = self._pw.chromium.launch_persistent_context(
                perfil, channel="msedge", headless=False, viewport=None)
            self.page = self._buscar_pestana(permitir_nueva=True)
        else:
            raise ValueError(f"Modo de conexión desconocido: {modo}")
        seguridad.instalar_barreras(self.page, self.registro)
        self.registro(f"Conectado. Pestaña de trabajo: {url_limpia(self.page.url)[:100]}")
        return self.page

    def _buscar_pestana(self, permitir_nueva=False):
        pestañas = [p for pc in [self.context] for p in pc.pages]
        for p in pestañas:
            if DOMINIO in (p.url or ""):
                return p
        if permitir_nueva:
            p = pestañas[0] if pestañas else self.context.new_page()
            p.goto(BASE)
            return p
        raise SesionInterrumpida(
            "No encuentro ninguna pestaña de app.wtransnet.com en el Edge conectado. "
            "Abre Wtransnet e inicia sesión en ese Edge y vuelve a pulsar Ejecutar.")

    def cerrar(self):
        """Cierra sólo lo que abrió el bot. Nunca cierra Edge ni tus pestañas."""
        for p in self._paginas_propias:
            try:
                if not p.is_closed():
                    p.close()
            except Exception:  # noqa: BLE001
                pass
        if self.page:
            seguridad.quitar_barreras(self.page)
        modo = self.conf.get("conexion", {}).get("modo", "conectar")
        try:
            if modo == "lanzar" and self.context:
                self.context.close()
            # En modo "conectar" NO se llama a browser.close(): cerraría tu Edge.
            if self._pw:
                self._pw.stop()
        except Exception:  # noqa: BLE001
            pass

    # ------------------------------------------------------------ utilidades
    def comprobar_parada(self):
        if self.parar is not None and self.parar.is_set():
            raise Detenido("Ejecución detenida por el usuario")

    def pausa(self, factor=1.0):
        r = self.conf.get("ritmo", {})
        s = random.uniform(float(r.get("pausa_min_s", 3)), float(r.get("pausa_max_s", 6))) * factor
        fin = time.time() + s
        while time.time() < fin:
            self.comprobar_parada()
            time.sleep(0.2)

    def nueva_pestana_bot(self):
        """Pestaña auxiliar en el MISMO navegador y sesión (no inicia otra sesión)."""
        p = self.context.new_page()
        seguridad.instalar_barreras(p, self.registro)
        self._paginas_propias.append(p)
        return p

    def ir(self, url, page=None):
        page = page or self.page
        if not seguridad.url_permitida(url):
            raise seguridad.AccionBloqueada(f"URL no permitida: {url[:100]}")
        self.comprobar_parada()
        intentos = int(self.conf.get("ritmo", {}).get("reintentos", 2)) + 1
        for n in range(intentos):
            try:
                page.goto(url, wait_until="domcontentloaded", timeout=45000)
                esperar_carga(page)
                break
            except Exception as e:  # noqa: BLE001
                if n == intentos - 1:
                    raise RuntimeError(f"No se pudo cargar la página tras {intentos} intentos: {e}") from None
                self.registro(f"Reintento de carga ({n + 1}): {str(e).splitlines()[0][:120]}")
                self.pausa(0.5)
        verificar_sesion(page)
        return page

    # ------------------------------------------------------------ clic seguro
    def clic(self, frame, locator, accion, page=None):
        """Pulsa un control tras pasar las barreras de seguridad."""
        page = page or self.page
        info = locator.evaluate(JS_INFO_CONTROL)
        seguridad.comprobar_clic(info, accion)
        self.comprobar_parada()
        self.registro(f"Clic permitido [{accion}]: {(info.get('texto') or info.get('value') or '')[:50]!r}")
        locator.click(timeout=15000)
        esperar_carga(page)
        verificar_sesion(page)


JS_INFO_CONTROL = """el => ({
  tag: el.tagName, type: el.getAttribute('type') || '',
  texto: (el.innerText || el.textContent || '').trim().slice(0, 200),
  value: el.tagName === 'INPUT' && ['button','submit','image','reset'].includes((el.type||'').toLowerCase()) ? el.value : '',
  title: el.getAttribute('title') || '', alt: el.getAttribute('alt') || '',
  aria: el.getAttribute('aria-label') || '',
  href: el.getAttribute('href') || '', onclick: el.getAttribute('onclick') || ''
})"""


def esperar_carga(page, tiempo_ms=30000):
    try:
        page.wait_for_load_state("domcontentloaded", timeout=tiempo_ms)
    except Exception:  # noqa: BLE001
        pass
    try:
        page.wait_for_load_state("networkidle", timeout=8000)
    except Exception:  # noqa: BLE001
        pass
    for f in page.frames:
        try:
            f.wait_for_load_state("domcontentloaded", timeout=5000)
        except Exception:  # noqa: BLE001
            pass


JS_ESTADO_SESION = """() => {
  const vis = el => !!(el && (el.offsetWidth || el.offsetHeight || el.getClientRects().length));
  const pwd = [...document.querySelectorAll('input[type=password]')].some(vis);
  const txt = (document.body ? document.body.innerText : '').slice(0, 5000).toLowerCase();
  const cap = !!document.querySelector('iframe[src*="captcha"], iframe[src*="recaptcha"], iframe[src*="hcaptcha"], .g-recaptcha, .h-captcha, [id*=captcha i]')
              || /captcha|no soy un robot|verifica que eres humano/.test(txt);
  const cad = /sesi[oó]n (ha )?(caducad|expirad|finalizad)|session (has )?expired|vuelva a (identificarse|iniciar)/.test(txt);
  return {pwd, cap, cad};
}"""


def verificar_sesion(page):
    """Detiene el bot si aparece login, CAPTCHA o sesión caducada."""
    for f in page.frames:
        try:
            est = f.evaluate(JS_ESTADO_SESION)
        except Exception:  # noqa: BLE001
            continue
        if est["cap"]:
            raise SesionInterrumpida("Aparece un CAPTCHA. Resuélvelo tú en Edge y pulsa Reanudar.")
        if est["pwd"] or est["cad"]:
            raise SesionInterrumpida(
                "Wtransnet pide iniciar sesión o la sesión ha caducado. Inicia sesión tú en Edge "
                "y pulsa Reanudar: se continuará desde el progreso guardado.")
    if DOMINIO not in (page.url or ""):
        raise SesionInterrumpida(f"La pestaña ha salido de Wtransnet ({url_limpia(page.url)[:80]}).")


def texto_empresa_sesion(page) -> str:
    """Texto de cabecera visible (primeros caracteres de cada marco) para identificar la cuenta."""
    trozos = []
    for f in page.frames:
        try:
            t = f.evaluate("() => (document.body ? document.body.innerText : '').slice(0, 600)")
        except Exception:  # noqa: BLE001
            continue
        if t.strip():
            trozos.append(t.strip())
    return "\n---\n".join(trozos)
