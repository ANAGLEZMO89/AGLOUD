{{-- Editor manual del convenio por secciones (fase 3). Cada sección es un formulario propio: se guarda por separado y avisa si hay cambios sin guardar. --}}
@php
  use App\Models\Convenio;
  use App\Models\ConvenioCategoria;
  use App\Models\ConvenioConcepto;
  use App\Services\Convenios;
  use App\Support\Formato;
  $secAct = session('cvSeccion');
  $R = $ver?->reglas ?? [];
  $catsV = $ver ? ConvenioCategoria::withoutGlobalScopes()->where('version_id', $ver->id)->orderBy('orden')->orderBy('id')->get() : collect();
  $concV = $ver ? ConvenioConcepto::withoutGlobalScopes()->with(['importes' => fn ($q) => $q->withoutGlobalScopes()])->where('version_id', $ver->id)->orderBy('orden')->orderBy('id')->get() : collect();
  $plusPC = $concV->firstWhere('nombre', Convenios::PLUS_CONVENIO)?->importes->pluck('importe', 'categoria_id') ?? collect();
  $concV = $concV->reject(fn ($x) => $x->nombre === Convenios::PLUS_CONVENIO)->values();
  $n = fn ($v, $d = 2) => $v === null || $v === '' ? '' : (is_numeric($v) ? Formato::num((float) $v, $d) : (string) $v);
  // Valor del formulario: lo enviado si esta sección falló al guardar; si no, lo guardado
  $ov = fn (string $sec, string $k, $def) => $secAct === $sec ? old($k, $def) : $def;
  $E = fn (string $k) => $errors->has($k) ? new \Illuminate\Support\HtmlString('<span class="cv-err" role="alert">'.e($errors->first($k)).'</span>') : '';
  $inv = fn (string $k) => $errors->has($k) ? 'aria-invalid=true' : '';
  $MES = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
  $opt = fn (array $ops, $sel) => new \Illuminate\Support\HtmlString(collect($ops)->map(fn ($l, $k) => '<option value="'.e($k).'"'.((string) $sel === (string) $k ? ' selected' : '').'>'.e($l).'</option>')->implode(''));
  $mesOpt = fn ($sel) => $opt(array_combine(range(1, 12), $MES), $sel);
  $rCont = $R['contrato'] ?? [];
  $rCal = $R['calendario'] ?? [];
  $est = [
      'identificacion' => $cv && $cv->nombre && $cv->codigo && $cv->publicacion ? 'ok' : 'pendiente',
      'version' => $ver && $ver->efectos_desde && $ver->estado === 'aprobado' ? 'ok' : 'pendiente',
      'categorias' => $catsV->isEmpty() ? 'falta' : ($catsV->contains(fn ($x) => $x->salario_base === null) ? 'pendiente' : 'ok'),
      'conceptos' => $concV->isEmpty() ? 'pendiente' : ($concV->contains(fn ($x) => ! Convenios::soportado($x)) ? 'pendiente' : 'ok'),
      'pagas' => ! empty($R['extras']) ? 'ok' : 'pendiente',
      'antiguedad' => ! empty($R['antig']) || ! empty($R['antigNo']) ? 'ok' : 'pendiente',
      'jornada' => ($R['jornada'] ?? '') !== '' && ($R['vacaciones'] ?? '') !== '' ? 'ok' : 'pendiente',
      'it' => 'pendiente',
      'contratacion' => ! empty($rCont['prueba']) ? 'ok' : 'pendiente',
  ];
  $col = ['ok' => ['#F3F9E1', '#2E3A00', '✓'], 'pendiente' => ['#FBF4E6', '#6E4300', '…'], 'falta' => ['#FBE9E9', '#8A1C1C', '!']];
@endphp

<nav class="cv-nav" aria-label="Secciones del convenio">
  @foreach(\App\Http\Controllers\ConvenioEditorController::SECCIONES as $k => $l)
    <a href="#sec-{{ $k }}" style="background:{{ $col[$est[$k]][0] }};color:{{ $col[$est[$k]][1] }}"><span aria-hidden="true">{{ $col[$est[$k]][2] }}</span> {{ $l }}</a>
  @endforeach
</nav>
@if($ver)<span class="hint muted">Editando la versión <b>{{ $ver->nombre }}</b> ({{ Convenio::ESTADOS[$ver->estado] ?? $ver->estado }}). Cada sección se guarda por separado; si sales con cambios sin guardar, te avisa.</span>@endif

