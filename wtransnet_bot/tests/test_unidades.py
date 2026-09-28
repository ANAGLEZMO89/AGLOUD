"""Pruebas unitarias con datos de ejemplo escritos a mano (no proceden de Wtransnet)."""
import datetime as dt

import openpyxl
import pytest

from wtbot import compatibilidad as C
from wtbot import normalizacion as N
from wtbot import seguridad as S
from wtbot.navegador import url_limpia


def test_numeros_y_unidades():
    assert N.parse_numero("24.000 Kg") == 24000
    assert N.parse_numero("1,5 t") == 1.5
    assert N.parse_cantidad("24.000 Kg", "peso")["valor"] == 24000
    assert N.parse_cantidad("1 Tn", "peso")["valor"] == 1000
    q = N.parse_cantidad("24", "peso")
    assert q["valor"] is None and "unidad no indicada" in q["nota"]
    assert N.parse_cantidad("", "peso")["nota"] == "dato ausente"
    assert N.parse_cantidad("0 kg", "peso")["valor"] == 0


def test_precio_cero_se_conserva_y_avisa():
    p = N.parse_precio("0 EUR")
    assert p["original"] == "0 EUR" and p["valor"] == 0 and "no está confirmado" in p["nota"]


def test_fechas():
    f = N.parse_fechas("30/09/26 - 02/10/26 08:30")
    assert f[0] == dt.datetime(2026, 9, 30) and f[1] == dt.datetime(2026, 10, 2, 8, 30)
    assert N.fecha_formulario("5/10/2026") == "05/10/26"
    with pytest.raises(ValueError):
        N.fecha_formulario("mañana")


def test_telefonos_emails_y_avisos():
    assert N.extraer_telefonos("Tel: 0034 600 111 222 / 961000001") == ["+34600111222", "961000001"]
    assert N.normalizar_telefono("961 00 00 01") == "961000001"  # no se inventa prefijo
    assert N.extraer_emails("Escribe a Trafico@Ejemplo.test.") == ["trafico@ejemplo.test"]
    av = N.avisos_contacto("Carga urgente. No contestamos correos, llamar al teléfono.")
    assert av and "No contestamos correos" in av[0]


def test_ubicacion_cp_texto_y_provincia():
    u = N.parse_ubicacion(pais="España", provincia="", cp="03001")
    assert u["cp"] == "03001" and u["provincia_cod"] == "03"
    u = N.parse_ubicacion("ES-46 VALENCIA")
    assert u["pais_iso"] == "ES" and u["provincia_cod"] == "46"
    assert N.codigo_provincia("Alicante/Alacant") == "03"
    assert N.codigo_provincia("Alicante/Murcia") is None  # partes contradictorias: no se adivina
    assert N.codigo_provincia("Alacant") == "03"


def test_barreras_de_seguridad():
    S.comprobar_clic({"texto": "Buscar", "type": "submit"}, "buscar")
    for info, acc in [({"value": "Ofertar", "type": "button"}, "buscar"),
                      ({"texto": "Buscar", "onclick": "ofertar()"}, "buscar"),
                      ({"texto": "30/09/26", "href": "tel:+34600"}, "ficha"),
                      ({"texto": "30/09/26", "href": "/x?accion=interes"}, "ficha"),
                      ({"texto": "Enviar mensaje"}, "empresa"),
                      ({"texto": "Aceptar"}, "buscar")]:
        with pytest.raises(S.AccionBloqueada):
            S.comprobar_clic(info, acc)
    assert not S.url_permitida("mailto:a@b.c")
    assert not S.url_permitida("https://app.wtransnet.com/x?accion=alta")
    assert S.url_permitida("https://app.wtransnet.com/WTNWEB/servlet/central?URL=/servlet/fhoOfertas%3Faccion=form%26cgcm=CG%26opcion=10%26nueva=s")


def test_url_sin_sesion():
    assert url_limpia("https://h/p;jsessionid=ABC?x=1&token=zz&sid=9") == "https://h/p?x=1"


def _oferta(**kw):
    base = {"campos": {}, "origen": {}, "destino": {}, "peso": {}, "volumen": {}, "largo": {}, "ancho": {}, "alto": {},
            "adr": None, "plataforma_elevadora": None, "doble_conductor": None, "forma_carga": [],
            "disp_desde": None, "disp_hasta": None, "telefonos": [], "moviles": [], "emails": []}
    base.update(kw)
    return base


def test_compatibilidad_capacidad_mma_y_pendientes():
    val = N.parse_ubicacion(pais="España", provincia="Valencia")
    mad = N.parse_ubicacion(pais="España", provincia="Madrid")
    d = dt.datetime(2026, 9, 30)
    carga = _oferta(campos={"especialidad": "Tautliner"}, origen=val, destino=mad, disp_desde=d, disp_hasta=d,
                    peso={"valor": 24000.0}, adr=False, forma_carga=["lateral"])
    furgon = _oferta(campos={"especialidad": "Furgón", "vehiculo": "Camión 3,5 T. MMA"}, origen=val, destino=mad,
                     disp_desde=d, disp_hasta=d, peso={"valor": 1000.0})
    ev = C.evaluar(carga, furgon)
    assert ev["estado"] == "Descartado" and "1000 kg" in ev["descarte"]
    sin_datos = _oferta(campos={"especialidad": "Tautliner"}, origen=val, destino=mad, disp_desde=d, disp_hasta=d)
    ev = C.evaluar(carga, sin_datos)
    assert ev["estado"] == C.PENDIENTE
    assert ev["criterios"]["capacidad"]["estado"] == C.PENDIENTE  # dato ausente nunca es favorable
    tarde = _oferta(campos={"especialidad": "Tautliner"}, origen=val, destino=mad,
                    disp_desde=d + dt.timedelta(days=3), disp_hasta=d + dt.timedelta(days=3))
    assert C.evaluar(carga, tarde)["criterios"]["fecha"]["estado"] == C.INCOMPATIBLE


def test_equivalencia_solo_si_la_declara_el_usuario():
    carga = _oferta(campos={"especialidad": "Tautliner"})
    cam = _oferta(campos={"especialidad": "Tautliner-Portab."})
    assert C.criterio_vehiculo(carga, cam, [])["estado"] == C.PENDIENTE
    eq = [{"carga": "Tautliner", "camion": "Tautliner-Portab.", "resultado": "compatible"}]
    assert C.criterio_vehiculo(carga, cam, eq)["estado"] == C.COMPATIBLE


def test_excel_texto_no_formula(tmp_path):
    from wtbot.excel import _texto
    wb = openpyxl.Workbook()
    ws = wb.active
    _texto(ws, 1, 1, '=HYPERLINK("http://malo")')
    _texto(ws, 2, 1, "03001")
    _texto(ws, 3, 1, "+34600111222")
    wb.save(tmp_path / "t.xlsx")
    ws2 = openpyxl.load_workbook(tmp_path / "t.xlsx").active
    assert ws2["A1"].data_type == "s" and ws2["A1"].value.startswith("=")
    assert ws2["A2"].value == "03001" and ws2["A3"].value == "+34600111222"
