@extends('layouts.app')
@php
  use App\Services\Nomina;
  use App\Support\Formato;
  $E = fn ($v) => Formato::eur($v);
  $sum = fn ($f) => array_sum(array_map($f, $noms));
  $mesL = Nomina::MESES[$mes - 1].' '.$anio;
  $q = ['anio' => $anio, 'mes' => $mes];
  $at = (float) $P['at'];
@endphp
@section('content')
<div class="page" style="gap:16px">
  @include('laboral._cabecera')
  @include('laboral._meses', ['ruta' => 'laboral.seguros'])
  <div class="no-print" style="display:flex;flex-wrap:wrap;align-items:center;gap:10px">
    <span class="lb-pill" style="font-size:12.5px;padding:6px 12px;background:{{ $st[1] }};color:{{ $st[2] }}">{{ $st[0] }}</span>
    <span style="font-size:13px;color:#3C3C3C">Plazo de ingreso: {{ Formato::fecha($plazo) }}</span>
    <form method="POST" action="{{ route('laboral.at') }}" style="display:flex;align-items:center;gap:8px;font-size:13px;font-weight:600">@csrf
      <label for="lb-at">Tipo AT y EP (%)</label><input id="lb-at" class="inp mono" name="at" value="{{ Formato::num($at) }}" style="width:72px;height:36px;text-align:right" data-autosubmit>
    </form>
    @if($puedeConfirmar)
      <form method="POST" action="{{ route('laboral.confirmar') }}" @if($redOk) data-abrir="{{ Nomina::URL['red'] }}" @endif>@csrf<input type="hidden" name="anio" value="{{ $anio }}"><input type="hidden" name="mes" value="{{ $mes }}"><button class="btn" type="submit">Confirmar liquidación en el SLD</button></form>
    @endif
    <div style="margin-left:auto"><div class="lb-exp"><span class="t">Exportar</span><a href="{{ route('laboral.seguros', $q + ['exportar' => 'excel']) }}">Excel</a><a href="{{ route('laboral.seguros', $q + ['exportar' => 'csv']) }}">CSV</a><button type="button" data-print>PDF</button></div></div>
  </div>
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:14px">
    @foreach([['Bases de cotización', $E($sum(fn ($x) => $x['n']['base'])), count($noms).' trabajadores'], ['Cuota empresa', $E($sum(fn ($x) => $x['n']['ssE'])), 'Incluye AT y EP al '.Formato::num($at).' %'], ['Cuota trabajadores', $E($sum(fn ($x) => $x['n']['ssT'])), 'Descontada en nómina'], ['Total liquidación', $E($sum(fn ($x) => $x['n']['ssE'] + $x['n']['ssT'])), 'RLC · '.$mesL]] as [$l, $v, $s])
      <div class="card" style="gap:6px;padding:16px 18px"><span style="font-size:13px;color:#6A6A65">{{ $l }}</span><span style="font:600 24px/1.1 var(--mono);letter-spacing:-.03em">{{ $v }}</span><span style="font-size:12.5px;color:#3C3C3C">{{ $s }}</span></div>
    @endforeach
  </div>
  <p style="margin:0;font-size:12.5px;color:#6A6A65;line-height:1.5">Tipos de 2026: contingencias comunes 23,60 % + 4,70 %, MEI 0,75 % + 0,15 %, desempleo 5,50 % + 1,55 % (indefinidos) o 6,70 % + 1,60 % (temporales), FOGASA 0,20 % y formación profesional 0,60 % + 0,10 %. Base máxima 5.101,20 € y mínima según grupo.</p>
  <div class="card" style="padding:6px 18px 10px;overflow-x:auto">
    <div style="min-width:640px">
      <div class="lb-grid lb-rnt lb-head"><span>NAF</span><span>Trabajador</span><span>Contrato</span><span class="r">Base CC</span><span class="r">Cuota total</span></div>
      @foreach($noms as $x)
        <div class="lb-grid lb-rnt lb-row" style="cursor:default"><span class="mono12">{{ $x['w']['naf'] }}</span><span class="ell" style="font-weight:600">{{ $x['w']['nombre'] }}</span><span><span class="lb-code">{{ Nomina::codigo($x['w']['contrato']) }}</span></span><span class="r m">{{ $E($x['n']['base']) }}</span><span class="r m">{{ $E($x['n']['ssE'] + $x['n']['ssT']) }}</span></div>
      @endforeach
      @if(! $noms)<div style="padding:24px 0;text-align:center;font-size:14px;color:#6A6A65">No hay trabajadores en alta este mes.</div>@endif
    </div>
  </div>
</div>
@endsection
@section('overlay')
@if($con) @include('laboral._conexion') @endif
@endsection