{{-- 1 · Identificación --}}
<form method="POST" action="{{ route('laboral.convenios.seccion', 'identificacion') }}" class="card cv-sec" id="sec-identificacion" data-cv-sec>
  @csrf<input type="hidden" name="version_id" value="{{ $ver?->id }}">
  <div class="cv-h"><span>Identificación y publicación</span><span class="cv-dirty" data-cv-dirty hidden>Cambios sin guardar</span></div>
  <div class="lb-fields">
    <label class="lb-f" style="grid-column:1 / -1"><span class="lb-l">Nombre del convenio *</span><input class="inp lb-in" name="nombre" value="{{ $ov('identificacion', 'nombre', $cv?->nombre) }}" maxlength="250" required {{ $inv('nombre') }}>{{ $E('nombre') }}</label>
    <label class="lb-f"><span class="lb-l">Código REGCON</span><input class="inp lb-in mono" name="codigo" value="{{ $ov('identificacion', 'codigo', $cv?->codigo) }}" maxlength="40"></label>
    <label class="lb-f"><span class="lb-l">Sector</span><input class="inp lb-in" name="sector" value="{{ $ov('identificacion', 'sector', $cv?->sector) }}" maxlength="160"></label>
    <label class="lb-f"><span class="lb-l">Ámbito territorial</span><input class="inp lb-in" name="ambito_territorial" value="{{ $ov('identificacion', 'ambito_territorial', $cv?->ambito_territorial) }}" maxlength="160" placeholder="Provincia de Huelva"></label>
    <label class="lb-f"><span class="lb-l">Estado del convenio</span><select class="sel lb-in" name="estado">{{ $opt(Convenio::ESTADOS, $ov('identificacion', 'estado', $cv?->estado ?? 'borrador')) }}</select>{{ $E('estado') }}</label>
    <label class="lb-f" style="grid-column:1 / -1"><span class="lb-l">Ámbito funcional</span><input class="inp lb-in" name="ambito_funcional" value="{{ $ov('identificacion', 'ambito_funcional', $cv?->ambito_funcional) }}" maxlength="500"></label>
    <label class="lb-f" style="grid-column:1 / -1"><span class="lb-l">Ámbito personal</span><input class="inp lb-in" name="ambito_personal" value="{{ $ov('identificacion', 'ambito_personal', $cv?->ambito_personal) }}" maxlength="500"></label>
    <label class="lb-f"><span class="lb-l">Publicación oficial</span><input class="inp lb-in" name="publicacion" value="{{ $ov('identificacion', 'publicacion', $cv?->publicacion) }}" maxlength="250" placeholder="BOP de Huelva nº 123"></label>
    <label class="lb-f"><span class="lb-l">Fecha de publicación</span><input class="inp lb-in" type="date" name="fecha_publicacion" value="{{ $ov('identificacion', 'fecha_publicacion', $cv?->fecha_publicacion?->toDateString()) }}" {{ $inv('fecha_publicacion') }}>{{ $E('fecha_publicacion') }}</label>
    <label class="lb-f"><span class="lb-l">Enlace</span><input class="inp lb-in" name="url" value="{{ $ov('identificacion', 'url', $cv?->url) }}" maxlength="500" placeholder="https://" {{ $inv('url') }}>{{ $E('url') }}</label>
    <label class="lb-f" style="grid-column:1 / -1"><span class="lb-l">Observaciones</span><textarea class="txa" name="observaciones" rows="2" maxlength="5000">{{ $ov('identificacion', 'observaciones', $cv?->observaciones) }}</textarea></label>
    <label class="lb-f" style="grid-column:1 / -1"><span class="lb-l">Reglas que AG Cloud todavía no calcula (revisión profesional)</span><textarea class="txa" name="no_soportado" rows="2" maxlength="5000" placeholder="Ej.: plus de festivos especiales del art. 30; jornada irregular del art. 22…">{{ $ov('identificacion', 'no_soportado', $cv?->no_soportado) }}</textarea></label>
  </div>
  <div class="cv-f"><button class="btn sm" type="submit">Guardar identificación</button></div>
</form>

@if($cv && $ver)
{{-- 2 · Versión --}}
<form method="POST" action="{{ route('laboral.convenios.seccion', 'version') }}" class="card cv-sec" id="sec-version" data-cv-sec>
  @csrf<input type="hidden" name="version_id" value="{{ $ver->id }}">
  <div class="cv-h"><span>Versión y vigencia</span><span class="cv-dirty" data-cv-dirty hidden>Cambios sin guardar</span></div>
  <div class="lb-fields">
    <label class="lb-f"><span class="lb-l">Nombre de la versión *</span><input class="inp lb-in" name="nombre" value="{{ $ov('version', 'nombre', $ver->nombre) }}" maxlength="160" required {{ $inv('nombre') }}>{{ $E('nombre') }}</label>
    <label class="lb-f"><span class="lb-l">Efectos económicos desde</span><input class="inp lb-in" type="date" name="efectos_desde" value="{{ $ov('version', 'efectos_desde', $ver->efectos_desde?->toDateString()) }}">{{ $E('efectos_desde') }}</label>
    <label class="lb-f"><span class="lb-l">Efectos económicos hasta</span><input class="inp lb-in" type="date" name="efectos_hasta" value="{{ $ov('version', 'efectos_hasta', $ver->efectos_hasta?->toDateString()) }}" {{ $inv('efectos_hasta') }}>{{ $E('efectos_hasta') }}</label>
    <label class="lb-f"><span class="lb-l">Vigencia del texto desde</span><input class="inp lb-in" type="date" name="vigencia_desde" value="{{ $ov('version', 'vigencia_desde', $ver->vigencia_desde?->toDateString()) }}"></label>
    <label class="lb-f"><span class="lb-l">Vigencia del texto hasta</span><input class="inp lb-in" type="date" name="vigencia_hasta" value="{{ $ov('version', 'vigencia_hasta', $ver->vigencia_hasta?->toDateString()) }}" {{ $inv('vigencia_hasta') }}>{{ $E('vigencia_hasta') }}</label>
    <label class="lb-f"><span class="lb-l">Estado de la versión</span><select class="sel lb-in" name="estado" {{ $inv('estado') }}>{{ $opt(Convenio::ESTADOS, $ov('version', 'estado', $ver->estado)) }}</select>{{ $E('estado') }}</label>
  </div>
  <span class="hint muted">Las nóminas de cada mes usan la versión <b>aprobada</b> cuyos efectos económicos cubren ese mes. La vigencia del texto es informativa. @if($ver->aprobada_at)Aprobada el {{ Formato::fechaHora($ver->aprobada_at) }}.@endif</span>
  <div class="cv-f"><button class="btn sm" type="submit">Guardar versión</button></div>
</form>

