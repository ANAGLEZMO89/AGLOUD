// Panel: criterios, ejecución, detener, reanudar, Excel e informe de la página.
"use strict";

const BASE_POR_DEFECTO = "https://app.wtransnet.com/WTNWEB/";
const $ = (s) => document.querySelector(s);
const form = $("#criterios");
let parar = false, enMarcha = false;

function log(m) {
  const t = new Date().toLocaleTimeString("es-ES");
  const el = $("#log");
  el.textContent += `[${t}] ${m}\n`;
  el.scrollTop = el.scrollHeight;
}
function estado(m, clase = "") { const e = $("#estado"); e.textContent = m; e.className = "estado " + clase; }

// ------------------------------------------------------------------ criterios
// «Valencia, 46001, Alicante» -> una zona por elemento. 5 cifras = código postal; si no, provincia.
const zonas = (t) => t.split(/[,;\n]+/).map((x) => x.trim()).filter(Boolean)
  .map((x) => (/^\d{5}$/.test(x) ? { pais: "España", provincia: "", codigo_postal: x, localidad: "" } : { pais: "España", provincia: x, codigo_postal: "", localidad: "" }));
const v = (n) => (form.elements[n] ? form.elements[n].value || "" : "").trim();
const chk = (n) => !!(form.elements[n] && form.elements[n].checked);
const aCorta = (iso) => (iso ? iso.slice(8, 10) + "/" + iso.slice(5, 7) + "/" + iso.slice(2, 4) : "");
const isoDe = (f) => new Date(f.getTime() - f.getTimezoneOffset() * 60000).toISOString().slice(0, 10);

function leerFormulario() {
  const desde = v("desde") || isoDe(new Date());
  const hasta = v("hasta") || desde;
  const camiones = chk("buscar_camiones");
  return {
    filtros: {
      origenes: zonas(v("zonas")), destinos: zonas(v("destinos_simple")), ambito_origen: v("ambito_origen"), ambito_destino: v("ambito_destino"),
      fecha_inicial: aCorta(desde), fecha_final: aCorta(hasta),
      // Fijos por decisión de la empresa: cargas completas, Trailers Completos, todas las bolsas
      tipo_bolsa: ["Trailers Completos"], redes: ["Wtransnet", "Teleroute", "123Cargo"],
      ida_y_vuelta: "", tipo_vehiculo: v("tipo_vehiculo"), especialidad: v("especialidad"), misma_especialidad: "",
      forma_carga: [], adr: v("adr"), doble_conductor: "", plataforma_elevadora: "", cargas_urgentes: false,
    },
    comprobaciones: { peso_maximo_kg: v("peso_maximo_kg"), descartar_no_vigentes: true, ficha_empresa_si_falta_contacto: chk("ficha_empresa") },
    camiones: { dias_antes: 1, filtrar_destino: true, max_fichas: 20 },
    limites: { max_cargas: Math.min(10, Math.max(1, Number(v("max_cargas") || 10))),
      max_camiones: camiones ? Math.min(10, Math.max(1, Number(v("max_camiones") || 10))) : 0,
      empresas_distintas: chk("empresas_distintas"), max_paginas: Math.min(5, Math.max(1, Number(v("max_paginas") || 3))) },
    equivalencias: [], empresa_esperada: v("empresa_esperada"),
    ritmo: { min: Math.max(2, Number(v("pausa_min") || 3)), max: Math.max(Number(v("pausa_min") || 3) + 1, Number(v("pausa_max") || 5)) },
    _form: Object.fromEntries([...form.elements].filter((e) => e.name).map((e) => [e.name, e.type === "checkbox" ? e.checked : e.value])),
  };
}
function volcarFormulario(g) {
  if (!g) return;
  for (const e of form.elements) {
    if (!e.name || !(e.name in g) || typeof g[e.name] === "undefined") continue;
    if (e.type === "checkbox") e.checked = !!g[e.name]; else e.value = g[e.name];
  }
}
function validar(c) {
  const err = [];
  if (!c.filtros.origenes.length) err.push("Escribe dónde cargar (provincia o código postal).");
  for (const z of c.filtros.origenes.concat(c.filtros.destinos))
    if (z.provincia && !N.codProvincia(z.provincia)) err.push(`No reconozco la provincia «${z.provincia}». Escríbela como en Wtransnet (p. ej. Valencia, Alicante, A Coruña) o pon un código postal.`);
  if (c.filtros.fecha_final && N.fecha(c.filtros.fecha_final) < N.fecha(c.filtros.fecha_inicial)) err.push("La fecha «Hasta» es anterior a «Desde».");
  return err;
}

