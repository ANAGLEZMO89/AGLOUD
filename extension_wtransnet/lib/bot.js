// Orquestador: cargas → fichas → camiones por carga → compatibilidad → progreso.
// Trabaja sobre TU pestaña de Wtransnet (sesión ya iniciada). Sólo busca y lee.
"use strict";

class SesionInterrumpida extends Error {}
class Detenido extends Error {}
class FiltroNoAplicable extends Error {}

const URLS_WT = (base) => ({
  buscar_carga: base + "servlet/central?URL=/servlet/fhoOfertas%3Faccion=form%26cgcm=CG%26opcion=10%26nueva=s",
  buscar_camion: base + "servlet/central?URL=/servlet/fhoOfertas%3Faccion=form%26cgcm=CM%26opcion=10%26nueva=s",
  todas_cargas: base + "servlet/central?URL=/servlet/fhoOfertas%3Faccion=listar%26cgcm=CG%26opcion=61%26ntimes=1",
  todos_camiones: base + "servlet/central?URL=/servlet/fhoOfertas%3Faccion=listar%26cgcm=CM%26opcion=61%26ntimes=1",
});

// Mismas acciones prohibidas que el agente, como reglas de bloqueo de red de la pestaña durante la ejecución.
const FILTROS_BLOQUEO = ["accion=alta", "accion=grabar", "accion=guardar", "accion=borrar", "accion=eliminar", "accion=enviar",
  "accion=contratar", "accion=aceptar", "accion=interes", "accion=archivar", "accion=ofertar", "accion=publicar", "accion=modificar",
  "accion=baja", "accion=reservar", "mensaj", "/chat", "whatsapp", "wa.me", "archivar", "garantia", "documentacion"];

class BotWT {
  constructor({ conf, log, parar, guardar, base }) {
    this.conf = conf; this.log = log; this.parar = parar; this.guardarProgreso = guardar;
    this.base = base; this.origen = new URL(base).origin; this.urls = URLS_WT(base);
    this.tabId = null; this.p = null;
  }

  // ------------------------------------------------------------------ utilidades
  comprobarParada() { if (this.parar()) throw new Detenido("Ejecución detenida por el usuario"); }
  async dormir(ms) { const fin = Date.now() + ms; while (Date.now() < fin) { this.comprobarParada(); await new Promise((r) => setTimeout(r, Math.min(200, fin - Date.now()))); } }
  async pausa(f = 1) { const r = this.conf.ritmo; await this.dormir(1000 * f * (r.min + Math.random() * (r.max - r.min))); }
  incidencia(tipo, detalle, oferta = "", enlace = "") {
    this.p.incidencias.push({ momento: new Date().toISOString(), tipo, oferta, detalle, enlace: Ficha.urlLimpia(enlace) });
    this.log(`Incidencia [${tipo}] ${oferta}: ${String(detalle).slice(0, 200)}`);
  }
  async guardar() { await this.guardarProgreso(this.p); }

  async agente(orden, args, frameId) {
    const target = frameId === undefined ? { tabId: this.tabId, allFrames: true } : { tabId: this.tabId, frameIds: [frameId] };
    // Los clics en enlaces «javascript:» de la propia web sólo funcionan desde el contexto de la página (MAIN).
    // Las barreras de seguridad se aplican igual, porque viajan dentro de la función.
    const world = ["clicFila", "volver", "paginar"].includes(orden) ? "MAIN" : "ISOLATED";
    const res = await chrome.scripting.executeScript({ target, func: agenteWT, args: [orden, args || {}], world });
    return res.map((r) => ({ frameId: r.frameId, ...(r.result || { ok: false, e: "sin respuesta" }) }));
  }
  async uno(orden, args, frameId) {
    const [r] = await this.agente(orden, args, frameId);
    if (!r || !r.ok) throw new Error(r ? r.e : "El marco ya no existe");
    return r.v;
  }
  async estados() {
    const tab = await chrome.tabs.get(this.tabId);
    if (!tab.url || !tab.url.startsWith(this.origen)) throw new SesionInterrumpida(`La pestaña ha salido de Wtransnet (${(tab.url || "").slice(0, 80)}). Vuelve a abrir Wtransnet y pulsa Reanudar.`);
    const est = (await this.agente("estado")).filter((x) => x.ok).map((x) => ({ frameId: x.frameId, ...x.v }));
    for (const e of est) for (const d of e.dialogos || []) this.log(`Wtransnet mostró una ventana (${d.tipo}): «${d.msg}»`);
    for (const e of est) {
      if (e.cap) throw new SesionInterrumpida("Aparece un CAPTCHA. Resuélvelo tú en la pestaña de Wtransnet y pulsa Reanudar.");
      if (e.pwd || e.cad) throw new SesionInterrumpida("Wtransnet pide iniciar sesión o la sesión ha caducado. Inicia sesión tú y pulsa Reanudar.");
    }
    return est;
  }
  async esperar(cond, ms = 45000, desc = "la página") {
    const fin = Date.now() + ms;
    while (Date.now() < fin) {
      this.comprobarParada();
      try {
        const tab = await chrome.tabs.get(this.tabId);
        if (tab.status === "complete") { const est = await this.estados(); const r = cond(est); if (r) return r; }
      } catch (e) { if (e instanceof SesionInterrumpida || e instanceof Detenido) throw e; }
      await new Promise((r) => setTimeout(r, 500));
    }
    throw new Error(`Tiempo de espera agotado esperando ${desc}.`);
  }
  async ir(url) {
    this.comprobarParada();
    await chrome.tabs.update(this.tabId, { url });
    await new Promise((r) => setTimeout(r, 800));
    return this.esperar((est) => est.length && est.every((e) => e.listo === "complete") && est, 45000, "que cargue Wtransnet");
  }
  marcoFormulario(est) {
    const c = est.filter((e) => e.buscar).sort((a, b) => b.nctr - a.nctr);
    return c.length ? c[0].frameId : null;
  }

