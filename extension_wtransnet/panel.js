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
const lineasUbic = (t) => t.split("\n").map((l) => l.split(";").map((x) => x.trim())).filter((p) => p.some(Boolean))
  .map((p) => ({ pais: p[0] || "", provincia: p[1] || "", codigo_postal: p[2] || "", localidad: p[3] || "" }));
const marcados = (n) => [...form.querySelectorAll(`input[name=${n}]:checked`)].map((i) => i.value);
const v = (n) => (form.elements[n].value || "").trim();

function leerFormulario() {
  return {
    filtros: {
      origenes: lineasUbic(v("origenes")), destinos: lineasUbic(v("destinos")), ambito_origen: v("ambito_origen"), ambito_destino: v("ambito_destino"),
      fecha_inicial: v("fecha_inicial"), fecha_final: v("fecha_final"), tipo_bolsa: marcados("tipo_bolsa"), redes: marcados("redes"),
      ida_y_vuelta: v("ida_y_vuelta"), tipo_vehiculo: v("tipo_vehiculo"), especialidad: v("especialidad"), misma_especialidad: v("misma_especialidad"),
      forma_carga: marcados("forma_carga"), adr: v("adr"), doble_conductor: v("doble_conductor"), plataforma_elevadora: v("plataforma_elevadora"),
      cargas_urgentes: form.elements.cargas_urgentes.checked,
    },
    comprobaciones: { peso_minimo_kg: v("peso_minimo_kg"), peso_maximo_kg: v("peso_maximo_kg"), largo_maximo_m: v("largo_maximo_m"),
      volumen_maximo_m3: v("volumen_maximo_m3"), descartar_no_vigentes: true, ficha_empresa_si_falta_contacto: form.elements.ficha_empresa.checked },
    camiones: { dias_antes: Number(v("dias_antes") || 1), filtrar_destino: form.elements.filtrar_destino.checked, max_fichas: Number(v("max_fichas") || 20) },
    limites: { max_cargas: Math.min(10, Math.max(1, Number(v("max_cargas") || 10))), max_camiones: Math.min(10, Math.max(1, Number(v("max_camiones") || 10))),
      empresas_distintas: form.elements.empresas_distintas.checked, max_paginas: Math.min(5, Math.max(1, Number(v("max_paginas") || 3))) },
    equivalencias: v("equivalencias").split("\n").map((l) => l.split("=").map((x) => x.trim())).filter((p) => p[0] && p[1])
      .map(([carga, camion]) => ({ carga, camion, resultado: "compatible" })),
    empresa_esperada: v("empresa_esperada"),
    ritmo: { min: Math.max(2, Number(v("pausa_min") || 3)), max: Math.max(Number(v("pausa_min") || 3) + 1, Number(v("pausa_max") || 6)) },
    _form: Object.fromEntries([...form.elements].filter((e) => e.name).map((e) => [e.name + "|" + (e.type === "checkbox" ? e.value : ""),
      e.type === "checkbox" ? e.checked : e.value])),
  };
}
function volcarFormulario(guardado) {
  if (!guardado) return;
  for (const e of form.elements) {
    if (!e.name) continue;
    const k = e.name + "|" + (e.type === "checkbox" ? e.value : "");
    if (!(k in guardado)) continue;
    if (e.type === "checkbox") e.checked = guardado[k]; else e.value = guardado[k];
  }
}
function validar(c) {
  const err = [];
  if (!c.filtros.origenes.length) err.push("Falta al menos un ORIGEN (país y provincia). No elijo rutas por ti.");
  if (!c.filtros.fecha_inicial) err.push("Falta la FECHA INICIAL.");
  for (const k of ["fecha_inicial", "fecha_final"]) { try { N.fechaFormulario(c.filtros[k]); } catch (e) { err.push(e.message); } }
  if (!c.filtros.tipo_bolsa.length) err.push("Marca al menos un TIPO DE BOLSA.");
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

(async () => { volcarFormulario(await leer("criterios")); refrescarBotones(); })();
