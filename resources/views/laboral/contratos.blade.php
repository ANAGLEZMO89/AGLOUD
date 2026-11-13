@extends('layouts.app')
@php
  use App\Services\Nomina;
  $extra = old('extra') ? array_values(old('extra')) : $P['clausExtra'];
  $clausSel = old('_enviado') ? (array) old('claus', []) : $clausCod;
  $habSel = old('_enviado') ? (array) old('hab', []) : $P['hab'];
  $defSel = old('_enviado') ? (array) old('clausDef', []) : $P['claus'];
  $exRow = fn ($i, $x) => new \Illuminate\Support\HtmlString('<div class="card" style="background:#F4F4F0;gap:8px;padding:12px 14px"><input type="hidden" name="extra['.$i.'][k]" value="'.e($x['k'] ?? '').'"><div style="display:flex;gap:8px"><input class="inp lb-in" name="extra['.$i.'][l]" value="'.e($x['l'] ?? '').'" placeholder="Título de la cláusula"><button type="button" class="lb-del" data-lb-del aria-label="Quitar" style="align-self:center">×</button></div><textarea class="txa" name="extra['.$i.'][txt]" rows="3" placeholder="Texto de la cláusula">'.e($x['txt'] ?? '').'</textarea></div>');
@endphp
@section('content')
<div class="page" style="gap:16px">
  @include('laboral._cabecera')
  <div class="card" style="padding:12px 14px;gap:8px">
    <span style="font-size:13px;font-weight:700">Modalidad de contrato</span>
    <div style="display:flex;flex-wrap:wrap;gap:6px">
      @foreach(Nomina::CONTRATOS as $x)
        @php $k = Nomina::codigo($x); $own = ! empty($P['contratos'][$k]['texto']) || isset($P['contratos'][$k]['claus']); @endphp
        <a href="{{ route('laboral.contratos', ['c' => $k]) }}" title="{{ $x }}" class="lb-chip {{ $cod === $k ? 'on' : '' }} {{ in_array($k, $P['hab'], true) ? '' : 'off' }}">{{ $k }}@if($own)<span style="color:#7CB518">●</span>@endif</a>
      @endforeach
    </div>
    <span style="font-size:12px;color:#6A6A65">● con plantilla o cláusulas propias · en gris, modalidades que la empresa no usa</span>
  </div>
  <form method="POST" action="{{ route('laboral.contratos.guardar') }}" style="display:flex;flex-direction:column;gap:16px">
    @csrf<input type="hidden" name="_enviado" value="1"><input type="hidden" name="c" value="{{ $cod }}">
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,460px),1fr));gap:16px;align-items:start">
      <div class="card">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap"><span style="font-size:15px;font-weight:700">Texto del contrato · {{ Nomina::contratoCompleto($cod) }}</span>@if($propio)<span class="lb-pill" style="background:#E6F6A8;color:#2E3A00">Plantilla propia</span>@else<span class="lb-pill" style="background:#ECECE7;color:#3C3C3C">Texto del diseño</span>@endif</div>
        <span style="font-size:12.5px;color:#6A6A65;line-height:1.45">Es el cuerpo de todos los contratos de esta modalidad (después de los datos de las partes). Escribe <b>**así**</b> para negrita y deja una línea en blanco entre cláusulas. Los marcadores entre llaves se rellenan con los datos de cada trabajador. En la ficha de cada trabajador se puede cambiar el texto de su contrato.</span>
        <textarea class="txa mono" name="texto" rows="22" style="font-size:12.5px;line-height:1.5;height:auto;padding:10px 12px" data-lb-texto>{{ old('texto', $texto) }}</textarea>
        @if($propio)<label class="lb-chk"><input type="checkbox" name="restablecer" value="1">Volver al texto del diseño al guardar</label>@endif
      </div>
      <div style="display:flex;flex-direction:column;gap:16px;min-width:0">
        <div class="card">
          <span style="font-size:15px;font-weight:700">Marcadores</span>
          <div style="display:flex;flex-wrap:wrap;gap:6px">@foreach(Nomina::MARCADORES as $k => $l)<button type="button" class="lb-chip" data-lb-marca="{{ '{'.$k.'}' }}" title="{{ $l }}">{{ '{'.$k.'}' }}</button>@endforeach</div>
          <span style="font-size:12px;color:#6A6A65">Pulsa un marcador para insertarlo donde esté el cursor.</span>
        </div>
        <div class="card">
          <span style="font-size:15px;font-weight:700">Cláusulas por defecto de esta modalidad</span>
          <span style="font-size:12.5px;color:#6A6A65">Se marcan solas al dar de alta un trabajador con este contrato y se añaden numeradas al final.</span>
          @foreach($todas as $k => [$l, $txt])<label class="lb-chk" style="align-items:flex-start"><input type="checkbox" name="claus[]" value="{{ $k }}" @checked(in_array($k, $clausSel, true))><span style="display:flex;flex-direction:column;gap:2px"><span style="font-weight:600">{{ $l }}</span><span style="font-size:12px;color:#6A6A65;line-height:1.4">{{ \Illuminate\Support\Str::limit($txt, 140) }}</span></span></label>@endforeach
        </div>
      </div>
    </div>
    <div class="card">
      <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap"><span style="font-size:15px;font-weight:700">Cláusulas propias</span><button type="button" class="btn soft" data-lb-add="extra">+ Nueva cláusula</button></div>
      <span style="font-size:12.5px;color:#6A6A65">Cláusulas redactadas por el despacho para esta empresa. Aparecen en todas las modalidades y en la ficha de cada trabajador.</span>
      <div data-lb-list="extra" data-n="{{ count($extra) }}" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(min(100%,420px),1fr));gap:10px">@foreach($extra as $i => $x){{ $exRow($i, $x) }}@endforeach</div>
      <template data-lb-tpl="extra">{{ $exRow('__', []) }}</template>
    </div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,420px),1fr));gap:16px;align-items:start">
      <div class="card">
        <span style="font-size:15px;font-weight:700">Contratos que usa la empresa</span>
        <div style="display:flex;flex-direction:column;gap:2px">
          @foreach(Nomina::CONTRATOS as $x)@php $k = Nomina::codigo($x); @endphp<label class="lb-chk" style="align-items:flex-start;min-height:32px"><input type="checkbox" name="hab[]" value="{{ $k }}" @checked(in_array($k, $habSel, true))><span><span class="lb-code" style="margin-right:6px">{{ $k }}</span>{{ implode(' · ', array_slice(explode(' · ', $x), 1)) }}</span></label>@endforeach
        </div>
      </div>
      <div class="card">
        <span style="font-size:15px;font-weight:700">Cláusulas por defecto del resto de modalidades</span>
        <span style="font-size:12.5px;color:#6A6A65">Para las modalidades sin cláusulas propias.</span>
        @foreach($todas as $k => [$l])<label class="lb-chk"><input type="checkbox" name="clausDef[]" value="{{ $k }}" @checked(in_array($k, $defSel, true))>{{ $l }}</label>@endforeach
      </div>
    </div>
    <div style="display:flex;justify-content:flex-end;gap:10px">
      <a class="btn white" href="{{ route('laboral.contratos', ['c' => $cod]) }}">Descartar cambios</a>
      <button class="btn lg" type="submit">Guardar parámetros de contrato</button>
    </div>
  </form>
</div>
@endsection
@section('overlay')
@if($con) @include('laboral._conexion') @endif
@endsection