  // ------------------------------------------------------------------ bloqueo de red (sólo esta pestaña y sólo mientras trabaja)
  async activarBloqueo() {
    try {
      const ids = FILTROS_BLOQUEO.map((_, i) => i + 1);
      await chrome.declarativeNetRequest.updateSessionRules({ removeRuleIds: ids, addRules: FILTROS_BLOQUEO.map((f, i) => ({ id: i + 1, priority: 1,
        action: { type: "block" }, condition: { urlFilter: f, isUrlFilterCaseSensitive: false, tabIds: [this.tabId] } })) });
    } catch (e) { this.log("Aviso: no se pudo activar el bloqueo de red adicional: " + e.message); }
  }
  async activarSinDialogos() {
    try { await chrome.scripting.unregisterContentScripts({ ids: ["wt-sin-dialogos"] }); } catch (e) { /* no estaba */ }
    await chrome.scripting.registerContentScripts([{ id: "wt-sin-dialogos", matches: [this.origen + "/*"], js: ["lib/sin_dialogos.js"],
      runAt: "document_start", allFrames: true, world: "MAIN", persistAcrossSessions: false }]);
    try { await chrome.scripting.executeScript({ target: { tabId: this.tabId, allFrames: true }, files: ["lib/sin_dialogos.js"], world: "MAIN" }); }
    catch (e) { this.log("Aviso: si la pestaña de Wtransnet tiene una ventana «Aceptar» abierta, ciérrala tú una vez."); }
  }
  async quitarSinDialogos() { try { await chrome.scripting.unregisterContentScripts({ ids: ["wt-sin-dialogos"] }); } catch (e) { /* nada */ } }
  async quitarBloqueo() { try { await chrome.declarativeNetRequest.updateSessionRules({ removeRuleIds: FILTROS_BLOQUEO.map((_, i) => i + 1) }); } catch (e) { /* nada */ } }

