@extends('layouts.app')
@php use App\Support\Formato; $meses = ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic']; @endphp
@section('content')
<div class="page" style="gap:16px">
  @include('contabilidad._cabecera')
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:14px">
    @foreach([['Ingresos', $r['ingresos'], 'Grupo 7'], ['Gastos', $r['gastos'], 'Grupo 6'], ['Resultado', $r['resultado'], $r['ingresos'] ? 'Margen '.Formato::num($r['resultado'] / $r['ingresos'] * 100, 0).' %' : 'Sin movimientos'], ['Tesorería', $tesoreria, 'Caja y bancos (57)']] as [$l, $v, $s])
      <div class="card" style="padding:16px 18px;gap:6px"><span style="font-size:13px;color:#6A6A65">{{ $l }}</span><span style="font:600 24px/1.1 var(--mono);letter-spacing:-.03em;color:{{ $v < 0 ? '#B42318' : '#0B0B0B' }}">{{ Formato::eur($v, 0) }}</span><span style="font-size:12.5px;color:#3C3C3C">{{ $s }}</span></div>
    @endforeach
  </div>
  <div style="display:flex;flex-wrap:wrap;gap:16px;align-items:flex-start">
    <div class="card" style="flex:999 1 560px;min-width:0;padding:18px 20px;gap:12px">
      <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px"><b style="font-size:15px">Ingresos y gastos por mes · {{ $ej->anio }}</b><span style="display:flex;gap:14px;font-size:12.5px"><span style="display:flex;align-items:center;gap:6px"><i style="width:10px;height:10px;border-radius:3px;background:#0B0B0B"></i>Ingresos</span><span style="display:flex;align-items:center;gap:6px"><i style="width:10px;height:10px;border-radius:3px;background:#C8F031"></i>Gastos</span></span></div>
      @if($r['ingresos'] || $r['gastos'])
        <div style="display:grid;grid-template-columns:repeat(12,minmax(0,1fr));gap:8px;align-items:end;height:190px;padding-top:10px">
          @foreach($grafica as $i => $g)
            <div style="display:flex;flex-direction:column;align-items:center;gap:6px;height:100%;justify-content:flex-end" title="{{ $meses[$i] }}: ingresos {{ Formato::eur($g['ing']) }} · gastos {{ Formato::eur($g['gas']) }}">
              <div style="display:flex;gap:3px;align-items:flex-end;height:160px;width:100%;justify-content:center"><i style="width:40%;border-radius:4px 4px 0 0;background:#0B0B0B;height:{{ max(0, round($g['ing'] / $max * 100)) }}%"></i><i style="width:40%;border-radius:4px 4px 0 0;background:#C8F031;height:{{ max(0, round($g['gas'] / $max * 100)) }}%"></i></div>
              <span style="font:500 11px var(--mono);color:#6A6A65">{{ $meses[$i] }}</span>
            </div>
          @endforeach
        </div>
      @else
        <div class="emptybox">Este ejercicio todavía no tiene movimientos. Empieza por la <a href="{{ route('contabilidad.bandeja') }}">bandeja de facturas</a> o un asiento en «Mantenimiento de apuntes».</div>
      @endif
    </div>
    <div style="flex:1 1 280px;min-width:0;display:flex;flex-direction:column;gap:12px">
      <div class="card" style="padding:16px 18px;gap:8px">
        <b style="font-size:14px">Estado del ejercicio</b>
        @foreach([[$nBandeja === 0, 'Facturas contabilizadas', $nBandeja ? $nBandeja.' pendientes en la bandeja' : 'Todas contabilizadas', route('contabilidad.bandeja')], [$cuadra, 'Debe y haber cuadran', $cuadra ? 'Libro diario cuadrado' : 'Revisa el libro diario', route('contabilidad.diario')], [$ej->cerrado(), 'Regularización y cierre', $ej->cerrado() ? 'Hechos' : 'Pendiente (a 31/12)', route('contabilidad.ejercicios')]] as [$ok, $l, $s, $u])
          <a href="{{ $u }}" style="display:grid;grid-template-columns:22px minmax(0,1fr);gap:10px;align-items:center;padding:8px 0;border-top:1px solid #F1F1EC;text-decoration:none;color:var(--ink)"><span class="chkbox {{ $ok ? 'on' : '' }}" style="cursor:pointer">{{ $ok ? '✓' : '' }}</span><span style="display:flex;flex-direction:column"><b style="font-size:13px">{{ $l }}</b><span style="font-size:12px;color:#6A6A65">{{ $s }}</span></span></a>
        @endforeach
      </div>
      <div class="card" style="padding:16px 18px;gap:6px;font-size:13px">
        <div style="display:flex;justify-content:space-between"><span>Pendiente de cobro (43)</span><b class="mono">{{ Formato::eur($clientes) }}</b></div>
        <div style="display:flex;justify-content:space-between"><span>Pendiente de pago (40/41)</span><b class="mono">{{ Formato::eur($proveedores) }}</b></div>
      </div>
    </div>
  </div>
</div>
@endsection
