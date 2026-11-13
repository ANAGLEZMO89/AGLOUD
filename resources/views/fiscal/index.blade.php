@extends('layouts.app')
@php use App\Services\Fiscal; @endphp
@section('content')
<div class="page" style="gap:16px">
  @include('fiscal._cabecera', ['crumb' => 'Áreas · Fiscal', 'h1' => 'Modelos fiscales'])

  <div style="background:#0B0B0B;color:#F4F4F0;border-radius:18px;padding:16px 20px;display:flex;flex-wrap:wrap;align-items:center;gap:14px">
    <div style="flex:1 1 280px;display:flex;flex-direction:column;gap:8px;min-width:0">
      <span style="font-size:14px;font-weight:600">{{ count($det) }} modelos detectados para {{ $c->nombre }} según sus parámetros y su contabilidad</span>
      <div style="display:flex;flex-wrap:wrap;gap:6px">@foreach($det as $code)<span style="font:700 12.5px var(--mono);padding:4px 9px;border-radius:7px;background:#C8F031;color:#0B0B0B">{{ $code }}</span>@endforeach</div>
    </div>
    <a href="{{ route('fiscal.index', ['parametros' => 1]) }}" style="height:38px;padding:0 16px;border-radius:19px;background:#fff;color:#0B0B0B;display:flex;align-items:center;font-weight:700;font-size:13.5px;white-space:nowrap;text-decoration:none">Revisar parámetros</a>
  </div>

  <form method="GET" action="{{ route('fiscal.index') }}" style="display:flex;flex-wrap:wrap;align-items:center;gap:10px">
    @if($todos)<input type="hidden" name="ver" value="todos">@endif
    <input class="inp" type="search" name="q" value="{{ $q }}" placeholder="Buscar modelo por número o nombre" style="flex:1;min-width:220px;max-width:360px;height:38px">
    <div class="chipbar" style="border-radius:11px">
      <a class="{{ $todos ? '' : 'on' }}" style="padding:6px 12px;border-radius:8px" href="{{ route('fiscal.index', array_filter(['q' => $q])) }}">Aplicables a este cliente</a>
      <a class="{{ $todos ? 'on' : '' }}" style="padding:6px 12px;border-radius:8px" href="{{ route('fiscal.index', array_filter(['ver' => 'todos', 'q' => $q])) }}">Todos los modelos</a>
    </div>
    <span style="font-size:13px;color:#6A6A65">{{ count($det) }} modelos que debe presentar</span>
  </form>

  @if($grupos)
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,340px),1fr));gap:16px;align-items:start">
    @foreach($grupos as $g)
      <div class="card" style="padding:18px 20px;gap:2px">
        <span style="font-size:15px;font-weight:700;padding-bottom:10px;border-bottom:2px solid #0B0B0B;margin-bottom:6px">{{ $g['cat'] }}</span>
        @foreach($g['mods'] as $md)
          <a href="{{ $md['url'] }}" class="fis-mod">
            <span style="font:700 15px var(--mono);padding:6px 0;border-radius:8px;background:#0B0B0B;color:#C8F031;text-align:center">{{ $md['code'] }}</span>
            <span style="display:flex;flex-direction:column;gap:2px;min-width:0"><span style="font-size:14px;font-weight:600;line-height:1.3">{{ $md['name'] }}</span><span style="font-size:12px;color:#6A6A65">{{ $md['sub'] }}</span></span>
            <span style="font-size:11.5px;font-weight:700;padding:3px 9px;border-radius:10px;background:{{ $md['bg'] }};color:{{ $md['fg'] }};white-space:nowrap">{{ $md['st'] }}</span>
          </a>
        @endforeach
      </div>
    @endforeach
  </div>
  @else
    <div class="card" style="padding:28px;text-align:center;font-size:14px;color:#6A6A65">Ningún modelo coincide con la búsqueda.</div>
  @endif
</div>
@endsection

