/*
 * AG Cloud · lector OCR de facturas y nóminas, todo en el navegador (los documentos no salen a terceros).
 * - PDF con texto: pdf.js extrae el texto.
 * - Fotos y PDF escaneados: tesseract.js (español) reconoce el texto.
 * - Después, unas reglas leen NIF, número, fecha, base, IVA, retención y total (o las partidas de la nómina).
 * Librerías alojadas en /vendor/ocr (pdf.js y tesseract.js, licencia Apache 2.0).
 */
(function () {
  'use strict';
  var BASE = (document.querySelector('meta[name="agc-base"]') || {}).content || '';
  var V = BASE + '/vendor/ocr/';
  var pdfLib = null, tessLoad = null;

  function cargarPdf() {
    if (!pdfLib) pdfLib = import(V + 'pdf.min.js').then(function (m) { m.GlobalWorkerOptions.workerSrc = V + 'pdf.worker.min.js'; return m; });
    return pdfLib;
  }
  function cargarTesseract() {
    if (!tessLoad) tessLoad = new Promise(function (ok, ko) {
      if (window.Tesseract) return ok(window.Tesseract);
      var s = document.createElement('script'); s.src = V + 'tesseract.min.js'; s.onload = function () { ok(window.Tesseract); }; s.onerror = ko; document.head.appendChild(s);
    });
    return tessLoad;
  }
  function reconocer(imagen, aviso) {
    return cargarTesseract().then(function (T) {
      aviso && aviso('Reconociendo el texto (la primera vez tarda un poco)…');
      return T.createWorker('spa', 1, { workerPath: V + 'worker.min.js', corePath: V, langPath: V, gzip: false, workerBlobURL: false })
        .then(function (w) { return w.recognize(imagen).then(function (r) { w.terminate(); return r.data.text || ''; }); });
    });
  }

  /** Texto de un PDF o una imagen */
  function texto(file, aviso) {
    var esPdf = /pdf/i.test(file.type || '') || /\.pdf$/i.test(file.name || '');
    if (!esPdf) return reconocer(file, aviso);
    aviso && aviso('Leyendo el PDF…');
    return cargarPdf().then(function (lib) {
      return file.arrayBuffer().then(function (buf) { return lib.getDocument({ data: buf, isEvalSupported: false }).promise; });
    }).then(function (pdf) {
      var n = Math.min(pdf.numPages, 3), out = '', i = 1;
      var sig = function () {
        if (i > n) return Promise.resolve(out);
        return pdf.getPage(i++).then(function (pg) { return pg.getTextContent(); }).then(function (tc) {
          out += tc.items.map(function (x) { return x.str + (x.hasEOL ? '\n' : ' '); }).join('') + '\n'; return sig();
        });
      };
      return sig().then(function (t) {
        if (t.replace(/\s/g, '').length >= 60) return t;
        // PDF escaneado: se pinta la primera página y se reconoce
        return pdf.getPage(1).then(function (pg) {
          var vp = pg.getViewport({ scale: 2 }), cv = document.createElement('canvas');
          cv.width = vp.width; cv.height = vp.height;
          return pg.render({ canvasContext: cv.getContext('2d'), viewport: vp }).promise.then(function () { return reconocer(cv, aviso); });
        });
      });
    });
  }

  // ------------------------------------------------------------ reglas
  var num = function (s) {
    s = String(s || '').replace(/[€\s]/g, '').replace(/−/g, '-');
    if (/,\d{1,2}$/.test(s)) s = s.replace(/\./g, '').replace(',', '.');
    else if (/\.\d{3}(\.|$)/.test(s) && !/\.\d{1,2}$/.test(s)) s = s.replace(/\./g, '');
    else s = s.replace(/,/g, '');
    var v = parseFloat(s); return isNaN(v) ? 0 : v;
  };
  var r2 = function (x) { return Math.round((x + Number.EPSILON) * 100) / 100; };
  var IMP = '(-?\\d{1,3}(?:[.\\s]\\d{3})*(?:,\\d{2})|-?\\d+(?:[.,]\\d{2}))';
  function importeTras(t, claves) {
    var lines = t.split(/\n/);
    for (var k = 0; k < claves.length; k++) {
      var re = new RegExp(claves[k], 'i');
      for (var i = 0; i < lines.length; i++) {
        if (!re.test(lines[i])) continue;
        var tail = lines[i].slice(lines[i].search(re));
        var m = tail.match(new RegExp(IMP, 'g')) || (lines[i + 1] || '').match(new RegExp(IMP, 'g'));
        if (m && m.length) return num(m[m.length - 1]);
      }
    }
    return 0;
  }
  var NIF_RE = /\b([A-HJNP-SUVW][-\s.]?\d{7}[-\s.]?[0-9A-J]|\d{8}[-\s.]?[A-Z]|[XYZ][-\s.]?\d{7}[-\s.]?[A-Z])\b/g;
  function nifs(t) { var out = []; (t.toUpperCase().match(NIF_RE) || []).forEach(function (x) { x = x.replace(/[-\s.]/g, ''); if (out.indexOf(x) < 0) out.push(x); }); return out; }
  var MESES = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
  function fecha(t) {
    var cand = [];
    var re = /\b(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{2,4})\b/g, m;
    while ((m = re.exec(t))) { var y = +m[3] < 100 ? 2000 + +m[3] : +m[3]; if (+m[2] >= 1 && +m[2] <= 12 && +m[1] >= 1 && +m[1] <= 31 && y > 2000 && y < 2100) cand.push({ i: m.index, v: y + '-' + String(m[2]).padStart(2, '0') + '-' + String(m[1]).padStart(2, '0') }); }
    var re2 = new RegExp('\\b(\\d{1,2}) de (' + MESES.join('|') + ') de (\\d{4})', 'gi');
    while ((m = re2.exec(t))) cand.push({ i: m.index, v: m[3] + '-' + String(MESES.indexOf(m[2].toLowerCase()) + 1).padStart(2, '0') + '-' + String(m[1]).padStart(2, '0') });
    if (!cand.length) return '';
    var f = t.search(/fecha/i);
    if (f >= 0) { cand.sort(function (a, b) { return Math.abs(a.i - f) - Math.abs(b.i - f); }); }
    return cand[0].v;
  }

  /** Datos de una factura a partir del texto. empresaNif = la empresa que contabiliza (no es el tercero) */
  function factura(t, empresaNif) {
    var emp = String(empresaNif || '').toUpperCase().replace(/[^A-Z0-9]/g, '');
    var ns = nifs(t).filter(function (x) { return x !== emp; });
    // Nº de factura: el primer código con alguna cifra tras «factura nº», «fra.», «invoice»… (el OCR confunde «º» con «°», «*», «e»)
    var numM = null, reN = /(?:factura|fra\.?|invoice|n[uú]mero)\s*(?:n\S{0,2}|num(?:ero)?\.?|no\.?)?\s*(?:de\s*factura)?\s*[:#.\-]?\s*([A-Z0-9][A-Z0-9\-\/.]{2,24})/gi, mm;
    while ((mm = reN.exec(t))) { if (/\d/.test(mm[1]) && !/^\d{1,2}[\/.\-]\d{1,2}[\/.\-]\d{2,4}$/.test(mm[1])) { numM = mm; break; } }
    var base = importeTras(t, ['base\\s*imponible', 'subtotal', 'base']);
    var pIva = 0, mI = t.match(/I\.?V\.?A\.?\s*(?:\(?\s*)?(21|10|5|4)\s*%/i) || t.match(/(21|10|5|4)\s*%\s*I\.?V\.?A/i);
    if (mI) pIva = +mI[1];
    var cuota = importeTras(t, ['cuota\\s*(?:de\\s*)?i\\.?v\\.?a', 'i\\.?v\\.?a\\.?\\s*\\(?\\s*(?:21|10|5|4)\\s*%', 'total\\s*i\\.?v\\.?a', 'i\\.?v\\.?a']);
    var mR = t.match(/(?:retenci[oó]n|I\.?R\.?P\.?F\.?)\s*[^\d\n]{0,12}(1|7|15|19)\s*%/i), pRet = mR ? +mR[1] : 0;
    var ret = pRet ? importeTras(t, ['retenci[oó]n', 'i\\.?r\\.?p\\.?f']) : 0;
    var total = importeTras(t, ['total\\s*(?:a\\s*pagar|factura|importe)', 'importe\\s*total', 'total\\s*€', '^\\s*total\\b', 'total']);
    if (cuota && base && cuota > base) cuota = 0;
    if (!base && total) base = r2(total / (1 + (pIva || 21) / 100 - pRet / 100));
    if (!pIva && base && cuota) pIva = [21, 10, 5, 4].reduce(function (a, b) { return Math.abs(cuota / base * 100 - b) < Math.abs(cuota / base * 100 - a) ? b : a; }, 21);
    if (!cuota && base) cuota = r2(base * (pIva || 0) / 100);
    if (!ret && base && pRet) ret = r2(base * pRet / 100);
    if (!total) total = r2(base + cuota - ret);
    // Nombre del tercero: la línea del NIF (sin el NIF) o la anterior
    var nombre = '';
    if (ns[0]) {
      var ls = t.split(/\n/), ix = -1;
      for (var i = 0; i < ls.length; i++) if (ls[i].toUpperCase().replace(/[-\s.]/g, '').indexOf(ns[0]) >= 0) { ix = i; break; }
      if (ix >= 0) {
        var l0 = ls[ix].replace(/(C\.?I\.?F\.?|N\.?I\.?F\.?)\s*[:.]?/i, '').replace(NIF_RE, '').replace(/[:|]/g, '').trim();
        if (l0.length > 3) nombre = l0;
        else for (var j = ix - 1; j >= 0 && j >= ix - 3; j--) { if (/[A-Za-zÁÉÍÓÚÑ]{3}/.test(ls[j]) && !/factura|fecha/i.test(ls[j])) { nombre = ls[j].trim(); break; } }
      }
    }
    var ok = (base > 0) + !!fecha(t) + !!ns[0] + !!(numM && numM[1]) + (Math.abs(r2(base + cuota - ret) - total) < 0.05);
    return { nif: ns[0] || '', nombre: nombre.slice(0, 80), num: numM ? numM[1].replace(/[.\-\/]$/, '') : '', fecha: fecha(t), base: r2(base), iva: pIva || (cuota ? 21 : 0), cuota: r2(cuota), ret: pRet, retImp: r2(ret), total: r2(total), confianza: Math.round(ok / 5 * 100) };
  }

  /** Partidas de una nómina a partir del texto */
  function nomina(t) {
    var dev = importeTras(t, ['total\\s*devengado', 'total\\s*devengos', 'devengado']);
    var irpf = importeTras(t, ['i\\.?\\s*r\\.?\\s*p\\.?\\s*f\\.?', 'retenci[oó]n\\s*irpf']);
    var ded = importeTras(t, ['total\\s*a\\s*deducir', 'total\\s*deducciones', 'total\\s*deducido']);
    var liq = importeTras(t, ['l[ií]quido\\s*total\\s*a\\s*percibir', 'l[ií]quido\\s*a\\s*percibir', 'l[ií]quido\\s*total', 'neto\\s*a\\s*percibir', 'l[ií]quido']);
    var ssE = importeTras(t, ['total\\s*aportaci[oó]n\\s*(?:de\\s*la\\s*)?empresa', 'aportaci[oó]n\\s*(?:de\\s*la\\s*)?empresa', 'coste\\s*seguridad\\s*social\\s*empresa']);
    var ant = importeTras(t, ['anticipos?']);
    var ssT = ded && irpf ? r2(ded - irpf - ant) : 0;
    if (!ssT) ssT = importeTras(t, ['total\\s*aportaciones\\s*(?:del\\s*)?trabajador', 'total\\s*aportaciones']);
    if (!dev && liq && ded) dev = r2(liq + ded);
    var mes = (t.match(new RegExp('(' + MESES.join('|') + ')\\s*(?:de\\s*)?(\\d{4})', 'i')) || []);
    return { mon: r2(dev), irpf: r2(irpf), ssT: r2(ssT), ssE: r2(ssE), ant: r2(ant), liquido: r2(liq), concepto: mes[1] ? 'Nómina de ' + mes[1].toLowerCase() + ' de ' + mes[2] : '' };
  }

  window.AGCOcr = { texto: texto, factura: factura, nomina: nomina, num: num };
})();
