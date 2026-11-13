@extends('layouts.app')
@php use App\Support\Formato; $cuadra = abs($totA - $totP) < 0.01; @endphp
@section('content')
<div class="page" style="gap:16px">
  @include('contabilidad._cabecera')
  <p style="margin:0;font-size:13px;color:#3C3C3C;line-height:1.5">Modelos abreviados del PGC-PYMES, calculados a partir del libro diario de {{ $ej->anio }}. Son <b>orientativos</b> para el seguimiento: las cuentas anuales que se depositan en el Registro Mercantil se prepararán en una fase posterior. El impuesto sobre beneficios se calculará en el módulo Fiscal.</p>
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,420px),1fr));gap:16px;align-items:start">
    <div class="card" style="padding:18px 20px;gap:2px">
      <b style="font-size:15px;padding-bottom:8px">Pérdidas y ganancias · {{ $ej->anio }}</b>
      @foreach($pyg as [$lp, $vp, $totp])
        <div style="display:flex;justify-content:space-between;gap:12px;padding:7px {{ $totp ? '8px' : '0' }};border-top:1px solid #F1F1EC;font-size:13px;{{ $totp ? 'font-weight:700;background:#F4F4F0;border-radius:6px' : '' }}"><span>{{ $lp }}</span><span class="mono" style="color:{{ $vp < 0 ? '#B42318' : 'inherit' }}">{{ Formato::num($vp) }}</span></div>
      @endforeach
    </div>
    <div style="display:flex;flex-direction:column;gap:16px">
      @foreach([['Balance · Activo', $balActivo, $totA, 'TOTAL ACTIVO'], ['Balance · Patrimonio neto y pasivo', $balPasivo, $totP, 'TOTAL PATRIMONIO NETO Y PASIVO']] as [$tituloBal, $filasBal, $totBal, $etqBal])
        <div class="card" style="padding:18px 20px;gap:2px">
          <b style="font-size:15px;padding-bottom:8px">{{ $tituloBal }}</b>
          @foreach($filasBal as [$lb, $vb, $negrita])
            <div style="display:flex;justify-content:space-between;gap:12px;padding:6px 0 6px {{ $negrita ? '0' : '14px' }};border-top:1px solid #F1F1EC;font-size:13px;{{ $negrita ? 'font-weight:700' : '' }}"><span>{{ $lb }}</span><span class="mono">{{ Formato::num($vb) }}</span></div>
          @endforeach
          <div style="display:flex;justify-content:space-between;padding:9px 8px;margin-top:6px;border-radius:6px;background:#0B0B0B;color:#fff;font-weight:700;font-size:13.5px"><span>{{ $etqBal }}</span><span class="mono">{{ Formato::num($totBal) }}</span></div>
        </div>
      @endforeach
      <div class="{{ $cuadra ? 'msg' : 'ferr' }}" style="font-weight:600">{{ $cuadra ? '✓ El activo y el patrimonio neto más el pasivo cuadran.' : 'Diferencia entre activo y pasivo: '.Formato::eur($totA - $totP).'. Suele deberse a cuentas mal clasificadas o asientos descuadrados.' }}</div>
    </div>
  </div>
</div>
@endsection
