@extends('layouts.app')
@php use App\Support\Formato; $s = fn ($k) => array_sum(array_column($porTrab, $k)); @endphp
@section('content')
<div class="page" style="gap:16px">
  @include('laboral._cabecera')
  @php $pendM = isset($porMes) ? collect($porMes)->flatMap(fn ($x, $mm) => collect($x['pend'])->map(fn ($p) => Nomina::MESES[$mm - 1].': '.$p))->all() : ($pend190 ?? []); @endphp
  @include('laboral._proceso', ['pasos' => [
      'preparar' => ['aqui', 'percepciones y retenciones de los recibos del año'],
      'fichero' => ['no', 'el fichero oficial no se genera aquí; los importes se pasan al modelo en Fiscal o en la sede de la AEAT'],
      'presentar' => ['fuera', 'en la sede electrónica de la AEAT'],
      'respuesta' => ['fuera', 'el justificante lo da la AEAT'],
  ]])
  @if($pendM)<div class="note-amber"><b>Provisional:</b> hay recibos sin emitir que pueden cambiar las cifras: {{ implode(' · ', array_slice($pendM, 0, 12)) }}{{ count($pendM) > 12 ? ' …' : '' }}. Si rectificas una nómina de un periodo ya presentado, corresponde una declaración complementaria o sustitutiva.</div>@endif
  <div style="display:flex;flex-wrap:wrap;align-items:center;gap:10px">
    <select class="sel" style="width:auto;height:38px" data-nav aria-label="Año">@foreach(range(\App\Support\Plazos::hoy()->year, \App\Support\Plazos::hoy()->year - 4) as $y)<option value="{{ route('laboral.m190', ['anio' => $y]) }}" @selected($y === $anio)>Ejercicio {{ $y }}</option>@endforeach</select>
    <span style="font-size:13.5px;color:#3C3C3C;flex:1 1 300px">Resumen anual de retenciones del trabajo (clave A · empleados por cuenta ajena) a partir de las nóminas. Se presenta en enero en la Sede de la AEAT; aquí se prepara y se revisa.</span>
    @include('laboral._barra')
  </div>
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:14px">
    @foreach([['Perceptores', (string) count($porTrab)], ['Percepciones íntegras', Formato::eur($s('perc'))], ['Retenciones', Formato::eur($s('ret'))], ['Gastos deducibles (SS)', Formato::eur($s('ss'))]] as [$l, $v])
      <div class="card" style="gap:6px;padding:16px 18px"><span style="font-size:13px;color:#6A6A65">{{ $l }}</span><span style="font:600 22px/1.1 var(--mono)">{{ $v }}</span></div>
    @endforeach
  </div>
  <div class="card" style="padding:6px 18px 10px;overflow-x:auto">
    <div style="min-width:860px">
      <div class="lb-grid lb-m190 lb-head"><span>NIF</span><span>Perceptor</span><span>Clave</span><span class="r">Percepción íntegra</span><span class="r">Retenciones</span><span class="r">Dietas exentas</span><span class="r">Gastos (SS)</span></div>
      @forelse($porTrab as $x)
        <div class="lb-grid lb-m190 lb-row" style="cursor:default"><span class="mono12">{{ $x['w']['nif'] }}</span><span style="display:flex;flex-direction:column;min-width:0"><span class="ell" style="font-weight:600">{{ $x['w']['nombre'] }}</span><span style="font-size:12px;color:#6A6A65">{{ $x['meses'] }} meses · 145: situación {{ $x['w']['m145']['sit'] ?? '3' }}, {{ count($x['w']['m145']['hijos'] ?? []) }} hijos</span></span><span><span class="lb-code">A</span></span>
          <span class="r m">{{ Formato::eur($x['perc']) }}</span><span class="r m">{{ Formato::eur($x['ret']) }}</span><span class="r m">{{ Formato::eur($x['dietas']) }}</span><span class="r m">{{ Formato::eur($x['ss']) }}</span></div>
      @empty
        <div style="padding:24px 0;text-align:center;font-size:14px;color:#6A6A65">No hay nóminas en {{ $anio }}.</div>
      @endforelse
    </div>
  </div>
  <span style="font-size:12px;color:#6A6A65">No se genera el fichero oficial del modelo 190: el Excel recoge los datos de cada perceptor para trasladarlos al formulario de la AEAT.</span>
</div>
@endsection
@section('overlay')
@if($con) @include('laboral._conexion') @endif
@endsection
