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
            @if(! $noms)<div style="padding:24px 0;text-align:center;font-size:14px;color:#6A6A65">No hay trabajadores en alta este mes.</div>@endif
          </div>
        </div>
      @else
        @php
          $w = $sel['w']; $n = $sel['n'];
          $prev = $idx > 0 ? $noms[$idx - 1]['w']['id'] : null; $next = $idx < count($noms) - 1 ? $noms[$idx + 1]['w']['id'] : null;
          $irTxt = $ir['fijo'] ? 'Tipo fijo del '.Formato::num($ir['tipo']).' % indicado en la ficha.'
            : 'Tipo calculado con el algoritmo de la AEAT: '.Formato::num($ir['tipoAnual']).' % anual'.(abs($ir['tipo'] - $ir['tipoAnual']) > 0.001 ? ', regularizado al '.Formato::num($ir['tipo']).' % este mes por lo ya retenido ('.$E($ir['retPrev']).').' : '.').' Retribución anual prevista: '.$E($ir['RT']).'.';
        @endphp
        <div style="display:flex;flex-wrap:wrap;align-items:center;gap:10px">
          <div style="display:flex;flex-direction:column;gap:2px;min-width:0;margin-right:auto"><span style="font-size:18px;font-weight:700">{{ $w['nombre'] }}</span><span style="font-size:12.5px;color:#6A6A65">{{ $w['contrato'] }} · {{ $w['cat'] }} · grupo {{ $w['grupo'] }}</span></div>
          @if($prev)<a class="btn white" href="{{ route('laboral.integral', $q + ['t' => $prev]) }}">← Anterior</a>@endif
          @if($next)<a class="btn white" href="{{ route('laboral.integral', $q + ['t' => $next]) }}">Siguiente →</a>@endif
          <a class="btn" href="{{ route('laboral.recibo', [$w['id']] + $q + ['imprimir' => 1]) }}" target="_blank" rel="noopener">Descargar nómina en PDF</a>
        </div>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px">
          @foreach([['Devengado', $n['dev']], ['Seguridad Social', $n['ssT']], ['IRPF '.Formato::num($n['ip']).' %', $n['irpf']], ['Líquido', $n['liq']]] as [$l, $v])
            <div class="card" style="gap:4px;padding:14px 16px;border-radius:16px"><span style="font-size:12.5px;color:#6A6A65">{{ $l }}</span><span style="font:600 20px var(--mono)">{{ $E($v) }}</span></div>
          @endforeach
        </div>
        <form class="card" method="POST" action="{{ route('laboral.incidencias', $w['id']) }}">
          @csrf<input type="hidden" name="anio" value="{{ $anio }}"><input type="hidden" name="mes" value="{{ $mes }}">
          <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap"><span style="font-size:15px;font-weight:700">Variables del mes</span>@if(! $asiento)<button class="btn sm" type="submit">Guardar variables</button>@endif</div>
          <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(min(100%,180px),1fr));gap:12px">
            @foreach(Nomina::INCIDENCIAS as $k => $l)
              <label class="lb-f"><span class="lb-l">{{ $l }}</span><input class="inp lb-in mono" name="{{ $k }}" value="{{ isset($inc[$k]) ? Formato::num($inc[$k]) : '' }}" placeholder="0,00" inputmode="decimal" style="text-align:right" @disabled($asiento)></label>
            @endforeach
          </div>
          <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;padding:12px 14px;border-radius:12px;background:#F4F4F0"><span style="font-size:13px;line-height:1.45;flex:1 1 300px">{{ $irTxt }}</span><a class="btn white sm" href="{{ route('laboral.ficha', [$w['id'], 'sec' => 'irpf']) }}">IRPF y modelo 145</a></div>
          @if($asiento)<span style="font-size:12.5px;color:#6A6A65">Las nóminas del mes ya están contabilizadas: para cambiar las variables, borra el asiento {{ $asiento->numero }} en Contabilidad.</span>@endif
        </form>
        <iframe class="lb-recibo" title="Nómina" src="{{ route('laboral.recibo', [$w['id']] + $q) }}"></iframe>
      @endif
    </div>
  </div>
</div>
@endsection
@section('overlay')
@if($con) @include('laboral._conexion') @endif
@endsection