// ------------------------------------------------------------------ almacenamiento
const leer = (k) => chrome.storage.local.get(k).then((r) => r[k]);
const escribir = (k, val) => chrome.storage.local.set({ [k]: val });

// ------------------------------------------------------------------ Excel
async function descargarExcel(p) {
  const buf = await Excel.generar(p);
  const blob = new Blob([buf], { type: "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" });
  const f = new Date(), dd = (n) => String(n).padStart(2, "0");
  const nombre = `Wtransnet_cargas_camiones_${f.getFullYear()}${dd(f.getMonth() + 1)}${dd(f.getDate())}_${dd(f.getHours())}${dd(f.getMinutes())}.xlsx`;
  const a = document.createElement("a");
  a.href = URL.createObjectURL(blob); a.download = nombre;
  document.body.appendChild(a); a.click(); a.remove();
  setTimeout(() => URL.revokeObjectURL(a.href), 60000);
  log(`Excel descargado: ${nombre} (carpeta Descargas)`);
  return nombre;
}

// ------------------------------------------------------------------ ejecución
function botones(activo) {
  enMarcha = activo;
  for (const id of ["#btnPrueba", "#btnEjecutar", "#btnInspeccionar", "#btnReanudar", "#btnExcel"]) $(id).disabled = activo;
  $("#btnDetener").disabled = !activo;
  if (!activo) refrescarBotones();
}
async function refrescarBotones() {
  const p = await leer("progreso");
  $("#btnExcel").disabled = !p;
  $("#btnReanudar").disabled = !(p && p.estado && p.estado !== "completada");
}
async function lanzar({ prueba = false, reanudar = false } = {}) {
  const conf = leerFormulario();
  await escribir("criterios", conf._form);
  let previo = null;
  if (reanudar) {
    previo = await leer("progreso");
    if (!previo) return estado("No hay ninguna búsqueda guardada para reanudar.", "aviso");
  } else {
    const err = validar(conf);
    if (err.length) { estado("Faltan datos: " + err.join(" "), "aviso"); return; }
  }
  parar = false; botones(true);
  estado(reanudar ? "Reanudando…" : prueba ? "Prueba en marcha (1 carga + 1 camión)…" : "Buscando… puedes seguir usando otras pestañas.");
  const base = (await leer("baseUrl")) || BASE_POR_DEFECTO;
  const bot = new BotWT({ conf, log, parar: () => parar, guardar: (p) => escribir("progreso", p), base });
  try {
    const p = await bot.ejecutar(previo, prueba);
    await descargarExcel(p);
    const n = p.orden_cargas.length;
    const clase = p.estado === "completada" ? "" : "aviso";
    estado(`${p.estado === "completada" ? "Terminado" : "Parado: " + p.estado}. ${n} carga(s). Excel en tu carpeta Descargas.` +
      (p.estado.startsWith("interrumpida") ? " Cuando lo resuelvas, pulsa Reanudar." : ""), clase);
  } catch (e) {
    log("⚠ " + e.message);
    estado(e.message, "error");
  } finally { botones(false); }
}