@section('overlay')
@if($abrirParam)
@php
  $t = $c->tipo; $part = $t === 'Particular'; $soc = $t === 'Sociedad'; $aut = $t === 'Autónomo'; $cb = $t === 'Comunidad de bienes';
  $v = fn ($k) => old($k, $P[$k]);
  $chk = fn ($k) => old('_enviado') ? (bool) old($k) : (bool) $P[$k];
  $volver = route('fiscal.index');
@endphp
<div class="drawer sm" role="dialog" aria-modal="true" aria-label="Parámetros fiscales">
  <a class="shade" href="{{ $volver }}" aria-label="Cerrar"></a>
  <div class="panel" style="width:min(520px,100%)">
    <div class="ph"><div><b>Parámetros fiscales</b><small>{{ $c->nombre }} · {{ $c->nif ?: 'Sin NIF' }} · {{ $c->tipo }}</small></div><a class="x" href="{{ $volver }}" aria-label="Cerrar">×</a></div>
    <form method="POST" action="{{ route('fiscal.parametros') }}" style="display:flex;flex-direction:column;flex:1;min-height:0">
      @csrf
      <input type="hidden" name="_enviado" value="1">
      <div class="pc" style="overflow-y:auto;gap:14px">
        @if($errors->any())<div class="ferr">{{ $errors->first() }}</div>@endif
        <div class="fis-sec">
          <span class="fis-st">Declarante</span>
          <label class="lbl-s">NIF<input class="inp mono" name="nif" value="{{ $v('nif') }}" maxlength="15"></label>
          <label class="lbl-s">{{ $aut || $part ? 'Apellidos' : 'Razón social' }}<input class="inp" name="razon" value="{{ $v('razon') }}" maxlength="120"></label>
          @if($aut || $part)<label class="lbl-s">Nombre<input class="inp" name="nombre" value="{{ $v('nombre') }}" maxlength="60"></label>@endif
          <label class="lbl-s">Teléfono de contacto<input class="inp" name="tel" value="{{ $v('tel') }}" maxlength="30"></label>
          <label class="lbl-s">Persona de contacto<input class="inp" name="contacto" value="{{ $v('contacto') }}" maxlength="80"></label>
        </div>
        @unless($part)
        <div class="fis-sec">
          <span class="fis-st">IVA</span>
          <label class="lbl-s">Régimen de IVA<select class="sel" name="ivaReg">@foreach(['General', 'Simplificado', 'Recargo de equivalencia', 'Exento'] as $o)<option @selected($v('ivaReg') === $o)>{{ $o }}</option>@endforeach</select></label>
          @foreach(['redeme' => ['Inscrito en el REDEME', ''], 'sii' => ['Lleva los libros de IVA por el SII', 'Queda exonerado del 390 y del 347'], 'roi' => ['Inscrito en el ROI (operaciones intracomunitarias)', '']] as $k => [$l, $h])
            <label class="fis-chk"><input type="checkbox" name="{{ $k }}" value="1" @checked($chk($k))><span>{{ $l }}@if($h)<small>{{ $h }}</small>@endif</span></label>
          @endforeach
        </div>
        @endunless
        @if($aut)
        <div class="fis-sec">
          <span class="fis-st">Impuesto sobre la renta</span>
          <label class="lbl-s">Método de estimación<select class="sel" name="edMet">@foreach(['Estimación directa simplificada', 'Estimación directa normal', 'Estimación objetiva'] as $o)<option @selected($v('edMet') === $o)>{{ $o }}</option>@endforeach</select></label>
          <label class="fis-chk"><input type="checkbox" name="exento130" value="1" @checked($chk('exento130'))><span>Más del 70 % de los ingresos con retención<small>Queda exonerado del modelo 130</small></span></label>
        </div>
        @endif
        @if($soc)
        <div class="fis-sec">
          <span class="fis-st">Impuesto sobre Sociedades</span>
          <label class="lbl-s">Tipo de gravamen %<select class="sel" name="tipoIS">@foreach(['25', '23', '15'] as $o)<option @selected((string) $v('tipoIS') === $o)>{{ $o }}</option>@endforeach</select></label>
          <label class="lbl-s">Modalidad del pago fraccionado<select class="sel" name="modal202">@foreach(['Artículo 40.2', 'Artículo 40.3'] as $o)<option @selected($v('modal202') === $o)>{{ $o }}</option>@endforeach</select></label>
        </div>
        @endif
        @if($cb)
        <div class="fis-sec">
          <span class="fis-st">Comunidad de bienes</span>
          <label class="lbl-s">Número de comuneros<input class="inp mono" type="number" min="2" max="99" name="comuneros" value="{{ $v('comuneros') }}"></label>
        </div>
        @endif
        @unless($part)
        <div class="fis-sec">
          <span class="fis-st">Retenciones</span>
          <label class="lbl-s">Trabajadores en nómina<input class="inp mono" type="number" min="0" max="9999" name="trab" value="{{ $v('trab') }}"></label>
          @foreach(['alq' => ['Paga el alquiler del local con retención', $P['det']['alq'] ? 'Detectado en la contabilidad' : ''], 'prof' => ['Paga a profesionales con retención', $P['det']['prof'] ? 'Detectado en la contabilidad' : ''], 'divid' => ['Reparte dividendos o paga intereses a socios', '']] as $k => [$l, $h])
            <label class="fis-chk"><input type="checkbox" name="{{ $k }}" value="1" @checked($chk($k))><span>{{ $l }}@if($h)<small>{{ $h }}</small>@endif</span></label>
          @endforeach
        </div>
        @endunless
        <div class="fis-sec">
          <span class="fis-st">Otras obligaciones</span>
          <label class="fis-chk"><input type="checkbox" name="ext720" value="1" @checked($chk('ext720'))><span>Bienes en el extranjero de más de 50.000 €</span></label>
          @if($aut || $part)<label class="fis-chk"><input type="checkbox" name="patr714" value="1" @checked($chk('patr714'))><span>Patrimonio de más de 2.000.000 €</span></label>@endif
        </div>
        <div class="fis-sec">
          <span class="fis-st">Presentación</span>
          <label class="lbl-s">IBAN para domiciliar o devolver<input class="inp mono" name="iban" value="{{ $v('iban') }}" placeholder="ES00 0000 0000 0000 0000 0000" maxlength="40"></label>
          <label class="lbl-s">Forma de ingreso por defecto<select class="sel" name="formaPago">@foreach(['Ingreso', 'Domiciliación'] as $o)<option @selected($v('formaPago') === $o)>{{ $o }}</option>@endforeach</select></label>
        </div>
        <div style="display:flex;flex-direction:column;gap:6px;padding:14px 16px;border-radius:12px;border:1.5px solid #0B0B0B">
          <span style="font-size:13px;font-weight:700">Modelos que tendrá que presentar</span>
          @foreach($R as $code => $why)
            <div style="display:grid;grid-template-columns:48px minmax(0,1fr);gap:10px;align-items:start;padding:6px 0;border-top:1px solid #F1F1EC"><span style="font:700 12.5px var(--mono);padding:4px 0;border-radius:6px;background:#0B0B0B;color:#C8F031;text-align:center">{{ $code }}</span><span style="display:flex;flex-direction:column;gap:1px;min-width:0"><span style="font-size:13px;font-weight:600">{{ Fiscal::nombre($code) }}</span><span style="font-size:12px;color:#6A6A65">{{ $why }}</span></span></div>
          @endforeach
          <span class="hint muted">Se actualiza al guardar los parámetros.</span>
        </div>
        <a href="{{ route('parametros', 'fiscal') }}" class="hint" style="font-weight:600">Parámetros generales del módulo (avisos, umbral del 347, porcentajes) →</a>
      </div>
      <div class="pf">
        <a class="btn soft" href="{{ $volver }}">Cancelar</a>
        <button class="btn lime" type="submit">Guardar parámetros</button>
      </div>
    </form>
  </div>
</div>
@endif
@endsection
