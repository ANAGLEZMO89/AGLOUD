// Excel con ExcelJS (incluido en vendor/, sin conexión a internet).
// Cabecera inmovilizada, autofiltro, texto ajustado, fechas reales, CP y teléfonos como texto,
// enlaces a fichas sin datos de sesión. Los textos de terceros se escriben como texto (nunca fórmulas).
"use strict";

const Excel = (() => {
  const AZUL = "FF1F4E78";
  const COLOR = { [Compat.COMPATIBLE]: "FFC6EFCE", [Compat.PENDIENTE]: "FFFFEB9C", [Compat.INCOMPATIBLE]: "FFFFC7CE", Descartado: "FFFFC7CE" };
  const LIMPIO = /[\u0000-\u0008\u000B\u000C\u000E-\u001F]/g;

  function fechaExcel(iso) {
    if (!iso) return null;
    const f = new Date(iso);
    return new Date(Date.UTC(f.getFullYear(), f.getMonth(), f.getDate(), f.getHours(), f.getMinutes()));
  }
  function poner(ws, fila, col, v) {
    const c = ws.getCell(fila, col);
    if (v === null || v === undefined || v === "" || (Array.isArray(v) && !v.length)) return c;
    if (typeof v === "boolean") v = v ? "Sí" : "No";
    if (v && v.__fecha) {
      c.value = fechaExcel(v.__fecha);
      const f = new Date(v.__fecha);
      c.numFmt = f.getHours() || f.getMinutes() ? "dd/mm/yyyy hh:mm" : "dd/mm/yyyy";
      return c;
    }
    if (typeof v === "number") { c.value = v; return c; }
    if (Array.isArray(v)) v = v.filter((x) => x !== null && x !== "").join("\n");
    c.value = String(v).replace(LIMPIO, "").slice(0, 32000); // cadena: ExcelJS nunca la trata como fórmula
    c.numFmt = "@";
    return c;
  }
  const F = (iso) => (iso ? { __fecha: iso } : null);
  function enlace(ws, fila, col, url, texto) {
    url = Ficha.urlLimpia(url || "");
    if (!url) return;
    const c = ws.getCell(fila, col);
    c.value = { text: texto || "Abrir ficha", hyperlink: url };
    c.font = { color: { argb: "FF0563C1" }, underline: true };
  }
  function hoja(wb, nombre, cols, anchos = {}) {
    const ws = wb.addWorksheet(nombre, { views: [{ state: "frozen", xSplit: 1, ySplit: 1 }] });
    cols.forEach((t, i) => {
      const c = ws.getCell(1, i + 1);
      c.value = t;
      c.font = { bold: true, color: { argb: "FFFFFFFF" } };
      c.fill = { type: "pattern", pattern: "solid", fgColor: { argb: AZUL } };
      c.alignment = { wrapText: true, vertical: "middle" };
      ws.getColumn(i + 1).width = anchos[t] || Math.max(12, Math.min(40, t.length + 4));
    });
    ws.getRow(1).height = 32;
    return ws;
  }
  function cerrar(ws, ncols) {
    ws.autoFilter = { from: { row: 1, column: 1 }, to: { row: Math.max(ws.rowCount, 1), column: ncols } };
    ws.eachRow((row, n) => { if (n > 1) row.eachCell((c) => { if (!c.value || !c.value.hyperlink) c.alignment = { wrapText: true, vertical: "top" }; }); });
  }
  const ubic = (u) => (u ? u.texto : "");
  const sn = (v) => (v === true ? "Sí" : v === false ? "No" : "No consta");
  const fuente = (r) => (r.contacto_fuente === "oferta" ? "Contacto de la oferta" : r.contacto_fuente === "empresa" ? "Contacto GENERAL de la empresa (ficha de empresa), no de la oferta" : "Sin contacto visible");

  const COLS = ["ID (red#nº)", "Red", "Nº oferta", "Enlace ficha", "Leído el", "Modificada", "Disponibilidad (texto)", "Disponible desde", "Disponible hasta",
    "Hora límite", "Fecha descarga", "Origen (texto)", "Origen país", "Origen provincia", "Origen CP", "Origen localidad", "Origen ámbito",
    "Destino (texto)", "Destino país", "Destino provincia", "Destino CP", "Destino localidad", "Destino ámbito", "Tipo de bolsa", "Vehículo",
    "Especialidad", "Peso (texto)", "Peso kg", "Volumen (texto)", "Volumen m3", "Largo (texto)", "Ancho (texto)", "Alto (texto)", "Forma de carga",
    "Mercancía / características", "ADR", "Plataforma elevadora", "Doble conductor", "Otros equipamientos", "Observaciones (texto original)",
    "Nº viajes", "Ida y vuelta", "Distancia (si consta)", "Precio (texto)", "Precio valor", "Moneda", "Plazo de pago", "Forma de pago",
    "Comentarios de pago", "Código empresa", "Empresa", "Persona de contacto", "Teléfonos", "Móviles", "Emails", "Fuente del contacto",
    "Advertencias de contacto", "Actividad empresarial", "Advertencias", "Otros datos de la ficha"];
  const ANCHOS = { "Observaciones (texto original)": 60, "Otros datos de la ficha": 60, Advertencias: 45, "Advertencias de contacto": 45,
    "Origen (texto)": 30, "Destino (texto)": 30, Empresa: 30, "Mercancía / características": 35, "Motivo de la coincidencia": 70,
    "Información pendiente": 70, Detalle: 90, Valor: 90, Concepto: 42 };

  function filaOferta(r) {
    const c = r.campos, o = r.origen || {}, d = r.destino || {};
    return [r.id, r.red || "No indicada", r.numero_oferta, null, F(r.leido_en), r.fecha_modificacion ? F(r.fecha_modificacion) : c.fecha_modificacion,
      c.disponibilidad, F(r.disp_desde), F(r.disp_hasta), r.hora_limite || c.hora_limite, r.fecha_descarga ? F(r.fecha_descarga) : c.fecha_descarga,
      ubic(o), o.pais || o.pais_iso, o.provincia || o.provincia_cod, o.cp, o.localidad, o.ambito, ubic(d), d.pais || d.pais_iso,
      d.provincia || d.provincia_cod, d.cp, d.localidad, d.ambito, c.tipo_bolsa, c.vehiculo, c.especialidad, r.peso.original, r.peso.valor,
      r.volumen.original, r.volumen.valor, r.largo.original, r.ancho.original, r.alto.original, c.forma_carga, c.mercancia,
      c.adr || sn(r.adr), c.plataforma_elevadora || sn(r.plataforma_elevadora), c.doble_conductor || sn(r.doble_conductor), c.equipamiento,
      c.observaciones, c.num_viajes, c.ida_y_vuelta, c.distancia, r.precio.original, r.precio.valor, r.precio.moneda || c.moneda, c.plazo_pago,
      c.forma_pago, c.comentarios_pago, r.empresa_codigo, r.empresa_nombre, r.contacto, r.telefonos, r.moviles, r.emails, fuente(r),
      r.avisos_contacto, c.actividad, r.avisos, r.otros_datos];
  }
  function escribirOfertas(ws, regs) {
    const ie = COLS.indexOf("Enlace ficha") + 1;
    regs.forEach((r, i) => {
      filaOferta(r).forEach((v, j) => { if (j + 1 !== ie) poner(ws, i + 2, j + 1, v); });
      enlace(ws, i + 2, ie, r.url);
    });
  }
  function criteriosTxt(obj) {
    return Object.entries(obj || {}).filter(([, v]) => !(v === null || v === "" || (Array.isArray(v) && !v.length)))
      .map(([k, v]) => `${k}: ${Array.isArray(v) ? v.map((x) => (typeof x === "object" ? Object.values(x).filter(Boolean).join(" / ") : x)).join(" + ") : v}`).join("\n") || "(ninguno)";
  }
  function posiblesDuplicados(regs) {
    const vistos = new Map(), out = [];
    for (const r of regs) {
      const h = [N.clave(r.empresa_nombre), N.clave(r.origen.texto), N.clave(r.destino.texto), r.disp_desde ? r.disp_desde.slice(0, 10) : ""];
      if (!h.every(Boolean)) continue;
      const k = h.join("|");
      if (vistos.has(k) && vistos.get(k) !== r.id) out.push([vistos.get(k), r.id]);
      else vistos.set(k, r.id);
    }
    return out;
  }

  async function generar(p) {
    const wb = new ExcelJS.Workbook();
    wb.creator = "Buscador Wtransnet";
    const cargas = p.orden_cargas.map((i) => p.cargas[i]).filter(Boolean);
    const camiones = [], visto = new Set();
    for (const cid of p.orden_cargas) for (const rel of p.relaciones[cid] || []) if (!visto.has(rel.camion) && p.camiones[rel.camion]) { visto.add(rel.camion); camiones.push(p.camiones[rel.camion]); }
    const rels = [];
    for (const cid of p.orden_cargas) for (const rel of p.relaciones[cid] || []) if (rel.estado !== "Descartado") rels.push([cid, rel]);
    const nComp = rels.filter(([, r]) => r.estado === Compat.COMPATIBLE).length, nPend = rels.filter(([, r]) => r.estado === Compat.PENDIENTE).length;
    const nDesc = p.orden_cargas.reduce((s, cid) => s + (p.relaciones[cid] || []).filter((r) => r.estado === "Descartado").length, 0);
    const empresas = new Set(camiones.map((t) => N.clave(t.empresa_codigo || t.empresa_nombre)).filter(Boolean));

    let ws = hoja(wb, "Resumen", ["Concepto", "Valor"], ANCHOS);
    const filas = [
      ["Fecha y hora de ejecución", F(p.inicio)], ["Fecha y hora de exportación", F(new Date().toISOString())], ["Estado de la ejecución", p.estado],
      ["Cuenta visible en la sesión", p.empresa_sesion || "No verificada"],
      ["Criterios solicitados (filtros Wtransnet)", criteriosTxt(p.criterios.filtros)], ["Comprobaciones posteriores del bot", criteriosTxt(p.criterios.comprobaciones)],
      ["Límites", criteriosTxt(p.criterios.limites)], ["Filtros aplicados y verificados en el formulario de cargas", (p.filtros_aplicados.carga || []).join("\n")],
      ["Cargas incluidas", cargas.length], ["Cargas leídas y excluidas por comprobaciones", p.descartadas_carga.length],
      ["Ofertas de camión en relaciones", camiones.length], ["Empresas distintas de camión", empresas.size],
      ["Vehículos identificados", "No calculado: las fichas no identifican vehículos de forma inequívoca (matrícula)"],
      ["Relaciones compatibles según datos publicados", nComp], ["Relaciones pendientes de confirmar", nPend],
      ["Candidatos descartados por incompatibilidad", `${nDesc} (ver hoja Incidencias)`], ["Incidencias registradas", p.incidencias.length],
      ["Puntuación interna", "Cobertura (0-100) = suma de pesos de criterios compatibles. Pesos: fecha 20, origen 20, destino 15, vehículo 15, capacidad 15, ADR/equipamiento 5, forma de carga 5, comentarios 5. Información (0-100) = % de 7 datos clave publicados por el camión. Es una puntuación interna, NO una probabilidad de contratación."],
      ["Advertencias", "Coincidir en provincia no demuestra proximidad. Solapar fechas no garantiza llegada a tiempo. No se calculan kilómetros en vacío. Un mismo camión puede aparecer en varias cargas y no está reservado. La flota declarada no acredita disponibilidad. Una oferta de camión no demuestra que el contacto sea el conductor ni que el vehículo sea propio. Los precios «0» se conservan literalmente y su significado no está confirmado."],
      ["Acciones realizadas en Wtransnet", "Sólo búsqueda, navegación y lectura. Ninguna publicación, contacto, contratación, marcado de interés ni cambio de configuración."],
    ];
    filas.forEach(([k, v], i) => { poner(ws, i + 2, 1, k).font = { bold: true }; poner(ws, i + 2, 2, v); });
    cerrar(ws, 2);

    ws = hoja(wb, "Cargas", COLS, ANCHOS); escribirOfertas(ws, cargas); cerrar(ws, COLS.length);
    ws = hoja(wb, "Camiones", COLS, ANCHOS); escribirOfertas(ws, camiones); cerrar(ws, COLS.length);

    const colsM = ["ID carga", "ID camión", "Posición en la carga", "Estado general", "Cobertura (interna)", "Información (interna)", "Ruta carga", "Fechas carga",
      "Ruta camión", "Fechas camión", "Empresa carga", "Contacto carga", "Teléfonos carga", "Emails carga", "Avisos contacto carga", "Empresa camión",
      "Contacto camión", "Teléfonos camión", "Emails camión", "Fuente contacto camión", "Avisos contacto camión",
      ...Object.keys(Compat.PESOS).map((k) => Compat.NOMBRES[k]), "Información pendiente", "Motivo de la coincidencia", "Advertencias", "Ficha carga", "Ficha camión"];
    ws = hoja(wb, "Matching", colsM, ANCHOS);
    ws.views = [{ state: "frozen", xSplit: 2, ySplit: 1 }];
    rels.slice(0, 100).forEach(([cid, rel], i) => {
      const ca = p.cargas[cid], cm = p.camiones[rel.camion], f = i + 2;
      const vals = [cid, rel.camion, rel.posicion, rel.estado, rel.cobertura, rel.informacion, `${ubic(ca.origen)} → ${ubic(ca.destino)}`, ca.campos.disponibilidad,
        `${ubic(cm.origen)} → ${ubic(cm.destino)}`, cm.campos.disponibilidad, ca.empresa_nombre, ca.contacto, ca.telefonos.concat(ca.moviles), ca.emails,
        ca.avisos_contacto, cm.empresa_nombre, cm.contacto, cm.telefonos.concat(cm.moviles), cm.emails, fuente(cm), cm.avisos_contacto,
        ...Object.keys(Compat.PESOS).map((k) => rel.criterios[k].estado), rel.pendiente, rel.motivo, rel.advertencias];
      vals.forEach((v, j) => {
        const c = poner(ws, f, j + 1, v);
        if (typeof v === "string" && COLOR[v]) c.fill = { type: "pattern", pattern: "solid", fgColor: { argb: COLOR[v] } };
      });
      enlace(ws, f, vals.length + 1, ca.url);
      enlace(ws, f, vals.length + 2, cm.url);
    });
    cerrar(ws, colsM.length);

    ws = hoja(wb, "Incidencias", ["Momento", "Tipo", "Oferta", "Detalle", "Enlace"], ANCHOS);
    const inc = p.incidencias.slice();
    for (const cid of p.orden_cargas) for (const rel of p.relaciones[cid] || []) if (rel.estado === "Descartado")
      inc.push({ tipo: "Candidato descartado", oferta: `${cid} ↔ ${rel.camion}`, detalle: rel.descarte, enlace: (p.camiones[rel.camion] || {}).url });
    for (const [a, b] of posiblesDuplicados(cargas).concat(posiblesDuplicados(Object.values(p.camiones))))
      inc.push({ tipo: "Posible duplicado (no fusionado)", oferta: `${a} / ${b}`, detalle: "Misma empresa, origen, destino y fecha: posible misma oferta en otra red o republicada" });
    inc.forEach((x, i) => { poner(ws, i + 2, 1, F(x.momento)); poner(ws, i + 2, 2, x.tipo); poner(ws, i + 2, 3, x.oferta); poner(ws, i + 2, 4, x.detalle); enlace(ws, i + 2, 5, x.enlace, "Abrir"); });
    cerrar(ws, 5);
    return wb.xlsx.writeBuffer();
  }
  return { generar };
})();
