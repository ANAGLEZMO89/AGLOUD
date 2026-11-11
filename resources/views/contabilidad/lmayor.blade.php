@extends('layouts.app')
@php use App\Support\Formato; @endphp
@section('content')
<div class="page" style="gap:16px">
  @include('contabilidad._cabecera')
  <datalist id="ctas">@foreach($catalogo as $k => $n)<option value="{{ $k }}">{{ $n }}</option>@endforeach</datalist>
  @component('contabilidad._listado-barra')
    <form method="GET" action="{{ route('contabilidad.lmayor') }}" style="display:flex;flex-wrap:wrap;align-items:center;gap:8px">
      <label class="lbl-s" style="flex-direction:row;align-items:center;gap:6px">De la cuenta<input class="inp mono" name="de" value="{{ $de }}" list="ctas" placeholder="100" style="width:140px;height:36px"></label>
      <label class="lbl-s" style="flex-direction:row;align-items:center;gap:6px">a la<input class="inp mono" name="a" value="{{ $a }}" list="ctas" placeholder="799" style="width:140px;height:36px"></label>
      <button class="pillbtn ink" type="submit">Ver</button>
    </form>
  @endcomponent
  @forelse($grupos as $g)
    <div class="card" style="padding:14px 20px;overflow-x:auto">
      <div style="min-width:700px">
        <div style="display:flex;justify-content:space-between;align-items:baseline;gap:10px;padding-bottom:6px"><span style="font-size:14px;font-weight:700"><span class="mono" style="margin-right:8px">{{ $g['cuenta'] }}</span>{{ $g['nombre'] }}</span><span class="mono" style="font-weight:700;font-size:13px">Saldo {{ Formato::num($g['saldo']) }}</span></div>
        @foreach($g['rows'] as $r)
          <div class="lm-cols"><span class="mono">{{ $r['n'] }}</span><span class="mono">{{ Formato::fecha($r['fecha']) }}</span><span class="ell">{{ $r['concepto'] }}</span><span class="mono" style="text-align:right">{{ $r['d'] ? Formato::num($r['d']) : '' }}</span><span class="mono" style="text-align:right">{{ $r['h'] ? Formato::num($r['h']) : '' }}</span><span class="mono" style="text-align:right">{{ Formato::num($r['saldo']) }}</span></div>
        @endforeach
        <div class="lm-cols" style="font-weight:700;border-top:1px solid #C4C4BE"><span></span><span></span><span>Totales</span><span class="mono" style="text-align:right">{{ Formato::num($g['d']) }}</span><span class="mono" style="text-align:right">{{ Formato::num($g['h']) }}</span><span class="mono" style="text-align:right">{{ Formato::num($g['saldo']) }}</span></div>
      </div>
    </div>
  @empty
    <div class="card"><div class="empty"><b>Sin movimientos</b><span>No hay apuntes en {{ $ej->anio }} entre esas cuentas.</span></div></div>
  @endforelse
</div>
@endsection
