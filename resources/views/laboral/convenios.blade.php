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
        <div class="lb-cat lb-head" style="padding:0"><span>Categoría</span><span>Grupo</span><span>Base €/mes</span><span>Plus €/mes</span><span></span></div>
        <div data-lb-list="cats" data-n="{{ count($cats) }}" style="display:flex;flex-direction:column;gap:8px">
          @foreach($cats as $i => $ct)
            <div class="lb-cat"><input class="inp lb-in" name="cats[{{ $i }}][cat]" value="{{ $ct['cat'] ?? '' }}"><select class="sel lb-in" name="cats[{{ $i }}][grupo]">@foreach(range(1, 11) as $g)<option @selected((string) ($ct['grupo'] ?? '7') === (string) $g)>{{ $g }}</option>@endforeach</select><input class="inp lb-in mono" name="cats[{{ $i }}][base]" value="{{ is_numeric($ct['base'] ?? null) ? Formato::num($ct['base']) : ($ct['base'] ?? '') }}" inputmode="decimal" style="text-align:right"><input class="inp lb-in mono" name="cats[{{ $i }}][plus]" value="{{ is_numeric($ct['plus'] ?? null) ? Formato::num($ct['plus']) : ($ct['plus'] ?? '') }}" inputmode="decimal" style="text-align:right"><button type="button" class="lb-del" data-lb-del aria-label="Quitar">×</button></div>
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
