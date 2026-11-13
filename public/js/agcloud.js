/* AG Cloud · interacción ligera (sin dependencias). La validación definitiva está SIEMPRE en el servidor. */
(function () {
  'use strict';
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
  var on = function (el, ev, fn) { if (el) el.addEventListener(ev, fn); };

  // ---------- Formato español ----------
  // 1.234,56 (toLocaleString('es-ES') no agrupa los miles en números de 4 cifras)
  var eu = function (n) { var neg = n < 0, p = Math.abs(n).toFixed(2).split('.'); return (neg ? '−' : '') + p[0].replace(/\B(?=(\d{3})+(?!\d))/g, '.') + ',' + p[1]; };
  var pnum = function (v) { var s = String(v == null ? '' : v).trim(); return parseFloat(s.indexOf(',') >= 0 ? s.replace(/\./g, '').replace(',', '.') : s) || 0; };

  // ---------- Menú lateral ----------
  $$('[data-menu]').forEach(function (b) { on(b, 'click', function () { $('#app').classList.toggle('menu-open'); }); });
  on(document, 'click', function (e) {
    var app = $('#app');
    if (app && app.classList.contains('menu-open') && !e.target.closest('.side') && !e.target.closest('[data-menu]')) app.classList.remove('menu-open');
  });
  var closed = {};
  try { closed = JSON.parse(localStorage.getItem('agcloud.nav') || '{}'); } catch (e) {}
  $$('.nav-grp[data-grp]').forEach(function (g) {
    var k = g.getAttribute('data-grp');
    var hasOn = !!$('.nav-it.on', g);
    if (closed[k] && !hasOn) { g.classList.add('closed'); var c = $('.chev', g); if (c) c.textContent = '▸'; }
    $$('[data-toggle-grp]', g).forEach(function (t) {
      on(t, 'click', function (e) {
        if (t.tagName === 'A' && t.getAttribute('href') !== '#') return;
        e.preventDefault();
        var isC = g.classList.toggle('closed');
        var c = $('.chev', g); if (c) c.textContent = isC ? '▸' : '▾';
        closed[k] = isC; try { localStorage.setItem('agcloud.nav', JSON.stringify(closed)); } catch (er) {}
      });
    });
  });
  $$('[data-dgrp]').forEach(function (g) {
    on($('[data-dgrp-tog]', g), 'click', function (e) { var c = g.classList.toggle('closed'); e.currentTarget.textContent = c ? '▸' : '▾'; });
  });

  // ---------- Formularios genéricos ----------
  $$('[data-autosubmit]').forEach(function (i) {
    var t; on(i, 'input', function () { clearTimeout(t); t = setTimeout(function () { i.form && i.form.submit(); }, 600); });
  });
  $$('[data-change-submit]').forEach(function (i) { on(i, 'change', function () { i.form && i.form.submit(); }); });
  $$('[data-autoupload]').forEach(function (i) { on(i, 'change', function () { if (i.files && i.files.length && i.form) { i.form.submit(); } }); });
  $$('form[data-confirm]').forEach(function (f) { on(f, 'submit', function (e) { if (!window.confirm(f.getAttribute('data-confirm'))) e.preventDefault(); }); });
  $$('[data-file-name]').forEach(function (i) { on(i, 'change', function () { var s = i.parentNode.querySelector('span'); if (s && i.files[0]) s.textContent = i.files[0].name; }); });
  $$('.tog input').forEach(function (i) { on(i, 'change', function () { var s = i.parentNode.querySelector('small'); if (s) s.textContent = i.checked ? 'Sí' : 'No'; }); });
  on(document, 'keydown', function (e) {
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') { var h = $('[data-hotkey="k"]'); if (h) { e.preventDefault(); h.focus(); } }
    if (e.key === 'Escape') { var sh = $('.drawer .shade'); if (sh) window.location = sh.href; }
  });

  // ---------- Arrastrar y soltar archivos ----------
  $$('[data-dropzone], [data-drop-img]').forEach(function (z) {
    var inp = $('input[type=file]', z);
    on(z, 'dragover', function (e) { e.preventDefault(); z.classList.add('drag'); });
    on(z, 'dragleave', function (e) { if (e.target === z) z.classList.remove('drag'); });
    on(z, 'drop', function (e) {
      e.preventDefault(); z.classList.remove('drag');
      if (inp && e.dataTransfer && e.dataTransfer.files.length) { inp.files = e.dataTransfer.files; inp.form && inp.form.submit(); }
    });
  });
  $$('[data-uprow-del]').forEach(function (b) { on(b, 'click', function () { var r = b.closest('[data-uprow]'); if (r) r.remove(); }); });
  $$('[data-ren-sug]').forEach(function (b) { on(b, 'click', function () { var i = $('[data-ren]', b.form); if (i) i.value = b.getAttribute('data-ren-sug'); }); });

  // ---------- NIF / NIE / CIF ----------
  var L = 'TRWAGMYFPDXBNJZSQVHLCKE';
  function nifCheck(v) {
    var s = String(v || '').toUpperCase().replace(/[\s.\-]/g, '');
    if (!s) return { ok: false, empty: true, msg: '' };
    if (/^\d{8}[A-Z]$/.test(s)) { var l = L[+s.slice(0, 8) % 23]; return l === s[8] ? { ok: true, msg: 'DNI correcto · persona física' } : { ok: false, msg: 'DNI incorrecto: la letra debería ser ' + l }; }
    if (/^[XYZ]\d{7}[A-Z]$/.test(s)) { var l2 = L[+('XYZ'.indexOf(s[0]) + s.slice(1, 8)) % 23]; return l2 === s[8] ? { ok: true, msg: 'NIE correcto · persona extranjera' } : { ok: false, msg: 'NIE incorrecto: la letra debería ser ' + l2 }; }
    if (/^[KLM]\d{7}[A-Z]$/.test(s)) { var l3 = L[+s.slice(1, 8) % 23]; return l3 === s[8] ? { ok: true, msg: 'NIF especial (' + s[0] + ') correcto' } : { ok: false, msg: 'NIF incorrecto: la letra debería ser ' + l3 }; }
    if (/^[ABCDEFGHJNPQRSUVW]\d{7}[0-9A-J]$/.test(s)) {
      var d = s.slice(1, 8), sum = 0;
      for (var i = 0; i < 7; i++) { var n = +d[i]; if (i % 2 === 0) { n *= 2; n = Math.floor(n / 10) + (n % 10); } sum += n; }
      var c = (10 - (sum % 10)) % 10, cl = 'JABCDEFGHI'[c], last = s[8];
      var onlyL = 'PQRSNW'.indexOf(s[0]) >= 0, onlyD = 'ABEH'.indexOf(s[0]) >= 0;
      var ok = onlyL ? last === cl : onlyD ? last === String(c) : (last === cl || last === String(c));
      var T = { A: 'Sociedad anónima', B: 'Sociedad limitada', C: 'Sociedad colectiva', D: 'Sociedad comanditaria', E: 'Comunidad de bienes', F: 'Cooperativa', G: 'Asociación / fundación', H: 'Comunidad de propietarios', J: 'Sociedad civil', N: 'Entidad extranjera', P: 'Corporación local', Q: 'Organismo público', R: 'Congregación religiosa', S: 'Órgano de la Administración', U: 'Unión temporal de empresas', V: 'Otro tipo', W: 'Establecimiento permanente de no residente' };
      return ok ? { ok: true, msg: 'CIF correcto · ' + T[s[0]] } : { ok: false, msg: 'CIF incorrecto: el dígito de control debería ser ' + (onlyL ? cl : onlyD ? c : c + ' o ' + cl) };
    }
    return { ok: false, msg: 'Formato no válido. Usa DNI (12345678Z), NIE (X1234567L) o CIF (B12345674).' };
  }
  $$('[data-nif]').forEach(function (i) {
    var wrap = i.closest('.fld') || i.parentNode, mark = $('[data-nif-mark]', wrap), msg = $('[data-nif-msg]', wrap);
    var run = function () {
      var r = nifCheck(i.value);
      i.classList.toggle('good', r.ok); i.classList.toggle('bad', !r.ok && !r.empty && i.value.replace(/\s/g, '').length >= 9);
      if (mark) { mark.className = 'nifmark ' + (r.ok ? 'ok' : (!r.empty && i.value.replace(/\s/g, '').length >= 9 ? 'bad' : '')); mark.textContent = r.ok ? '✓' : '✕'; }
      if (msg) { msg.textContent = r.empty || (!r.ok && i.value.replace(/\s/g, '').length < 9) ? '' : r.msg; msg.className = 'hint ' + (r.ok ? 'ok' : 'err'); }
    };
    on(i, 'input', run); run();
  });

  // ---------- IBAN ----------
  var BANCOS = window.AGC_BANCOS || {};
  function bankOf(v) { var s = String(v).replace(/\s+/g, '').toUpperCase(); if (s.indexOf('ES') !== 0 || s.length < 8) return null; return BANCOS[s.slice(4, 8)] || null; }
  function ibanOk(v) {
    var s = String(v).replace(/\s+/g, '').toUpperCase();
    if (!/^[A-Z]{2}\d{2}[A-Z0-9]{10,30}$/.test(s) || (s.indexOf('ES') === 0 && s.length !== 24)) return false;
    var r = (s.slice(4) + s.slice(0, 4)).replace(/[A-Z]/g, function (ch) { return ch.charCodeAt(0) - 55; }), m = 0;
    for (var i = 0; i < r.length; i++) m = (m * 10 + +r[i]) % 97;
    return m === 1;
  }
  $$('[data-iban]').forEach(function (i) {
    var f = i.form, hint = $('[data-iban-hint]', i.closest('.fld') || i.parentNode);
    var run = function () {
      var s = i.value.replace(/\s+/g, ''), b = bankOf(s);
      // La entidad y el BIC dependen del código de entidad del IBAN: se rellenan siempre que se reconoce
      if (b && f) { var e = f.querySelector('[name="d[entidad]"]'), bic = f.querySelector('[name="d[bic]"]'); if (e) e.value = b[0]; if (bic && b[1]) bic.value = b[1]; }
      if (!hint) return;
      if (s.length < 8) { hint.textContent = ''; return; }
      if (s.length >= 24 && !ibanOk(s)) { hint.textContent = 'IBAN no válido: revisa los dígitos de control'; hint.className = 'hint err'; return; }
      hint.textContent = b ? b[0] + (b[1] ? ' · BIC ' + b[1] : ' · sin BIC registrado: escríbelo a mano') + (s.length >= 24 ? ' · IBAN correcto' : '') : 'Entidad ' + s.slice(4, 8) + ' no registrada en el Banco de España: revisa el IBAN';
      hint.className = 'hint' + (b ? '' : ' err');
    };
    on(i, 'input', run); run();
  });

  // ---------- Código postal / CCC → provincia ----------
  var PROV = ['Álava', 'Albacete', 'Alicante', 'Almería', 'Ávila', 'Badajoz', 'Illes Balears', 'Barcelona', 'Burgos', 'Cáceres', 'Cádiz', 'Castellón', 'Ciudad Real', 'Córdoba', 'A Coruña', 'Cuenca', 'Girona', 'Granada', 'Guadalajara', 'Gipuzkoa', 'Huelva', 'Huesca', 'Jaén', 'León', 'Lleida', 'La Rioja', 'Lugo', 'Madrid', 'Málaga', 'Murcia', 'Navarra', 'Ourense', 'Asturias', 'Palencia', 'Las Palmas', 'Pontevedra', 'Salamanca', 'Santa Cruz de Tenerife', 'Cantabria', 'Segovia', 'Sevilla', 'Soria', 'Tarragona', 'Teruel', 'Toledo', 'Valencia', 'Valladolid', 'Bizkaia', 'Zamora', 'Zaragoza', 'Ceuta', 'Melilla'];
  var provCod = function (v) { var d = String(v).replace(/\D/g, ''); if (d.length < 2) return ''; var n = +d.slice(0, 2); return n >= 1 && n <= 52 ? PROV[n - 1] : ''; };
  $$('[data-cp], [data-ccc]').forEach(function (i) {
    var isCp = i.hasAttribute('data-cp'), fld = i.closest('.fld'), hint = fld && (fld.querySelector('[data-cp-hint]') || fld.querySelector('[data-prov-hint]'));
    var run = function () {
      var p = isCp ? (/^\d{5}$/.test(i.value.trim()) ? provCod(i.value) : '') : provCod(i.value);
      if (hint) hint.textContent = p ? 'Provincia' + (isCp ? '' : ' del CCC') + ': ' + p : '';
      if (p && i.form) {
        var sel = i.form.querySelector(isCp ? '[data-provincia], [name="d[prov]"]' : '[name="d[cccProv]"], [name="d[prov]"]');
        if (sel && i === document.activeElement) sel.value = p;
      }
    };
    on(i, 'input', run); run();
  });

  // ---------- Portal: contraseña provisional ----------
  var pt = $('[data-portal-toggle]');
  on(pt, 'change', function () { var b = $('[data-portal-box]'); if (b) b.classList.toggle('hidden', !pt.checked); });
  on($('[data-portal-email]'), 'input', function (e) { var u = $('[data-portal-user]'); if (u) u.textContent = e.target.value.trim() ? 'Usuario: ' + e.target.value.trim() : 'El usuario será el correo del cliente'; });
  on($('[data-pass-show]'), 'click', function () { var p = $('[data-pass]'); if (p) p.type = p.type === 'password' ? 'text' : 'password'; });
  on($('[data-pass-gen]'), 'click', function () {
    var a = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ', n = '23456789', out = [], r = new Uint32Array(12);
    (window.crypto || window.msCrypto).getRandomValues(r);
    for (var i = 0; i < 12; i++) out.push(i < 9 ? a[r[i] % a.length] : n[r[i] % n.length]);
    out.sort(function () { return 0.5 - Math.random(); });
    var p = $('[data-pass]'); if (p) { p.value = out.join(''); p.type = 'text'; }
  });

  // ---------- Cuota (tarifa con varias líneas) ----------
  var cf = $('[data-cuota]');
  if (cf) {
    var MULT = { Mensual: 12, Trimestral: 4, Semestral: 2, Anual: 1 };
    var calc = function () {
      // IVA redondeado al céntimo por línea antes de sumar
      var r2 = function (x) { return Math.round((x + Number.EPSILON) * 100) / 100; };
      var base = 0, iva = 0;
      $$('[data-row]', cf).forEach(function (row) {
        var q = $('[data-c-q]', row), p = $('[data-c-p]', row), i = $('[data-c-i]', row);
        var b = r2(pnum(q ? q.value : 1) * pnum(p ? p.value : 0));
        base += b; iva += r2(b * (+(i ? i.value : 0) || 0) / 100);
      });
      base = r2(base); iva = r2(iva);
      var mult = MULT[$('[data-c-per]', cf).value];
      $('[data-c-base]', cf).textContent = eu(base) + ' €'; $('[data-c-ivat]', cf).textContent = eu(iva) + ' €'; $('[data-c-tot]', cf).textContent = eu(base + iva) + ' €';
      $('[data-c-anual]', cf).textContent = !base ? 'Escribe los conceptos y precios acordados con el cliente.' : mult ? 'Equivale a ' + eu(r2((base + iva) * mult)) + ' € al año con IVA.' : 'Importe de referencia por cada trámite.';
    };
    on(cf, 'input', calc); on(cf, 'change', calc);
    on(cf, 'click', function (e) { if (e.target.closest && (e.target.closest('[data-row-del]') || e.target.closest('[data-row-add]'))) setTimeout(calc, 0); });
    calc();
  }

  // ---------- CNAE / IAE ----------
  var combos = $$('[data-combo]');
  if (combos.length) {
    var data = null;
    var load = function (cb) {
      if (data) return cb(data);
      var sc = document.createElement('script');
      sc.src = (document.querySelector('script[src*="agcloud.js"]').src.split('/js/')[0]) + '/js/actividades-data.js';
      sc.onload = function () {
        var cnae = String(window.AG_CNAE_TXT || '').split('\n').filter(Boolean).map(function (l) { var p = l.split('|'); return { code: p[0], desc: p[1], tag: 'CNAE' }; });
        var iae = String(window.AG_IAE_TXT || '').split('\n').filter(Boolean).map(function (l) { var p = l.split('|'); return { code: p[1], desc: p[2], tag: ['', 'Empresarial', 'Profesional', 'Artística'][+p[0]] || '' }; });
        data = { cnae: cnae, iae: iae }; cb(data);
      };
      document.head.appendChild(sc);
    };
    var norm = function (s) { return String(s).toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, ''); };
    combos.forEach(function (i) {
      var src = i.getAttribute('data-combo'), box = null, idx = -1, items = [];
      var close = function () { if (box) { box.remove(); box = null; } };
      var render = function () {
        load(function (D) {
          var q = norm(i.value.trim()), qd = q.replace(/[^0-9]/g, '');
          items = D[src].filter(function (o) { return !q || o.code.replace(/[^0-9]/g, '').indexOf(qd || '§') === 0 || norm(o.desc).indexOf(q) >= 0; }).slice(0, 40);
          close();
          box = document.createElement('div'); box.className = 'combo-list';
          var info = document.createElement('small'); info.textContent = items.length ? (src === 'cnae' ? 'CNAE 2025 (RD 10/2025)' : 'IAE (RDL 1175/1990)') + ' · ' + items.length + ' resultados' : 'Sin resultados'; box.appendChild(info);
          items.forEach(function (o, k) {
            var r = document.createElement('div'), b = document.createElement('b'), s = document.createElement('span'), t = document.createElement('i');
            b.textContent = o.code; s.textContent = o.desc; t.textContent = o.tag; r.appendChild(b); r.appendChild(s); r.appendChild(t);
            r.addEventListener('mousedown', function (e) { e.preventDefault(); pick(o); });
            if (k === idx) r.className = 'act';
            box.appendChild(r);
          });
          i.parentNode.appendChild(box);
        });
      };
      var pick = function (o) {
        i.value = o.code + ' · ' + o.desc; close();
        var desc = i.form && i.form.querySelector('[name="d[desc]"]'); if (desc && !desc.value) desc.value = o.desc;
      };
      on(i, 'focus', render); on(i, 'input', function () { idx = -1; render(); });
      on(i, 'blur', function () { setTimeout(close, 150); });
      on(i, 'keydown', function (e) {
        if (!box) return;
        if (e.key === 'ArrowDown') { e.preventDefault(); idx = Math.min(items.length - 1, idx + 1); render(); }
        if (e.key === 'ArrowUp') { e.preventDefault(); idx = Math.max(0, idx - 1); render(); }
        if (e.key === 'Enter' && idx >= 0) { e.preventDefault(); pick(items[idx]); }
      });
    });
  }


  // ---------- Notificaciones: registrar (plantillas) ----------
  var nf = $('[data-notif-form]');
  if (nf) {
    var tipos = []; try { tipos = JSON.parse(nf.getAttribute('data-tipos') || '[]'); } catch (e) {}
    var pl = $('[data-plantilla]', nf);
    on(pl, 'change', function () {
      var t = tipos[parseInt(pl.value, 10)];
      if (!t) { var ti = $('[data-f="2"]', nf); ti.value = ''; ti.focus(); return; }
      $$('[data-f]', nf).forEach(function (el) { var k = parseInt(el.getAttribute('data-f'), 10); el.value = t[k] == null ? '' : t[k]; });
    });
    var ab = $('[data-abierta]', nf), acc = $('[data-acceso]', nf);
    on(ab, 'change', function () { acc.classList.toggle('hidden', !ab.checked); });
  }

  // ---------- Avisos en este navegador ----------
  var navOn = function () { try { return localStorage.getItem('agcloud.avisosNav') === '1'; } catch (e) { return false; } };
  var cbNav = $('[data-avisos-nav]'), perm = $('[data-avisos-perm]');
  var permTxt = function () {
    if (!perm) return;
    perm.textContent = !window.Notification ? 'Este navegador no admite avisos.' : Notification.permission === 'granted' ? 'Permiso concedido en este navegador.' : Notification.permission === 'denied' ? 'Bloqueados en el navegador. Actívalos desde el candado de la barra de direcciones.' : 'El navegador pedirá permiso al activarlos.';
  };
  if (cbNav) {
    cbNav.checked = navOn() && !!window.Notification && Notification.permission === 'granted';
    permTxt();
    on(cbNav, 'change', function () {
      var save = function (ok) { try { localStorage.setItem('agcloud.avisosNav', ok ? '1' : '0'); } catch (e) {} cbNav.checked = ok; permTxt(); };
      if (!cbNav.checked || !window.Notification) return save(false);
      if (Notification.permission === 'granted') return save(true);
      try { Notification.requestPermission().then(function (p) { save(p === 'granted'); }); } catch (e) { save(false); }
    });
  }
  var mt = document.querySelector('meta[name="agc-caducan"]');
  if (mt && navOn() && window.Notification && Notification.permission === 'granted') {
    var nCad = parseInt(mt.getAttribute('content'), 10) || 0, hoyIso = new Date().toISOString().slice(0, 10), ult = '';
    try { ult = localStorage.getItem('agcloud.avisosUlt') || ''; } catch (e) {}
    if (nCad > 0 && ult !== hoyIso) {
      try { new Notification('AG Cloud · Notificaciones', { body: nCad + (nCad === 1 ? ' notificación sin abrir caduca' : ' notificaciones sin abrir caducan') + ' en los próximos días.' }); localStorage.setItem('agcloud.avisosUlt', hoyIso); } catch (e) {}
    }
  }

  // ---------- Expedientes: vista previa de la plantilla ----------
  var ef = $('[data-exp-form]');
  if (ef) {
    var tpl = {}; try { tpl = JSON.parse(ef.getAttribute('data-tpl') || '{}'); } catch (e) {}
    var stp = $('[data-exp-tpl]', ef), hon = $('[data-exp-hon]', ef);
    var pinta = function (cambiaHon) {
      var t = tpl[stp.value]; if (!t) return;
      $('[data-prev-cat]', ef).textContent = 'PLANTILLA · ' + t.cat;
      $('[data-prev-hon]', ef).textContent = eu(t.hon).replace(',00', '') + ' €';
      var ul = $('[data-prev-pasos]', ef);
      while (ul.firstChild) ul.removeChild(ul.firstChild);
      t.pasos.forEach(function (p) { var d = document.createElement('div'); d.className = 'prev-paso'; d.appendChild(document.createElement('span')); d.appendChild(document.createTextNode(p)); ul.appendChild(d); });
      $('[data-prev-docs]', ef).textContent = 'Se pedirá al cliente: ' + (t.docs.length ? t.docs.join(', ') : 'nada por ahora');
      if (cambiaHon && hon) hon.value = eu(t.hon);
    };
    on(stp, 'change', function () { pinta(true); });
    pinta(false);
  }


  // ---------- Facturación: nueva factura y presupuestos ----------
  var lineasForm = function (form, opts) {
    var box = $('[data-fc-lines]', form), tpl = $(opts.tpl);
    var cat = []; try { cat = JSON.parse(form.getAttribute('data-catalogo') || '[]'); } catch (e) {}
    var renum = function () {
      $$('[data-fc-line]', box).forEach(function (row, i) {
        $$('[data-k]', row).forEach(function (el) { el.name = 'lineas[' + i + '][' + el.getAttribute('data-k') + ']'; });
      });
    };
    var calc = function () {
      var base = 0, cuota = 0, sup = 0, ivas = {};
      $$('[data-fc-line]', box).forEach(function (row) {
        var q = pnum($('[data-k="q"]', row).value), p = pnum($('[data-k="p"]', row).value), cb = $('[data-k="sup"]', row);
        var s = cb && cb.checked, imp = Math.round(q * p * 100) / 100, iva = s ? 0 : pnum(($('[data-k="iva"]', row) || {}).value || 0);
        var out = $('[data-imp]', row); if (out) out.textContent = eu(imp);
        if (s) { sup += imp; return; }
        base += imp; ivas[iva] = (ivas[iva] || 0) + imp;
      });
      Object.keys(ivas).forEach(function (t) { cuota += Math.round(ivas[t] * t) / 100; });
      var retSel = $('[name="ret_pct"]', form), ret = retSel ? Math.round(base * pnum(retSel.value)) / 100 : 0;
      var tot = $('[data-fc-tot]', form);
      if (tot) tot.textContent = 'Base ' + eu(base) + ' · IVA ' + eu(cuota) + (ret ? ' · Retención −' + eu(ret) : '') + (sup ? ' · Suplidos ' + eu(sup) : '') + ' · Total ' + eu(base + cuota - ret + sup) + ' €';
    };
    var wire = function (row) {
      on($('[data-fc-del]', row), 'click', function () { if ($$('[data-fc-line]', box).length > 1) { row.remove(); renum(); calc(); refresh(); } });
      var cb = $('[data-k="sup"]', row);
      on(cb, 'change', function () { var s = cb.checked, iv = $('[data-k="iva"]', row), ns = $('.fc-nosuj', row); if (iv) iv.style.display = s ? 'none' : ''; if (ns) ns.style.display = s ? '' : 'none'; calc(); });
      on($('[data-k="d"]', row), 'change', function (e) {
        var v = e.target.value.trim().toLowerCase(), pr = cat.filter(function (x) { return String(x.nombre).toLowerCase() === v; })[0];
        if (pr) { $('[data-k="p"]', row).value = eu(+pr.precio); var iv = $('[data-k="iva"]', row); if (iv) iv.value = String(pr.iva); calc(); }
      });
    };
    var add = function (sup) {
      var row = tpl.content.firstElementChild.cloneNode(true);
      box.appendChild(row); wire(row);
      if (sup) { var cb = $('[data-k="sup"]', row); cb.checked = true; cb.dispatchEvent(new Event('change')); $('[data-k="d"]', row).value = 'Suplido · '; }
      renum(); calc(); $('[data-k="d"]', row).focus();
    };
    $$('[data-fc-line]', box).forEach(wire);
    on($('[data-fc-add]', form), 'click', function () { add(false); });
    on($('[data-fc-add-sup]', form), 'click', function () { add(true); });
    on(form, 'input', calc); on(form, 'change', calc);
    var prevBtn = $('[data-fc-prev]', form), tmr = null;
    var refresh = function () { if (!prevBtn) return; clearTimeout(tmr); tmr = setTimeout(function () { prevBtn.click(); }, 500); };
    on(form, 'change', refresh);
    renum(); calc(); refresh();
  };

  var fcf = $('[data-fc-form]');
  if (fcf) {
    lineasForm(fcf, { tpl: '[data-fc-tpl]' });
    var recs = [], prox = {};
    try { recs = JSON.parse(fcf.getAttribute('data-receptores') || '[]'); prox = JSON.parse(fcf.getAttribute('data-proximos') || '{}'); } catch (e) {}
    var cli = $('[data-fc-cli]', fcf), nuevo = $('[data-fc-cli-nuevo]', fcf), isel = $('[data-fc-iban-sel]', fcf), iban = $('[data-fc-iban]', fcf);
    var find = function (n) { n = String(n || '').trim().toLowerCase(); return recs.filter(function (x) { return x.nombre.toLowerCase() === n; })[0]; };
    var fillIbans = function (r) {
      while (isel.options.length > 1) isel.remove(1);
      isel.options[0].text = r && r.ibans.length ? 'Elegir una cuenta del cliente…' : 'El cliente no tiene cuentas guardadas';
      (r ? r.ibans : []).forEach(function (ib) { var o = document.createElement('option'); o.value = ib; o.text = ib.replace(/(.{4})/g, '$1 ').trim(); isel.add(o); });
    };
    var onCli = function (fill) {
      var r = find(cli.value);
      if (nuevo) nuevo.style.display = cli.value.trim() && !r ? '' : 'none';
      // Cliente del despacho con tarifa y factura aún sin conceptos: se carga su cuota (líneas, incluido, cobro)
      if (r && fill && r.cuota) {
        var vacia = $$('[name^="lineas["][name$="[d]"]', fcf).every(function (x) { return !x.value.trim(); }) && $$('[name^="lineas["][name$="[p]"]', fcf).every(function (x) { return !x.value.trim(); });
        if (vacia) {
          var fe = $('[name="fecha"]', fcf), se = $('[name="serie"]', fcf);
          window.location = r.cuota + '&fecha=' + encodeURIComponent(fe ? fe.value : '') + '&serie=' + encodeURIComponent(se ? se.value : '');
          return;
        }
      }
      if (r && fill) {
        $('[data-fc-nif]', fcf).value = r.nif; $('[data-fc-dir]', fcf).value = r.dir;
        var fps = $('[data-fc-fp]', fcf);
        if (r.fp && fps && [].some.call(fps.options, function (o) { return o.value === r.fp; })) { fps.value = r.fp; fps.dispatchEvent(new Event('change')); }
        if (r.ibanSepa) iban.value = r.ibanSepa; else if (!iban.value && r.ibans[0]) iban.value = r.ibans[0];
        var ems = $('[name="iban_emisor"]', fcf);
        if (r.ibanDesp && ems && [].some.call(ems.options, function (o) { return o.value === r.ibanDesp; })) ems.value = r.ibanDesp;
        [['[data-fc-cp]', 'cp'], ['[data-fc-mun]', 'municipio'], ['[data-fc-prov]', 'provincia']].forEach(function (x) { var el = $(x[0], fcf); if (el && r[x[1]] != null) el.value = r[x[1]]; });
      }
      fillIbans(r);
    };
    on(cli, 'change', function () { onCli(true); }); onCli(false);
    on(isel, 'change', function () { if (isel.value) { iban.value = isel.value; iban.dispatchEvent(new Event('input')); } });
    var fp = $('[data-fc-fp]', fcf);
    var onFp = function () {
      var v = fp.value, bank = v === 'Transferencia' || v === 'Domiciliación SEPA';
      $('[data-fc-bank]', fcf).style.display = bank ? '' : 'none';
      $$('[data-fc-cli-bank]', fcf).forEach(function (x) { x.style.display = v === 'Domiciliación SEPA' ? '' : 'none'; });
      $('[data-fc-em]', fcf).style.display = v === 'Transferencia' ? '' : 'none';
      $('[data-fc-dias]', fcf).style.display = ['Tarjeta', 'Bizum', 'Efectivo'].indexOf(v) >= 0 ? 'none' : '';
    };
    on(fp, 'change', onFp); onFp();
    var ser = $('[data-fc-serie]', fcf), nx = $('[data-fc-next]', fcf);
    on(ser, 'change', function () { if (nx) nx.textContent = 'Próximo número: ' + (prox[ser.value] || ''); });
  }
  var prf = $('[data-pr-form]');
  if (prf) lineasForm(prf, { tpl: '[data-pr-tpl]' });

  // ---------- Facturación: configuración ----------
  $$('[data-color-pick]').forEach(function (r) { on(r, 'change', function () { var ci = $('[data-color-input]'); if (ci) ci.value = r.value; }); });
  on($('[data-color-input]'), 'input', function () { $$('[data-color-pick]').forEach(function (r) { r.checked = false; }); });
  var catBox = $('[data-cat-box]');
  if (catBox) {
    var catN = 1000;
    var catWire = function (row) { on($('[data-cat-del]', row), 'click', function () { row.remove(); var d = $('[data-dirty]'); if (d) d.style.display = ''; }); };
    $$('[data-cat-row]', catBox).forEach(catWire);
    on($('[data-cat-add]'), 'click', function () {
      var row = $('[data-cat-tpl]').content.firstElementChild.cloneNode(true), k = catN++;
      $$('[name]', row).forEach(function (el) { el.name = el.name.replace('__', k); });
      catBox.appendChild(row); catWire(row); $('input', row).focus();
    });
    var cf = catBox.closest('form');
    on(cf, 'change', function () { var d = $('[data-dirty]'); if (d) d.style.display = ''; });
  }

  // ---------- Parámetros: filas que se añaden y se quitan ----------
  var rowN = 5000;
  $$('[data-rows]').forEach(function (box) {
    var k = box.getAttribute('data-rows');
    var wire = function (row) { on($('[data-row-del]', row), 'click', function () { if ($$('[data-row]', box).length > 1) row.remove(); }); };
    $$('[data-row]', box).forEach(wire);
    on($('[data-row-add="' + k + '"]'), 'click', function () {
      var tpl = $('[data-row-tpl="' + k + '"]'), row = tpl.content.firstElementChild.cloneNode(true), n = rowN++;
      $$('[name]', row).forEach(function (el) { el.name = el.name.replace('[__]', '[' + n + ']'); });
      box.appendChild(row); wire(row); var f = $('input', row); if (f) f.focus();
    });
  });

  // ---------- Gestión: proveedores, acreedores y facturas recibidas ----------
  $$('select[data-nav]').forEach(function (s) { on(s, 'change', function () { if (s.value) window.location.href = s.value; }); });
  var terForm = $('[data-tercero-form]');
  if (terForm) {
    var cta = $('[data-cta]', terForm);
    var modoCta = function () { var m = $('[data-cta-modo]:checked', terForm); var auto = !m || m.value === 'auto'; cta.readOnly = auto; cta.style.background = auto ? '#F4F4F0' : '#fff'; };
    $$('[data-cta-modo]', terForm).forEach(function (x) { on(x, 'change', modoCta); });
    modoCta();
  }
  var recForm = $('[data-rec-form]');
  if (recForm) {
    var ter = {}; try { ter = JSON.parse(recForm.getAttribute('data-ter') || '{}'); } catch (e) {}
    var calcRec = function () {
      var b = pnum(($('[data-rec-base]', recForm) || {}).value), iv = pnum(($('[data-rec-iva]', recForm) || {}).value), rt = pnum(($('[data-rec-ret]', recForm) || {}).value || 0);
      var cu = Math.round(b * iv) / 100, rete = Math.round(b * rt) / 100, out = $('[data-rec-tot]', recForm);
      if (out) out.textContent = 'Total ' + eu(b + cu) + ' €' + (rete ? ' · retención ' + eu(rete) + ' € · a pagar ' + eu(b + cu - rete) + ' €' : '');
    };
    var terSel = $('[data-rec-ter]', recForm);
    on(terSel, 'change', function () { var t = ter[terSel.value]; if (!t) return; var r = $('[data-rec-ret]', recForm); if (r) r.value = String(t.ret); var fp = $('[data-rec-fp]', recForm); if (fp && t.fp) fp.value = t.fp; calcRec(); });
    on(recForm, 'input', calcRec); on(recForm, 'change', calcRec); calcRec();
  }

  // ---------- Facturación: diseño de la factura con vista previa en directo ----------
  var disForm = $('[data-dis-form]');
  if (disForm) {
    var disBtn = $('[data-dis-prev]', disForm), disT = null;
    var disRefresh = function () { var d = $('[data-dirty]', disForm); if (d) d.style.display = ''; clearTimeout(disT); disT = setTimeout(function () { disBtn.click(); }, 350); };
    on(disForm, 'change', disRefresh);
    on(disForm, 'input', function (e) { if (e.target.type === 'color' || e.target.tagName === 'TEXTAREA') disRefresh(); });
  }

  // ---------- Contabilidad: asiento manual y bandeja ----------
  var band = $('[data-bandeja]');
  if (band) {
    var todas = $('[data-band-todas]', band);
    on(todas, 'change', function () { $$('[data-band-k]', band).forEach(function (c) { c.checked = todas.checked; }); });
  }

  // ---------- Fiscal: recálculo al momento de las casillas del modelo ----------
  var ff = $('[data-fiscal]');
  if (ff) {
    var fT = null, fDirty = $('[data-dirty]');
    var fCalc = function () {
      fetch(ff.getAttribute('data-calc'), { method: 'POST', body: new FormData(ff), headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }, credentials: 'same-origin' })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (j) {
          if (!j) return;
          Object.keys(j.casillas || {}).forEach(function (id) {
            ff.querySelectorAll('[data-cas]').forEach(function (el) { if (el.getAttribute('data-cas') === id) el.textContent = j.casillas[id]; });
          });
          var rs = $('[data-res]'), rl = $('[data-reslbl]');
          if (rs && j.res) rs.textContent = j.res;
          if (rl) rl.textContent = j.resLbl || '';
          var sel = $('[data-tipo]');
          if (sel && j.tipoOpts) {
            var keys = Object.keys(j.tipoOpts), cur = keys.map(function (k) { return k; }).join(','), now = Array.prototype.map.call(sel.options, function (o) { return o.value; }).join(',');
            if (cur !== now) {
              sel.innerHTML = '';
              keys.forEach(function (k) { var o = document.createElement('option'); o.value = k; o.textContent = j.tipoOpts[k]; if (k === j.tipo) o.selected = true; sel.appendChild(o); });
            }
          }
        })
        .catch(function () {});
    };
    ff.addEventListener('input', function (e) {
      if (e.target.classList && e.target.classList.contains('fis-inp')) {
        var orig = ff.querySelector('input[type=hidden][name="' + e.target.name.replace(/^cas/, 'orig') + '"]');
        e.target.classList.toggle('cambiada', !!orig && orig.value.trim() !== e.target.value.trim());
      }
      if (fDirty) fDirty.classList.remove('hidden');
      clearTimeout(fT); fT = setTimeout(fCalc, 350);
    });
    ff.addEventListener('change', function (e) { if (e.target.hasAttribute && e.target.hasAttribute('data-tipo') && fDirty) fDirty.classList.remove('hidden'); });
  }

  // ---------- Bandeja de facturas: subida y ventana de revisión con OCR ----------
  var bdz = $('[data-band-drop]');
  if (bdz) {
    var bfi = $('[data-band-files]'), bform = bdz.closest('form');
    on(bfi, 'change', function () { if (bfi.files.length) bform.submit(); });
    on(bdz, 'dragover', function (e) { e.preventDefault(); bdz.classList.add('sobre'); });
    on(bdz, 'dragleave', function () { bdz.classList.remove('sobre'); });
    on(bdz, 'drop', function (e) {
      e.preventDefault(); bdz.classList.remove('sobre');
      if (!e.dataTransfer || !e.dataTransfer.files.length) return;
      try { bfi.files = e.dataTransfer.files; bform.submit(); } catch (x) {}
    });
  }
  var rv = $('[data-rev]');
  if (rv && $('[data-r="base"]', rv)) {
    var rcta = {}, rter = {}, rdef = {};
    try { rcta = JSON.parse(rv.getAttribute('data-cuentas') || '{}'); rter = JSON.parse(rv.getAttribute('data-terceros') || '{}'); rdef = JSON.parse(rv.getAttribute('data-cta-def') || '{}'); } catch (e) {}
    var R = function (k) { return $('[data-r="' + k + '"]', rv); };
    var rclase = function () { var v = $('[data-rev-clase-v]', rv); return v ? v.value : 'Recibida'; };
    var rcalc = function () {
      var base = pnum(R('base').value), iva = Math.round(base * (+R('iva').value) / 100 * 100) / 100, ret = Math.round(base * (+R('ret').value) / 100 * 100) / 100;
      $('[data-rt="base"]', rv).textContent = eu(base); $('[data-rt="iva"]', rv).textContent = eu(iva); $('[data-rt="ret"]', rv).textContent = eu(ret); $('[data-rt="tot"]', rv).textContent = eu(base + iva - ret);
      var c = String(R('cuenta').value || '').replace(/\D/g, ''); c = c.length >= 9 ? c.slice(0, 9) : (c + '000000000').slice(0, 9);
      var n = $('[data-rev-cta-n]', rv); n.textContent = R('cuenta').value ? (rcta[c] || rcta[(c.slice(0, 3) + '000000')] || 'No existe en el PGC') : '';
      var ter = $('[data-rev-ter]', rv), nuevo = !ter || !ter.value;
      $$('[data-rev-nuevo]', rv).forEach(function (el) { el.style.display = rclase() === 'Emitida' || nuevo ? '' : 'none'; });
      $$('[data-solo]', rv).forEach(function (el) { if (!el.hasAttribute('data-rev-nuevo')) el.style.display = el.getAttribute('data-solo') === rclase() ? '' : 'none'; else if (rclase() !== 'Recibida') el.style.display = 'none'; });
      var cl = $('[data-rev-cta-l]', rv); if (cl) cl.textContent = rclase() === 'Emitida' ? 'ingreso' : 'gasto';
    };
    $$('[data-rev-clase]', rv).forEach(function (b) {
      on(b, 'click', function () {
        var antes = rclase();
        $('[data-rev-clase-v]', rv).value = b.getAttribute('data-rev-clase');
        $$('[data-rev-clase]', rv).forEach(function (x) { x.classList.toggle('on', x === b); });
        if (!R('cuenta').value || R('cuenta').value === rdef[antes]) R('cuenta').value = rdef[rclase()] || '';
        rcalc();
      });
    });
    on(rv, 'input', rcalc); on(rv, 'change', rcalc); rcalc();
    var bocr = $('[data-rev-ocr]', rv), rmsg = $('[data-rev-msg]', rv);
    on(bocr, 'click', function () {
      if (!window.AGCOcr) { rmsg.textContent = 'El lector OCR no está disponible.'; return; }
      bocr.disabled = true; rmsg.textContent = 'Descargando el documento…';
      fetch(rv.getAttribute('data-doc'), { credentials: 'same-origin' }).then(function (r) { if (!r.ok) throw new Error('doc'); return r.blob(); })
        .then(function (bl) { return window.AGCOcr.texto(bl, function (m) { rmsg.textContent = m; }); })
        .then(function (txt) {
          var o = window.AGCOcr.factura(txt, rv.getAttribute('data-nif-empresa'));
          if (o.nif) R('nif').value = o.nif;
          if (o.nombre && !R('nombre').value) R('nombre').value = o.nombre;
          if (o.num) R('num').value = o.num;
          if (o.fecha) R('fecha').value = o.fecha;
          if (o.base) R('base').value = eu(o.base);
          if ([21, 10, 5, 4, 0].indexOf(o.iva) >= 0) R('iva').value = String(o.iva);
          if ([0, 1, 7, 15, 19].indexOf(o.ret) >= 0) R('ret').value = String(o.ret);
          var t = o.nif && rter[o.nif], sel = $('[data-rev-ter]', rv);
          if (t && sel) sel.value = String(t[0]);
          rmsg.textContent = 'Leído con OCR · confianza ' + o.confianza + ' %' + (t ? ' · tercero reconocido por su NIF' : (o.nif ? ' · NIF ' + o.nif + ' sin dar de alta: se creará al contabilizar' : '')) + '. Revisa los datos.';
          rmsg.style.color = o.confianza >= 80 ? '#2E3A00' : '#6E4300';
          rcalc();
        })
        .catch(function () { rmsg.textContent = 'No se ha podido leer el documento: completa los datos a mano.'; rmsg.style.color = '#8A1C1C'; })
        .then(function () { bocr.disabled = false; });
    });
    // Documento nuevo: se lee solo al abrirlo
    if (bocr && !R('num').value) bocr.click();
  }
  // Imprimir / guardar como PDF y cierre de los desplegables de pestañas
  $$('[data-print]').forEach(function (b) { on(b, 'click', function () { window.print(); }); });
  document.addEventListener('click', function (e) { $$('details.ct-dd[open]').forEach(function (d) { if (!d.contains(e.target)) d.removeAttribute('open'); }); });
  $$('[data-autosubmit-select]').forEach(function (s) { on(s, 'change', function () { s.form.submit(); }); });

  // ---------- Laboral y SS ----------
  // Formularios que abren la sede oficial (RED / SEPE) en otra pestaña al registrar el trámite
  $$('form[data-abrir]').forEach(function (f) { on(f, 'submit', function () { try { window.open(f.getAttribute('data-abrir'), '_blank', 'noopener'); } catch (e) {} }); });
  $$('input[data-autosubmit]').forEach(function (i) { on(i, 'change', function () { i.form && i.form.submit(); }); });
  // Filas dinámicas (hijos, ascendientes, categorías del convenio)
  $$('[data-lb-add]').forEach(function (b) {
    on(b, 'click', function () {
      var k = b.getAttribute('data-lb-add'), tpl = $('template[data-lb-tpl="' + k + '"]'), list = $('[data-lb-list="' + k + '"]');
      if (!tpl || !list) return;
      var n = +list.getAttribute('data-n') || 0; list.setAttribute('data-n', n + 1);
      var div = document.createElement('div'); div.innerHTML = tpl.innerHTML.replace(/__/g, 'n' + n);
      var row = div.firstElementChild; list.appendChild(row); var f = row.querySelector('input'); f && f.focus();
    });
  });
  on(document, 'click', function (e) { var d = e.target.closest('[data-lb-del]'); if (d) { var r = d.parentElement; r && r.remove(); } });
  // Nombre del fichero elegido
  $$('input[data-lb-fname]').forEach(function (i) { on(i, 'change', function () { var t = i.parentElement.querySelector('[data-lb-fname-t]'); if (t && i.files[0]) t.textContent = i.files[0].name; }); });
  var mismo = $('[data-lb-mismo]'), sepeCert = $('[data-lb-sepecert]');
  on(mismo, 'change', function () { if (sepeCert) sepeCert.hidden = mismo.checked; });

  // Ficha del trabajador
  var lbf = $('#lbf');
  if (lbf) {
    var secIn = $('[data-lb-secin]');
    $$('[data-lb-sec]').forEach(function (b) {
      on(b, 'click', function () {
        var v = b.getAttribute('data-lb-sec');
        $$('[data-lb-sec]').forEach(function (x) { x.classList.toggle('on', x === b); });
        $$('[data-lb-panel]').forEach(function (p) { p.hidden = p.getAttribute('data-lb-panel') !== v; });
        if (secIn) secIn.value = v;
        try { var u = new URL(window.location.href); u.searchParams.set('sec', v); history.replaceState(null, '', u); } catch (e) {}
      });
    });
    var F = function (n) { return lbf.querySelector('[name="' + n + '"]'); };
    var box = function (attr) { return $$('[' + attr + ']', lbf); };
    var code = function () { var c = F('contrato'); return c ? c.value.split(' · ')[0] : ''; };
    var lbTemp = function () {
      var c = code(), temp = /^[45]/.test(c), sust = /^(410|510)/.test(c);
      box('data-lb-temp').forEach(function (x) { x.hidden = !temp; });
      box('data-lb-sust').forEach(function (x) { x.hidden = !sust; });
      var cl = $('[data-lb-causa] [data-lbl]', lbf);
      if (cl) cl.textContent = sust ? 'Causa de la sustitución' : /^(42|52)/.test(c) ? 'Titulación y puesto formativo' : 'Causa concreta de la temporalidad';
    };
    var horas = function () { var j = F('jor'), h = F('horas'); if (j && h) h.value = String(Math.round(40 * pnum(j.value) / 100)); };
    on(F('contrato'), 'change', function () {
      var c = code(), j = F('jor');
      if (j) { var tp = /^[25]/.test(c), v = pnum(j.value); if (tp && v >= 100) j.value = '50'; if (!tp) j.value = '100'; horas(); }
      var fin = F('fin'), alta = F('alta');
      if (/^[45]/.test(c) && fin && !fin.value && alta && alta.value) {
        var d = new Date(alta.value + 'T12:00:00'); d.setMonth(d.getMonth() + (/^(42|52)/.test(c) ? 12 : 6)); d.setDate(d.getDate() - 1); fin.value = d.toISOString().slice(0, 10);
      }
      lbTemp(); retr();
    });
    on(F('jor'), 'change', horas);
    var cats = {}; try { cats = JSON.parse(lbf.getAttribute('data-lb-cats') || '{}'); } catch (e) {}
    on(F('convCat'), 'change', function () {
      var ct = cats[F('convCat').value]; if (!ct) return;
      if (F('salBase')) F('salBase').value = ct[0]; if (F('plus')) F('plus').value = ct[1]; if (F('grupo')) F('grupo').value = ct[2]; retr();
    });
    var fijo = function () { var m = F('irpfModo'); box('data-lb-fijo').forEach(function (x) { x.hidden = !m || m.value !== 'fijo'; }); };
    on(F('irpfModo'), 'change', fijo);
    var sit = $('[data-lb-sit]', lbf), cony = $('[data-lb-cony]', lbf);
    on(sit, 'change', function () { if (cony) cony.hidden = sit.value !== '2'; });
    var retr = function () {
      var sb = pnum(F('salBase') && F('salBase').value), pl = pnum(F('plus') && F('plus').value), pg = +(F('pagas') && F('pagas').value) || 14, pr = F('prorr') && F('prorr').checked;
      var dia = F('salTipo') && F('salTipo').value === 'Diario', sd = pnum(F('salDia') && F('salDia').value), de = pnum(F('diasExtra') && F('diasExtra').value) || 30;
      box('data-lb-mens').forEach(function (x) { x.hidden = dia; });
      box('data-lb-dia').forEach(function (x) { x.hidden = !dia; });
      var pe = dia ? sd * de : sb, mes = dia ? sd * 30 : sb;
      var a = $('[data-lb-anual]'), m = $('[data-lb-mensual]');
      if (a) a.textContent = eu(dia ? sd * 365 + (pg - 12) * pe + pl * 12 : sb * pg + pl * 12) + ' €';
      if (m) m.textContent = eu(mes + pl + (pr && pg === 14 ? pe * 2 / 12 : 0)) + ' €';
    };
    ['salBase', 'salTipo', 'salDia', 'diasExtra', 'plus', 'pagas', 'prorr'].forEach(function (n) { on(F(n), 'input', retr); on(F(n), 'change', retr); });
    lbTemp(); fijo();
  }

  // Parámetros de laboral: lectura del PDF del convenio (primeras páginas)
  var convPdf = $('[data-lb-convpdf]'), convSt = $('[data-lb-convst]');
  on(convPdf, 'change', function () {
    var f = convPdf.files && convPdf.files[0]; convPdf.value = '';
    if (!f) return;
    var st = function (t) { if (convSt) { convSt.hidden = false; convSt.textContent = t; } };
    if (!window.AGCOcr) { st('No se ha podido cargar el lector de PDF. Introduce los datos a mano.'); return; }
    st('Leyendo el convenio ' + f.name + '…');
    window.AGCOcr.texto(f, st).then(function (t) {
      var txt = String(t || '').replace(/[ \t]+/g, ' '), n = 0;
      var put = function (k, v) { var i = $('[data-conv="' + k + '"]'); if (i && v) { i.value = v.trim().slice(0, 200); n++; } };
      var m = txt.match(/(convenio colectivo[^\n.]{5,180})/i); put('nombre', m && m[1].replace(/\s+/g, ' '));
      m = txt.match(/c[oó]digo(?: de convenio)?(?: n[ºo°.]*)?\s*:?\s*(\d{8,14})/i); put('codigo', m && m[1]);
      m = txt.match(/vigencia[^\n]{0,80}?((?:19|20)\d{2}\s*(?:[-–a]|al|y)\s*(?:19|20)\d{2})/i); put('vigencia', m && m[1].replace(/\s*(a|al|y)\s*/, '-'));
      m = txt.match(/(\d[\d.]{2,5})\s*horas?\s*(?:de trabajo\s*)?(?:efectivo\s*)?(?:anuales|al año|en c[oó]mputo anual)/i); put('jornada', m && m[1].replace(/\./g, ''));
      m = txt.match(/(\d{2})\s*d[ií]as\s*naturales\s*de\s*vacaciones|vacaciones[^\n]{0,60}?(\d{2})\s*d[ií]as\s*naturales/i); put('vacaciones', m && (m[1] || m[2]));
      m = txt.match(/[ÁA]mbito (?:territorial|funcional)[^\n.]{0,10}?:?\s*([^\n.]{4,80})/i); put('ambito', m && m[1]);
      st(n ? 'Convenio leído: ' + n + ' datos rellenados. Revisa los campos, completa las tablas salariales a mano y guarda los parámetros.' : 'No se han encontrado los datos del convenio en el PDF. Introdúcelos a mano.');
    }).catch(function () { st('No se ha podido leer el PDF del convenio. Introduce los datos a mano.'); });
  });

  // Laboral: marcadores del texto del contrato
  var lbTexto = $('[data-lb-texto]');
  $$('[data-lb-marca]').forEach(function (b) {
    on(b, 'click', function () {
      if (!lbTexto) return;
      var m = b.getAttribute('data-lb-marca'), s = lbTexto.selectionStart || 0, e2 = lbTexto.selectionEnd || s;
      lbTexto.value = lbTexto.value.slice(0, s) + m + lbTexto.value.slice(e2); lbTexto.focus(); lbTexto.selectionStart = lbTexto.selectionEnd = s + m.length;
    });
  });
  // Laboral: remesa de nóminas (marcar todos y total)
  var rem = $('form[data-lb-remesa]');
  if (rem) {
    var chks = $$('input[name="t[]"]:not([disabled])', rem), tot = $('[data-lb-remtot]', rem);
    var suma = function () { var s = 0; chks.forEach(function (c) { if (c.checked) s += +c.getAttribute('data-liq') || 0; }); if (tot) tot.textContent = eu(s) + ' €'; };
    on($('[data-lb-todos]', rem), 'change', function (e) { chks.forEach(function (c) { c.checked = e.target.checked; }); suma(); });
    chks.forEach(function (c) { on(c, 'change', suma); });
  }
  // Laboral: cláusulas por defecto según la modalidad al dar de alta
  var lbForm = $('#lbf');
  if (lbForm && lbForm.getAttribute('data-lb-clausdef')) {
    var cdef = {}; try { cdef = JSON.parse(lbForm.getAttribute('data-lb-clausdef')); } catch (e) {}
    on(lbForm.querySelector('[name="contrato"]'), 'change', function (e) {
      var k = e.target.value.split(' · ')[0], l = cdef[k]; if (!l) return;
      $$('input[name="claus[]"]', lbForm).forEach(function (c) { c.checked = l.indexOf(c.value) >= 0; });
    });
  }

  // Laboral: régimen del trabajador (cuenta ajena o autónomo) → apartados de la ficha
  var lbReg = document.querySelector('[data-lb-regimen] select');
  if (lbReg) {
    on(lbReg, 'change', function () {
      var aut = lbReg.value === 'Autónomo', act = null;
      $$('[data-lb-sec]').forEach(function (b) {
        var r = b.getAttribute('data-lb-reg') || '';
        b.hidden = !(r === '' || (r === 'aut') === aut);
        if (b.classList.contains('on')) act = b;
      });
      if (act && act.hidden) { var p = $('[data-lb-sec="personal"]'); if (p) p.click(); }
    });
  }

  // ---------- Integral de nóminas: editor ----------
  $$('[data-confirm-btn]').forEach(function (b) { on(b, 'click', function (e) { if (!window.confirm(b.getAttribute('data-confirm-btn'))) e.preventDefault(); }); });
  $$('[data-ne-del]').forEach(function (b) {
    on(b, 'click', function () {
      var row = b.closest('.ne-row'); if (!row) return;
      row.querySelectorAll('input').forEach(function (i) { if (i.type !== 'hidden') i.value = ''; });
      row.hidden = true;
    });
  });

  // ---------- Integral de nóminas: añadir líneas, catálogo de conceptos, cuantía × precio, IRPF de la AEAT ----------
  var neNum = function (v) { v = String(v || '').replace(/\./g, '').replace(',', '.').trim(); var n = parseFloat(v); return isNaN(n) ? null : n; };
  var neFmt = function (n) { return n.toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };
  $$('form[data-ne]').forEach(function (f) {
    var cat = {};
    try { cat = JSON.parse(f.getAttribute('data-ne-cat') || '{}'); } catch (e) { cat = {}; }
    var cuenta = { conc: 1, ded: 1 };
    var enlazar = function (row) {
      var c = row.querySelector('[data-ne-concepto]');
      if (c) on(c, 'change', function () {
        var k = cat[c.value.trim()]; if (!k) return;
        var cod = row.querySelector('[data-ne-cod]'); if (cod) cod.value = k[0];
        var cl = row.querySelector('[data-ne-clase]'); if (cl) cl.value = k[1];
      });
      var ca = row.querySelector('[data-ne-cant]'), pr = row.querySelector('[data-ne-precio]'), im = row.querySelector('[data-ne-imp]');
      var mult = function () { var a = neNum(ca.value), b = neNum(pr.value); if (a !== null && b !== null && a && b) im.value = neFmt(Math.round(a * b * 100) / 100); };
      if (ca && pr && im) { on(ca, 'input', mult); on(pr, 'input', mult); }
    };
    f.querySelectorAll('.ne-row, .ne-var').forEach(enlazar);
    f.querySelectorAll('[data-ne-add]').forEach(function (b) {
      on(b, 'click', function () {
        var tipo = b.getAttribute('data-ne-add');
        var tpl = f.querySelector('[data-ne-tpl="' + tipo + '"]'); if (!tpl) return;
        var n = tpl.cloneNode(true);
        n.removeAttribute('data-ne-tpl'); n.hidden = false;
        var i = cuenta[tipo]++;
        n.querySelectorAll('[name]').forEach(function (el) { el.name = el.name.replace('__i__', i); el.disabled = false; });
        tpl.parentNode.insertBefore(n, tpl);
        enlazar(n);
        var first = n.querySelector('input:not([type=hidden])'); if (first) first.focus();
      });
    });
    f.querySelectorAll('[data-ne-aeat]').forEach(function (b) {
      on(b, 'click', function () { var i = f.querySelector('[data-ne-irpf]'); if (i) { i.value = ''; i.focus(); } });
    });
  });

  // ---------- Menú lateral: recoger y desplegar (se recuerda en una cookie) ----------
  $$('[data-side-tog]').forEach(function (b) {
    on(b, 'click', function () {
      var app = $('#app'); if (!app) return;
      var min = app.classList.toggle('side-min');
      document.cookie = 'agc_side=' + (min ? 'min' : 'max') + ';path=/;max-age=31536000;SameSite=Lax';
    });
  });

  // ---------- Ficha del cliente › Cuota: la cuenta depende de la forma de cobro ----------
  $$('[data-cuota-fp]').forEach(function (s) {
    var f = s.form;
    var upd = function () { $$('[data-cuota-cta]', f).forEach(function (x) { x.style.display = x.getAttribute('data-cuota-cta') === s.value ? '' : 'none'; }); };
    on(s, 'change', upd); upd();
  });

  // ---------- 2FA: código de recuperación ----------
  on($('[data-2fa-switch]'), 'click', function () {
    var c = $('[data-2fa-code]'), r = $('[data-2fa-rec]'), t = $('[data-2fa-txt]');
    var toRec = r.classList.contains('hidden');
    c.classList.toggle('hidden', toRec); r.classList.toggle('hidden', !toRec);
    if (t) t.textContent = toRec ? 'Escribe uno de tus códigos de recuperación.' : 'Escribe el código de 6 cifras de tu aplicación de autenticación.';
    $('input', toRec ? r : c).focus();
  });
})();
