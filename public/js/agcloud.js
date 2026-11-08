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
  var BANCOS = { '0049': ['Banco Santander', 'BSCHESMMXXX'], '0030': ['Banco Santander', 'ESPCESMMXXX'], '0075': ['Banco Santander', 'POPUESMMXXX'], '0182': ['BBVA', 'BBVAESMMXXX'], '2100': ['CaixaBank', 'CAIXESBBXXX'], '2038': ['CaixaBank', 'CAHMESMMXXX'], '0081': ['Banco Sabadell', 'BSABESBBXXX'], '0128': ['Bankinter', 'BKBKESMMXXX'], '3058': ['Cajamar', 'CCRIES2AXXX'], '1465': ['ING', 'INGDESMMXXX'], '0073': ['Openbank', 'OPENESMMXXX'], '2080': ['Abanca', 'CAGLESMMXXX'], '2085': ['Ibercaja', 'CAZRES2ZXXX'], '2095': ['Kutxabank', 'BASKES2BXXX'], '0239': ['EVO Banco', 'EVOBESMMXXX'], '1491': ['Triodos Bank', 'TRIOESMMXXX'], '2103': ['Unicaja', 'UCJAES2MXXX'], '2048': ['Unicaja (Liberbank)', 'CECAESMM048'], '0237': ['Cajasur', 'CSURES2CXXX'], '0487': ['Banco Mare Nostrum', 'GBMNESMMXXX'], '3035': ['Laboral Kutxa', 'CLPEES2MXXX'], '0061': ['Banca March', 'BMARES2MXXX'], '0186': ['Banco Mediolanum', 'BFIVESBBXXX'], '0019': ['Deutsche Bank', 'DEUTESBBXXX'], '3025': ["Caixa d'Enginyers", 'CDENESBBXXX'], '3183': ['Arquia Bank', 'CASDESBBXXX'], '0234': ['Banco Caminos', 'CCOCESMMXXX'], '0083': ['Renta 4 Banco', 'RENBESMMXXX'], '0138': ['Bankoa', 'BKOAES22XXX'], '1583': ['Revolut Bank', 'REVOESM2XXX'] };
  function bankOf(v) { var s = String(v).replace(/\s+/g, '').toUpperCase(); if (s.indexOf('ES') !== 0 || s.length < 8) return null; var c = s.slice(4, 8); return BANCOS[c] || (c.indexOf('30') === 0 ? ['Caja Rural / cooperativa de crédito (' + c + ')', 'BCOEESMM' + c.slice(1)] : null); }
  function ibanOk(v) {
    var s = String(v).replace(/\s+/g, '').toUpperCase();
    if (!/^[A-Z]{2}\d{2}[A-Z0-9]{10,30}$/.test(s) || (s.indexOf('ES') === 0 && s.length !== 24)) return false;
    var r = (s.slice(4) + s.slice(0, 4)).replace(/[A-Z]/g, function (ch) { return ch.charCodeAt(0) - 55; }), m = 0;
    for (var i = 0; i < r.length; i++) m = (m * 10 + +r[i]) % 97;
    return m === 1;
  }
  $$('[data-iban]').forEach(function (i) {
    var f = i.form, hint = $('[data-iban-hint]', i.closest('.fld'));
    var run = function () {
      var s = i.value.replace(/\s+/g, ''), b = bankOf(s);
      if (b && f) { var e = f.querySelector('[name="d[entidad]"]'), bic = f.querySelector('[name="d[bic]"]'); if (e && !e.value) e.value = b[0]; if (bic && !bic.value) bic.value = b[1]; }
      if (!hint) return;
      if (s.length < 8) { hint.textContent = ''; return; }
      if (s.length >= 24 && !ibanOk(s)) { hint.textContent = 'IBAN no válido: revisa los dígitos de control'; hint.className = 'hint err'; return; }
      hint.textContent = b ? b[0] + ' · BIC ' + b[1] + (s.length >= 24 ? ' · IBAN correcto' : '') : 'Entidad no reconocida: escribe la entidad y el BIC a mano';
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

  // ---------- Cuota ----------
  var cf = $('[data-cuota]');
  if (cf) {
    var MULT = { Mensual: 12, Trimestral: 4, Semestral: 2, Anual: 1 };
    var calc = function () {
      // IVA redondeado al céntimo antes de sumar (1.234,50 × 21 % = 259,25 → total 1.493,75)
      var r2 = function (x) { return Math.round((x + Number.EPSILON) * 100) / 100; };
      var imp = r2(pnum($('[data-c-imp]', cf).value)), iva = r2(imp * (+$('[data-c-iva]', cf).value || 0) / 100), mult = MULT[$('[data-c-per]', cf).value];
      $('[data-c-base]', cf).textContent = eu(imp) + ' €'; $('[data-c-ivat]', cf).textContent = eu(iva) + ' €'; $('[data-c-tot]', cf).textContent = eu(imp + iva) + ' €';
      $('[data-c-anual]', cf).textContent = !imp ? 'Escribe el importe acordado con el cliente.' : mult ? 'Equivale a ' + eu(r2((imp + iva) * mult)) + ' € al año con IVA.' : 'Importe de referencia por cada trámite.';
    };
    $$('input, select', cf).forEach(function (i) { on(i, 'input', calc); on(i, 'change', calc); });
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

  // ---------- 2FA: código de recuperación ----------
  on($('[data-2fa-switch]'), 'click', function () {
    var c = $('[data-2fa-code]'), r = $('[data-2fa-rec]'), t = $('[data-2fa-txt]');
    var toRec = r.classList.contains('hidden');
    c.classList.toggle('hidden', toRec); r.classList.toggle('hidden', !toRec);
    if (t) t.textContent = toRec ? 'Escribe uno de tus códigos de recuperación.' : 'Escribe el código de 6 cifras de tu aplicación de autenticación.';
    $('input', toRec ? r : c).focus();
  });
})();