{{-- 3 · Categorías --}}
@php $cRows = $secAct === 'categorias' && old('cats') ? array_values(old('cats')) : $catsV->map(fn ($x) => ['nombre' => $x->nombre, 'codigo' => $x->codigo, 'grupo_profesional' => $x->grupo_profesional, 'nivel' => $x->nivel,
    'grupo_cotizacion' => $x->grupo_cotizacion, 'unidad' => $x->unidad, 'salario_base' => $n($x->salario_base), 'plus' => $n($plusPC[$x->id] ?? 0), 'referencia' => $x->referencia])->all(); @endphp
<form method="POST" action="{{ route('laboral.convenios.seccion', 'categorias') }}" class="card cv-sec" id="sec-categorias" data-cv-sec>
  @csrf<input type="hidden" name="version_id" value="{{ $ver->id }}">
  <div class="cv-h"><span>Categorías y tablas salariales</span><span class="cv-dirty" data-cv-dirty hidden>Cambios sin guardar</span><button type="button" class="btn soft sm" data-lb-add="cvcats">+ Categoría</button></div>
  <div class="cv-tab" role="table" aria-label="Categorías">
    <div class="cv-cat cv-th" role="row"><span>Categoría *</span><span>Código</span><span>Grupo profesional</span><span>Nivel</span><span>Grupo cot.</span><span>Salario</span><span>Salario base €</span><span>Plus de convenio €/mes</span><span>Artículo / página</span><span></span></div>
    <div data-lb-list="cvcats" data-n="{{ count($cRows) }}">
      @foreach($cRows as $i => $r)
        <div class="cv-cat" role="row">
          <span><input class="inp lb-in" name="cats[{{ $i }}][nombre]" value="{{ $r['nombre'] ?? '' }}" aria-label="Categoría" {{ $inv("cats.$i.nombre") }}>{{ $E("cats.$i.nombre") }}</span>
          <input class="inp lb-in" name="cats[{{ $i }}][codigo]" value="{{ $r['codigo'] ?? '' }}" aria-label="Código">
          <input class="inp lb-in" name="cats[{{ $i }}][grupo_profesional]" value="{{ $r['grupo_profesional'] ?? '' }}" aria-label="Grupo profesional del convenio">
          <input class="inp lb-in" name="cats[{{ $i }}][nivel]" value="{{ $r['nivel'] ?? '' }}" aria-label="Nivel">
          <span><select class="sel lb-in" name="cats[{{ $i }}][grupo_cotizacion]" aria-label="Grupo de cotización"><option value="">—</option>{{ $opt(array_combine(range(1, 11), range(1, 11)), $r['grupo_cotizacion'] ?? '') }}</select>{{ $E("cats.$i.grupo_cotizacion") }}</span>
          <select class="sel lb-in" name="cats[{{ $i }}][unidad]" aria-label="Unidad del salario">{{ $opt(['mes' => 'Mensual', 'dia' => 'Diario'], $r['unidad'] ?? 'mes') }}</select>
          <span><input class="inp lb-in mono" name="cats[{{ $i }}][salario_base]" value="{{ $r['salario_base'] ?? '' }}" inputmode="decimal" placeholder="No localizado" aria-label="Salario base" {{ $inv("cats.$i.salario_base") }}>{{ $E("cats.$i.salario_base") }}</span>
          <input class="inp lb-in mono" name="cats[{{ $i }}][plus]" value="{{ $r['plus'] ?? '' }}" inputmode="decimal" aria-label="Plus de convenio">
          <input class="inp lb-in" name="cats[{{ $i }}][referencia]" value="{{ $r['referencia'] ?? '' }}" aria-label="Artículo o página" placeholder="Anexo I, pág. 30">
          <button type="button" class="lb-del" data-lb-del aria-label="Quitar categoría">×</button>
        </div>
      @endforeach
    </div>
  </div>
  <template data-lb-tpl="cvcats"><div class="cv-cat" role="row"><span><input class="inp lb-in" name="cats[__][nombre]" aria-label="Categoría"></span><input class="inp lb-in" name="cats[__][codigo]" aria-label="Código"><input class="inp lb-in" name="cats[__][grupo_profesional]" aria-label="Grupo profesional del convenio"><input class="inp lb-in" name="cats[__][nivel]" aria-label="Nivel"><select class="sel lb-in" name="cats[__][grupo_cotizacion]" aria-label="Grupo de cotización"><option value="">—</option>@foreach(range(1, 11) as $g)<option>{{ $g }}</option>@endforeach</select><select class="sel lb-in" name="cats[__][unidad]" aria-label="Unidad del salario"><option value="mes">Mensual</option><option value="dia">Diario</option></select><input class="inp lb-in mono" name="cats[__][salario_base]" inputmode="decimal" placeholder="No localizado" aria-label="Salario base"><input class="inp lb-in mono" name="cats[__][plus]" value="0,00" inputmode="decimal" aria-label="Plus de convenio"><input class="inp lb-in" name="cats[__][referencia]" aria-label="Artículo o página"><button type="button" class="lb-del" data-lb-del aria-label="Quitar categoría">×</button></div></template>
  <span class="hint muted">El grupo profesional y el nivel son los del convenio; el grupo de cotización es el de la Seguridad Social (1 a 11). Salario vacío = no localizado (nunca se toma como cero). Varias categorías pueden compartir nivel.</span>
  <div class="cv-f"><button class="btn sm" type="submit">Guardar categorías</button></div>
</form>

{{-- 4 · Conceptos retributivos --}}
@php $kRows = $secAct === 'conceptos' && old('conceptos') ? array_values(old('conceptos')) : $concV->map(fn ($x) => array_merge($x->only(['nombre', 'codigo', 'descripcion', 'cra', 'naturaleza', 'calculo', 'unidad', 'base', 'formula', 'cotiza', 'tributa', 'limite', 'redondeo', 'referencia']),
    ['valor' => $n($x->valor, 4), 'automatico' => $x->automatico, 'prop_jornada' => $x->prop_jornada, 'prop_periodo' => $x->prop_periodo, 'en_pagas' => $x->en_pagas, 'soportado' => Convenios::soportado($x),
     'motivo' => Convenios::motivoNoSoportado($x), 'limite_importe' => $n($x->limite_importe), 'limite_unidad' => $x->limite_unidad,
     'importes' => $x->importes->mapWithKeys(fn ($im) => [$im->categoria_id => $n($im->importe, 4)])->all()]))->all(); @endphp