  // ------------------------------------------------------------------ API
  async ejecutar(progresoPrevio, prueba) {
    const tabs = await chrome.tabs.query({ url: this.origen + "/*" });
    if (!tabs.length) throw new SesionInterrumpida("No encuentro ninguna pestaña de Wtransnet abierta. Abre Wtransnet, inicia sesión y vuelve a pulsar el botón.");
    this.tabId = tabs[0].id;
    this.p = progresoPrevio || {
      inicio: new Date().toISOString(), estado: "en curso", empresa_sesion: "", filtros_aplicados: {},
      criterios: { filtros: this.conf.filtros, comprobaciones: this.conf.comprobaciones, camiones: this.conf.camiones,
        limites: prueba ? { ...this.conf.limites, max_cargas: 1, max_camiones: this.conf.limites.max_camiones ? 1 : 0 } : this.conf.limites, equivalencias: this.conf.equivalencias },
      cargas: {}, orden_cargas: [], camiones: {}, relaciones: {}, incidencias: [], cargas_completadas: [], descartadas_carga: [], filas_vistas: [],
    };
    if (progresoPrevio) {
      this.conf = { ...this.conf, filtros: this.p.criterios.filtros, comprobaciones: this.p.criterios.comprobaciones, camiones: this.p.criterios.camiones, equivalencias: this.p.criterios.equivalencias };
      this.log("Reanudando la ejecución guardada con sus criterios originales.");
    }
    const lim = this.p.criterios.limites;
    this.p.estado = "en curso";
    await this.activarBloqueo();
    await this.activarSinDialogos();
    try {
      this.log(`Trabajando en la pestaña de Wtransnet: ${Ficha.urlLimpia(tabs[0].url).slice(0, 90)}`);
      await this.verificarCuenta();
      if (this.p.orden_cargas.length < lim.max_cargas) await this.buscarCargas(lim.max_cargas);
      for (const cid of this.p.orden_cargas.slice()) {
        if (this.p.cargas_completadas.includes(cid)) continue;
        if (!lim.max_camiones) { this.p.cargas_completadas.push(cid); continue; }
        try { await this.camionesPara(cid, lim); }
        catch (e) {
          if (e instanceof Detenido || e instanceof SesionInterrumpida) throw e;
          this.incidencia("Búsqueda de camiones fallida", e.message, cid);
        }
        this.p.cargas_completadas.push(cid);
        await this.guardar();
      }
      this.p.estado = "completada";
    } catch (e) {
      if (e instanceof Detenido) { this.p.estado = "detenida por el usuario (resultados parciales)"; this.incidencia("Detención", e.message); }
      else if (e instanceof SesionInterrumpida) { this.p.estado = "interrumpida: requiere tu intervención (resultados parciales)"; this.incidencia("Sesión", e.message); }
      else if (e instanceof FiltroNoAplicable) { this.p.estado = "detenida: filtro no aplicable"; this.incidencia("Filtro", e.message); }
      else { this.p.estado = "error"; this.incidencia("Error", e.message || String(e)); console.error(e); }
    } finally {
      await this.quitarBloqueo();
      await this.quitarSinDialogos();
      await this.guardar();
    }
    return this.p;
  }

  async verificarCuenta() {
    const esperada = (this.conf.empresa_esperada || "").trim();
    let texto = (await this.estados()).map((e) => e.texto).join("\n");
    if (esperada && !N.clave(texto).includes(N.clave(esperada))) {
      await this.ir(this.urls.buscar_carga);
      texto = (await this.estados()).map((e) => e.texto).join("\n");
    }
    if (esperada) {
      if (!N.clave(texto).includes(N.clave(esperada))) throw new SesionInterrumpida(`La empresa «${esperada}» no aparece en la sesión abierta. Revisa con qué cuenta has entrado antes de continuar.`);
      this.p.empresa_sesion = `${esperada} (verificada en la página)`;
    } else {
      this.p.empresa_sesion = "No verificada (indica la empresa esperada en Ajustes)";
      this.log("Aviso: no has indicado la empresa esperada; no se verifica la cuenta.");
    }
  }

