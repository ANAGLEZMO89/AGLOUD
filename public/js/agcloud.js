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
        $('[data-fc-nif]', fcf).value = r.nif; $('[data-fc-dir]', fcf).value = r.dir; if (!iban.value && r.ibans[0]) iban.value = r.ibans[0];
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
  var apF = $('[data-asiento]');
  if (apF) {
    var ctas = {}; try { ctas = JSON.parse(apF.getAttribute('data-cuentas') || '{}'); } catch (e) {}
    var apBox = $('[data-ap-lines]', apF), apN = 9000;
    var a9 = function (v) { v = String(v || '').replace(/\D/g, ''); return v.length >= 9 ? v.slice(0, 9) : (v + '000000000').slice(0, 9); };
    var nomDe = function (v) {
      var c = a9(v); if (!String(v || '').replace(/\D/g, '')) return '';
      if (ctas[c]) return ctas[c];
      var k4 = a9(c.slice(0, 4)), k3 = a9(c.slice(0, 3));
      return ctas[k4] || ctas[k3] || '⚠ No existe en el plan de cuentas';
    };
    var apCalc = function () {
      var d = 0, h = 0;
      $$('[data-ap-line]', apBox).forEach(function (row) {
        d += pnum($('[data-k="d"]', row).value); h += pnum($('[data-k="h"]', row).value);
        var n = $('[data-ap-nom]', row); n.textContent = nomDe($('[data-k="c"]', row).value);
        n.style.color = /^⚠/.test(n.textContent) ? '#B42318' : '#3C3C3C';
      });
      d = Math.round(d * 100) / 100; h = Math.round(h * 100) / 100;
      $('[data-ap-sd]', apF).textContent = eu(d); $('[data-ap-sh]', apF).textContent = eu(h);
      var cu = $('[data-ap-cuadre]', apF), ok = Math.abs(d - h) < 0.005 && d > 0;
      cu.textContent = ok ? '✓ Cuadra' : (d || h ? 'Diferencia ' + eu(d - h) : '');
      cu.style.color = ok ? '#2E7D32' : '#B42318';
    };
    var apWire = function (row) {
      on($('[data-ap-del]', row), 'click', function () { if ($$('[data-ap-line]', apBox).length > 2) { row.remove(); apCalc(); } });
      ['d', 'h'].forEach(function (k) {
        on($('[data-k="' + k + '"]', row), 'keydown', function (e) {
          if (e.key !== '=') return; e.preventDefault();
          var d = 0, h = 0; $$('[data-ap-line]', apBox).forEach(function (r) { if (r !== row) { d += pnum($('[data-k="d"]', r).value); h += pnum($('[data-k="h"]', r).value); } });
          var dif = Math.round((k === 'd' ? h - d : d - h) * 100) / 100; if (dif > 0) { e.target.value = eu(dif); apCalc(); }
        });
      });
    };
    $$('[data-ap-line]', apBox).forEach(apWire);
    on($('[data-ap-add]', apF), 'click', function () {
      var row = $('[data-ap-tpl]').content.firstElementChild.cloneNode(true), n = apN++;
      $$('[data-k]', row).forEach(function (el) { el.name = 'l[' + n + '][' + el.getAttribute('data-k') + ']'; });
      apBox.appendChild(row); apWire(row); $('input', row).focus();
    });
    on(apF, 'input', apCalc); apCalc();
  }
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

  // ---------- 2FA: código de recuperación ----------
  on($('[data-2fa-switch]'), 'click', function () {
    var c = $('[data-2fa-code]'), r = $('[data-2fa-rec]'), t = $('[data-2fa-txt]');
    var toRec = r.classList.contains('hidden');
    c.classList.toggle('hidden', toRec); r.classList.toggle('hidden', !toRec);
    if (t) t.textContent = toRec ? 'Escribe uno de tus códigos de recuperación.' : 'Escribe el código de 6 cifras de tu aplicación de autenticación.';
    $('input', toRec ? r : c).focus();
  });
})();