<form method="POST" action="{{ route('laboral.convenios.seccion', 'conceptos') }}" class="card cv-sec" id="sec-conceptos" data-cv-sec>
  @csrf<input type="hidden" name="version_id" value="{{ $ver->id }}">
  <div class="cv-h"><span>Conceptos retributivos</span><span class="cv-dirty" data-cv-dirty hidden>Cambios sin guardar</span><button type="button" class="btn soft sm" data-lb-add="cvconc">+ Concepto</button></div>
  <details style="font-size:12.5px"><summary style="cursor:pointer;font-weight:600">Cómo escribir fórmulas</summary>
    <p style="margin:6px 0">Operaciones + − * / y paréntesis; decimales con coma o punto; <span class="mono">min(a; b)</span>, <span class="mono">max(a; b)</span>, <span class="mono">redondear(x; 2)</span>. Para usar otro concepto, ponle código y escribe <span class="mono">c_CÓDIGO</span>. Variables permitidas:</p>
    <ul style="margin:0;padding-left:18px;columns:2;line-height:1.5">@foreach(\App\Services\Formula::VARIABLES as $k => [$d, $u])<li><span class="mono">{{ $k }}</span>: {{ $d }} ({{ $u }})</li>@endforeach</ul>
    <p style="margin:6px 0 0">El resultado es el importe por la unidad del concepto (por mes o por día). Se calcula con decimales exactos y se redondea al final de la línea, la mitad hacia arriba.</p>
  </details>
  <span class="hint muted">Pluses, complementos y percepciones del convenio. El plus de convenio por categoría se edita en la tabla de categorías. La cotización y el IRPF se definen en cada concepto: nada se considera exento por llamarse «dieta» o «indemnización».</span>
  <div data-lb-list="cvconc" data-n="{{ count($kRows) }}" style="display:flex;flex-direction:column;gap:10px">
    @foreach($kRows as $i => $r)
      @include('laboral._convenio-concepto', ['i' => $i, 'r' => $r])
    @endforeach
  </div>
  <template data-lb-tpl="cvconc">@include('laboral._convenio-concepto', ['i' => '__', 'r' => ['naturaleza' => 'salarial', 'calculo' => 'fijo', 'unidad' => 'mes', 'cotiza' => 'si', 'tributa' => 'si', 'redondeo' => '2d', 'prop_jornada' => true, 'prop_periodo' => true]])</template>
  <div class="cv-f"><button class="btn sm" type="submit">Guardar conceptos</button></div>
</form>

{{-- 5 · Pagas extraordinarias --}}
@php $pRows = $secAct === 'pagas' && old('extras') ? array_values(old('extras')) : array_map(fn ($x) => ['nombre' => $x['nombre'] ?? '', 'mes' => $x['mes'] ?? 12, 'calculo' => $x['calculo'] ?? 'mensualidad',
    'dias' => $n($x['dias'] ?? null, 0), 'importe' => $n($x['importe'] ?? null), 'plus' => $x['plus'] ?? false, 'antig' => $x['antig'] ?? false, 'devengo_tipo' => $x['devengo']['tipo'] ?? 'anual',
    'devengo_desde' => $x['devengo']['desde'] ?? '', 'devengo_hasta' => $x['devengo']['hasta'] ?? '', 'conceptos' => $x['conceptos'] ?? '', 'referencia' => $x['art'] ?? ''], $R['extras'] ?? []); @endphp