  // ------------------------------------------------------------------ formulario
  async op(op) {
    const est = await this.estados();
    const f = this.marcoFormulario(est);
    if (f === null) throw new FiltroNoAplicable("No encuentro el formulario de búsqueda con botón «Buscar».");
    let r, ultimo;
    for (let intento = 0; intento < 6; intento++) {
      try { r = await this.uno("op", { op }, this.marcoFormulario(await this.estados()) ?? f); ultimo = null; break; }
      catch (e) {
        ultimo = e;
        // p. ej. las provincias se cargan después de elegir el país: se espera y se reintenta
        if (!/no coincide literalmente|No encuentro/.test(e.message)) break;
        await this.dormir(1000);
      }
    }
    if (ultimo) throw new FiltroNoAplicable(ultimo.message);
    await new Promise((res) => setTimeout(res, 400));
    await this.esperar((e) => e.length && e.every((x) => x.listo === "complete") && e, 20000, "el formulario");
    return r;
  }
  async pulsar(texto, accion, bloque) {
    const f = this.marcoFormulario(await this.estados());
    if (f === null) throw new FiltroNoAplicable("No encuentro el formulario de búsqueda.");
    const r = await this.uno("pulsar", { texto, accion, bloque }, f).catch((e) => { throw new FiltroNoAplicable(e.message); });
    this.log(r);
    return r;
  }
  async aplicarFiltros(f, tipo) {
    const ap = [];
    const add = async (op) => { if (op.valor === undefined || op.valor === null || op.valor === "" || (Array.isArray(op.valor) && !op.valor.length)) return; ap.push(await this.op(op)); };
    const tablaProv = N.PROV_POR_NOMBRE, tablaPais = N.PAIS_POR_NOMBRE;
    await add({ tipo: "texto", campo: "fecha_inicial", valor: N.fechaFormulario(f.fecha_inicial) });
    await add({ tipo: "texto", campo: "fecha_final", valor: N.fechaFormulario(f.fecha_final) });
    await add({ tipo: "casillas", campo: "tipo_bolsa", valor: f.tipo_bolsa });
    if (tipo === "carga") await add({ tipo: "casillas", campo: "redes", valor: f.redes });
    await add({ tipo: "opcion", campo: "ida_y_vuelta", valor: f.ida_y_vuelta });
    await add({ tipo: "select", campo: "tipo_vehiculo", valor: f.tipo_vehiculo });
    await add({ tipo: "select", campo: "especialidad", valor: f.especialidad });
    if (f.misma_especialidad === "si" || f.misma_especialidad === "no") await add({ tipo: "casilla", campo: "misma_especialidad", valor: f.misma_especialidad === "si" });
    if (tipo === "camion") { await add({ tipo: "opcion", campo: "sirve_frigorifico", valor: f.sirve_frigorifico }); await add({ tipo: "opcion", campo: "sirve_lateral_bajo", valor: f.sirve_lateral_bajo }); }
    await add({ tipo: "casillas", campo: "forma_carga", valor: f.forma_carga });
    await add({ tipo: "opcion", campo: "adr", valor: f.adr });
    await add({ tipo: "opcion", campo: "doble_conductor", valor: f.doble_conductor });
    await add({ tipo: "opcion", campo: "plataforma_elevadora", valor: f.plataforma_elevadora });
    if (tipo === "carga" && f.cargas_urgentes) await add({ tipo: "casilla", campo: "cargas_urgentes", valor: true });
    for (const [bloque, lista, amb] of [["origen", f.origenes || [], f.ambito_origen], ["destino", f.destinos || [], f.ambito_destino]]) {
      for (const u of lista) {
        const pais = u.pais || u.pais_iso || "", prov = u.provincia || u.provincia_cod || "";
        await add({ tipo: "select", campo: "pais", bloque, valor: pais, cod: u.pais_iso || N.codPais(pais), tabla: tablaPais });
        await add({ tipo: "select", campo: "provincia", bloque, valor: prov, cod: u.provincia_cod || N.codProvincia(prov), tabla: tablaProv });
        await add({ tipo: "texto", campo: "codigo_postal", bloque, valor: u.codigo_postal });
        await add({ tipo: "texto", campo: "localidad", bloque, valor: u.localidad });
        if (lista.length > 1) { await this.pulsar("Anotar", "anotar", bloque); await this.dormir(800); }
      }
      if (amb) await add({ tipo: "select", campo: "ambito", bloque, valor: amb });
    }
    return ap;
  }
  async buscar(tipo, filtros) {
    await this.ir(this.urls[tipo === "carga" ? "buscar_carga" : "buscar_camion"]);
    const ap = await this.aplicarFiltros(filtros, tipo);
    this.log(`Filtros aplicados y verificados (${tipo}): ${ap.join("; ")}`);
    await this.pulsar("Buscar", "buscar");
    await this.dormir(1500);
    await this.esperar((est) => est.some((e) => e.filas > 0 || e.sin_resultados) && est.every((e) => e.listo === "complete") && !est.some((e) => e.buscar && e.filas === 0 && !e.sin_resultados && e.nctr > 10) && est,
      60000, "los resultados de la búsqueda");
    return ap;
  }

