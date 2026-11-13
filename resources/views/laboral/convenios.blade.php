@extends('layouts.app')
@php
  use App\Services\Nomina;
  use App\Support\Formato;
  $o = fn ($k, $def) => old($k, $def);
  $conv = $P['conv'];
  $cats = old('_enviado') ? array_values((array) old('cats', [])) : $conv['cats'];
@endphp
@section('content')
<div class="page" style="gap:16px">
  @include('laboral._cabecera')
  @php $iaOk = \App\Services\ConvenioIA::configurada(); $ia = $conv['ia'] ?? null; $art = fn ($x) => ! empty($x) ? ' ('.$x.')' : ''; @endphp
  <div class="card" style="gap:12px">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:10px;flex-wrap:wrap">
      <div style="display:flex;flex-direction:column;gap:3px;min-width:0;flex:1 1 300px"><span style="font-size:15px;font-weight:700">Leer el convenio con IA</span>
        <span style="font-size:12.5px;color:#6A6A65;line-height:1.45">Sube el PDF completo del convenio (con sus anexos y tablas). La IA lee las tablas salariales, las pagas extra, la antigüedad, los pluses, el complemento de IT y las horas extra, y se aplican solas a las nóminas de esta empresa. Los trabajadores que cobren menos que su categoría se suben al mínimo del convenio; nunca se baja un salario. Solo se envía el texto del convenio, no datos de trabajadores.</span></div>
      @if($iaOk)
        <form method="POST" action="{{ route('laboral.convenios.ia') }}" enctype="multipart/form-data" data-lb-ia>@csrf
          <label class="btn lime" style="cursor:pointer">Subir PDF y leer con IA<input type="file" name="pdf" accept=".pdf,application/pdf" class="hidden" data-autoupload></label>
          <span class="hint muted" style="display:block;margin-top:4px">Tarda de 1 a 3 minutos: no cierres la página.</span>
        </form>
      @endif
    </div>
    @if(! $iaOk)
      @if(auth()->user()->esAdmin())
        <form method="POST" action="{{ route('laboral.convenios.ia.clave') }}" style="display:flex;flex-wrap:wrap;gap:8px;align-items:flex-end">@csrf
          <label class="lbl-s" style="flex:1 1 280px">Clave de la API de Anthropic (se guarda cifrada)<input class="inp mono" type="password" name="clave" autocomplete="off" placeholder="sk-ant-…" required></label>
          <button class="btn sm" type="submit">Guardar clave</button>
        </form>
        <span class="hint muted">Se crea en console.anthropic.com › API Keys. Cada convenio cuesta unos céntimos.</span>
      @else
        <span class="hint muted">La administración del despacho tiene que configurar la clave de la API para leer convenios con IA.</span>
      @endif
    @endif
    @if($ia)
      <div style="display:flex;flex-direction:column;gap:6px;padding-top:10px;border-top:1px solid #F1F1EC;font-size:13px;line-height:1.5">
        <span style="font-size:12.5px;color:#6A6A65">Leído con IA el {{ \App\Support\Formato::fecha(substr($ia['fecha'], 0, 10)) }} de «{{ $ia['archivo'] }}»{{ ! empty($ia['anioTablas']) ? ' · tablas de '.$ia['anioTablas'] : '' }}</span>
        <span><b>Pagas extra:</b> {{ $conv['extras'] ?? [] ? collect($conv['extras'])->map(fn ($x) => $x['nombre'].' en '.\App\Support\Formato::mesNombre($x['mes']).' · '.($x['calculo'] === 'dias' ? \App\Support\Formato::num($x['dias'], 0).' días' : ($x['calculo'] === 'importe' ? \App\Support\Formato::eur($x['importe']) : 'una mensualidad')).($x['plus'] ? ' + plus' : '').($x['antig'] ? ' + antigüedad' : '').$art($x['art']))->implode(' · ') : 'no fija pagas extra' }}</span>
        <span><b>Antigüedad:</b> {{ ($a = $conv['antig'] ?? null) ? ($a['tipo'] === 'porcentaje' ? \App\Support\Formato::num($a['valor']).' % del salario base' : \App\Support\Formato::eur($a['valor']).' al mes').' por cada '.$a['periodo'].' años'.($a['tope'] ? ', máximo '.$a['tope'].' periodos' : '').$art($a['art']) : 'no consta' }}</span>
        <span><b>Pluses:</b> {{ ($conv['pluses'] ?? []) ? collect($conv['pluses'])->map(fn ($p) => $p['nombre'].' '.($p['tipo'] === 'porcentaje_base' ? \App\Support\Formato::num($p['valor']).' %' : \App\Support\Formato::eur($p['valor'])).' por '.['mes' => 'mes', 'dia_trabajado' => 'día trabajado', 'dia_natural' => 'día natural', 'hora' => 'hora'][$p['unidad']].($p['auto'] ? ' (automático)' : ' (se añade en la nómina cuando corresponda)').($p['cotiza'] ? '' : ' · no cotiza').$art($p['art']))->implode(' · ') : 'ninguno' }}</span>
        <span><b>Complemento de IT:</b> {{ ($conv['it']['cc'] ?? []) || ($conv['it']['at'] ?? []) ? collect(['cc' => 'enfermedad común', 'at' => 'accidente de trabajo'])->map(fn ($l, $k) => ($conv['it'][$k] ?? []) ? $l.': '.collect($conv['it'][$k])->map(fn ($t) => 'días '.$t['desde'].($t['hasta'] ? '-'.$t['hasta'] : ' en adelante').' al '.\App\Support\Formato::num($t['pct'], 0).' %')->implode(', ') : null)->filter()->implode(' · ').$art($conv['it']['art'] ?? '') : 'sin mejora sobre la prestación' }}</span>
        <span><b>Horas extra:</b> {{ ($h = $conv['hx'] ?? null) ? ($h['tipo'] === 'importe' ? \App\Support\Formato::eur($h['valor']).' la hora' : 'recargo del '.\App\Support\Formato::num($h['valor'], 0).' % sobre la hora ordinaria').$art($h['art']) : 'no consta precio' }}</span>
        @if(! empty($ia['avisos']))<span class="note-amber"><b>Dudas de la lectura:</b> {{ implode(' · ', $ia['avisos']) }}</span>@endif
      </div>
    @endif
  </div>
  <form method="POST" action="{{ route('laboral.convenios.guardar') }}" style="display:flex;flex-direction:column;gap:16px" data-lb-par>
    @csrf<input type="hidden" name="_enviado" value="1">
      <div class="card">
        <span style="font-size:15px;font-weight:700">Convenio colectivo aplicable</span>
        @if($conv['nombre'])
          <div style="padding:12px 14px;border-radius:12px;background:#0B0B0B;color:#F4F4F0;display:flex;flex-direction:column;gap:2px"><span style="font-size:14px;font-weight:700">{{ $conv['nombre'] }}</span><span style="font-size:12px;color:#BDBDB7">{{ implode(' · ', array_filter([$conv['codigo'] ? 'Código '.$conv['codigo'] : null, $conv['ambito'] ?: null, $conv['vigencia'] ? 'Vigencia '.$conv['vigencia'] : null])) ?: 'Sin código ni vigencia' }}</span></div>
        @else
          <div class="msg warn" style="font-weight:500">Sin convenio seleccionado. Búscalo en REGCON o el BOE y carga su PDF, o introdúcelo a mano.</div>
        @endif
        <div style="display:flex;flex-wrap:wrap;gap:8px">
          <input class="inp" data-lb-convq placeholder="Sector y provincia: hostelería Huelva" style="flex:1 1 200px;height:38px">
          <a class="btn soft" href="{{ Nomina::URL['regcon'] }}" target="_blank" rel="noopener">Buscar en REGCON ↗</a>
          <a class="btn soft" href="{{ Nomina::URL['boe'] }}" target="_blank" rel="noopener" data-lb-boe>Buscar en el BOE ↗</a>
        </div>
        <label class="lb-file"><input type="file" accept=".pdf" data-lb-convpdf hidden><span class="lb-ic" style="background:#0B0B0B;color:#fff">PDF</span><span style="display:flex;flex-direction:column;gap:2px"><span style="font-size:13.5px;font-weight:700">Cargar el PDF del convenio</span><span style="font-size:12px;color:#6A6A65">Se leen el nombre, el código, la vigencia, la jornada y las vacaciones de las primeras páginas</span></span></label>
        <span data-lb-convst style="font-size:12.5px;font-weight:600" hidden></span>
        <div class="lb-fields">
          @foreach([['conv_nombre', 'Nombre del convenio', 'nombre'], ['conv_codigo', 'Código del convenio (REGCON)', 'codigo'], ['conv_ambito', 'Ámbito', 'ambito'], ['conv_vigencia', 'Vigencia', 'vigencia'], ['conv_jornada', 'Jornada anual (horas)', 'jornada'], ['conv_vacaciones', 'Vacaciones (días naturales)', 'vacaciones']] as [$k, $l, $f])
            <label class="lb-f"><span class="lb-l">{{ $l }}</span><input class="inp lb-in" name="{{ $k }}" value="{{ $o($k, $conv[$f]) }}" data-conv="{{ $f }}"></label>
          @endforeach
          <label class="lb-f"><span class="lb-l">Pagas</span><select class="sel lb-in" name="conv_pagas">@foreach(['14', '12', '15', '16'] as $x)<option @selected((string) $o('conv_pagas', $conv['pagas']) === $x)>{{ $x }}</option>@endforeach</select></label>
        </div>
        <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;padding-top:6px;border-top:1px solid #F1F1EC"><span style="font-size:15px;font-weight:700">Tablas salariales</span><button type="button" class="btn soft" data-lb-add="cats">+ Categoría</button></div>
        <div class="lb-cat lb-head" style="padding:0"><span>Categoría</span><span>Grupo</span><span>Base €/mes{{ collect($cats)->contains(fn ($x) => ($x['unidad'] ?? 'mes') === 'dia') ? ' (o €/día)' : '' }}</span><span>Plus €/mes</span><span></span></div>
        <div data-lb-list="cats" data-n="{{ count($cats) }}" style="display:flex;flex-direction:column;gap:8px">
          @foreach($cats as $i => $ct)
            <div class="lb-cat"><input class="inp lb-in" name="cats[{{ $i }}][cat]" value="{{ $ct['cat'] ?? '' }}" title="{{ ($ct['unidad'] ?? 'mes') === 'dia' ? 'Salario DIARIO (€/día)' : '' }}{{ ! empty($ct['art']) ? ' · '.$ct['art'] : '' }}"><input type="hidden" name="cats[{{ $i }}][unidad]" value="{{ $ct['unidad'] ?? 'mes' }}"><input type="hidden" name="cats[{{ $i }}][art]" value="{{ $ct['art'] ?? '' }}"><select class="sel lb-in" name="cats[{{ $i }}][grupo]">@foreach(range(1, 11) as $g)<option @selected((string) ($ct['grupo'] ?? '7') === (string) $g)>{{ $g }}</option>@endforeach</select><input class="inp lb-in mono" name="cats[{{ $i }}][base]" value="{{ is_numeric($ct['base'] ?? null) ? Formato::num($ct['base']) : ($ct['base'] ?? '') }}" inputmode="decimal" style="text-align:right"><input class="inp lb-in mono" name="cats[{{ $i }}][plus]" value="{{ is_numeric($ct['plus'] ?? null) ? Formato::num($ct['plus']) : ($ct['plus'] ?? '') }}" inputmode="decimal" style="text-align:right"><button type="button" class="lb-del" data-lb-del aria-label="Quitar">×</button></div>
          @endforeach
        </div>
        <template data-lb-tpl="cats"><div class="lb-cat"><input class="inp lb-in" name="cats[__][cat]" value="Nueva categoría"><select class="sel lb-in" name="cats[__][grupo]">@foreach(range(1, 11) as $g)<option @selected($g === 7)>{{ $g }}</option>@endforeach</select><input class="inp lb-in mono" name="cats[__][base]" value="1.221,00" inputmode="decimal" style="text-align:right"><input class="inp lb-in mono" name="cats[__][plus]" value="0,00" inputmode="decimal" style="text-align:right"><button type="button" class="lb-del" data-lb-del aria-label="Quitar">×</button></div></template>
      </div>

    <div style="display:flex;justify-content:flex-end;gap:10px">
      <a class="btn white" href="{{ route('laboral.convenios') }}">Descartar cambios</a>
      <button class="btn lg" type="submit">Guardar convenio</button>
    </div>
  </form>
</div>
@endsection
@section('overlay')
@if($con) @include('laboral._conexion') @endif
@endsection
@push('scripts')
<script src="{{ asset('js/ocr.js') }}?v={{ config('app.version', '1') }}"></script>
@endpush
