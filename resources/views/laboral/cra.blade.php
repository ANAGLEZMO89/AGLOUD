@extends('layouts.app')
@php use App\Services\Nomina; use App\Support\Formato; $mesL = Nomina::MESES[$mes - 1].' '.$anio; @endphp
@section('content')
<div class="page" style="gap:16px">
  @include('laboral._cabecera')
  @include('laboral._meses', ['ruta' => 'laboral.cra'])
  @include('laboral._proceso', ['pasos' => [
      'preparar' => ['aqui', 'conceptos de los recibos del mes con su código CRA (el del concepto del convenio o, si no tiene, el de esta tabla)'],
      'fichero' => ['no', 'AG Cloud no genera el fichero CRA oficial; el Excel o CSV es de apoyo'],
      'presentar' => ['fuera', 'en el Sistema RED'],
      'respuesta' => ['fuera', 'la da el Sistema RED'],
  ]])
  <div style="display:flex;flex-wrap:wrap;align-items:center;gap:10px">
    <span style="font-size:13.5px;color:#3C3C3C;flex:1 1 300px">Conceptos retributivos abonados en las nóminas de {{ mb_strtolower($mesL) }} ({{ $nTrab }} trabajadores). Se comunican a la TGSS por el Sistema RED.</span>
    @if($redOk)<a class="btn" href="{{ Nomina::URL['red'] }}" target="_blank" rel="noopener">Abrir el Sistema RED ↗</a>@endif
    @include('laboral._barra')
  </div>
  <form class="card no-print" method="POST" action="{{ route('laboral.cra.guardar') }}">
    @csrf
    <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap"><span style="font-size:15px;font-weight:700">Códigos CRA de cada concepto</span><button class="btn sm" type="submit">Guardar códigos</button></div>
    <span style="font-size:12.5px;color:#6A6A65">Indica el código de la tabla de conceptos retributivos de la TGSS que corresponde a cada concepto de la nómina. Se guardan para esta empresa.</span>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(min(100%,200px),1fr));gap:12px">
      @foreach(Nomina::CRA as $k => $l)
        <label class="lb-f"><span class="lb-l">{{ $l }}</span><input class="inp lb-in mono" name="cra[{{ $k }}]" value="{{ $P['cra'][$k] ?? '' }}" maxlength="6" placeholder="Código"></label>
      @endforeach
    </div>
  </form>
  <div class="card" style="padding:6px 18px 10px;overflow-x:auto">
    <div style="min-width:700px">
      <div class="lb-grid lb-cra lb-head"><span>NAF</span><span>Trabajador</span><span>Código CRA</span><span>Concepto</span><span class="r">Importe</span></div>
      @forelse($filas as $f)
        <div class="lb-grid lb-cra lb-row" style="cursor:default"><span class="mono12">{{ $f['w']['naf'] }}</span><span class="ell" style="font-weight:600">{{ $f['w']['nombre'] }}</span><span>@if($f['cod'])<span class="lb-code">{{ $f['cod'] }}</span>@else<span style="font-size:12px;color:#8A1C1C">Sin código</span>@endif</span><span class="ell">{{ $f['l'] }}{{ $f['propio'] ? ' · código del convenio' : '' }}{{ $f['provisional'] ? ' · provisional' : '' }}</span><span class="r m">{{ Formato::eur($f['v']) }}</span></div>
      @empty
        <div style="padding:24px 0;text-align:center;font-size:14px;color:#6A6A65">No hay nóminas este mes.</div>
      @endforelse
    </div>
  </div>
</div>
@endsection
@section('overlay')
@if($con) @include('laboral._conexion') @endif
@endsection