  // ------------------------------------------------------------------ listados y fichas
  async listado() {
    const res = (await this.agente("listado")).filter((r) => r.ok);
    res.sort((a, b) => b.v.filas.length - a.v.filas.length);
    return res.length ? { frameId: res[0].frameId, ...res[0].v } : { filas: [], siguiente: null };
  }
  async *recorrer(maxPaginas) {
    for (let pag = 0; pag < maxPaginas; pag++) {
      let l = await this.listado();
      if (!l.filas.length) { if (pag === 0) this.log("El listado no contiene ofertas" + (l.sin_resultados ? " (la página indica que no hay resultados)" : "")); return; }
      this.log(`Página ${pag + 1} del listado: ${l.filas.length} ofertas visibles ${l.total_texto || ""}`);
      for (let i = 0; i < l.filas.length; i++) {
        const act = await this.listado();
        if (i >= act.filas.length) break;
        yield { fila: act.filas[i], frameId: act.frameId };
      }
      l = await this.listado();
      if (l.siguiente === null || pag + 1 >= maxPaginas) return;
      const primera = l.filas[0] ? l.filas[0].texto_fila : "";
      this.log(await this.uno("paginar", { indice: l.siguiente }, l.frameId));
      await this.dormir(1000);
      await this.esperar((est) => est.some((e) => e.filas > 0 && e.primera !== primera) && est, 45000, "la página siguiente");
      await this.pausa();
    }
  }
  async descargar(url) {
    const r = await this.uno("descargar", { url }, 0);
    if (r.status >= 400) throw new Error(`La ficha devolvió el error ${r.status}`);
    const doc = new DOMParser().parseFromString(r.html, "text/html");
    return { doc, url: r.url };
  }
  async leerDocumento(url, profundidad = 0) {
    const { doc, url: final } = await this.descargar(url);
    const bruto = Ficha.extraer(doc);
    const marcos = [...doc.querySelectorAll("frame[src], iframe[src]")].map((f) => new URL(f.getAttribute("src"), final).href);
    if (bruto.kv.length < 3 && marcos.length && profundidad < 2) {
      let mejor = null;
      for (const m of marcos) {
        if (!m.startsWith(this.origen)) continue;
        const r = await this.leerDocumento(m, profundidad + 1);
        if (!mejor || r.bruto.kv.length > mejor.bruto.kv.length) mejor = r;
      }
      if (mejor) return { bruto: mejor.bruto, url: url };
    }
    return { bruto, url: final };
  }
  async leerOferta(fila, frameId, tipo) {
    this.comprobarParada();
    let bruto, url;
    if (fila.href) {
      ({ bruto, url } = await this.leerDocumento(fila.href));
    } else {
      this.log(await this.uno("clicFila", { indice: fila.indice_enlace }, frameId));
      await this.dormir(1000);
      await this.esperar((est) => est.every((e) => e.listo === "complete") && !est.some((e) => e.filas > 0) && est, 30000, "la ficha");
      const htmls = (await this.agente("html")).filter((r) => r.ok).map((r) => r.v);
      let mejor = null;
      for (const h of htmls) { const b = Ficha.extraer(new DOMParser().parseFromString(h.html, "text/html")); if (!mejor || b.kv.length > mejor.b.kv.length) mejor = { b, url: h.url }; }
      bruto = mejor.b; url = mejor.url;
      const f = (await this.estados()).find((e) => e.filas === 0 && e.frameId === frameId) ? frameId : 0;
      await this.uno("volver", {}, f).catch(() => this.uno("volver", {}, 0));
      await this.dormir(800);
      await this.esperar((est) => est.some((e) => e.filas > 0) && est, 30000, "volver al listado");
    }
    const reg = Ficha.interpretar(bruto, tipo, fila, url);
    const cb = this.conf.comprobaciones;
    if (!reg.contacto_fuente && cb.ficha_empresa_si_falta_contacto && reg.enlace_empresa) await this.fichaEmpresa(reg, url);
    await this.pausa();
    return reg;
  }
  async fichaEmpresa(reg, urlFicha) {
    try {
      const u = new URL(reg.enlace_empresa, urlFicha).href;
      if (!u.startsWith(this.origen) || /^javascript/i.test(reg.enlace_empresa)) return;
      await this.pausa(0.5);
      const { bruto, url } = await this.leerDocumento(u);
      const emp = Ficha.interpretar(bruto, "empresa", null, url);
      if (emp.telefonos.length || emp.moviles.length || emp.emails.length) {
        reg.telefonos = emp.telefonos; reg.moviles = emp.moviles; reg.emails = emp.emails;
        reg.contacto = reg.contacto || emp.contacto; reg.contacto_fuente = "empresa";
        reg.avisos.push("Contacto tomado de la ficha GENERAL de la empresa (la oferta no mostraba contacto)");
      }
      if (emp.campos.actividad && !reg.campos.actividad) reg.campos.actividad = emp.campos.actividad;
    } catch (e) {
      if (e instanceof Detenido || e instanceof SesionInterrumpida) throw e;
      this.incidencia("Ficha de empresa no leída", e.message, reg.id);
    }
  }

