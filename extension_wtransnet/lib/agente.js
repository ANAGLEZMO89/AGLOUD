/*
 * Agente que se inyecta en cada marco de la pestaña de Wtransnet.
 * Debe ser una función AUTOCONTENIDA (Chrome la serializa): no usa nada externo.
 *
 * Órdenes:
 *   estado      -> sesión (login/CAPTCHA/caducada), si hay formulario con «Buscar», filas de listado, cabecera
 *   controles   -> controles del formulario con su etiqueta visible (para inspección)
 *   op          -> aplica UN filtro (select/texto/opción/casillas/casilla) y lo verifica
 *   pulsar      -> pulsa Buscar / Anotar (con barreras de seguridad)
 *   listado     -> filas de resultados (enlace de fecha) y enlace «siguiente»
 *   clicFila    -> abre una ficha con enlace javascript (sólo si no hay URL)
 *   html        -> HTML actual del marco (para leer una ficha abierta en la pestaña)
 *   volver      -> «Volver a la lista» o historial del marco
 *   paginar     -> pulsa «siguiente»
 *   descargar   -> GET de una URL del mismo sitio con la sesión (fichas y empresas)
 *
 * Seguridad: nunca pulsa controles cuyo texto o destino contenga acciones
 * comerciales; nunca activa tel:/mailto:/chat; nunca lee campos de contraseña.
 */