<form method="POST" action="{{ route('laboral.convenios.seccion', 'pagas') }}" class="card cv-sec" id="sec-pagas" data-cv-sec>
  @csrf<input type="hidden" name="version_id" value="{{ $ver->id }}">
  <div class="cv-h"><span>Pagas extraordinarias</span><span class="cv-dirty" data-cv-dirty hidden>Cambios sin guardar</span><button type="button" class="btn soft sm" data-lb-add="cvpagas">+ Paga</button></div>
  <span class="hint muted">Cualquier número de pagas. Sin pagas aquí se aplican verano (junio) y Navidad (diciembre) de una mensualidad. El periodo de devengo se guarda ya; el cálculo proporcional a altas y bajas por devengo llega en la fase 6.</span>
  <div data-lb-list="cvpagas" data-n="{{ count($pRows) }}" style="display:flex;flex-direction:column;gap:8px">
    @foreach($pRows as $i => $x)
      <div class="lb-fila cv-pg">
        <label class="cv-l">Nombre *<input class="inp lb-in" name="extras[{{ $i }}][nombre]" value="{{ $x['nombre'] }}" {{ $inv("extras.$i.nombre") }}>{{ $E("extras.$i.nombre") }}</label>
        <label class="cv-l">Mes de cobro<select class="sel lb-in" name="extras[{{ $i }}][mes]">{{ $mesOpt($x['mes']) }}</select>{{ $E("extras.$i.mes") }}</label>
        <label class="cv-l">Importe<select class="sel lb-in" name="extras[{{ $i }}][calculo]">{{ $opt(['mensualidad' => 'Una mensualidad', 'dias' => 'Días de salario', 'importe' => 'Importe fijo'], $x['calculo']) }}</select></label>
        <label class="cv-l">Días<input class="inp lb-in mono" name="extras[{{ $i }}][dias]" value="{{ $x['dias'] }}" inputmode="decimal" style="width:70px" {{ $inv("extras.$i.dias") }}>{{ $E("extras.$i.dias") }}</label>
        <label class="cv-l">Importe €<input class="inp lb-in mono" name="extras[{{ $i }}][importe]" value="{{ $x['importe'] }}" inputmode="decimal" style="width:100px" {{ $inv("extras.$i.importe") }}>{{ $E("extras.$i.importe") }}</label>
        <label class="lb-chk"><input type="checkbox" name="extras[{{ $i }}][plus]" value="1" @checked(! empty($x['plus']))>+ plus</label>
        <label class="lb-chk"><input type="checkbox" name="extras[{{ $i }}][antig]" value="1" @checked(! empty($x['antig']))>+ antigüedad</label>
        <label class="cv-l">Devengo<select class="sel lb-in" name="extras[{{ $i }}][devengo_tipo]">{{ $opt(['anual' => 'Anual', 'semestral' => 'Semestral', 'otro' => 'Otro periodo'], $x['devengo_tipo']) }}</select></label>
        <label class="cv-l">Desde<select class="sel lb-in" name="extras[{{ $i }}][devengo_desde]"><option value="">—</option>{{ $mesOpt($x['devengo_desde']) }}</select>{{ $E("extras.$i.devengo.desde") }}</label>
        <label class="cv-l">Hasta<select class="sel lb-in" name="extras[{{ $i }}][devengo_hasta]"><option value="">—</option>{{ $mesOpt($x['devengo_hasta']) }}</select>{{ $E("extras.$i.devengo.hasta") }}</label>
        <label class="cv-l" style="flex:1 1 200px">Conceptos incluidos<input class="inp lb-in" name="extras[{{ $i }}][conceptos]" value="{{ $x['conceptos'] }}" placeholder="Salario base, antigüedad…"></label>
        <label class="cv-l">Artículo<input class="inp lb-in" name="extras[{{ $i }}][referencia]" value="{{ $x['referencia'] }}" style="width:110px"></label>
        <button type="button" class="lb-del" data-lb-del aria-label="Quitar paga">×</button>
      </div>
    @endforeach
  </div>
  <template data-lb-tpl="cvpagas"><div class="lb-fila cv-pg"><label class="cv-l">Nombre *<input class="inp lb-in" name="extras[__][nombre]"></label><label class="cv-l">Mes de cobro<select class="sel lb-in" name="extras[__][mes]">{{ $mesOpt(12) }}</select></label><label class="cv-l">Importe<select class="sel lb-in" name="extras[__][calculo]"><option value="mensualidad">Una mensualidad</option><option value="dias">Días de salario</option><option value="importe">Importe fijo</option></select></label><label class="cv-l">Días<input class="inp lb-in mono" name="extras[__][dias]" inputmode="decimal" style="width:70px"></label><label class="cv-l">Importe €<input class="inp lb-in mono" name="extras[__][importe]" inputmode="decimal" style="width:100px"></label><label class="lb-chk"><input type="checkbox" name="extras[__][plus]" value="1">+ plus</label><label class="lb-chk"><input type="checkbox" name="extras[__][antig]" value="1">+ antigüedad</label><label class="cv-l">Devengo<select class="sel lb-in" name="extras[__][devengo_tipo]"><option value="anual">Anual</option><option value="semestral">Semestral</option><option value="otro">Otro periodo</option></select></label><label class="cv-l">Desde<select class="sel lb-in" name="extras[__][devengo_desde]"><option value="">—</option>{{ $mesOpt('') }}</select></label><label class="cv-l">Hasta<select class="sel lb-in" name="extras[__][devengo_hasta]"><option value="">—</option>{{ $mesOpt('') }}</select></label><label class="cv-l" style="flex:1 1 200px">Conceptos incluidos<input class="inp lb-in" name="extras[__][conceptos]"></label><label class="cv-l">Artículo<input class="inp lb-in" name="extras[__][referencia]" style="width:110px"></label><button type="button" class="lb-del" data-lb-del aria-label="Quitar paga">×</button></div></template>
  <div class="cv-f"><button class="btn sm" type="submit">Guardar pagas</button></div>
</form>