  // ------------------------------------------------------------------ cargas
  async buscarCargas(maximo) {
    // Una búsqueda por cada zona indicada (más fiable que «Anotar» varias)
    const zonas = (this.conf.filtros.origenes || []).length ? this.conf.filtros.origenes : [null];
    this.p.zonas_hechas = this.p.zonas_hechas || [];
    for (const zona of zonas) {
      if (this.p.orden_cargas.length >= maximo) break;
      const clave = JSON.stringify(zona);
      if (this.p.zonas_hechas.includes(clave)) continue;
      const filtros = { ...this.conf.filtros, origenes: zona ? [zona] : [] };
      if (zona) this.log(`Buscando cargas en: ${[zona.provincia, zona.codigo_postal, zona.localidad].filter(Boolean).join(" ") || zona.pais}`);
      this.p.filtros_aplicados.carga = (this.p.filtros_aplicados.carga || []).concat(await this.buscar("carga", filtros));
      await this.leerListadoCargas(maximo);
      this.p.zonas_hechas.push(clave);
      await this.guardar();
    }
    if (this.p.orden_cargas.length < maximo)
      this.incidencia("Menos cargas de las pedidas", `Se han obtenido ${this.p.orden_cargas.length} cargas válidas de ${maximo}: el listado no ofrecía más ofertas que cumplieran los filtros y comprobaciones dentro del límite de páginas.`);
  }
  async leerListadoCargas(maximo) {
    const vistos = new Set(this.p.filas_vistas);
    for await (const { fila, frameId } of this.recorrer(this.p.criterios.limites.max_paginas)) {
      if (this.p.orden_cargas.length >= maximo) break;
      const huella = fila.href || fila.texto_fila;
      if (vistos.has(huella)) continue;
      let reg = null;
      try { reg = await this.leerOferta(fila, frameId, "carga"); }
      catch (e) {
        if (e instanceof Detenido || e instanceof SesionInterrumpida) throw e;
        this.incidencia("Ficha de carga no leída", e.message, fila.texto_fila.slice(0, 80), fila.href);
      }
      vistos.add(huella); this.p.filas_vistas = [...vistos];
      if (!reg) { await this.guardar(); continue; }
      if (this.p.cargas[reg.id]) { this.incidencia("Duplicado", "La misma carga (red y número) aparece varias veces en el listado", reg.id); continue; }
      const motivo = this.comprobarCarga(reg);
      if (motivo) { this.p.descartadas_carga.push(reg.id); this.incidencia("Carga excluida por comprobación", motivo, reg.id, reg.url); }
      else {
        this.p.cargas[reg.id] = reg; this.p.orden_cargas.push(reg.id);
        this.log(`Carga ${this.p.orden_cargas.length}/${maximo}: ${reg.id} ${reg.origen.texto} → ${reg.destino.texto}`);
      }
      await this.guardar();
    }
  }
  comprobarCarga(r) {
    const cb = this.conf.comprobaciones, f = this.conf.filtros;
    const hoy = new Date(); hoy.setHours(0, 0, 0, 0);
    if (cb.descartar_no_vigentes && r.disp_hasta && new Date(r.disp_hasta) < hoy) return `No vigente: disponibilidad hasta ${N.fmtCorta(new Date(r.disp_hasta))}`;
    if (!r.disp_desde) r.avisos.push("Disponibilidad no legible en la ficha: vigencia sin comprobar");
    const p = r.peso.valor, num = (x) => (x === "" || x === null || x === undefined ? null : Number(String(x).replace(",", ".")));
    const pmax = num(cb.peso_maximo_kg), pmin = num(cb.peso_minimo_kg);
    if (p !== null) {
      if (pmax !== null && p > pmax) return `Peso ${r.peso.original} supera el máximo pedido (${pmax} kg)`;
      if (pmin !== null && p < pmin) return `Peso ${r.peso.original} inferior al mínimo pedido (${pmin} kg)`;
    } else if (pmax !== null || pmin !== null) r.avisos.push("Peso no publicado o sin unidad: límite de peso sin comprobar");
    for (const [dim, lk, nom] of [["largo", "largo_maximo_m", "Largo"], ["volumen", "volumen_maximo_m3", "Volumen"]]) {
      const l = num(cb[lk]);
      if (l === null) continue;
      if (r[dim].valor === null) r.avisos.push(`${nom} no publicado: límite sin comprobar`);
      else if (r[dim].valor > l) return `${nom} ${r[dim].original} supera el máximo pedido (${l})`;
    }
    for (const [k, campo, nom] of [["especialidad", "especialidad", "Especialidad"], ["tipo_vehiculo", "vehiculo", "Vehículo"]]) {
      const pub = r.campos[campo];
      if (f[k] && pub && N.clave(pub) !== N.clave(f[k])) r.avisos.push(`${nom} publicada «${pub}» distinta de la filtrada «${f[k]}» (Wtransnet la devolvió; revisar)`);
    }
    for (const [b, lista, amb] of [["origen", f.origenes, f.ambito_origen], ["destino", f.destinos, f.ambito_destino]]) {
      if (!lista || !lista.length || amb) continue;
      const pedidos = new Set(lista.map((u) => N.codProvincia(u.provincia || "")).filter(Boolean));
      const c = r[b].provincia_cod;
      if (pedidos.size && c && !pedidos.has(c)) return `${b} en provincia ${N.nombreProvincia(c)}, distinta de la solicitada`;
    }
    return "";
  }

