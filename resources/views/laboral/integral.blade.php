@extends('layouts.app')
@php
  use App\Services\Nomina;
  use App\Support\Formato;
  $E = fn ($v) => Formato::eur($v);
  $sum = fn ($f) => array_sum(array_map($f, $noms));
  $mesL = Nomina::MESES[$mes - 1].' '.$anio;
  $q = ['anio' => $anio, 'mes' => $mes];
@endphp
@section('content')
<div class="page" style="gap:16px">
  @include('laboral._cabecera')
  @include('laboral._meses', ['ruta' => 'laboral.integral'])
  <div style="display:flex;flex-wrap:wrap;gap:16px;align-items:flex-start">
    <div class="card" style="flex:0 1 280px;padding:8px;gap:2px;min-width:0">
      <a class="lb-im {{ $sel ? '' : 'on' }}" href="{{ route('laboral.integral', $q) }}"><span class="t">Resumen de la empresa</span><span class="s">{{ count($noms) }} nóminas · {{ $mesL }}</span></a>
      @foreach($noms as $x)
        <a class="lb-im {{ $sel && $sel['w']['id'] === $x['w']['id'] ? 'on' : '' }}" href="{{ route('laboral.integral', $q + ['t' => $x['w']['id']]) }}"><span class="t">{{ $x['w']['nombre'] }}</span><span class="s">{{ $x['w']['cat'] }} · líquido {{ $E($x['n']['liq']) }}</span></a>
      @endforeach
    </div>
    <div style="flex:1 1 560px;display:flex;flex-direction:column;gap:16px;min-width:0">
      @if(! $sel)
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:14px">
          @foreach([['Trabajadores en alta', (string) count($noms), $plantilla->count().' en plantilla'], ['Coste empresa · '.mb_strtolower(Nomina::MESES[$mes - 1]), $E($sum(fn ($x) => $x['n']['dev']) + $sum(fn ($x) => $x['n']['ssE'])), 'Bruto más Seguridad Social'], ['Líquido a pagar', $E($sum(fn ($x) => $x['n']['liq'])), 'Neto de las nóminas'], ['Retenciones IRPF', $E($sum(fn ($x) => $x['n']['irpf'])), 'Pasan al modelo 111']] as [$l, $v, $s])
            <div class="card" style="gap:6px;padding:16px 18px"><span style="font-size:13px;color:#6A6A65">{{ $l }}</span><span style="font:600 24px/1.1 var(--mono);letter-spacing:-.03em">{{ $v }}</span><span style="font-size:12.5px;color:#3C3C3C">{{ $s }}</span></div>
          @endforeach
        </div>
        <div class="no-print" style="display:flex;flex-wrap:wrap;align-items:center;gap:10px">
          @if($asiento)
            <span class="btn" style="background:#E6F6A8;color:#2E3A00">✓ Nóminas contabilizadas · asiento {{ $asiento->numero }}</span>
          @elseif($noms)
            <form method="POST" action="{{ route('laboral.contabilizar') }}">@csrf<input type="hidden" name="anio" value="{{ $anio }}"><input type="hidden" name="mes" value="{{ $mes }}"><button class="btn" type="submit">Contabilizar nóminas de {{ mb_strtolower(Nomina::MESES[$mes - 1]) }}</button></form>
          @endif
          <a class="btn white" href="{{ route('fiscal.modelo', ['codigo' => 111, 'anio' => $anio, 'per' => (int) ceil($mes / 3).'T']) }}">Ver modelo 111 del trimestre →</a>
          <div style="margin-left:auto"><div class="lb-exp"><span class="t">Exportar</span><a href="{{ route('laboral.integral', $q + ['exportar' => 'excel']) }}">Excel</a><a href="{{ route('laboral.integral', $q + ['exportar' => 'csv']) }}">CSV</a><button type="button" data-print>PDF</button></div></div>
        </div>
        <div class="card" style="padding:6px 18px 10px;overflow-x:auto">
          <div style="min-width:900px">
            <div class="lb-grid lb-nom lb-head"><span>Trabajador</span><span class="r">Devengado</span><span class="r">Base CC</span><span class="r">SS trabajador</span><span class="r">IRPF</span><span class="r">Líquido</span><span class="r">SS empresa</span></div>
            @foreach($noms as $x)
              <a class="lb-grid lb-nom lb-row" href="{{ route('laboral.integral', $q + ['t' => $x['w']['id']]) }}">
                <span style="display:flex;flex-direction:column;min-width:0"><span class="ell" style="font-weight:600">{{ $x['w']['nombre'] }}</span><span style="font-size:12px;color:#6A6A65">{{ $x['w']['cat'] }}</span></span>
                <span class="r m">{{ $E($x['n']['dev']) }}</span><span class="r m">{{ $E($x['n']['base']) }}</span><span class="r m">{{ $E($x['n']['ssT']) }}</span><span class="r m">{{ $E($x['n']['irpf']) }}</span><span class="r m" style="font-weight:700">{{ $E($x['n']['liq']) }}</span><span class="r m">{{ $E($x['n']['ssE']) }}</span>
              </a>
            @endforeach
            <div class="lb-grid lb-nom" style="padding:12px 0 4px;font-weight:700"><span>Totales</span><span class="r m">{{ $E($sum(fn ($x) => $x['n']['dev'])) }}</span><span></span><span class="r m">{{ $E($sum(fn ($x) => $x['n']['ssT'])) }}</span><span class="r m">{{ $E($sum(fn ($x) => $x['n']['irpf'])) }}</span><span class="r m">{{ $E($sum(fn ($x) => $x['n']['liq'])) }}</span><span class="r m">{{ $E($sum(fn ($x) => $x['n']['ssE'])) }}</span></div>
            @if(! $noms)<div style="padding:24px 0;text-align:center;font-size:14px;color:#6A6A65;line-height:1.5">No hay trabajadores por cuenta ajena en alta este mes.@if($aut = $plantilla->filter(fn ($t) => \App\Services\Nomina::esAutonomo($t))->count())<br>{{ $aut }} {{ $aut === 1 ? 'persona está dada de alta como autónoma' : 'personas están dadas de alta como autónomas' }} (RETA): los autónomos no tienen nómina, pagan su cuota del RETA.@endif</div>@endif
          </div>
        </div>
      @else
        @include('laboral._editor')
      @endif
    </div>
  </div>
</div>
@endsection
@section('overlay')
@if($con) @include('laboral._conexion') @endif
@endsection