{{-- 6 · Antigüedad --}}
@php $A = $R['antig'] ?? null; $av = fn ($k, $d) => $ov('antiguedad', $k, $d); @endphp
<form method="POST" action="{{ route('laboral.convenios.seccion', 'antiguedad') }}" class="card cv-sec" id="sec-antiguedad" data-cv-sec>
  @csrf<input type="hidden" name="version_id" value="{{ $ver->id }}">
  <div class="cv-h"><span>Antigüedad y complementos personales</span><span class="cv-dirty" data-cv-dirty hidden>Cambios sin guardar</span></div>
  <div style="display:flex;gap:16px;flex-wrap:wrap">
    <label class="lb-chk"><input type="checkbox" name="si" value="1" @checked($av('si', (bool) $A))>El convenio paga antigüedad</label>
    <label class="lb-chk"><input type="checkbox" name="no_tiene" value="1" @checked($av('no_tiene', ! empty($R['antigNo'])))>Comprobado: el convenio no tiene antigüedad</label>
  </div>
  <div class="lb-fields">
    <label class="lb-f"><span class="lb-l">Cada</span><select class="sel lb-in" name="periodo">{{ $opt([1 => 'Año (anualidades)', 2 => 'Bienios', 3 => 'Trienios', 4 => 'Cuatrienios', 5 => 'Quinquenios', 6 => 'Sexenios'], $av('periodo', $A['periodo'] ?? 3)) }}</select>{{ $E('periodo') }}</label>
    <label class="lb-f"><span class="lb-l">Tipo</span><select class="sel lb-in" name="tipo">{{ $opt(['importe' => '€ al mes por periodo', 'porcentaje' => '% por periodo'], $av('tipo', $A['tipo'] ?? 'importe')) }}</select></label>
    <label class="lb-f"><span class="lb-l">Valor</span><input class="inp lb-in mono" name="valor" value="{{ $av('valor', $n($A['valor'] ?? null)) }}" inputmode="decimal" {{ $inv('valor') }}>{{ $E('valor') }}</label>
    <label class="lb-f"><span class="lb-l">Base del porcentaje</span><select class="sel lb-in" name="base">{{ $opt(['salario_base' => 'Salario base', 'salario_base_plus' => 'Salario base + plus de convenio'], $av('base', $A['base'] ?? 'salario_base')) }}</select></label>
    <label class="lb-f"><span class="lb-l">Tope (nº de periodos)</span><input class="inp lb-in mono" name="tope" value="{{ $av('tope', $A['tope'] ?? '') }}" inputmode="numeric" placeholder="Sin tope">{{ $E('tope') }}</label>
    <label class="lb-f"><span class="lb-l">Se cuenta desde</span><select class="sel lb-in" name="computo">{{ $opt(['alta' => 'Fecha de antigüedad de la ficha (o de alta)', 'antiguedad' => 'Fecha de antigüedad reconocida'], $av('computo', $A['computo'] ?? 'alta')) }}</select></label>
    <label class="lb-f"><span class="lb-l">Congelada desde</span><input class="inp lb-in" type="date" name="congelada" value="{{ $av('congelada', $A['congelada'] ?? '') }}">{{ $E('congelada') }}</label>
    <label class="lb-f"><span class="lb-l">Artículo / página</span><input class="inp lb-in" name="referencia" value="{{ $av('referencia', $A['art'] ?? '') }}"></label>
  </div>
  <span class="hint muted">Con fecha de congelación no se generan periodos nuevos desde ese día. Los complementos personales «ad personam» y las condiciones transitorias se ponen en la ficha de cada trabajador.</span>
  <div class="cv-f"><button class="btn sm" type="submit">Guardar antigüedad</button></div>
</form>

{{-- 7 · Jornada, vacaciones y horas --}}
@php $H = $R['hx'] ?? null; $jv = fn ($k, $d) => $ov('jornada', $k, $d); @endphp
<form method="POST" action="{{ route('laboral.convenios.seccion', 'jornada') }}" class="card cv-sec" id="sec-jornada" data-cv-sec>
  @csrf<input type="hidden" name="version_id" value="{{ $ver->id }}">
  <div class="cv-h"><span>Jornada, vacaciones y horas</span><span class="cv-dirty" data-cv-dirty hidden>Cambios sin guardar</span></div>
  <div class="lb-fields">
    <label class="lb-f"><span class="lb-l">Jornada anual (horas)</span><input class="inp lb-in mono" name="jornada" value="{{ $jv('jornada', $R['jornada'] ?? '') }}" inputmode="decimal" {{ $inv('jornada') }}>{{ $E('jornada') }}</label>
    <label class="lb-f"><span class="lb-l">Jornada semanal (horas)</span><input class="inp lb-in mono" name="semanal" value="{{ $jv('semanal', $n($rCal['semanal'] ?? null)) }}" inputmode="decimal" {{ $inv('semanal') }}>{{ $E('semanal') }}</label>
    <label class="lb-f"><span class="lb-l">Vacaciones (días)</span><input class="inp lb-in mono" name="vacaciones" value="{{ $jv('vacaciones', $R['vacaciones'] ?? '') }}" inputmode="decimal" {{ $inv('vacaciones') }}>{{ $E('vacaciones') }}</label>
    <label class="lb-f"><span class="lb-l">Las vacaciones son</span><select class="sel lb-in" name="vac_tipo">{{ $opt(['naturales' => 'Días naturales', 'laborables' => 'Días laborables'], $jv('vac_tipo', $rCal['vac_tipo'] ?? 'naturales')) }}</select></label>
    <label class="lb-f" style="grid-column:1 / -1"><span class="lb-l">Distribución de la jornada</span><textarea class="txa" name="distribucion" rows="2" maxlength="1000" placeholder="Lunes a viernes; jornada intensiva de verano; distribución irregular hasta el 10 %…">{{ $jv('distribucion', $rCal['distribucion'] ?? '') }}</textarea></label>
    <label class="lb-f" style="grid-column:1 / -1"><span class="lb-l">Descansos y calendario</span><textarea class="txa" name="descansos" rows="2" maxlength="1000">{{ $jv('descansos', $rCal['descansos'] ?? '') }}</textarea></label>
    <label class="lb-f"><span class="lb-l">Trabajo nocturno y turnos</span><textarea class="txa" name="nocturno" rows="2" maxlength="1000">{{ $jv('nocturno', $rCal['nocturno'] ?? '') }}</textarea></label>
    <label class="lb-f"><span class="lb-l">Festivos y fines de semana</span><textarea class="txa" name="festivos" rows="2" maxlength="1000">{{ $jv('festivos', $rCal['festivos'] ?? '') }}</textarea></label>
    <label class="lb-f" style="grid-column:1 / -1"><span class="lb-l">Permisos retribuidos</span><textarea class="txa" name="permisos" rows="2" maxlength="2000">{{ $jv('permisos', $rCal['permisos'] ?? '') }}</textarea></label>
    <label class="lb-f"><span class="lb-l">Horas extraordinarias</span><select class="sel lb-in" name="hx_tipo">{{ $opt(['no_consta' => 'El convenio no fija precio', 'importe' => '€ por hora', 'recargo' => '% de recargo sobre la hora ordinaria'], $jv('hx_tipo', $H['tipo'] ?? 'no_consta')) }}</select></label>
    <label class="lb-f"><span class="lb-l">Precio o recargo</span><input class="inp lb-in mono" name="hx_valor" value="{{ $jv('hx_valor', $n($H['valor'] ?? null)) }}" inputmode="decimal" {{ $inv('hx_valor') }}>{{ $E('hx_valor') }}</label>
    <label class="lb-f"><span class="lb-l">Compensación</span><select class="sel lb-in" name="hx_compensacion">{{ $opt(['economica' => 'Económica', 'descanso' => 'Descanso', 'ambas' => 'A elección'], $jv('hx_compensacion', $H['compensacion'] ?? 'economica')) }}</select></label>
    <label class="lb-f"><span class="lb-l">Horas complementarias (parciales)</span><input class="inp lb-in" name="hcomp" value="{{ $jv('hcomp', $rCal['hcomp'] ?? '') }}" maxlength="1000"></label>
    <label class="lb-f"><span class="lb-l">Artículo / página</span><input class="inp lb-in" name="referencia" value="{{ $jv('referencia', $rCal['art'] ?? '') }}"></label>
  </div>
  <span class="hint muted">La jornada y las vacaciones se usan ya (vacaciones del finiquito). La distribución, los descansos, la nocturnidad y los festivos se guardan para el calendario de la fase 5: hoy no cambian la nómina.</span>
  <div class="cv-f"><button class="btn sm" type="submit">Guardar jornada</button></div>
