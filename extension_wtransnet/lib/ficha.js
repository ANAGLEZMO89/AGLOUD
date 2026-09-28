// Lectura de fichas (oferta y empresa) a partir de su HTML.
// Todo valor procede literalmente de la ficha; lo no reconocido va a «otros_datos».
"use strict";

const Ficha = (() => {
  const BLOQUES = new Set(["P", "DIV", "TR", "LI", "TABLE", "H1", "H2", "H3", "H4", "H5", "H6", "FORM", "DL", "DT", "DD", "SECTION"]);
  function texto(el) {
    let s = "";
    const rec = (n) => {
      if (n.nodeType === 3) { s += n.textContent; return; }
      if (n.nodeType !== 1) return;
      const t = n.tagName;
      if (t === "SCRIPT" || t === "STYLE" || t === "NOSCRIPT") return;
      if (t === "BR") { s += "\n"; return; }
      if (t === "INPUT" || t === "SELECT" || t === "TEXTAREA") return;
      for (const c of n.childNodes) rec(c);
      if (t === "TD" || t === "TH") s += " \t";
      if (BLOQUES.has(t)) s += "\n";
    };
    rec(el);
    return N.limpiar(s.replace(/[ \t]+/g, " "));
  }
  const una = (el) => N.limpiar(texto(el)).replace(/\s*\n\s*/g, " ");

  function extraer(doc) {
    const kv = [];
    let seccion = "";
    const enl = (el) => [...el.querySelectorAll("a")].map((a) => ({ texto: una(a), href: a.getAttribute("href") || "" }));
    const esEtiq = (t) => t && t.length <= 45 && /:\s*$/.test(t);
    const filas = [...doc.querySelectorAll("tr")].filter((tr) => !tr.querySelector("tr"));
    const celdas = (tr) => [...tr.children].filter((c) => /^T[DH]$/.test(c.tagName));
    const soloEtiquetas = (cs) => { const t = cs.map(una).filter(Boolean); return t.length >= 2 && t.every((x) => x.length <= 40 && /:\s*$/.test(x)); };
    let saltar = null;
    for (let fi = 0; fi < filas.length; fi++) {
      const tr = filas[fi];
      if (tr === saltar) continue;
      // Caso Wtransnet: fila de etiquetas («País: | Provincia: | C.P.: | Localidad:») y debajo la fila de valores
      const todas = celdas(tr), sig = filas[fi + 1];
      if (sig && soloEtiquetas(todas)) {
        const vals = celdas(sig);
        if (vals.length === todas.length && !soloEtiquetas(vals)) {
          todas.forEach((c, i) => { const et = una(c).replace(/:\s*$/, ""); if (et) kv.push({ seccion, etiqueta: et, valor: texto(vals[i]), enlaces: enl(vals[i]) }); });
          saltar = sig;
          continue;
        }
      }
      const cs = todas.filter((c) => una(c) || c.querySelector("img,a"));
      if (!cs.length) continue;
      if (cs.length === 1) {
        const t = texto(cs[0]);
        const varios = [...t.matchAll(/(?:^|\s)([A-ZÁÉÍÓÚÑ][A-Za-zÁÉÍÓÚÑáéíóúñ.º ]{0,24}):\s*([^\n]*?)(?=\s+[A-ZÁÉÍÓÚÑ][A-Za-zÁÉÍÓÚÑáéíóúñ.º ]{0,24}:|\n|$)/g)];
        if (varios.length >= 2) { for (const v of varios) kv.push({ seccion, etiqueta: v[1].trim(), valor: v[2].trim(), enlaces: enl(cs[0]) }); continue; }
        const m = t.match(/^([^:\n]{1,45}):\s*([\s\S]+)$/);
        if (m) kv.push({ seccion, etiqueta: m[1].trim(), valor: m[2], enlaces: enl(cs[0]) });
        else if (t.length <= 60 && !t.includes("\n")) seccion = t;
        else if (t) kv.push({ seccion, etiqueta: "", valor: t, enlaces: enl(cs[0]) });
        continue;
      }
      if (cs.every((c) => c.tagName === "TH")) { seccion = cs.map(una).join(" ").slice(0, 60); continue; }
      for (let i = 0; i < cs.length; i++) {
        const t = una(cs[i]);
        const sig = cs[i + 1];
        if ((esEtiq(t) || cs[i].tagName === "TH") && sig) {
          kv.push({ seccion, etiqueta: t.replace(/:\s*$/, ""), valor: texto(sig), enlaces: enl(sig),
            imagenes: [...sig.querySelectorAll("img")].map((im) => N.limpiar(im.getAttribute("alt") || im.getAttribute("title") || "")) });
          i++;
        } else {
          const m = texto(cs[i]).match(/^([^:\n]{1,45}):\s*([\s\S]+)$/);
          if (m) kv.push({ seccion, etiqueta: m[1].trim(), valor: m[2], enlaces: enl(cs[i]) });
        }
      }
    }
    for (const dt of doc.querySelectorAll("dt")) {
      const d = dt.nextElementSibling;
      if (d && d.tagName === "DD") kv.push({ seccion, etiqueta: una(dt).replace(/:\s*$/, ""), valor: texto(d), enlaces: enl(d) });
    }
    const contacto = [...doc.querySelectorAll('a[href^="tel:"], a[href^="mailto:"], a[href^="callto:"]')].map((a) => {
      const h = a.getAttribute("href");
      let v = h.split(":").slice(1).join(":").split("?")[0];
      try { v = decodeURIComponent(v); } catch (e) { /* se deja tal cual */ }
      return { tipo: h.split(":")[0].toLowerCase(), valor: v };
    });
    return { kv, contacto, texto: doc.body ? texto(doc.body) : "", titulo: doc.title || "" };
  }

  const CAMPOS = {
    numero_oferta: ["n oferta", "no oferta", "num oferta", "numero oferta", "numero de oferta", "n de oferta", "oferta n", "oferta", "referencia", "ref", "id oferta", "codigo oferta", "cod oferta"],
    red: ["red", "bolsa de origen", "procedencia", "origen de la oferta"],
    fecha_modificacion: ["modificada", "modificado", "fecha modificacion", "ultima modificacion", "actualizada", "fecha actualizacion", "fecha de modificacion",
      "fecha y hora de modificacion"],
    fecha_publicacion: ["publicada", "fecha publicacion", "fecha de publicacion", "fecha alta"],
    disponibilidad: ["disponibilidad", "fecha disponibilidad", "fecha de disponibilidad", "fecha de carga", "fecha carga", "disponible", "fechas", "fecha"],
    hora_limite: ["hora limite", "hora limite de carga", "hora"],
    fecha_descarga: ["fecha descarga", "fecha de descarga", "descarga", "fecha entrega", "fecha de entrega"],
    tipo_bolsa: ["tipo de bolsa", "bolsa", "tipo bolsa"],
    vehiculo: ["vehiculo", "tipo de vehiculo", "tipo vehiculo"],
    especialidad: ["especialidad", "carroceria", "especialidad carroceria"],
    peso: ["peso", "peso kg", "peso kgs", "peso tn", "peso t", "toneladas", "kilos", "capacidad", "carga util", "peso maximo", "peso total"],
    volumen: ["volumen", "volumen m3", "m3", "metros cubicos"],
    largo: ["largo", "longitud", "largo m", "longitud m"], ancho: ["ancho", "anchura", "ancho m"], alto: ["alto", "altura", "alto m"],
    metros_lineales: ["metros lineales", "ml", "m lineales"],
    forma_carga: ["forma de carga", "forma carga", "carga por"],
    mercancia: ["mercancia", "tipo de mercancia", "tipo mercancia", "caracteristicas", "caracteristicas de la mercancia", "producto", "descripcion", "descripcion mercancia"],
    adr: ["adr", "mercancia peligrosa", "mercancias peligrosas"],
    plataforma_elevadora: ["plataforma elevadora", "plataforma"], doble_conductor: ["doble conductor"],
    equipamiento: ["equipamiento", "otros equipamientos", "equipamientos", "extras", "otros"],
    observaciones: ["observaciones", "comentarios", "notas", "comentario", "otras observaciones"],
    num_viajes: ["viajes", "n viajes", "numero de viajes", "num viajes", "no viajes", "numero de ofertas o viajes"],
    ida_y_vuelta: ["ida y vuelta", "viaje de ida y vuelta", "viajes ida y vuelta", "viajes de ida y vuelta", "ida vuelta"],
    distancia: ["distancia", "km", "kms", "kilometros", "distancia aproximada"],
    precio: ["precio", "importe", "tarifa", "flete", "precio ofertado"], moneda: ["moneda", "divisa"],
    plazo_pago: ["plazo pago", "plazo de pago", "dias pago", "dias de pago"],
    forma_pago: ["forma de pago", "forma pago", "medio de pago"],
    comentarios_pago: ["comentarios pago", "comentarios de pago", "observaciones pago", "condiciones de pago", "informacion adicional sobre el pago"],
    empresa_codigo: ["codigo empresa", "cod empresa", "cod emp", "codigo de empresa", "n empresa", "id empresa", "codigo cliente", "cod cliente", "codigo"],
    empresa_nombre: ["empresa", "razon social", "nombre empresa", "nombre de la empresa", "anunciante", "ofertante"],
    contacto: ["contacto", "persona de contacto", "persona contacto", "atiende", "responsable", "nombre contacto"],
    telefono: ["telefono", "telefonos", "tel", "tfno", "telf", "tlf", "telefono fijo"],
    movil: ["movil", "moviles", "telefono movil", "celular", "mov"],
    email: ["email", "e mail", "correo", "correo electronico", "mail", "e mail contacto"],
    fax: ["fax"], ambito: ["ambito", "ambito geografico", "zona"], actividad: ["actividad", "actividades", "actividad empresarial"],
    cif: ["cif", "nif", "vat", "identificacion fiscal"], direccion: ["direccion", "domicilio"],
  };
  const UBIC = { pais: ["pais"], provincia: ["provincia", "prov"], cp: ["codigo postal", "c postal", "cp", "c p"], localidad: ["localidad", "poblacion", "ciudad"] };
  const subUbic = (k) => Object.keys(UBIC).find((c) => UBIC[c].includes(k)) || null;

  function canon(etq) {
    const k = N.clave(etq);
    if (subUbic(k)) return null;
    for (const [c, s] of Object.entries(CAMPOS)) if (s.includes(k)) return c;
    // prefijo sólo con sinónimos de varias palabras o muy concretos (nunca «fecha», «codigo», «viajes»…)
    for (const [c, s] of Object.entries(CAMPOS)) if (s.some((x) => (x.includes(" ") || ["peso", "volumen", "precio", "largo", "ancho", "alto"].includes(x)) && k.startsWith(x + " "))) return c;
    return null;
  }
  function bloqueSeccion(sec, etq) {
    const t = N.clave(sec + " " + etq);
    if (/\borig|\bcarga en\b|\brecogida/.test(t)) return "origen";
    if (/\bdest|\bdescarga en\b|\bentrega en\b/.test(t)) return "destino";
    if (/\bempresa|\banunciante|\bcontacto/.test(t)) return "empresa";
    return "";
  }
  function resumenFila(fila) {
    const d = {};
    const cab = fila.cabecera || [];
    if (cab.length === fila.celdas.length) cab.forEach((c, i) => { if (c) d[c] = fila.celdas[i]; });
    else fila.celdas.forEach((v, i) => (d["col" + (i + 1)] = v));
    return d;
  }
  function redDeFila(fila) {
    const t = N.clave((fila.iconos || []).join(" ") + " " + (fila.texto_fila || ""));
    if (/teleroute/.test(t)) return "Teleroute";
    if (/123cargo|bursa/.test(t)) return "123Cargo/Bursa";
    if (/wtransnet/.test(t)) return "Wtransnet";
    return "";
  }
  function idDeUrl(url) {
    try {
      const u = new URL(url);
      const q = new URLSearchParams(u.search);
      const inner = q.get("URL");
      if (inner) new URLSearchParams(inner.split("?")[1] || "").forEach((v, k) => q.set(k, v));
      for (const k of ["idOferta", "idoferta", "id_oferta", "oferta", "numOferta", "numoferta", "nof", "codigo", "cod", "id"]) if (q.get(k)) return q.get(k);
    } catch (e) { /* url no válida */ }
    return "";
  }
  function urlLimpia(url) {
    if (!url) return "";
    try {
      const u = new URL(url.replace(/;jsessionid=[^?#]*/i, ""));
      for (const k of [...u.searchParams.keys()]) if (/^(jsessionid|sid|session|sessionid|token|auth|ticket|t|_)$/i.test(k)) u.searchParams.delete(k);
      u.hash = "";
      return u.toString();
    } catch (e) { return ""; }
  }

  function interpretar(bruto, tipo, fila, url) {
    const r = { tipo, leido_en: new Date().toISOString(), url: urlLimpia(url), campos: {}, origen: {}, destino: {},
      otros_datos: [], avisos: [], texto_ficha: bruto.texto };
    const ub = { origen: {}, destino: {} };
    for (const p of bruto.kv) {
      let val = N.limpiar(p.valor);
      const etq = p.etiqueta, sec = p.seccion;
      if (!val && p.imagenes) val = p.imagenes.filter(Boolean).join(", ");
      if (!etq) continue;
      const bloque = bloqueSeccion(sec, etq);
      const k = N.clave(etq);
      const sub = subUbic(k);
      if ((bloque === "origen" || bloque === "destino") && sub) {
        if (!(sub in ub[bloque]) && val && !/:\s*$/.test(val)) ub[bloque][sub] = val;
        continue;
      }
      if (sub) { r.otros_datos.push(`${sec ? sec + " › " : ""}${etq}: ${val}`); continue; }
      if (["origen", "lugar de carga", "carga en", "recogida"].includes(k)) { ub.origen.texto = ub.origen.texto || val; continue; }
      if (["destino", "lugar de descarga", "descarga en", "entrega en"].includes(k)) { ub.destino.texto = ub.destino.texto || val; continue; }
      const campo = canon(etq);
      if (campo === "ambito" && (bloque === "origen" || bloque === "destino")) { ub[bloque].ambito = ub[bloque].ambito || val; continue; }
      if (campo) {
        if (campo in r.campos && r.campos[campo] !== val) r.campos[campo] += " | " + val;
        else if (!(campo in r.campos)) r.campos[campo] = val;
        if (campo === "empresa_nombre" && p.enlaces && p.enlaces.length) r.enlace_empresa = p.enlaces[0].href;
      } else r.otros_datos.push(`${sec ? sec + " › " : ""}${etq}: ${val}`);
    }
    const c = r.campos;
    r.red = c.red || (fila ? redDeFila(fila) : "") || "";
    if (fila) r.listado = resumenFila(fila);
    r.numero_oferta = N.limpiar(c.numero_oferta || "") || idDeUrl(url);
    if (!r.numero_oferta) r.avisos.push("Número de oferta no visible: se identifica por el enlace de la ficha");
    for (const b of ["origen", "destino"]) {
      const d = ub[b];
      let u = N.ubicacion(d.texto || "", d.pais || "", d.provincia || "", d.cp || "", d.localidad || "");
      u.ambito = d.ambito || "";
      if (!u.texto && fila) {
        for (const [col, v] of Object.entries(resumenFila(fila))) {
          if (N.clave(col).startsWith(b.slice(0, 4))) {
            u = N.ubicacion(v); u.ambito = "";
            u.origen_datos.push("tomado del listado (puede estar recortado)");
            r.avisos.push(`${b} tomado del listado: revisar ficha`);
          }
        }
      }
      r[b] = u;
    }
    // contactos
    const tel = [];
    for (const campo of ["telefono", "movil"]) for (const t of N.telefonos(c[campo] || "")) if (!tel.some((x) => x[1] === t)) tel.push([campo, t]);
    // Sección «contacto» de la ficha: los teléfonos y emails pueden ir con iconos en lugar de etiqueta escrita
    const zonaContacto = bruto.kv.filter((p) => /contacto/.test(N.clave(p.seccion)) && !["observaciones", "comentarios_pago"].includes(canon(p.etiqueta || "")))
      .map((p) => p.valor).join("\n");
    for (const t of N.telefonos(zonaContacto)) if (!tel.some((x) => x[1] === t)) tel.push([/^\+?(34)?[67]/.test(t) ? "movil" : "telefono", t]);
    const emZona = N.emails(zonaContacto);
    for (const e of bruto.contacto) if (e.tipo === "tel" || e.tipo === "callto") { const t = N.telefono(e.valor); if (t && !tel.some((x) => x[1] === t)) tel.push(["telefono", t]); }
    const em = N.emails(c.email || "");
    for (const x of emZona) if (!em.includes(x)) em.push(x);
    for (const e of bruto.contacto) if (e.tipo === "mailto") for (const x of N.emails(e.valor)) if (!em.includes(x)) em.push(x);
    r.telefonos = tel.filter((x) => x[0] === "telefono").map((x) => x[1]);
    r.moviles = tel.filter((x) => x[0] === "movil").map((x) => x[1]);
    r.emails = em;
    // Empresa: nombre = texto (no numérico) del enlace a la ficha de empresa; código = número de «Cod. Emp.»
    const enlacesEmp = bruto.kv.flatMap((p) => (p.enlaces || []).map((a) => ({ ...a, sec: p.seccion })))
      .filter((a) => a.texto && !/^(tel|mailto|javascript)/i.test(a.href) && /empresa|emp/i.test(a.href));
    const conNombre = enlacesEmp.filter((a) => !/^\s*[\d\s().\-]+\s*$/.test(a.texto));
    if (conNombre.length) {
      const pref = conNombre.find((a) => /contacto/.test(N.clave(a.sec))) || conNombre[0];
      if (!c.empresa_nombre || /^\s*[\d\s|().\-]+\s*$/.test(c.empresa_nombre)) c.empresa_nombre = pref.texto;
      r.enlace_empresa = pref.href;
    } else if (enlacesEmp.length && !r.enlace_empresa) r.enlace_empresa = enlacesEmp[0].href;
    if (c.empresa_codigo) {
      const cod = String(c.empresa_codigo).match(/\b\d{3,}\b/);
      if (cod) c.empresa_codigo = cod[0];
    }
    r.contacto = N.limpiar((c.contacto || "").split(" | ")[0]);
    r.empresa_nombre = N.limpiar(c.empresa_nombre || "");
    r.empresa_codigo = N.limpiar(c.empresa_codigo || "");
    r.contacto_fuente = r.telefonos.length || r.moviles.length || r.emails.length ? "oferta" : "";
    r.avisos_contacto = N.avisosContacto([c.observaciones, c.comentarios_pago, c.equipamiento].filter(Boolean).join(" \n"));
    // cantidades
    r.peso = N.cantidad(c.peso, "peso");
    r.volumen = N.cantidad(c.volumen, "volumen");
    for (const d of ["largo", "ancho", "alto"]) r[d] = N.cantidad(c[d], "longitud");
    r.precio = N.precio(c.precio);
    if (r.precio.nota.startsWith("Precio publicado como 0")) r.avisos.push(r.precio.nota);
    const fs = N.fechas(c.disponibilidad || "");
    r.disp_desde = fs[0] ? fs[0].toISOString() : null;
    r.disp_hasta = fs[1] ? fs[1].toISOString() : r.disp_desde;
    r.fecha_modificacion = N.fecha(c.fecha_modificacion || "") ? N.fecha(c.fecha_modificacion).toISOString() : null;
    r.fecha_descarga = N.fecha(c.fecha_descarga || "") ? N.fecha(c.fecha_descarga).toISOString() : null;
    r.hora_limite = N.hora(c.hora_limite || "");
    r.adr = N.siNo(c.adr); r.plataforma_elevadora = N.siNo(c.plataforma_elevadora); r.doble_conductor = N.siNo(c.doble_conductor);
    const fc = N.clave(c.forma_carga || "");
    r.forma_carga = ["arriba", "lateral", "detras"].filter((f) => fc.includes(f));
    r.id = `${r.red || "red no indicada"}#${r.numero_oferta || "url:" + r.url}`;
    // trazabilidad: cada teléfono/email exportado debe verse en la ficha
    const dig = (bruto.texto || "").replace(/\D/g, ""), low = (bruto.texto || "").toLowerCase();
    for (const t of r.telefonos.concat(r.moviles)) if (!dig.includes(t.replace("+", "")) && !dig.includes(t.replace("+", "").slice(-9))) r.avisos.push(`Teléfono ${t} no visible como texto en la ficha (procede de un enlace)`);
    for (const e of r.emails) if (!low.includes(e)) r.avisos.push(`Email ${e} no visible como texto en la ficha (procede de un enlace)`);
    return r;
  }

  // Estructura de una ficha para diagnóstico: filas y celdas con números y emails ocultos.
  function estructura(doc) {
    const ocultar = (t) => t.replace(/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+/g, "<email>").replace(/\d/g, "#");
    const out = [];
    for (const tr of doc.querySelectorAll("tr")) {
      if (tr.querySelector("tr")) continue;
      const cs = [...tr.children].filter((c) => /^T[DH]$/.test(c.tagName));
      const partes = cs.map((c) => {
        const img = [...c.querySelectorAll("img")].map((i) => "[img:" + (i.getAttribute("alt") || i.getAttribute("title") || (i.getAttribute("src") || "").split("/").pop()) + "]").join("");
        const a = [...c.querySelectorAll("a")].map((x) => "[a:" + ((x.getAttribute("href") || "").split(/[?(]/)[0].slice(0, 40)) + "]").join("");
        return (c.tagName === "TH" ? "TH:" : "") + img + a + ocultar(una(c)).slice(0, 60);
      }).filter((x) => x.trim());
      if (partes.length) out.push(partes.join(" | "));
      if (out.length >= 150) break;
    }
    return out;
  }

  return { extraer, interpretar, estructura, canon, subUbic, bloqueSeccion, urlLimpia, texto, CAMPOS };
})();

if (typeof module !== "undefined") module.exports = Ficha;
