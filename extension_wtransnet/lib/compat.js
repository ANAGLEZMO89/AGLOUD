// Compatibilidad carga ↔ camión con reglas fijas y reproducibles (sin IA).
// Estados por criterio: COMPATIBLE / INCOMPATIBLE / PENDIENTE. Un dato ausente nunca es favorable.
// Estado general: alguna INCOMPATIBLE -> "Descartado"; todas COMPATIBLE -> COMPATIBLE; resto -> PENDIENTE.
// Cobertura (0-100, interna): suma de pesos de criterios compatibles. NO es probabilidad de contratación.
"use strict";

const Compat = (() => {
  const COMPATIBLE = "Compatible según datos publicados", INCOMPATIBLE = "Incompatible", PENDIENTE = "Pendiente de confirmar";
  const PESOS = { fecha: 20, origen: 20, destino: 15, vehiculo: 15, capacidad: 15, equipamiento: 5, forma_carga: 5, comentarios: 5 };
  const NOMBRES = { fecha: "Fecha / llegada a carga", origen: "Cobertura origen", destino: "Cobertura destino", vehiculo: "Vehículo y carrocería",
    capacidad: "Capacidad y dimensiones", equipamiento: "ADR y equipamientos", forma_carga: "Forma de carga", comentarios: "Restricciones en comentarios" };
  const R = (estado, motivo) => ({ estado, motivo });
  const d = (iso) => (iso ? new Date(iso) : null);
  const dia = (f) => new Date(f.getFullYear(), f.getMonth(), f.getDate()).getTime();
  const corta = (f) => N.fmtCorta(f);

  function mismaProvincia(a, b) {
    if (a.pais_iso && b.pais_iso && a.pais_iso !== b.pais_iso) return false;
    if (a.provincia_cod && b.provincia_cod) return a.provincia_cod === b.provincia_cod;
    if (a.provincia && b.provincia) return N.clave(a.provincia) === N.clave(b.provincia);
    return null;
  }
  function fecha(c, t) {
    if (!c.disp_desde || !t.disp_desde) return R(PENDIENTE, "Falta la disponibilidad de la carga o del camión");
    const ci = d(c.disp_desde), cf = d(c.disp_hasta || c.disp_desde), ti = d(t.disp_desde), tf = d(t.disp_hasta || t.disp_desde);
    if (dia(ti) > dia(cf)) return R(INCOMPATIBLE, `Camión disponible desde ${corta(ti)}, después de la carga (${corta(cf)})`);
    if (dia(tf) < dia(ci)) return R(PENDIENTE, `El camión publica disponibilidad ${corta(ti)}${dia(tf) !== dia(ti) ? "-" + corta(tf) : ""} y la carga es el ${corta(ci)}: confirmar si sigue libre ese día`);
    if (mismaProvincia(c.origen, t.origen) === true) return R(COMPATIBLE, `Fechas solapadas y camión en la provincia de carga. Hora de llegada no verificada`);
    return R(PENDIENTE, "Fechas solapadas, pero no consta que el camión esté en la provincia de carga: llegada a tiempo sin comprobar");
  }
  function origen(c, t) {
    const co = c.origen, to = t.origen;
    if (!co.texto || !to.texto) return R(PENDIENTE, "Falta el origen de la carga o del camión");
    if (co.pais_iso && to.pais_iso && co.pais_iso !== to.pais_iso) return R(PENDIENTE, `Camión en ${to.pais_iso} y carga en ${co.pais_iso}: requiere desplazamiento sin calcular`);
    const m = mismaProvincia(co, to);
    if (m === true) return R(COMPATIBLE, `Misma provincia${co.cp && co.cp === to.cp ? " (mismo CP)" : ""}: «${to.texto}» / carga «${co.texto}». No acredita proximidad exacta`);
    if (m === false) return R(PENDIENTE, `Provincia distinta (${to.texto} → ${co.texto})${to.ambito ? `; ámbito del camión «${to.ambito}» (no se traduce a km)` : ""}`);
    return R(PENDIENTE, "No se puede determinar la provincia de alguno de los orígenes");
  }
  function destino(c, t) {
    const cd = c.destino, td = t.destino;
    if (!cd.texto) return R(PENDIENTE, "Falta el destino de la carga");
    if (!td.texto) return R(PENDIENTE, "El camión no publica destino");
    if (["indiferente", "cualquiera", "todos", "todas"].includes(N.clave(td.texto))) return R(COMPATIBLE, `Destino del camión publicado como «${td.texto}»`);
    if (cd.pais_iso && td.pais_iso && cd.pais_iso !== td.pais_iso) return R(INCOMPATIBLE, `Camión hacia ${td.pais_iso}, carga hacia ${cd.pais_iso}`);
    const m = mismaProvincia(cd, td);
    if (m === true) return R(COMPATIBLE, `Misma provincia de destino: «${td.texto}»`);
    if (m === null && td.pais_iso && !td.provincia_cod && !td.provincia && cd.pais_iso === td.pais_iso &&
        [N.clave(td.pais || ""), td.pais_iso.toLowerCase()].includes(N.clave(td.texto))) return R(COMPATIBLE, `Camión acepta destino país completo «${td.texto}»`);
    if (m === false) return R(PENDIENTE, `Destino del camión «${td.texto}» distinto del de la carga «${cd.texto}»${td.ambito ? `; ámbito «${td.ambito}»` : ""}`);
    return R(PENDIENTE, "No se puede comparar el destino con los datos publicados");
  }
  function vehiculo(c, t, eqs) {
    const ce = N.clave(c.campos.especialidad), te = N.clave(t.campos.especialidad), cv = N.clave(c.campos.vehiculo), tv = N.clave(t.campos.vehiculo);
    if (!ce && !cv) return R(PENDIENTE, "La carga no indica vehículo ni especialidad");
    if (!te && !tv) return R(PENDIENTE, "El camión no indica vehículo ni especialidad");
    for (const e of eqs || []) {
      if (N.clave(e.carga) === ce && N.clave(e.camion) === te) {
        if (N.clave(e.resultado) === "compatible") return R(COMPATIBLE, `Equivalencia declarada por ti: ${e.carga} ↔ ${e.camion}`);
        if (N.clave(e.resultado) === "incompatible") return R(INCOMPATIBLE, `Incompatibilidad declarada por ti: ${e.carga} ↔ ${e.camion}`);
      }
    }
    const espOk = ce && te ? ce === te : null, vehOk = cv && tv ? cv === tv : null;
    if (espOk && vehOk !== false) return R(COMPATIBLE, `Misma especialidad «${t.campos.especialidad}»${vehOk ? "" : "; vehículo no comparado literalmente"}`);
    if (espOk === false || vehOk === false) return R(PENDIENTE, `Carrocería/vehículo distintos (carga: ${c.campos.especialidad || c.campos.vehiculo}; camión: ${t.campos.especialidad || t.campos.vehiculo}). No se suponen equivalencias`);
    return R(PENDIENTE, "Datos de vehículo insuficientes para comparar");
  }
  function capacidad(c, t) {
    const faltan = [], ok = [];
    for (const [k, nom, u] of [["peso", "Peso", "kg"], ["volumen", "Volumen", "m3"], ["largo", "Largo", "m"], ["ancho", "Ancho", "m"], ["alto", "Alto", "m"]]) {
      const cv = c[k] && c[k].valor, tv = t[k] && t[k].valor;
      if (cv == null) continue;
      if (tv == null) { faltan.push(nom); continue; }
      if (tv < cv) return R(INCOMPATIBLE, `${nom}: el camión indica ${tv} ${u} y la carga requiere ${cv} ${u}`);
      ok.push(`${nom} ${tv} ≥ ${cv} ${u}`);
    }
    if (!c.peso || c.peso.valor == null) faltan.unshift("peso de la carga");
    if (faltan.length) return R(PENDIENTE, "Sin dato para comparar: " + faltan.join(", ") + (ok.length ? ` (comprobado: ${ok.join("; ")})` : ""));
    return R(COMPATIBLE, ok.join("; ") + ". Capacidad indicada, no MMA");
  }
  function equipamiento(c, t) {
    const ok = [], pend = [];
    for (const [k, nom] of [["adr", "ADR"], ["plataforma_elevadora", "Plataforma elevadora"], ["doble_conductor", "Doble conductor"]]) {
      if (c[k] === true) {
        if (t[k] === false) return R(INCOMPATIBLE, `La carga exige ${nom} y el camión indica que no`);
        if (t[k] == null) pend.push(nom); else ok.push(`${nom} sí`);
      } else if (c[k] == null) pend.push(`${nom} (la carga no lo indica)`);
    }
    if (pend.length) return R(PENDIENTE, "Sin confirmar: " + pend.join(", "));
    return R(COMPATIBLE, ok.join(", ") || "La carga no exige ADR, plataforma ni doble conductor");
  }
  function forma(c, t) {
    const req = c.forma_carga || [], tie = t.forma_carga || [];
    if (!req.length) return R(PENDIENTE, "La carga no indica forma de carga");
    if (!tie.length) return R(PENDIENTE, "El camión no indica forma de carga");
    const com = req.filter((x) => tie.includes(x));
    if (com.length) return R(COMPATIBLE, "Forma de carga en común: " + com.join(", "));
    return R(INCOMPATIBLE, `Carga por ${req.join(", ")}; camión sólo ${tie.join(", ")}`);
  }
  function comentarios(c, t) {
    const con = [["carga", c], ["camión", t]].filter(([, o]) => (o.campos.observaciones || "").trim()).map(([n, o]) => `${n}: «${o.campos.observaciones.slice(0, 160)}»`);
    if (con.length) return R(PENDIENTE, "Hay comentarios publicados que deben leerse antes de llamar: " + con.join(" | "));
    return R(COMPATIBLE, "Sin comentarios publicados en ninguna de las dos ofertas");
  }
  function informacion(t) {
    const x = [!!t.disp_desde, !!t.origen.texto, !!t.destino.texto, !!t.campos.vehiculo, !!t.campos.especialidad, t.peso && t.peso.valor != null, !!(t.telefonos.length || t.moviles.length || t.emails.length)];
    return Math.round((100 * x.filter(Boolean).length) / x.length);
  }
  function evaluar(carga, camion, eqs) {
    const cr = { fecha: fecha(carga, camion), origen: origen(carga, camion), destino: destino(carga, camion), vehiculo: vehiculo(carga, camion, eqs),
      capacidad: capacidad(carga, camion), equipamiento: equipamiento(carga, camion), forma_carga: forma(carga, camion), comentarios: comentarios(carga, camion) };
    const est = Object.values(cr).map((v) => v.estado);
    const estado = est.includes(INCOMPATIBLE) ? "Descartado" : est.every((e) => e === COMPATIBLE) ? COMPATIBLE : PENDIENTE;
    const cobertura = Math.round((100 * Object.entries(cr).filter(([, v]) => v.estado === COMPATIBLE).reduce((s, [k]) => s + PESOS[k], 0)) / 100);
    const lista = (e) => Object.entries(cr).filter(([, v]) => v.estado === e).map(([k, v]) => `${NOMBRES[k]}: ${v.motivo}`).join("; ");
    return { criterios: cr, estado, cobertura, informacion: informacion(camion), motivo: lista(COMPATIBLE), pendiente: lista(PENDIENTE), descarte: lista(INCOMPATIBLE) };
  }
  const orden = (ev, cam) => [{ [COMPATIBLE]: 0, [PENDIENTE]: 1, Descartado: 2 }[ev.estado], -ev.cobertura, -ev.informacion, cam.leido_en];
  function comparar(a, b) { for (let i = 0; i < a.length; i++) { if (a[i] < b[i]) return -1; if (a[i] > b[i]) return 1; } return 0; }
  return { evaluar, orden, comparar, COMPATIBLE, INCOMPATIBLE, PENDIENTE, PESOS, NOMBRES };
})();

if (typeof module !== "undefined") module.exports = Compat;