</form>

{{-- 8 · Incapacidad temporal --}}
@php $IT = $R['it'] ?? []; $iv = fn ($k, $d) => $ov('it', $k, $d); @endphp
<form method="POST" action="{{ route('laboral.convenios.seccion', 'it') }}" class="card cv-sec" id="sec-it" data-cv-sec>
  @csrf<input type="hidden" name="version_id" value="{{ $ver->id }}">
  <div class="cv-h"><span>Incapacidad temporal · mejora del convenio</span><span class="cv-dirty" data-cv-dirty hidden>Cambios sin guardar</span></div>
  <span class="note-amber">La mejora se guarda, pero <b>la nómina todavía no calcula las bajas</b> (ni la prestación ni la mejora). Llega con el calendario e incidencias (fase 5).</span>
  @foreach(['it_cc' => ['Enfermedad común o accidente no laboral', $IT['cc'] ?? []], 'it_at' => ['Accidente de trabajo o enfermedad profesional', $IT['at'] ?? []]] as $k => [$l, $rows])
    @php $rows = $secAct === 'it' && old($k) ? array_values(old($k)) : $rows; @endphp
    <div style="display:flex;justify-content:space-between;align-items:center;gap:10px"><span style="font-size:13px;font-weight:600">{{ $l }}</span><button type="button" class="btn soft sm" data-lb-add="{{ $k }}">+ Tramo</button></div>
    <div data-lb-list="{{ $k }}" data-n="{{ count($rows) }}" style="display:flex;flex-direction:column;gap:6px">
      @foreach($rows as $i => $t)
        <div class="lb-fila"><label class="cv-l">Del día<input class="inp lb-in mono" name="{{ $k }}[{{ $i }}][desde]" value="{{ $t['desde'] ?? 1 }}" style="width:70px" inputmode="numeric">{{ $E("$k.$i.desde") }}</label><label class="cv-l">al día<input class="inp lb-in mono" name="{{ $k }}[{{ $i }}][hasta]" value="{{ $t['hasta'] ?? '' }}" style="width:70px" inputmode="numeric" placeholder="fin" {{ $inv("$k.$i.hasta") }}>{{ $E("$k.$i.hasta") }}</label><label class="cv-l">% del salario<input class="inp lb-in mono" name="{{ $k }}[{{ $i }}][pct]" value="{{ $n($t['pct'] ?? null, 0) }}" style="width:70px" inputmode="decimal" {{ $inv("$k.$i.pct") }}>{{ $E("$k.$i.pct") }}</label><label class="lb-chk"><input type="checkbox" name="{{ $k }}[{{ $i }}][hosp]" value="1" @checked(! empty($t['hosp']))>Solo con hospitalización</label><button type="button" class="lb-del" data-lb-del aria-label="Quitar tramo">×</button></div>
      @endforeach
    </div>
    <template data-lb-tpl="{{ $k }}"><div class="lb-fila"><label class="cv-l">Del día<input class="inp lb-in mono" name="{{ $k }}[__][desde]" value="1" style="width:70px" inputmode="numeric"></label><label class="cv-l">al día<input class="inp lb-in mono" name="{{ $k }}[__][hasta]" style="width:70px" inputmode="numeric" placeholder="fin"></label><label class="cv-l">% del salario<input class="inp lb-in mono" name="{{ $k }}[__][pct]" value="100" style="width:70px" inputmode="decimal"></label><label class="lb-chk"><input type="checkbox" name="{{ $k }}[__][hosp]" value="1">Solo con hospitalización</label><button type="button" class="lb-del" data-lb-del aria-label="Quitar tramo">×</button></div></template>
  @endforeach
  <div class="lb-fields">
    <label class="lb-f"><span class="lb-l">Base de referencia del complemento</span><input class="inp lb-in" name="base" value="{{ $iv('base', $IT['base'] ?? '') }}" maxlength="300" placeholder="Salario real del mes anterior, base reguladora…"></label>
    <label class="lb-f"><span class="lb-l">Pagas extra durante la IT</span><input class="inp lb-in" name="pagas" value="{{ $iv('pagas', $IT['pagas'] ?? '') }}" maxlength="500"></label>
    <label class="lb-f" style="grid-column:1 / -1"><span class="lb-l">Otras condiciones, límites y duración</span><textarea class="txa" name="obs" rows="2" maxlength="2000">{{ $iv('obs', $IT['obs'] ?? '') }}</textarea></label>
    <label class="lb-f"><span class="lb-l">Artículo / página</span><input class="inp lb-in" name="referencia" value="{{ $iv('referencia', $IT['art'] ?? '') }}"></label>
  </div>
  <div class="cv-f"><button class="btn sm" type="submit">Guardar IT</button></div>
