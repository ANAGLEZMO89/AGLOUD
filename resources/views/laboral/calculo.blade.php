@extends('layouts.app')
@php
  use App\Services\Nomina; use App\Support\Formato;
  $E = fn ($v) => Formato::eur($v);
  $sum = fn ($k) => array_sum(array_map(fn ($x) => is_callable($k) ? $k($x) : $x['t'][$k], $noms));
  $mesL = Nomina::MESES[$mes - 1].' '.$anio;
  $fq = array_filter($f + ['anio' => $anio, 'mes' => $mes], fn ($v) => $v !== '');
@endphp
@section('content')
<div class="page" style="gap:16px">
  @include('laboral._cabecera')
  @include('laboral._meses', ['ruta' => 'laboral.calculo'])
  <form method="GET" action="{{ route('laboral.calculo') }}" class="card no-print" style="flex-direction:row;flex-wrap:wrap;align-items:flex-end;gap:10px;padding:14px 16px">
    <input type="hidden" name="anio" value="{{ $anio }}"><input type="hidden" name="mes" value="{{ $mes }}">
    <label class="lb-f" style="flex:1 1 200px"><span class="lb-l">Buscar</span><input class="inp lb-in" type="search" name="q" value="{{ $f['q'] }}" placeholder="Nombre, NIF o NAF"></label>
    <label class="lb-f"><span class="lb-l">CCC</span><select class="sel lb-in" name="ccc"><option value="">Todos</option>@foreach($opc['ccc'] as $x)<option @selected($f['ccc'] === $x)>{{ $x }}</option>@endforeach</select></label>
    <label class="lb-f"><span class="lb-l">Tipo</span><select class="sel lb-in" name="tipo">@foreach(['' => 'Todos', 'indefinido' => 'Indefinidos', 'temporal' => 'Temporales', 'completa' => 'Jornada completa', 'parcial' => 'Tiempo parcial'] as $v => $l)<option value="{{ $v }}" @selected($f['tipo'] === $v)>{{ $l }}</option>@endforeach</select></label>
    <label class="lb-f"><span class="lb-l">Contrato</span><select class="sel lb-in" name="contrato"><option value="">Todos</option>@foreach($opc['contrato'] as $x)<option value="{{ Nomina::codigo($x) }}" @selected($f['contrato'] === Nomina::codigo($x))>{{ \Illuminate\Support\Str::limit($x, 40) }}</option>@endforeach</select></label>
    <label class="lb-f"><span class="lb-l">Categoría</span><select class="sel lb-in" name="cat"><option value="">Todas</option>@foreach($opc['cat'] as $x)<option @selected($f['cat'] === $x)>{{ $x }}</option>@endforeach</select></label>
    <label class="lb-f"><span class="lb-l">Grupo</span><select class="sel lb-in" name="grupo"><option value="">Todos</option>@foreach($opc['grupo'] as $x)<option @selected($f['grupo'] === $x)>{{ $x }}</option>@endforeach</select></label>
    <button class="btn" type="submit">Filtrar</button>
    @if(array_filter($f))<a class="btn soft" href="{{ route('laboral.calculo', ['anio' => $anio, 'mes' => $mes]) }}">Quitar filtros</a>@endif
  </form>
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:14px">
    @foreach([['Nóminas', count($noms).' de '.$total, $mesL], ['Devengado', $E($sum('dev')), 'Bruto del periodo'], ['Líquido', $E($sum('liq')), 'A transferir'], ['IRPF', $E($sum('irpf')), 'Retenciones'], ['Coste empresa', $E($sum('dev') + $sum('ssE')), 'Bruto más SS empresa']] as [$l, $v, $s])
      <div class="card" style="gap:6px;padding:16px 18px"><span style="font-size:13px;color:#6A6A65">{{ $l }}</span><span style="font:600 22px/1.1 var(--mono);letter-spacing:-.03em">{{ $v }}</span><span style="font-size:12.5px;color:#3C3C3C">{{ $s }}</span></div>
    @endforeach
  </div>
  <div class="no-print" style="display:flex;flex-wrap:wrap;align-items:center;gap:10px">
    @if($noms)<a class="btn" href="{{ route('laboral.calculo.recibos', $fq) }}" target="_blank" rel="noopener">Imprimir las {{ count($noms) }} nóminas en PDF</a>@endif
    <a class="btn white" href="{{ route('laboral.remesa', ['anio' => $anio, 'mes' => $mes]) }}">Remesa de transferencias →</a>
    <div style="margin-left:auto">@include('laboral._barra')</div>
  </div>
  <div class="card" style="padding:6px 18px 10px;overflow-x:auto">
    <div style="min-width:1100px">
      <div class="lb-grid lb-calc lb-head"><span>Trabajador</span><span>CCC</span><span>Contrato</span><span class="r">Devengado</span><span class="r">Base CC</span><span class="r">SS trab.</span><span class="r">IRPF %</span><span class="r">IRPF</span><span class="r">Líquido</span><span class="r">Coste empresa</span></div>
      @forelse($noms as $x)
        <a class="lb-grid lb-calc lb-row" href="{{ route('laboral.integral', ['anio' => $anio, 'mes' => $mes, 't' => $x['w']['id']]) }}">
          <span style="display:flex;flex-direction:column;min-width:0"><span class="ell" style="font-weight:600">{{ $x['w']['nombre'] }}</span><span class="ell" style="font-size:12px;color:#6A6A65">{{ $x['w']['convCat'] ?: $x['w']['cat'] }} · grupo {{ $x['w']['grupo'] }}</span></span>
          <span class="mono12">{{ $x['w']['ccc'] }}</span><span><span class="lb-code">{{ Nomina::codigo($x['w']['contrato']) }}</span></span>
          <span class="r m">{{ $E($x['t']['dev']) }}</span><span class="r m">{{ $E($x['t']['base']) }}</span><span class="r m">{{ $E($x['t']['ssT']) }}</span><span class="r m">{{ Formato::num($x['t']['ip']) }}</span><span class="r m">{{ $E($x['t']['irpf']) }}</span><span class="r m" style="font-weight:700">{{ $E($x['t']['liq']) }}</span><span class="r m">{{ $E($x['t']['dev'] + $x['t']['ssE']) }}</span>
        </a>
      @empty
        <div style="padding:24px 0;text-align:center;font-size:14px;color:#6A6A65">Ninguna nómina coincide con los filtros.</div>
      @endforelse
      @if($noms)<div class="lb-grid lb-calc" style="padding:12px 0 4px;font-weight:700"><span>Totales</span><span></span><span></span><span class="r m">{{ $E($sum('dev')) }}</span><span class="r m">{{ $E($sum('base')) }}</span><span class="r m">{{ $E($sum('ssT')) }}</span><span></span><span class="r m">{{ $E($sum('irpf')) }}</span><span class="r m">{{ $E($sum('liq')) }}</span><span class="r m">{{ $E($sum('dev') + $sum('ssE')) }}</span></div>@endif
    </div>
  </div>
</div>
@endsection
@section('overlay')
@if($con) @include('laboral._conexion') @endif
@endsection
