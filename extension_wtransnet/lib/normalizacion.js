// Normalización: se conserva siempre el texto original; el valor normalizado
// sólo se rellena cuando la conversión es inequívoca. Nunca se inventa un dato.
"use strict";

const N = (() => {
  const limpiar = (t) => (t == null ? "" : String(t)).replace(/ /g, " ").replace(/[ \t]+/g, " ").replace(/\s*\n\s*/g, "\n").trim();
  const clave = (s) => limpiar(s).normalize("NFKD").replace(/[̀-ͯ]/g, "").toLowerCase()
    .replace(/[ºª]/g, " ").replace(/[^a-z0-9]+/g, " ").trim();

  // ------------------------------------------------ fechas (dd/mm/aa[aa] [hh:mm])
  const RE_FECHA = /\b(\d{1,2})[/.\-](\d{1,2})[/.\-](\d{2,4})\b(?:\s*(?:a las\s*)?(\d{1,2})[:.h](\d{2}))?/g;
  function fechas(texto) {
    const out = [];
    for (const m of String(texto || "").matchAll(RE_FECHA)) {
      let a = +m[3]; if (a < 100) a += 2000;
      const f = new Date(a, +m[2] - 1, +m[1], +(m[4] || 0), +(m[5] || 0));
      if (f.getMonth() === +m[2] - 1 && f.getDate() === +m[1]) out.push(f);
    }
    return out;
  }
  const fecha = (t) => fechas(t)[0] || null;
  const hora = (t) => { const m = String(t || "").match(/\b(\d{1,2})[:.h](\d{2})\b/); return m ? m[1].padStart(2, "0") + ":" + m[2] : ""; };
  const dd = (n) => String(n).padStart(2, "0");
  const fmtCorta = (f) => `${dd(f.getDate())}/${dd(f.getMonth() + 1)}/${String(f.getFullYear()).slice(2)}`;
  function fechaFormulario(t) {
    t = limpiar(t);
    if (!t) return "";
    const f = fecha(t);
    if (!f) throw new Error(`Fecha no válida: «${t}». Usa dd/mm/aa.`);
    return fmtCorta(f);
  }

  // ------------------------------------------------ números y unidades
  function numero(texto) {
    const m = String(texto || "").match(/-?\d[\d.,]*/);
    if (!m) return null;
    let s = m[0].replace(/[.,]$/, "");
    if (s.includes(".") && s.includes(",")) s = s.lastIndexOf(",") > s.lastIndexOf(".") ? s.replace(/\./g, "").replace(",", ".") : s.replace(/,/g, "");
    else if (s.includes(",")) s = /^-?\d+,\d+$/.test(s) ? s.replace(",", ".") : s.replace(/,/g, "");
    else if (/^-?\d{1,3}(\.\d{3})+$/.test(s)) s = s.replace(/\./g, "");
    const v = parseFloat(s);
    return Number.isFinite(v) ? v : null;
  }
  const UNI = {
    peso: { kg: 1, kgs: 1, kilos: 1, kilogramos: 1, k: 1, t: 1000, tn: 1000, tns: 1000, tm: 1000, ton: 1000, tons: 1000, toneladas: 1000, tonelada: 1000 },
    longitud: { m: 1, mts: 1, mt: 1, metros: 1, metro: 1, cm: 0.01, mm: 0.001 },
    volumen: { m3: 1, "m³": 1, mc: 1, l: 0.001, litros: 0.001 },
  };
  function cantidad(texto, tipo) {
    const original = limpiar(texto);
    const r = { original, valor: null, nota: "" };
    if (!original) { r.nota = "dato ausente"; return r; }
    const n = numero(original);
    if (n === null) { r.nota = "sin valor numérico"; return r; }
    const m = original.normalize("NFKD").replace(/[̀-ͯ]/g, "").toLowerCase().match(/-?\d[\d.,]*\s*([a-z³]+)?/);
    const u = ((m && m[1]) || "").replace(/\.$/, "");
    const f = UNI[tipo][u];
    if (f === undefined) { r.nota = "unidad no indicada: no se convierte"; return r; }
    r.valor = Math.round(n * f * 1000) / 1000;
    if (r.valor === 0) r.nota = "valor cero publicado";
    return r;
  }
  function precio(texto) {
    const original = limpiar(texto);
    const r = { original, valor: null, moneda: "", nota: "" };
    if (!original) { r.nota = "dato ausente"; return r; }
    const m = original.match(/(EUR|€|USD|\$|GBP|£|PLN|RON|CHF)/i);
    if (m) r.moneda = { "€": "EUR", $: "USD", "£": "GBP" }[m[1]] || m[1].toUpperCase();
    r.valor = numero(original);
    if (r.valor === 0) r.nota = "Precio publicado como 0: su significado comercial no está confirmado";
    else if (r.valor === null) r.nota = "precio no numérico (se conserva el texto)";
    return r;
  }
  function siNo(t) {
    const k = clave(t);
    if (!k) return null;
    if (["si", "s", "yes", "x", "true", "1"].includes(k) || k.startsWith("si ") || k.startsWith("solo ")) return true;
    if (["no", "n", "false", "0"].includes(k) || k.startsWith("no ")) return false;
    return null;
  }

  // ------------------------------------------------ contactos
  const RE_EMAIL = /[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/g;
  const RE_TEL = /(?<![\w/])(?:\+|00)?\(?\d[\d\s.\-()]{6,}\d(?![\w/])/g;
  function telefono(t) {
    t = limpiar(t).replace(/^tel:/i, "");
    const mas = t.startsWith("+") || t.startsWith("00");
    let d = t.replace(/\D/g, "");
    if (t.startsWith("00")) d = d.slice(2);
    return (mas ? "+" : "") + d;
  }
  function telefonos(texto) {
    const out = [];
    for (const m of String(texto || "").match(RE_TEL) || []) {
      if (fecha(m)) continue;
      const n = telefono(m);
      const len = n.replace("+", "").length;
      if (len >= 9 && len <= 15 && !out.includes(n)) out.push(n);
    }
    return out;
  }
  const emails = (t) => [...new Set((String(t || "").match(RE_EMAIL) || []).map((e) => e.replace(/\.$/, "").toLowerCase()))];
  const RE_AVISO = /[^.\n]*\b(no\s+(?:se\s+)?(?:contest|respond|atend|leemos|mir)\w*|s[oó]lo\s+(?:por\s+)?(?:tel|llamad|whats|mail|correo)\w*|no\s+(?:enviar|mandar)\s+\w*|llamar\s+(?:a|al|s[oó]lo)\b|no\s+(?:llamar|emails?|correos?|mails?)\b)[^.\n]*/gi;
  const avisosContacto = (t) => [...new Set((String(t || "").match(RE_AVISO) || []).map(limpiar).filter(Boolean))];

  // ------------------------------------------------ ubicación
  // Códigos INE de provincia (= dos primeras cifras del código postal español).
  const PROVINCIAS = {
    "01": ["alava", "araba", "araba alava", "alava araba", "araba alava"], "02": ["albacete"], "03": ["alicante", "alacant", "alicante alacant"],
    "04": ["almeria"], "05": ["avila"], "06": ["badajoz"], "07": ["baleares", "illes balears", "islas baleares", "balears"],
    "08": ["barcelona"], "09": ["burgos"], "10": ["caceres"], "11": ["cadiz"], "12": ["castellon", "castello", "castellon castello", "castellon de la plana", "castello de la plana"],
    "13": ["ciudad real"], "14": ["cordoba"], "15": ["a coruna", "la coruna", "coruna"], "16": ["cuenca"], "17": ["girona", "gerona"],
    "18": ["granada"], "19": ["guadalajara"], "20": ["gipuzkoa", "guipuzcoa"], "21": ["huelva"], "22": ["huesca"], "23": ["jaen"],
    "24": ["leon"], "25": ["lleida", "lerida"], "26": ["la rioja", "rioja"], "27": ["lugo"], "28": ["madrid"], "29": ["malaga"],
    "30": ["murcia"], "31": ["navarra", "nafarroa"], "32": ["ourense", "orense"], "33": ["asturias"], "34": ["palencia"],
    "35": ["las palmas", "palmas"], "36": ["pontevedra"], "37": ["salamanca"], "38": ["santa cruz de tenerife", "s c tenerife", "tenerife", "sta cruz de tenerife", "s c de tenerife"],
    "39": ["cantabria"], "40": ["segovia"], "41": ["sevilla"], "42": ["soria"], "43": ["tarragona"], "44": ["teruel"], "45": ["toledo"],
    "46": ["valencia"], "47": ["valladolid"], "48": ["bizkaia", "vizcaya", "vizcaya bizkaia"], "49": ["zamora"], "50": ["zaragoza"], "51": ["ceuta"], "52": ["melilla"],
  };
  const PROV_POR_NOMBRE = {};
  for (const [c, ns] of Object.entries(PROVINCIAS)) ns.forEach((n) => (PROV_POR_NOMBRE[n] = c));
  const PAISES = { ES: ["espana", "spain", "es", "e"], PT: ["portugal", "pt", "p"], FR: ["francia", "france", "fr", "f"],
    DE: ["alemania", "germany", "deutschland", "de", "d"], IT: ["italia", "italy", "it", "i"], BE: ["belgica", "belgium", "be", "b"],
    NL: ["holanda", "paises bajos", "netherlands", "nl"], GB: ["reino unido", "united kingdom", "gb", "uk"], PL: ["polonia", "poland", "pl"],
    MA: ["marruecos", "morocco", "ma"], AD: ["andorra", "ad"], CH: ["suiza", "switzerland", "ch"], AT: ["austria", "at", "a"],
    RO: ["rumania", "romania", "ro"], CZ: ["republica checa", "chequia", "cz"] };
  const PAIS_POR_NOMBRE = {};
  for (const [c, ns] of Object.entries(PAISES)) ns.forEach((n) => (PAIS_POR_NOMBRE[n] = c));
  const codPais = (t) => PAIS_POR_NOMBRE[clave(t)] || null;
  function codProvincia(t) {
    const k = clave(t);
    if (PROV_POR_NOMBRE[k]) return PROV_POR_NOMBRE[k];
    if (/^\d{2}$/.test(k) && PROVINCIAS[k]) return k;
    const partes = String(t || "").split("/").map(clave).filter(Boolean);
    if (partes.length > 1) {
      const cods = new Set(partes.map((p) => PROV_POR_NOMBRE[p]));
      if (cods.size === 1 && !cods.has(undefined)) return [...cods][0];
    }
    return null;
  }
  const nombreProvincia = (c) => (PROVINCIAS[c] ? PROVINCIAS[c][0].replace(/\b\w/g, (x) => x.toUpperCase()) : "");
  function ubicacion(texto = "", pais = "", provincia = "", cp = "", localidad = "") {
    const original = limpiar(texto) || [pais, provincia, cp, localidad].filter(Boolean).join(" / ");
    const u = { texto: original, pais: limpiar(pais), pais_iso: null, provincia: limpiar(provincia), provincia_cod: null,
      cp: limpiar(cp), localidad: limpiar(localidad), ambito: "", origen_datos: [] };
    if (u.pais) u.pais_iso = codPais(u.pais) || (/^[A-Za-z]{2}$/.test(u.pais) ? u.pais.toUpperCase() : null);
    if (!u.cp) { const m = original.match(/\b(\d{5})\b/); if (m) { u.cp = m[1]; u.origen_datos.push("CP leído del texto"); } }
    if (!u.pais_iso) {
      const m = original.match(/^\(?([A-Z]{2})\)?(?:[\s\-:]|$)/);
      if (m && PAISES[m[1]]) { u.pais_iso = m[1]; u.origen_datos.push("país por prefijo ISO"); }
      else for (const tr of original.split(/[/,;()\-]/)) { const c = codPais(tr); if (c && clave(tr).length > 2) { u.pais_iso = c; u.origen_datos.push("país leído del texto"); break; } }
    }
    if (u.provincia) u.provincia_cod = codProvincia(u.provincia);
    if (!u.provincia_cod && (u.pais_iso === null || u.pais_iso === "ES")) {
      for (const tr of original.split(/[/,;()\-]/)) { const c = codProvincia(tr.replace(/\d+/g, " ")); if (c) { u.provincia_cod = c; u.origen_datos.push("provincia leída del texto"); break; } }
    }
    if (!u.provincia_cod && /^\d{5}$/.test(u.cp) && u.pais_iso === "ES" && PROVINCIAS[u.cp.slice(0, 2)]) {
      u.provincia_cod = u.cp.slice(0, 2); u.origen_datos.push("provincia por CP (código INE)");
    }
    return u;
  }

  return { limpiar, clave, fechas, fecha, hora, fmtCorta, fechaFormulario, numero, cantidad, precio, siNo, telefono, telefonos,
    emails, avisosContacto, ubicacion, codPais, codProvincia, nombreProvincia, PROV_POR_NOMBRE, PAIS_POR_NOMBRE };
})();

if (typeof module !== "undefined") module.exports = N;