$("#btnPrueba").onclick = () => lanzar({ prueba: true });
$("#btnEjecutar").onclick = () => lanzar();
$("#btnReanudar").onclick = () => lanzar({ reanudar: true });
$("#btnDetener").onclick = () => { parar = true; estado("Deteniendo… se guardará lo leído y se descargará el Excel.", "aviso"); };
$("#btnExcel").onclick = async () => { const p = await leer("progreso"); if (p) await descargarExcel(p); };
$("#btnInspeccionar").onclick = async () => {
  botones(true); estado("Revisando la página de Wtransnet (no se busca nada)…");
  const base = (await leer("baseUrl")) || BASE_POR_DEFECTO;
  const bot = new BotWT({ conf: leerFormulario(), log, parar: () => parar, guardar: async () => {}, base });
  try {
    const inf = await bot.inspeccionar();
    const lineas = [`INFORME WTRANSNET ${inf.fecha}`, "", "CABECERA:", (inf.cabecera || "").slice(0, 600), ""];
    for (const [n, pg] of Object.entries(inf.paginas)) {
      lineas.push(`== ${n} ==`);
      if (pg.filtros) for (const f of pg.filtros) lineas.push(`${f.item}: ${f.estado} ${f.tipo || ""} ${f.junto_a ? "[" + f.junto_a + "]" : ""} ${f.detalle || ""} ${f.opciones ? "{" + f.opciones.slice(0, 40).join(" | ") + "}" : ""}`);
      if ("filas" in pg) lineas.push(`filas=${pg.filas} columnas=${(pg.cabecera || []).join(" | ")} siguiente=${pg.siguiente} enlace=${pg.enlace}`);
      if (pg.etiquetas) for (const e of pg.etiquetas) lineas.push(`  ficha: [${e.seccion}] ${e.etiqueta} -> ${e.campo}`);
      if (pg.lectura) lineas.push("  LECTURA: " + JSON.stringify({ ...pg.lectura, id: "(oculto)", origen: (pg.lectura.origen || "").replace(/\d/g, "#"), destino: (pg.lectura.destino || "").replace(/\d/g, "#") }));
      if (pg.estructura) lineas.push("  ESTRUCTURA (números y emails ocultos):", ...pg.estructura.map((x) => "    " + x));
      lineas.push("");
    }
    $("#informe").value = lineas.join("\n");
    $("#zonaInforme").hidden = false;
    const malos = Object.values(inf.paginas).flatMap((p) => p.filtros || []).filter((f) => f.estado !== "localizado").length;
    estado(malos ? `Revisión hecha: ${malos} filtro(s) sin localizar. Copia el informe y pégalo en el chat.` : "Revisión hecha: todos los filtros localizados.", malos ? "aviso" : "");
  } catch (e) { log("⚠ " + e.message); estado(e.message, "error"); }
  finally { botones(false); }
};
$("#btnCopiarLog").onclick = async () => {
  const p = await leer("progreso");
  const inc = p ? p.incidencias.map((i) => `- ${i.tipo}: ${String(i.detalle).replace(/\d{6,}/g, "#")}`).join("\n") : "";
  await navigator.clipboard.writeText(`REGISTRO\n${$("#log").textContent}\nESTADO: ${p ? p.estado : "-"}\nINCIDENCIAS:\n${inc}`);
  estado("Registro copiado. Pégalo en el chat.");
};
$("#btnCopiar").onclick = async () => { await navigator.clipboard.writeText($("#informe").value); estado("Informe copiado. Pégalo en el chat."); };

form.addEventListener("change", () => escribir("criterios", leerFormulario()._form));
window.addEventListener("beforeunload", (e) => { if (enMarcha) { e.preventDefault(); e.returnValue = ""; } });

(async () => {
  const g = await leer("criterios");
  if (g && "zonas" in g) volcarFormulario(g); // criterios guardados con el formato actual
  const hoy = new Date();
  if (!v("desde") || v("desde") < isoDe(hoy)) form.elements.desde.value = isoDe(hoy);
  if (!v("hasta") || v("hasta") < v("desde")) form.elements.hasta.value = isoDe(new Date(hoy.getTime() + 2 * 86400000));
  refrescarBotones();
})();