function agenteWT(orden, args) {
  args = args || {};
  const norm = (s) => (s || "").replace(/ /g, " ").replace(/\s+/g, " ").trim();
  const clave = (s) =>
    norm(s).normalize("NFKD").replace(/[̀-ͯ]/g, "").toLowerCase()
      .replace(/[ºª]/g, " ").replace(/[^a-z0-9]+/g, " ").trim();
  const vis = (el) => !!(el && (el.offsetWidth || el.offsetHeight || el.getClientRects().length));
  const esCtrl = (el) => ["INPUT", "SELECT", "TEXTAREA", "BUTTON"].includes(el.tagName);

  // ---------------------------------------------------------------- seguridad
  const PROHIBIDOS = ["ofertar", "publicar", "aceptar", "contratar", "reservar", "asignar", "adjudicar",
    "enviar", "mensaje", "chat", "conversacion", "llamar", "whatsapp", "sms", "interes", "archivar",
    "anadir contacto", "agregar contacto", "favorito", "solicitar", "documentacion", "garantia", "cobro",
    "seguro", "pagar", "preferencias", "configuracion", "guardar", "grabar", "eliminar", "borrar oferta",
    "baja", "alta", "modificar", "editar", "valorar", "denunciar"];
  const PROTOCOLOS = ["tel:", "mailto:", "sms:", "whatsapp:", "callto:", "skype:", "sip:"];
  const RED_PROHIBIDA = /(accion=(alta|grabar|guardar|borrar|eliminar|enviar|contratar|aceptar|interes|archivar|ofertar|publicar|modificar|baja|reservar)\b|mensaj|\/chat|whatsapp|wa\.me|mailto:|tel:|interes\b|archivar|garantia|documentacion)/i;
  const PERMITIDAS = {
    buscar: [/^buscar$/, /^buscar ofertas$/],
    anotar: [/^anotar$/],
    volver: [/^volver a la lista$/, /^volver al listado$/, /^volver$/],
    paginar: [/^siguiente$/, /^pagina siguiente$/, /^sig\.?$/, /^>$/, /^>>$/, /^»$/],
    ficha: [/^\d{1,2}[ /.\-]\d{1,2}([ /.\-]\d{2,4})?( \d{1,2}[ :.]\d{2})?$/],
  };
  function comprobarClic(el, accion) {
    const tipo = (el.getAttribute("type") || "").toLowerCase();
    const crudo = norm((el.innerText || el.textContent || "") + " " +
      (["button", "submit", "image"].includes(tipo) ? el.value || "" : "")).toLowerCase();
    const texto = clave(crudo + " " + (el.getAttribute("title") || "") + " " + (el.getAttribute("alt") || "") + " " + (el.getAttribute("aria-label") || ""));
    const href = decodeURIComponent((el.getAttribute("href") || "").toLowerCase());
    const onclick = (el.getAttribute("onclick") || "").toLowerCase();
    if (PROTOCOLOS.some((p) => href.startsWith(p) || onclick.includes(p))) throw new Error("Enlace de contacto bloqueado");
    const destino = clave(href + " " + onclick);
    for (const t of PROHIBIDOS) {
      const re = new RegExp("\\b" + t + "\\b");
      if (re.test(texto) || re.test(destino)) throw new Error(`Control bloqueado por contener «${t}»: ${crudo.slice(0, 60)}`);
    }
    if (RED_PROHIBIDA.test(href + " " + onclick)) throw new Error("Destino bloqueado");
    if (tipo === "submit" && !["buscar", "anotar"].includes(accion)) throw new Error("Botón de envío no permitido");
    const pats = PERMITIDAS[accion] || [];
    if (!pats.some((p) => p.test(texto) || p.test(crudo))) throw new Error(`El control «${crudo.slice(0, 60)}» no corresponde a la acción «${accion}»`);
  }

  // ---------------------------------------------------------------- controles
  function listarControles() {
    const todos = [...document.querySelectorAll("input,select,textarea,button,a")];
    const esCab = (el) => {
      const t = norm(el.innerText || "");
      if (!t || t.length > 60 || el.querySelector("input,select,textarea,button")) return false;
      if (/^(H[1-6]|LEGEND|TH|CAPTION|B|STRONG)$/.test(el.tagName)) return true;
      const c = (el.className || "").toString().toLowerCase();
      if (/(tit|cab|head|seccion|section)/.test(c) && /^(TD|DIV|SPAN|P)$/.test(el.tagName)) return true;
      return el.tagName === "TD" && el.colSpan > 1;
    };
    let seccion = "";
    const secc = new Map();
    for (const el of document.body ? document.body.querySelectorAll("*") : []) {
      if (esCab(el)) seccion = norm(el.innerText);
      if (esCtrl(el) || el.tagName === "A") secc.set(el, seccion);
    }
    const txtNodo = (n) => n.nodeType === 3 ? n.textContent
      : (n.nodeType === 1 && !esCtrl(n) && !n.querySelector("input,select,textarea,button") ? (n.innerText || n.textContent || "") : null);
    const vecino = (el, dir) => {
      let s = "", n = dir > 0 ? el.nextSibling : el.previousSibling;
      while (n && s.length < 80) {
        const t = txtNodo(n);
        if (t === null) break;
        s = dir > 0 ? s + " " + t : t + " " + s;
        n = dir > 0 ? n.nextSibling : n.previousSibling;
      }
      return norm(s);
    };
    const salida = [];
    todos.forEach((el, i) => {
      const tipo = (el.getAttribute("type") || (el.tagName === "SELECT" ? "select" : el.tagName === "TEXTAREA" ? "textarea" : el.tagName === "A" ? "enlace" : "text")).toLowerCase();
      if (tipo === "password") return;
      if (el.tagName === "A" && !/^(buscar|anotar)$/i.test(norm(el.innerText))) return;
      let etiqueta = "";
      if (el.id) { const l = document.querySelector(`label[for="${CSS.escape(el.id)}"]`); if (l) etiqueta = norm(l.innerText); }
      if (!etiqueta && el.closest("label")) etiqueta = norm(el.closest("label").innerText);
      const td = el.closest("td,th");
      let celdaAnt = "";
      if (td) { let p = td.previousElementSibling; while (p && !norm(p.innerText)) p = p.previousElementSibling; if (p) celdaAnt = norm(p.innerText).slice(0, 80); }
      const tr = el.closest("tr");
      const fila = tr && tr.cells && tr.cells.length ? norm(tr.cells[0].innerText).slice(0, 80) : "";
      const o = { indice: i, tag: el.tagName, tipo, name: el.getAttribute("name") || "", id: el.id || "",
        etiqueta, antes: vecino(el, -1), despues: vecino(el, 1), celda_anterior: celdaAnt, fila,
        seccion: secc.get(el) || "", visible: vis(el), deshabilitado: !!el.disabled,
        form: el.form ? (el.form.getAttribute("name") || el.form.id || "form") : "",
        texto: el.tagName === "A" || el.tagName === "BUTTON" ? norm(el.innerText) : "" };
      if (tipo === "checkbox" || tipo === "radio") { o.value = el.value; o.checked = el.checked; }
      else if (["button", "submit", "image", "reset"].includes(tipo)) o.value = el.value || "";
      else if (el.tagName === "SELECT") { o.value = el.value; o.opciones = [...el.options].map((op) => ({ value: op.value, texto: norm(op.text) })); }
      else if (tipo !== "hidden") o.value = el.value;
      else o.value = "";
      salida.push(o);
    });
    return { ctr: salida, els: todos };
  }

  const ETIQUETAS = {
    fecha_inicial: ["fecha inicial", "desde", "fecha desde", "fecha inicio", "disponibilidad desde"],
    fecha_final: ["fecha final", "hasta", "fecha hasta", "fecha fin"],
    pais: ["pais"], provincia: ["provincia", "prov"], codigo_postal: ["codigo postal", "c postal", "cp", "c p"],
    localidad: ["localidad", "poblacion", "ciudad"], ambito: ["ambito"],
    tipo_vehiculo: ["tipo de vehiculo", "tipo vehiculo", "vehiculo"],
    especialidad: ["especialidad", "carroceria", "especialidad carroceria"],
    misma_especialidad: ["misma especialidad"],
    ida_y_vuelta: ["viajes de ida y vuelta", "ida y vuelta", "ida vuelta"],
    adr: ["adr"], doble_conductor: ["doble conductor"], plataforma_elevadora: ["plataforma elevadora", "plataforma"],
    cargas_urgentes: ["cargas urgentes", "urgentes", "urgente"],
    sirve_frigorifico: ["sirve frigorifico"], sirve_lateral_bajo: ["sirve lateral bajo"],
    forma_carga: ["forma de carga", "forma carga", "carga por"], redes: ["redes", "red"], tipo_bolsa: ["tipo de bolsa", "bolsa"],
  };
  const OPCIONES_GRUPO = { tipo_bolsa: ["trailers completos", "grupajes", "rigidos completos"],
    redes: ["wtransnet", "teleroute", "123cargo", "bursa", "123cargo bursa"], forma_carga: ["arriba", "lateral", "detras"] };
  const TIPOS_TEXTO = ["text", "search", "tel", "number", "date", ""];
  const cerca = (c) => clave(c.etiqueta || c.antes || c.celda_anterior || c.fila || "");
  const contexto = (c) => clave([c.etiqueta, c.antes, c.celda_anterior, c.fila].join(" "));
  const textoOpcion = (c) => clave(c.etiqueta || c.despues || c.antes || "");
  const coincide = (t, sin) => { t = clave(t); return sin.some((s) => t === s || new RegExp("(^| )" + s.replace(/[.*+?^${}()|[\]\\]/g, "\\$&") + "( |$)").test(t)); };
  const bloqueDe = (c) => {
    const t = clave([c.seccion, c.fila, c.celda_anterior, c.etiqueta, c.antes].join(" "));
    const id = clave(c.name + " " + c.id);
    const o = /\borig/.test(t) || /ori/.test(id), d = /\bdest/.test(t) || /des/.test(id);
    return o && !d ? "origen" : d && !o ? "destino" : "";
  };
  function localizar(ctr, campo, bloque, tipos) {
    const sin = ETIQUETAS[campo];
    const ok = (c) => c.visible && !c.deshabilitado && (!tipos || tipos.includes(c.tipo));
    let cands = ctr.filter((c) => ok(c) && coincide(cerca(c), sin));
    if (!cands.length) cands = ctr.filter((c) => ok(c) && coincide(contexto(c), sin));
    if (bloque) {
      const conBloque = cands.filter((c) => bloqueDe(c) === bloque);
      const sinBloque = cands.filter((c) => bloqueDe(c) === "");
      if (conBloque.length) cands = conBloque;
      else if (sinBloque.length === 2) cands = [bloque === "origen" ? sinBloque[0] : sinBloque[1]];
      else cands = [];
    }
    if (!cands.length) throw new Error(`No encuentro el campo «${campo}»${bloque ? " de " + bloque : ""} en el formulario.`);
    const nombres = new Set(cands.map((c) => c.name + "|" + c.id));
    if (nombres.size > 1) throw new Error(`Campo «${campo}» ambiguo: ${cands.slice(0, 5).map((c) => (c.name || c.id) + " (" + cerca(c) + ")").join("; ")}`);
    return cands[0];
  }
  function grupo(ctr, campo, bloque) {
    const sin = ETIQUETAS[campo];
    let g = ctr.filter((c) => ["radio", "checkbox"].includes(c.tipo) && c.visible &&
      coincide(clave([c.seccion, c.fila, c.celda_anterior].join(" ")), sin) && (!bloque || bloqueDe(c) === bloque));
    if (!g.length && OPCIONES_GRUPO[campo]) g = ctr.filter((c) => ["radio", "checkbox"].includes(c.tipo) && c.visible && coincide(textoOpcion(c), OPCIONES_GRUPO[campo]));
    return g;
  }
  const casillaUnica = (ctr, campo) => ctr.filter((x) => x.tipo === "checkbox" && x.visible && coincide(textoOpcion(x) + " " + cerca(x), ETIQUETAS[campo]));
  const disparar = (el) => { el.dispatchEvent(new Event("input", { bubbles: true })); el.dispatchEvent(new Event("change", { bubbles: true })); };
  const marcar = (el, v) => { if (el.checked !== v) { el.click(); if (el.checked !== v) { el.checked = v; disparar(el); } } };

  function opcionSelect(c, texto, cod, tabla) {
    const k = clave(texto);
    const ex = (c.opciones || []).filter((o) => clave(o.texto) === k);
    if (ex.length === 1) return ex[0];
    if (cod && tabla) {
      const porCod = (c.opciones || []).filter((o) => {
        if (!o.texto) return false;
        const t = clave(o.texto);
        if (tabla[t] === cod) return true;
        const partes = o.texto.split("/").map(clave).filter(Boolean);
        return partes.length > 1 && partes.every((p) => tabla[p] === cod);
      });
      if (porCod.length === 1) return porCod[0];
    }
    throw new Error(`«${texto}» no coincide literalmente con ninguna opción del desplegable ${c.name || c.id}. Opciones reales: ${(c.opciones || []).map((o) => o.texto).filter(Boolean).join(" | ").slice(0, 900)}`);
  }

  function aplicar(op) {
    const { ctr, els } = listarControles();
    const el = (c) => els[c.indice];
    const pref = op.bloque ? op.bloque + " " : "";
    switch (op.tipo) {
      case "select": {
        const c = localizar(ctr, op.campo, op.bloque, ["select"]);
        const o = opcionSelect(c, op.valor, op.cod, op.tabla);
        el(c).value = o.value; disparar(el(c));
        if (el(c).value !== o.value) throw new Error(`El desplegable ${op.campo} no conservó «${op.valor}»`);
        return pref + op.campo + " = " + o.texto;
      }
      case "texto": {
        const c = localizar(ctr, op.campo, op.bloque, TIPOS_TEXTO);
        const e = el(c); e.focus(); e.value = op.valor; disparar(e); e.blur();
        if (norm(e.value) !== norm(op.valor)) throw new Error(`El campo ${op.campo} muestra «${e.value}» en lugar de «${op.valor}»`);
        return pref + op.campo + " = " + op.valor;
      }
      case "casillas": {
        const g = grupo(ctr, op.campo).filter((c) => c.tipo === "checkbox");
        if (!g.length) throw new Error(`No encuentro las casillas de «${op.campo}».`);
        const pedidas = op.valor.map(clave), hallada = new Set();
        for (const c of g) {
          const t = textoOpcion(c);
          const p = pedidas.filter((x) => t === x || t.startsWith(x));
          p.forEach((x) => hallada.add(x));
          marcar(el(c), p.length > 0);
        }
        const faltan = pedidas.filter((x) => !hallada.has(x));
        if (faltan.length) throw new Error(`Opciones no encontradas en «${op.campo}»: ${faltan.join(", ")}. Existentes: ${g.map(textoOpcion).join(", ")}`);
        return op.campo + " = " + op.valor.join(", ");
      }
      case "casilla": {
        const c = casillaUnica(ctr, op.campo);
        if (c.length !== 1) throw new Error(`No encuentro una casilla única para «${op.campo}».`);
        marcar(el(c[0]), !!op.valor);
        return op.campo + " = " + (op.valor ? "sí" : "no");
      }
      case "opcion": {
        const g = grupo(ctr, op.campo, op.bloque).filter((c) => c.tipo === "radio");
        const k = clave(op.valor);
        if (g.length) {
          const e = g.filter((c) => textoOpcion(c) === k || textoOpcion(c).startsWith(k));
          if (e.length !== 1) throw new Error(`Opción «${op.valor}» no encontrada para ${op.campo}. Opciones: ${[...new Set(g.map(textoOpcion))].join(", ")}`);
          marcar(el(e[0]), true);
          if (!el(e[0]).checked) throw new Error(`No se pudo marcar ${op.campo} = ${op.valor}`);
          return op.campo + " = " + op.valor;
        }
        try { return aplicar({ ...op, tipo: "select" }); } catch (err) {
          const c = casillaUnica(ctr, op.campo);
          if (c.length !== 1) throw err;
          const v = k === "indiferente" ? false : (k === "si" || k === "s" || k.startsWith("solo"));
          marcar(el(c[0]), v);
          return op.campo + " = " + (v ? "marcado" : "sin marcar");
        }
      }
    }
    throw new Error("Operación desconocida");
  }

  function boton(texto, accion, bloque) {
    const { ctr, els } = listarControles();
    const k = clave(texto);
    let b = ctr.filter((c) => c.visible && clave(c.texto || c.value || "") === k && (["button", "submit", "image", "enlace"].includes(c.tipo) || c.tag === "BUTTON"));
    if (b.length > 1 && bloque) b = b.filter((c) => bloqueDe(c) === bloque).length ? b.filter((c) => bloqueDe(c) === bloque) : b;
    if (b.length > 1) {
      const forms = ctr.filter((c) => c.tipo === "select" && c.visible).map((c) => c.form);
      const principal = forms.sort((a, z) => forms.filter((x) => x === z).length - forms.filter((x) => x === a).length)[0];
      b = b.filter((c) => c.form === principal).length ? b.filter((c) => c.form === principal) : b;
    }
    if (!b.length) throw new Error(`No encuentro el botón «${texto}».`);
    if (b.length > 1) throw new Error(`Hay ${b.length} botones «${texto}».`);
    const e = els[b[0].indice];
    comprobarClic(e, accion);
    setTimeout(() => e.click(), 50); // se devuelve el control antes de que el marco navegue
    return `Clic permitido [${accion}]: ${texto}`;
  }

  // ---------------------------------------------------------------- listados
  const reFecha = /^\d{1,2}[/.\-]\d{1,2}([/.\-]\d{2,4})?(\s+\d{1,2}[:.]\d{2})?$/;
  function listado() {
    const anchors = [...document.querySelectorAll("a")];
    const filas = [], vistas = new Set();
    for (const a of anchors) {
      const t = norm(a.innerText);
      if (!reFecha.test(t)) continue;
      const tr = a.closest("tr");
      if (!tr || vistas.has(tr)) continue;
      vistas.add(tr);
      const tabla = tr.closest("table");
      let cab = [];
      if (tabla) {
        const th = tabla.querySelector("thead tr") || [...tabla.querySelectorAll("tr")].find((r) => r.querySelector("th"));
        if (th) cab = [...th.cells].map((c) => norm(c.innerText));
      }
      const attr = a.getAttribute("href") || "";
      filas.push({ indice_enlace: anchors.indexOf(a), texto_enlace: t,
        href: attr && !attr.toLowerCase().startsWith("javascript") && !attr.startsWith("#") ? a.href : "",
        celdas: [...tr.cells].map((c) => norm(c.innerText)), cabecera: cab,
        iconos: [...tr.querySelectorAll("img,[title]")].map((i) => norm(i.getAttribute("alt") || i.getAttribute("title") || "")).filter(Boolean),
        texto_fila: norm(tr.innerText).slice(0, 600) });
    }
    const sig = anchors.findIndex((a) => /^(siguiente|p[aá]gina siguiente|sig\.?|>|>>|»)$/i.test(norm(a.innerText)) || /siguiente/i.test(a.getAttribute("title") || ""));
    const body = document.body ? document.body.innerText : "";
    const total = body.match(/(\d[\d.]*)\s+(ofertas|resultados|registros)/i);
    return { filas, siguiente: sig >= 0 ? sig : null, total_texto: total ? total[0] : "",
      sin_resultados: /no se (han )?encontr|sin resultados|no hay ofertas|\b0 ofertas/i.test(body) };
  }

  // ---------------------------------------------------------------- órdenes
  const ejecutar = () => { switch (orden) {
    case "estado": {
      const txt = (document.body ? document.body.innerText : "").slice(0, 5000);
      const low = txt.toLowerCase();
      const pwd = [...document.querySelectorAll("input[type=password]")].some(vis);
      const cap = !!document.querySelector('iframe[src*="captcha"], iframe[src*="recaptcha"], iframe[src*="hcaptcha"], .g-recaptcha, .h-captcha') || /captcha|no soy un robot|verifica que eres humano/.test(low);
      const cad = /sesi[oó]n (ha )?(caducad|expirad|finalizad)|session (has )?expired|vuelva a (identificarse|iniciar)/.test(low);
      const buscar = [...document.querySelectorAll("input[type=submit],input[type=button],button,a")].some((e) => vis(e) && clave(e.value || e.innerText) === "buscar");
      const nctr = document.querySelectorAll("select,input:not([type=hidden])").length;
      const l = listado();
      return { url: location.href, pwd, cap, cad, buscar, nctr, filas: l.filas.length, sin_resultados: l.sin_resultados,
        primera: l.filas.length ? l.filas[0].texto_fila : "", texto: txt.slice(0, 700), listo: document.readyState };
    }
    case "controles": return listarControles().ctr;
    case "diagnostico": {
      // Cómo resolvería cada filtro, sin tocar nada.
      const { ctr } = listarControles();
      const CLASE = { fecha_inicial: "texto", fecha_final: "texto", codigo_postal: "texto", localidad: "texto", tipo_vehiculo: "select",
        especialidad: "select", pais: "select", provincia: "select", ambito: "select", misma_especialidad: "casilla", cargas_urgentes: "casilla",
        tipo_bolsa: "grupo", redes: "grupo", forma_carga: "grupo", ida_y_vuelta: "opcion", adr: "opcion", doble_conductor: "opcion",
        plataforma_elevadora: "opcion", sirve_frigorifico: "opcion", sirve_lateral_bajo: "opcion" };
      return args.items.map((item) => {
        const [bloque, campo] = item.includes(".") ? item.split(".") : ["", item];
        try {
          const cl = CLASE[campo];
          if (cl === "texto" || cl === "select") {
            const c = localizar(ctr, campo, bloque, cl === "texto" ? TIPOS_TEXTO : ["select"]);
            return { item, estado: "localizado", tipo: c.tipo, junto_a: cerca(c), opciones: (c.opciones || []).map((o) => o.texto) };
          }
          if (cl === "grupo") {
            const g = grupo(ctr, campo).filter((c) => c.tipo === "checkbox");
            if (!g.length) throw new Error("No encuentro las casillas");
            return { item, estado: "localizado", tipo: "casillas", opciones: g.map(textoOpcion) };
          }
          if (cl === "casilla") {
            const c = casillaUnica(ctr, campo);
            if (c.length !== 1) throw new Error("No encuentro una casilla única");
            return { item, estado: "localizado", tipo: "casilla", junto_a: textoOpcion(c[0]) };
          }
          const g = grupo(ctr, campo, bloque).filter((c) => c.tipo === "radio");
          if (g.length) return { item, estado: "localizado", tipo: "radio", opciones: g.map(textoOpcion) };
          try {
            const c = localizar(ctr, campo, bloque, ["select"]);
            return { item, estado: "localizado", tipo: "select", opciones: (c.opciones || []).map((o) => o.texto) };
          } catch (e) {
            const c = casillaUnica(ctr, campo);
            if (c.length === 1) return { item, estado: "localizado", tipo: "casilla única", junto_a: textoOpcion(c[0]) };
            throw e;
          }
        } catch (e) { return { item, estado: "NO LOCALIZADO", detalle: e.message }; }
      });
    }
    case "op": return aplicar(args.op);
    case "pulsar": return boton(args.texto, args.accion, args.bloque);
    case "listado": return listado();
    case "clicFila": {
      const a = document.querySelectorAll("a")[args.indice];
      if (!a) throw new Error("La fila ya no está en el listado");
      comprobarClic(a, "ficha");
      setTimeout(() => a.click(), 50);
      return "Clic permitido [ficha]";
    }
    case "paginar": {
      const a = document.querySelectorAll("a")[args.indice];
      comprobarClic(a, "paginar");
      setTimeout(() => a.click(), 50);
      return "Clic permitido [paginar]";
    }
    case "html": return { url: location.href, html: document.documentElement.outerHTML };
    case "volver": {
      const v = [...document.querySelectorAll("a,input[type=button],button")].find((e) => /^volver a la lista$/i.test(norm(e.innerText || e.value)));
      if (v) { comprobarClic(v, "volver"); setTimeout(() => v.click(), 50); return "volver"; }
      setTimeout(() => history.back(), 50);
      return "historial";
    }
    case "descargar": {
      const url = new URL(args.url, location.href);
      if (url.origin !== location.origin) throw new Error("Sólo se descargan páginas del mismo sitio");
      if (RED_PROHIBIDA.test(decodeURIComponent(url.href))) throw new Error("URL no permitida: " + url.pathname);
      return fetch(url.href, { credentials: "include", method: "GET" }).then(async (r) => {
        const buf = await r.arrayBuffer();
        let cs = (r.headers.get("content-type") || "").match(/charset=([\w-]+)/i);
        let texto = new TextDecoder(cs ? cs[1] : "utf-8").decode(buf);
        const meta = !cs && texto.match(/<meta[^>]+charset=["']?([\w-]+)/i);
        if (meta && !/utf-?8/i.test(meta[1])) texto = new TextDecoder(meta[1]).decode(buf);
        return { status: r.status, url: r.url, html: texto };
      });
    }
  }
  throw new Error("Orden desconocida: " + orden); };
  // Resultado siempre serializable: {ok, v} o {ok:false, e}
  try {
    const v = ejecutar();
    if (v && typeof v.then === "function") return v.then((x) => ({ ok: true, v: x }), (e) => ({ ok: false, e: String((e && e.message) || e) }));
    return { ok: true, v };
  } catch (e) {
    return { ok: false, e: String((e && e.message) || e) };
  }
}
