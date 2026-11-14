@extends('layouts.app')
@php
  use App\Services\Nomina; use App\Support\Formato;
  $perc = array_sum(array_column($porMes, 'perc')); $ret = array_sum(array_column($porMes, 'ret'));
  $nPerc = count(array_filter($porTrab, fn ($x) => $x['perc'] > 0));
@endphp
@section('content')
<div class="page" style="gap:16px">
  @include('laboral._cabecera')
  @php $pendM = isset($porMes) ? collect($porMes)->flatMap(fn ($x, $mm) => collect($x['pend'])->map(fn ($p) => Nomina::MESES[$mm - 1].': '.$p))->all() : ($pend190 ?? []); @endphp
  @include('laboral._proceso', ['pasos' => [
      'preparar' => ['aqui', 'percepciones y retenciones de los recibos del trimestre'],
      'fichero' => ['no', 'el fichero oficial no se genera aquí; los importes se pasan al modelo en Fiscal o en la sede de la AEAT'],
      'presentar' => ['fuera', 'en la sede electrónica de la AEAT'],
      'respuesta' => ['fuera', 'el justificante lo da la AEAT'],
  ]])
  @if($pendM)<div class="note-amber"><b>Provisional:</b> hay recibos sin emitir que pueden cambiar las cifras: {{ implode(' · ', array_slice($pendM, 0, 12)) }}{{ count($pendM) > 12 ? ' …' : '' }}. Si rectificas una nómina de un periodo ya presentado, corresponde una declaración complementaria o sustitutiva.</div>@endif
  <div style="display:flex;flex-wrap:wrap;align-items:center;gap:10px">
    <div class="chipbar" style="border-radius:11px">@foreach([1, 2, 3, 4] as $x)<a class="{{ $t === $x ? 'on' : '' }}" style="padding:7px 13px;border-radius:8px" href="{{ route('laboral.m111', ['anio' => $anio, 't' => $x]) }}">{{ $x }}T</a>@endforeach</div>
    <select class="sel" style="width:auto;height:38px" data-nav aria-label="Año">@foreach(range(\App\Support\Plazos::hoy()->year + 1, \App\Support\Plazos::hoy()->year - 4) as $y)<option value="{{ route('laboral.m111', ['anio' => $y, 't' => $t]) }}" @selected($y === $anio)>{{ $y }}</option>@endforeach</select>
    <a class="btn" style="margin-left:auto" href="{{ route('fiscal.modelo', ['codigo' => 111, 'anio' => $anio, 'per' => $t.'T']) }}">Abrir el modelo 111 en Fiscal →</a>
  </div>
  <div class="card">
    <span style="font-size:15px;font-weight:700">Rendimientos del trabajo · {{ $t }}T {{ $anio }} (percepciones dinerarias)</span>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px">
      @foreach([['01', 'Nº de perceptores', (string) $nPerc], ['02', 'Importe de las percepciones', Formato::eur($perc)], ['03', 'Importe de las retenciones', Formato::eur($ret)]] as [$cas, $l, $v])
        <div style="padding:14px 16px;border-radius:14px;background:#F4F4F0;display:flex;flex-direction:column;gap:6px"><span style="font-size:12.5px;color:#6A6A65"><span class="lb-code" style="margin-right:6px">{{ $cas }}</span>{{ $l }}</span><span style="font:600 22px var(--mono)">{{ $v }}</span></div>
      @endforeach
    </div>
    <span style="font-size:12.5px;color:#6A6A65;line-height:1.45">Calculado con las nóminas del trimestre. Las dietas y el kilometraje se tratan como exentos y no se incluyen. El modelo de la pestaña Fiscal sale de la contabilidad (cuenta 4751): si las nóminas están contabilizadas, ambos deben coincidir.</span>
  </div>
  <div class="card" style="padding:6px 18px 10px;overflow-x:auto">
    <div style="min-width:560px">
      <div class="lb-grid lb-afi lb-head"><span>Mes</span><span>Nóminas</span><span class="r">Percepciones</span><span class="r">Retenciones</span></div>
      @foreach($porMes as $m => $x)
        <div class="lb-grid lb-afi lb-row" style="cursor:default"><span style="font-weight:600">{{ Nomina::MESES[$m - 1] }}</span><span class="mono12">{{ $x['n'] }}</span><span class="r m">{{ Formato::eur($x['perc']) }}</span><span class="r m">{{ Formato::eur($x['ret']) }}</span></div>
      @endforeach
      <div class="lb-grid lb-afi" style="padding:12px 0 4px;font-weight:700"><span>Trimestre</span><span></span><span class="r m">{{ Formato::eur($perc) }}</span><span class="r m">{{ Formato::eur($ret) }}</span></div>
    </div>
  </div>
</div>
@endsection
@section('overlay')
@if($con) @include('laboral._conexion') @endif
@endsection