</form>

{{-- 9 · Contratación --}}
@php $pr = $secAct === 'contratacion' && old('prueba') ? array_values(old('prueba')) : ($rCont['prueba'] ?? []); $pv = $secAct === 'contratacion' && old('preaviso') ? array_values(old('preaviso')) : ($rCont['preaviso'] ?? []); @endphp
<form method="POST" action="{{ route('laboral.convenios.seccion', 'contratacion') }}" class="card cv-sec" id="sec-contratacion" data-cv-sec>
  @csrf<input type="hidden" name="version_id" value="{{ $ver->id }}">
  <div class="cv-h"><span>Contratación y extinción</span><span class="cv-dirty" data-cv-dirty hidden>Cambios sin guardar</span></div>
  <span class="note-grey">Estas reglas <b>no se automatizan</b>: quedan como referencia para revisar cada contrato. La comprobación del periodo de prueba de la ficha sigue usando el máximo del art. 14 ET.</span>
  <div style="display:flex;justify-content:space-between;align-items:center"><span style="font-size:13px;font-weight:600">Periodo de prueba</span><button type="button" class="btn soft sm" data-lb-add="cvprueba">+ Fila</button></div>
  <div data-lb-list="cvprueba" data-n="{{ count($pr) }}" style="display:flex;flex-direction:column;gap:6px">
    @foreach($pr as $i => $x)<div class="lb-fila"><label class="cv-l" style="flex:1 1 200px">Grupo o categoría<input class="inp lb-in" name="prueba[{{ $i }}][aplica]" value="{{ $x['aplica'] ?? '' }}"></label><label class="cv-l">Modalidad<input class="inp lb-in" name="prueba[{{ $i }}][modalidad]" value="{{ $x['modalidad'] ?? '' }}" placeholder="Indefinido, temporal…"></label><label class="cv-l">Duración<input class="inp lb-in" name="prueba[{{ $i }}][duracion]" value="{{ $x['duracion'] ?? '' }}" placeholder="2 meses"></label><button type="button" class="lb-del" data-lb-del aria-label="Quitar fila">×</button></div>@endforeach
  </div>
  <template data-lb-tpl="cvprueba"><div class="lb-fila"><label class="cv-l" style="flex:1 1 200px">Grupo o categoría<input class="inp lb-in" name="prueba[__][aplica]"></label><label class="cv-l">Modalidad<input class="inp lb-in" name="prueba[__][modalidad]"></label><label class="cv-l">Duración<input class="inp lb-in" name="prueba[__][duracion]"></label><button type="button" class="lb-del" data-lb-del aria-label="Quitar fila">×</button></div></template>
  <div style="display:flex;justify-content:space-between;align-items:center"><span style="font-size:13px;font-weight:600">Preavisos</span><button type="button" class="btn soft sm" data-lb-add="cvpreaviso">+ Fila</button></div>
  <div data-lb-list="cvpreaviso" data-n="{{ count($pv) }}" style="display:flex;flex-direction:column;gap:6px">
    @foreach($pv as $i => $x)<div class="lb-fila"><label class="cv-l">Quién<input class="inp lb-in" name="preaviso[{{ $i }}][quien]" value="{{ $x['quien'] ?? '' }}" placeholder="Trabajador / empresa"></label><label class="cv-l" style="flex:1 1 200px">Grupo o categoría<input class="inp lb-in" name="preaviso[{{ $i }}][aplica]" value="{{ $x['aplica'] ?? '' }}"></label><label class="cv-l">Plazo<input class="inp lb-in" name="preaviso[{{ $i }}][plazo]" value="{{ $x['plazo'] ?? '' }}" placeholder="15 días"></label><button type="button" class="lb-del" data-lb-del aria-label="Quitar fila">×</button></div>@endforeach
  </div>
  <template data-lb-tpl="cvpreaviso"><div class="lb-fila"><label class="cv-l">Quién<input class="inp lb-in" name="preaviso[__][quien]"></label><label class="cv-l" style="flex:1 1 200px">Grupo o categoría<input class="inp lb-in" name="preaviso[__][aplica]"></label><label class="cv-l">Plazo<input class="inp lb-in" name="preaviso[__][plazo]"></label><button type="button" class="lb-del" data-lb-del aria-label="Quitar fila">×</button></div></template>
  <div class="lb-fields">
    <label class="lb-f" style="grid-column:1 / -1"><span class="lb-l">Cláusulas y condiciones de contratación</span><textarea class="txa" name="clausulas" rows="3" maxlength="5000">{{ $ov('contratacion', 'clausulas', $rCont['clausulas'] ?? '') }}</textarea></label>
    <label class="lb-f" style="grid-column:1 / -1"><span class="lb-l">Extinción y otras reglas</span><textarea class="txa" name="obs" rows="2" maxlength="5000">{{ $ov('contratacion', 'obs', $rCont['obs'] ?? '') }}</textarea></label>
    <label class="lb-f"><span class="lb-l">Artículo / página</span><input class="inp lb-in" name="referencia" value="{{ $ov('contratacion', 'referencia', $rCont['art'] ?? '') }}"></label>
  </div>
  <div class="cv-f"><button class="btn sm" type="submit">Guardar contratación</button></div>
</form>
@elseif(! $cv)
  <div class="card"><span style="font-size:13.5px">Guarda primero la identificación (o asigna arriba un convenio del despacho) para poder introducir las versiones, las tablas y las reglas.</span></div>
@endif