  // ------------------------------------------------------------------ camiones
  filtrosCamion(carga) {
    const fc = this.conf.filtros, cam = this.conf.camiones;
    const ub = (u) => (u.pais || u.pais_iso ? { pais: u.pais || "", pais_iso: u.pais_iso, provincia: u.provincia || "", provincia_cod: u.provincia_cod } : null);
    const desde = carga.disp_desde ? new Date(carga.disp_desde) : null, hasta = carga.disp_hasta ? new Date(carga.disp_hasta) : desde;
    // Nunca una fecha anterior a hoy: Wtransnet la rechaza
    const hoy = new Date(); hoy.setHours(0, 0, 0, 0);
    let ini = desde ? new Date(desde.getTime() - 86400000 * (Number(cam.dias_antes) || 0)) : null;
    if (ini && ini < hoy) ini = hoy;
    const f = {
      origenes: [ub(carga.origen)].filter(Boolean), destinos: cam.filtrar_destino ? [ub(carga.destino)].filter(Boolean) : [],
      fecha_inicial: ini ? N.fmtCorta(ini) : fc.fecha_inicial,
      fecha_final: hasta ? N.fmtCorta(hasta < (ini || hoy) ? (ini || hoy) : hasta) : fc.fecha_final,
      tipo_bolsa: fc.tipo_bolsa, tipo_vehiculo: fc.tipo_vehiculo, especialidad: fc.especialidad, misma_especialidad: fc.misma_especialidad,
    };
    if (!f.origenes.length) throw new FiltroNoAplicable(`La carga ${carga.id} no tiene país de origen legible: no se puede buscar camión.`);
    return f;
  }
  async camionesPara(cid, lim) {
    const carga = this.p.cargas[cid], maximo = lim.max_camiones, tope = Number(this.conf.camiones.max_fichas) || 20;
    this.log(`Buscando camiones para ${cid}`);
    let filtros;
    try { filtros = this.filtrosCamion(carga); } catch (e) { this.incidencia("Búsqueda de camión no realizada", e.message, cid); return; }
    this.p.filtros_aplicados[cid] = await this.buscar("camion", filtros);
    await this.pausa();
    const rels = this.p.relaciones[cid] = this.p.relaciones[cid] || [];
    const ids = new Set(rels.map((r) => r.camion));
    const claveEmp = (t) => N.clave(t.empresa_codigo || t.empresa_nombre || "");
    const empresas = new Set(rels.filter((r) => r.estado !== "Descartado").map((r) => claveEmp(this.p.camiones[r.camion])).filter(Boolean));
    let leidas = 0;
    for await (const { fila, frameId } of this.recorrer(lim.max_paginas)) {
      if (rels.filter((r) => r.estado !== "Descartado").length >= maximo || leidas >= tope) break;
      let cam;
      try { cam = await this.leerOferta(fila, frameId, "camion"); leidas++; }
      catch (e) {
        if (e instanceof Detenido || e instanceof SesionInterrumpida) throw e;
        this.incidencia("Ficha de camión no leída", e.message, fila.texto_fila.slice(0, 80), fila.href); continue;
      }
      if (ids.has(cam.id)) continue;
      if (!this.p.camiones[cam.id]) this.p.camiones[cam.id] = cam;
      const ev = Compat.evaluar(carga, cam, this.conf.equivalencias);
      const emp = claveEmp(cam);
      if (lim.empresas_distintas && ev.estado !== "Descartado") {
        if (emp && empresas.has(emp)) { this.incidencia("Omitido por empresa repetida", "Pediste empresas distintas por carga", `${cid} ↔ ${cam.id}`, cam.url); continue; }
        if (!emp) cam.avisos.push("Empresa no identificada: no se puede garantizar que sea distinta");
      }
      ids.add(cam.id);
      const adv = cam.avisos.slice();
      if (Object.entries(this.p.relaciones).some(([k, rs]) => k !== cid && rs.some((r) => r.camion === cam.id))) adv.push("Este camión también figura como candidato de otra carga: no está reservado");
      rels.push({ camion: cam.id, posicion: null, ...ev, advertencias: adv });
      if (ev.estado !== "Descartado" && emp) empresas.add(emp);
      this.log(`  Camión ${cam.id}: ${ev.estado} (cobertura ${ev.cobertura})`);
      await this.guardar();
    }
    const validos = rels.filter((r) => r.estado !== "Descartado").sort((a, b) => Compat.comparar(Compat.orden(a, this.p.camiones[a.camion]), Compat.orden(b, this.p.camiones[b.camion]))).slice(0, maximo);
    validos.forEach((r, i) => (r.posicion = i + 1));
    this.p.relaciones[cid] = validos.concat(rels.filter((r) => r.estado === "Descartado"));
    if (validos.length < maximo) this.incidencia("Menos camiones de los pedidos", `${validos.length} candidatos válidos de ${maximo} tras leer ${leidas} fichas (límite ${tope}); el resto no existía o fue descartado.`, cid);
    await this.guardar();
  }

