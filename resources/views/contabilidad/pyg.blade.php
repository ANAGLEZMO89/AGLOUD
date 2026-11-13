@extends('layouts.app')
@php use App\Support\Formato; $res = round(array_sum($tot), 2); @endphp
@section('content')
<div class="page" style="gap:16px">
  @include('contabilidad._cabecera')
  @component('contabilidad._listado-barra')<span style="font-size:13px;color:#3C3C3C">Ingresos y gastos de {{ $c->nombre }} por trimestres · ejercicio {{ $ej->anio }} (sin regularización ni cierre).</span>@endcomponent
  <div class="card" style="padding:16px 18px;overflow-x:auto">
    <div style="min-width:760px">
      <div class="pyg-cols pyg-head"><span>Concepto</span><span>1T</span><span>2T</span><span>3T</span><span>4T</span><span>Total</span></div>
      @forelse($filas as [$l, $v, $t])
        <div class="pyg-cols"><span>{{ $l }}</span>@foreach($v as $x)<span class="mono" style="color:{{ $x < 0 ? '#B42318' : 'inherit' }}">{{ Formato::num($x) }}</span>@endforeach<span class="mono" style="font-weight:700;color:{{ $t < 0 ? '#B42318' : 'inherit' }}">{{ Formato::num($t) }}</span></div>
      @empty
        <div style="padding:28px 0;text-align:center;font-size:14px;color:#6A6A65">No hay ingresos ni gastos en {{ $ej->anio }}.</div>
      @endforelse
      <div class="pyg-cols pyg-tot"><span>RESULTADO</span>@foreach($tot as $x)<span class="mono">{{ Formato::num($x) }}</span>@endforeach<span class="mono">{{ Formato::num($res) }}</span></div>
    </div>
  </div>
  <span class="hint muted">Orientativo para el seguimiento. Las cuentas anuales en formato oficial están en Listados contables › Balances oficiales.</span>
</div>
@endsection
