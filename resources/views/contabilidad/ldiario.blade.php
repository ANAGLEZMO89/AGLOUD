@extends('layouts.app')
@php use App\Services\Contabilidad; use App\Support\Formato; @endphp
@section('content')
<div class="page" style="gap:16px">
  @include('contabilidad._cabecera')
  @component('contabilidad._listado-barra')
    <form method="GET" action="{{ route('contabilidad.ldiario') }}" style="display:flex;flex-wrap:wrap;align-items:center;gap:8px">
      <label class="lbl-s" style="flex-direction:row;align-items:center;gap:6px">Desde<input class="inp" type="date" name="desde" value="{{ $desde }}" style="height:36px"></label>
      <label class="lbl-s" style="flex-direction:row;align-items:center;gap:6px">Hasta<input class="inp" type="date" name="hasta" value="{{ $hasta }}" style="height:36px"></label>
      <button class="pillbtn ink" type="submit">Ver</button>
    </form>
  @endcomponent
  <div class="card" style="padding:16px 20px;overflow-x:auto">
    <div style="min-width:760px;font-family:var(--mono)">
      <div style="text-align:center;font-weight:700;font-size:13px;padding-bottom:4px">LISTADO DIARIO DE MOVIMIENTOS · {{ mb_strtoupper($c->nombre) }} · {{ $c->nif }}</div>
      <div style="text-align:center;font-size:12px;color:#6A6A65;padding-bottom:10px;border-bottom:1.5px solid #0B0B0B">Del {{ Formato::fecha($desde) }} al {{ Formato::fecha($hasta) }}</div>
      @php $tD = 0; $tH = 0; @endphp
      @forelse($lista as $a)
        <div style="display:flex;justify-content:space-between;gap:10px;padding:10px 0 4px;font-size:12.5px;font-weight:700"><span>Asiento {{ $a->numero }} · {{ Formato::fecha($a->fecha) }}</span><span class="ell" style="font-family:var(--sans,inherit)">{{ $a->concepto }}</span></div>
        @foreach($a->apuntes as $p)
          @php $tD += $p->debe; $tH += $p->haber; @endphp
          <div class="ld-cols"><span>{{ $p->cuenta }}</span><span class="ell">{{ Contabilidad::nombre($p->cuenta, $propias) }}{{ $p->concepto && $p->concepto !== $a->concepto ? ' · '.$p->concepto : '' }}</span><span style="text-align:right">{{ (float) $p->debe ? Formato::num($p->debe) : '' }}</span><span style="text-align:right">{{ (float) $p->haber ? Formato::num($p->haber) : '' }}</span></div>
        @endforeach
      @empty
        <div style="padding:28px 0;text-align:center;font-size:13px;color:#6A6A65">No hay asientos en ese periodo.</div>
      @endforelse
      <div class="ld-cols" style="border-top:1.5px solid #0B0B0B;margin-top:10px;padding-top:8px;font-weight:700"><span></span><span>Totales · {{ $lista->count() }} asientos</span><span style="text-align:right">{{ Formato::num($tD) }}</span><span style="text-align:right">{{ Formato::num($tH) }}</span></div>
    </div>
  </div>
</div>
@endsection