  // ------------------------------------------------------------------ inspección (no pulsa Buscar)
  async inspeccionar() {
    const tabs = await chrome.tabs.query({ url: this.origen + "/*" });
    if (!tabs.length) throw new SesionInterrumpida("No encuentro ninguna pestaña de Wtransnet abierta. Abre Wtransnet e inicia sesión.");
    this.tabId = tabs[0].id;
    this.p = { incidencias: [] };
    await this.activarSinDialogos();
    try { return await this._inspeccionar(); } finally { await this.quitarSinDialogos(); }
  }
  async _inspeccionar() {
    const informe = { fecha: new Date().toISOString(), paginas: {} };
    for (const nombre of ["buscar_carga", "buscar_camion"]) {
      this.log(`Inspeccionando formulario ${nombre}`);
      await this.ir(this.urls[nombre]);
      const est = await this.estados();
      if (nombre === "buscar_carga") informe.cabecera = est.map((e) => e.texto).join("\n---\n").slice(0, 1500);
      const f = this.marcoFormulario(est);
      const items = ["fecha_inicial", "fecha_final", "tipo_bolsa", "ida_y_vuelta", "tipo_vehiculo", "especialidad", "misma_especialidad", "forma_carga",
        "adr", "doble_conductor", "plataforma_elevadora", "origen.pais", "origen.provincia", "origen.codigo_postal", "origen.localidad", "origen.ambito",
        "destino.pais", "destino.provincia", "destino.codigo_postal", "destino.localidad", "destino.ambito"]
        .concat(nombre === "buscar_carga" ? ["redes", "cargas_urgentes"] : ["sirve_frigorifico", "sirve_lateral_bajo"]);
      informe.paginas[nombre] = { marco_formulario: f !== null, filtros: f === null ? [] : await this.uno("diagnostico", { items }, f),
        controles: f === null ? [] : (await this.uno("controles", {}, f)).map((c) => ({ tipo: c.tipo, name: c.name, etiqueta: c.etiqueta || c.celda_anterior || c.antes, seccion: c.seccion, visible: c.visible, opciones: c.opciones ? c.opciones.length : undefined })) };
      await this.pausa(0.5);
    }
    for (const [nombre, tipo] of [["todas_cargas", "carga"], ["todos_camiones", "camion"]]) {
      this.log(`Inspeccionando listado ${nombre}`);
      await this.ir(this.urls[nombre]);
      const l = await this.listado();
      const info = { filas: l.filas.length, cabecera: l.filas[0] ? l.filas[0].cabecera : [], siguiente: l.siguiente !== null, enlace: l.filas[0] ? (l.filas[0].href ? "URL" : "javascript") : "" };
      if (l.filas[0] && l.filas[0].href) {
        await this.pausa(0.5);
        const { bruto } = await this.leerDocumento(l.filas[0].href);
        info.etiquetas = bruto.kv.map((x) => ({ seccion: x.seccion, etiqueta: x.etiqueta, campo: this.campoBot(x) }));
        const { doc } = await this.descargar(l.filas[0].href);
        info.estructura = Ficha.estructura(doc);
        const reg = Ficha.interpretar(bruto, tipo, l.filas[0], l.filas[0].href);
        info.lectura = { id: reg.id, origen: reg.origen.texto, destino: reg.destino.texto, disponibilidad: reg.campos.disponibilidad || "",
          telefonos: reg.telefonos.length + reg.moviles.length, emails: reg.emails.length, empresa: !!reg.empresa_nombre, peso: reg.peso.original };
        info.enlaces_contacto = bruto.contacto.length;
      }
      informe.paginas[nombre] = info;
    }
    return informe;
  }
  campoBot(x) {
    const k = N.clave(x.etiqueta), sub = Ficha.subUbic(k), b = Ficha.bloqueSeccion(x.seccion, x.etiqueta);
    if (sub) return b === "origen" || b === "destino" ? `${b}.${sub}` : "(ubicación fuera de origen/destino)";
    return Ficha.canon(x.etiqueta) || "(sin clasificar: va a «Otros datos»)";
  }
}
