/* AG Cloud · Mantenimiento de apuntes: manual, factura y nómina, plantillas, OCR y asiento en vivo */
(function () {
  'use strict';
  var f = document.querySelector('[data-asiento]');
  if (!f) return;
  var $ = function (s, r) { return (r || f).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || f).querySelectorAll(s)); };
  var J = function (k, d) { try { return JSON.parse(f.getAttribute(k) || ''); } catch (e) { return d; } };
  var ctas = J('data-cuentas', {}), cfg = J('data-cfg', {}), TER = J('data-terceros', {}), TPL = J('data-plantillas', []), NOM = J('data-nomina', []);
  var r2 = function (x) { return Math.round((x + Number.EPSILON) * 100) / 100; };
  var eu = function (n) { var neg = n < 0, p = Math.abs(n).toFixed(2).split('.'); return (neg ? '−' : '') + p[0].replace(/\B(?=(\d{3})+(?!\d))/g, '.') + ',' + p[1]; };
  var pnum = function (v) { var s = String(v == null ? '' : v).trim().replace(/−/g, '-'); if (/^-?\d{1,3}(\.\d{3})+$/.test(s)) s = s.replace(/\./g, ''); return parseFloat(s.indexOf(',') >= 0 ? s.replace(/\./g, '').replace(',', '.') : s) || 0; };
  // 572.1 → 572000001 · 4751 → 475100000
  var a9 = function (v) {
    v = String(v || '').replace(/[^\d.]/g, '');
    if (!v.replace(/\./g, '')) return '';
    if (v.indexOf('.') >= 0) { var p = v.split('.'), a = p[0], b = p.slice(1).join(''); if (a.length + b.length <= 9) return a + '000000000'.slice(0, 9 - a.length - b.length) + b; v = a + b; }
    return v.length >= 9 ? v.slice(0, 9) : (v + '000000000').slice(0, 9);
  };
  var nomDe = function (c9) { if (!c9) return ''; return ctas[c9] || ctas[a9(c9.slice(0, 4))] || ctas[a9(c9.slice(0, 3))] || '⚠ No existe en el PGC'; };
  var pintaNom = function (el, c9) { if (!el) return; el.textContent = nomDe(c9); el.style.color = /^⚠/.test(el.textContent) ? '#B42318' : '#3C3C3C'; };

  var tipo = $('[data-ap-tipo]').value || 'Manual';
  var clase = 'Recibida';

  // ---------------------------------------------------------------- tipo
  function setTipo(t) {
    tipo = t; $('[data-ap-tipo]').value = t;
    $$('[data-ap-set]').forEach(function (b) { b.classList.toggle('on', b.getAttribute('data-ap-set') === t); });
    $$('[data-ap-only]').forEach(function (el) { el.hidden = el.getAttribute('data-ap-only') !== t; });
    // Solo se envían los apuntes manuales en modo Manual, y el archivo del modo activo
    $$('[data-ap-lines] input').forEach(function (i) { i.disabled = t !== 'Manual'; });
    $$('[data-ocr-drop]').forEach(function (d) { var i = $('[data-ocr-file]', d); i.disabled = d.getAttribute('data-ocr-drop') !== (t === 'Factura' ? 'factura' : t === 'Nómina' ? 'nomina' : ''); });
    if (t === 'Factura' && !$('[data-f="cta"]').value) setFacCta();
    calc();
  }
  $$('[data-ap-set]').forEach(function (b) { b.addEventListener('click', function () { if (!b.disabled) setTipo(b.getAttribute('data-ap-set')); }); });

  // ---------------------------------------------------------------- manual
  var box = $('[data-ap-lines]'), nN = 9000;
  function renum() { $$('[data-ap-line]', box).forEach(function (r, i) { var n = $('.ap-n', r); if (n) n.textContent = i + 1; }); }
  function wire(row) {
    $('[data-ap-del]', row).addEventListener('click', function () { if ($$('[data-ap-line]', box).length > 2) { row.remove(); renum(); calc(); } });
    var c = $('[data-k="c"]', row);
    c.addEventListener('blur', function () { if (c.value.trim()) { c.value = a9(c.value); calc(); } });
    ['d', 'h'].forEach(function (k) {
      $('[data-k="' + k + '"]', row).addEventListener('keydown', function (e) {
        if (e.key !== '=') return; e.preventDefault();
        var d = 0, h = 0; $$('[data-ap-line]', box).forEach(function (r) { if (r !== row) { d += pnum($('[data-k="d"]', r).value); h += pnum($('[data-k="h"]', r).value); } });
        var dif = r2(k === 'd' ? h - d : d - h); if (dif > 0) { e.target.value = eu(dif); calc(); }
      });
    });
  }
  function nuevaLinea(c) {
    var row = $('[data-ap-tpl]').content.firstElementChild.cloneNode(true), n = nN++;
    $$('[data-k]', row).forEach(function (el) { el.name = 'l[' + n + '][' + el.getAttribute('data-k') + ']'; });
    box.appendChild(row); wire(row); if (c) $('[data-k="c"]', row).value = c; renum(); return row;
  }
  $$('[data-ap-line]', box).forEach(wire);
  $('[data-ap-add]').addEventListener('click', function () { $('[data-k="c"]', nuevaLinea()).focus(); });
  $('[data-ap-plantilla]').addEventListener('change', function (e) {
    var t = TPL[+e.target.value]; if (!t) return;
    $$('[data-ap-line]', box).forEach(function (r) { r.remove(); });
    t[1].forEach(function (c) { nuevaLinea(c); });
    var conc = $('[data-ap-conc]'); if (!conc.value.trim()) conc.value = t[0];
    e.target.value = ''; calc(); var d = $('[data-ap-line] [data-k="d"]', box); if (d) d.focus();
  });

  // ---------------------------------------------------------------- factura
  var F = function (k) { return $('[data-f="' + k + '"]'); };
  function setFacCta() { F('cta').value = clase === 'Recibida' ? (cfg.gasto || cfg.compras) : cfg.ventas; }
  $$('[data-fac-clase]').forEach(function (b) {
    b.addEventListener('click', function () {
      var antes = clase === 'Recibida' ? [cfg.gasto, cfg.compras] : [cfg.ventas];
      clase = b.getAttribute('data-fac-clase');
      $$('[data-fac-clase]').forEach(function (x) { x.classList.toggle('on', x === b); });
      if (!F('cta').value || antes.indexOf(F('cta').value) >= 0) setFacCta();
      F('nodeduc').closest('label').hidden = clase !== 'Recibida';
      calc();
    });
  });
  ['ter', 'cta'].forEach(function (k) { F(k).addEventListener('blur', function () { if (F(k).value.trim()) { F(k).value = a9(F(k).value); calc(); } }); });
  function lineasFactura() {
    var base = pnum(F('base').value), pIva = +F('iva').value, pRet = +F('ret').value, ter = a9(F('ter').value), cta = a9(F('cta').value);
    var iva = r2(base * pIva / 100), ret = r2(base * pRet / 100), tot = r2(base + iva - ret);
    $('[data-ft="base"]').textContent = eu(base); $('[data-ft="iva"]').textContent = eu(iva); $('[data-ft="ret"]').textContent = eu(ret); $('[data-ft="tot"]').textContent = eu(tot);
    pintaNom($('[data-f-nom="ter"]'), ter); pintaNom($('[data-f-nom="cta"]'), cta);
    if (!base) return [];
    var nTer = (ctas[ter] || '').trim();
    var con = 'Fra. ' + (F('num').value.trim() || 's/n') + (nTer ? ' · ' + nTer : ''), doc = F('num').value.trim(), L = [];
    if (clase === 'Recibida') {
      var nd = F('nodeduc').checked;
      L.push([cta, nd ? r2(base + iva) : base, 0]); if (iva && !nd) L.push([cfg.ivaSop, iva, 0]); if (ret) L.push([cfg.retPrac, 0, ret]); L.push([ter, 0, tot]);
    } else {
      L.push([ter, tot, 0]); if (ret) L.push([cfg.retSop, ret, 0]); L.push([cta, 0, base]); if (iva) L.push([cfg.ivaRep, 0, iva]);
    }
    return L.map(function (l) { return { c: l[0], d: l[1], h: l[2], t: con, doc: doc }; });
  }

  // ---------------------------------------------------------------- nómina
  var NV = function (k) { var i = $('[data-nom-v="' + k + '"]'); return i ? pnum(i.value) : 0; };
  var NC = function (k) { var i = $('[data-nom-c="' + k + '"]'); return i ? a9(i.value) : ''; };
  $$('[data-nom-c]').forEach(function (i) { i.addEventListener('blur', function () { if (i.value.trim()) { i.value = a9(i.value); calc(); } }); });
  function lineasNomina() {
    var neto = r2(NV('mon') + NV('esp') + NV('diet') + NV('ind') - NV('ant') - NV('emb') - NV('dedE') - NV('otr') - NV('irpf') - NV('irpfE') - NV('ssT'));
    $('[data-nom-neto]').textContent = eu(neto);
    NOM.forEach(function (r) { pintaNom($('[data-nom-n="' + r[0] + '"]'), NC(r[0])); });
    var con = $('[data-nom-conc]').value.trim() || 'Nóminas', L = [];
    var add = function (k, d, h) { if (d || h) L.push({ c: NC(k), d: r2(d), h: r2(h), t: con, doc: '' }); };
    ['mon', 'esp', 'diet', 'ind', 'ssE'].forEach(function (k) { add(k, NV(k), 0); });
    add('irpf', 0, NV('irpf')); add('irpfE', 0, NV('irpfE'));
    // Seguridad Social acreedora: cuota del trabajador + cuota de la empresa
    if (NV('ssT') + NV('ssE')) L.push({ c: NC('ssT'), d: 0, h: r2(NV('ssT') + NV('ssE')), t: con, doc: '' });
    ['ant', 'emb', 'dedE', 'otr'].forEach(function (k) { add(k, 0, NV(k)); });
    if (neto > 0) add('neto', 0, neto);
    return L;
  }

  // ---------------------------------------------------------------- vista previa
  function lineasManual() {
    var con = $('[data-ap-conc]').value.trim();
    return $$('[data-ap-line]', box).map(function (r) {
      var c = a9($('[data-k="c"]', r).value);
      pintaNom($('[data-ap-nom]', r), c);
      return { c: c, d: pnum($('[data-k="d"]', r).value), h: pnum($('[data-k="h"]', r).value), t: $('[data-k="t"]', r).value.trim() || con, doc: $('[data-k="doc"]', r).value.trim() };
    }).filter(function (l) { return l.c || l.d || l.h; });
  }
  function lineas() { return tipo === 'Factura' ? lineasFactura() : tipo === 'Nómina' ? lineasNomina() : lineasManual(); }
  function concepto(L) { return tipo === 'Manual' ? $('[data-ap-conc]').value.trim() : (L[0] ? L[0].t : ''); }
  var esc = function (s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; };
  function calc() {
    var L = lineas(), d = r2(L.reduce(function (a, l) { return a + l.d; }, 0)), h = r2(L.reduce(function (a, l) { return a + l.h; }, 0));
    var rows = $('[data-ap-prev-rows]');
    rows.innerHTML = L.length ? L.map(function (l) {
      return '<div class="ap-prev-r"><span>' + esc(l.c || '—') + '</span><span class="ell">' + esc(l.c ? nomDe(l.c) : 'Falta la cuenta') + '</span><span>' + (l.d ? eu(l.d) : '') + '</span><span>' + (l.h ? eu(l.h) : '') + '</span></div>';
    }).join('') : '<div style="text-align:center;font-size:12.5px;color:#6A6A65;padding:10px 0">Los apuntes aparecerán aquí a medida que los introduzcas.</div>';
    $('[data-ap-sd]').textContent = eu(d); $('[data-ap-sh]').textContent = eu(h);
    var fe = $('[data-ap-fecha]').value, fs = fe ? fe.split('-').reverse().join('/') : '';
    $('[data-ap-prev-h]').textContent = (f.querySelector('[name="editar"]') ? 'MODIFICANDO ASIENTO' : 'ASIENTO NUEVO') + (fs ? ' · ' + fs : '') + (concepto(L) ? ' · ' + concepto(L).toUpperCase() : '');
    var ok = d > 0 && Math.abs(d - h) < 0.005, cu = $('[data-ap-cuadre]');
    cu.className = 'ap-cuadre ' + (ok ? 'ok' : (d || h ? 'mal' : ''));
    cu.textContent = ok ? '✓ Cuadrado' : (d || h ? 'Descuadre ' + eu(r2(d - h)) : '');
    var falta = !L.length ? 'introduce los apuntes.' : L.some(function (l) { return !/^\d{9}$/.test(l.c); }) ? 'todas las líneas necesitan cuenta (revisa las vacías).'
      : L.some(function (l) { return /^⚠/.test(nomDe(l.c)); }) ? 'hay cuentas que no existen en el PGC.' : !ok ? 'el asiento tiene que cuadrar.' : !concepto(L) ? 'falta el concepto.' : '';
    $('[data-ap-hint]').textContent = falta ? 'Para guardar: ' + falta : '';
    return L;
  }
  f.addEventListener('input', calc); f.addEventListener('change', calc);
  $$('[data-ap-vt]').forEach(function (b) { b.addEventListener('click', function () { $$('[data-ap-vt]').forEach(function (x) { x.classList.toggle('on', x === b); }); $$('[data-ap-v]').forEach(function (v) { v.hidden = v.getAttribute('data-ap-v') !== b.getAttribute('data-ap-vt'); }); }); });

  // ---------------------------------------------------------------- guardar
  f.addEventListener('submit', function (e) {
    var L = calc(), gen = $('[data-ap-gen]');
    gen.innerHTML = '';
    $('[data-ap-conc-final]').value = concepto(L);
    if (tipo !== 'Manual') {
      L.forEach(function (l, i) {
        [['c', l.c], ['d', l.d ? String(l.d).replace('.', ',') : ''], ['h', l.h ? String(l.h).replace('.', ',') : ''], ['t', l.t], ['doc', l.doc]].forEach(function (p) {
          var inp = document.createElement('input'); inp.type = 'hidden'; inp.name = 'l[' + i + '][' + p[0] + ']'; inp.value = p[1]; gen.appendChild(inp);
        });
      });
    }
    if (!L.length) { e.preventDefault(); $('[data-ap-hint]').textContent = 'Para guardar: introduce los apuntes.'; }
  });

  // ---------------------------------------------------------------- OCR
  $$('[data-ocr-drop]').forEach(function (d) {
    var inp = $('[data-ocr-file]', d), modo = d.getAttribute('data-ocr-drop'), t = $('[data-dz-t]', d), s = $('[data-dz-s]', d);
    var leer = function (file) {
      t.textContent = file.name; s.textContent = 'Leyendo con OCR…'; d.classList.add('leyendo');
      if (!window.AGCOcr) { s.textContent = 'El lector no está disponible: introduce los datos a mano.'; return; }
      window.AGCOcr.texto(file, function (m) { s.textContent = m; }).then(function (txt) {
        if (modo === 'factura') {
          var r = window.AGCOcr.factura(txt, cfg.nif);
          if (r.nif) F('nif').value = r.nif;
          if (r.num) F('num').value = r.num;
          if (r.fecha) F('ffecha').value = r.fecha;
          if (r.base) F('base').value = eu(r.base);
          if (r.iva !== undefined) F('iva').value = String([21, 10, 5, 4, 0].indexOf(r.iva) >= 0 ? r.iva : 21);
          F('ret').value = String([0, 1, 7, 15, 19].indexOf(r.ret) >= 0 ? r.ret : 0);
          // Tercero por NIF entre las subcuentas
          var nif = r.nif;
          if (nif && TER[nif]) F('ter').value = TER[nif];
          s.textContent = 'Leído · confianza ' + r.confianza + ' %' + (r.nombre ? ' · ' + r.nombre : '') + '. Revisa los datos antes de guardar.';
        } else {
          var n = window.AGCOcr.nomina(txt);
          [['mon', n.mon], ['irpf', n.irpf], ['ssT', n.ssT], ['ssE', n.ssE], ['ant', n.ant]].forEach(function (p) { var i = $('[data-nom-v="' + p[0] + '"]'); if (i && p[1]) i.value = eu(p[1]); });
          if (n.concepto) $('[data-nom-conc]').value = n.concepto;
          s.textContent = 'Leído' + (n.liquido ? ' · líquido a percibir ' + eu(n.liquido) + ' €' : '') + '. Revisa las partidas' + (n.ssE ? '' : ' (la Seguridad Social de la empresa no aparece: escríbela)') + '.';
        }
        d.classList.remove('leyendo'); calc();
      }).catch(function () { d.classList.remove('leyendo'); s.textContent = 'No se ha podido leer el documento: introduce los datos a mano.'; });
    };
    inp.addEventListener('change', function () { if (inp.files[0]) leer(inp.files[0]); });
    d.addEventListener('dragover', function (e) { e.preventDefault(); d.classList.add('sobre'); });
    d.addEventListener('dragleave', function () { d.classList.remove('sobre'); });
    d.addEventListener('drop', function (e) {
      e.preventDefault(); d.classList.remove('sobre');
      var file = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0]; if (!file) return;
      try { var dt = new DataTransfer(); dt.items.add(file); inp.files = dt.files; } catch (x) {}
      leer(file);
    });
  });

  setTipo(tipo);
})();
