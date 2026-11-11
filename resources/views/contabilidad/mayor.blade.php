@extends('layouts.app')
@php use App\Services\Contabilidad; use App\Support\Formato; @endphp
@section('content')
<div class="page" style="gap:16px">
  @include('contabilidad._cabecera')
  <datalist id="ctas">@foreach($catalogo as $k => $n)<option value="{{ $k }}">{{ $n }}</option>@endforeach</datalist>
  <form method="GET" action="{{ route('contabilidad.mayor') }}" style="display:flex;flex-wrap:wrap;align-items:center;gap:10px">
    <input class="inp mono" name="c" value="{{ $cta }}" list="ctas" placeholder="Cuenta: 572, 430000001…" style="width:240px;height:38px" required>
    <button class="pillbtn ink" type="submit">Ver mayor</button>
    @if($cta !== '')<span style="font-size:14px;font-weight:600">{{ $nombreCta }}</span><a class="pillbtn" style="margin-left:auto" href="{{ request()->fullUrlWithQuery(['exportar' => 'csv']) }}">Excel / CSV</a>@endif
  </form>
  @if($cta !== '')
  <div class="card" style="padding:6px 18px 10px;overflow-x:auto">
    <div style="min-width:860px">
      <div class="may-cols" style="font-size:11px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;color:#6A6A65;padding:12px 0 10px;border-bottom:1px solid #E6E6E0"><span>Asiento</span><span>Fecha</span><span>Cuenta</span><span>Concepto</span><span style="text-align:right">Debe</span><span style="text-align:right">Haber</span><span style="text-align:right">Saldo</span></div>
      @forelse($movs as $m)
        <div class="may-cols" style="padding:8px 0;border-bottom:1px solid #F1F1EC;font-size:13px">
          <span class="mono">{{ $m->numero }}</span><span class="mono" style="font-size:12.5px">{{ Formato::fecha(substr((string) $m->fecha, 0, 10)) }}</span><span class="mono" style="font-size:12.5px" title="{{ Contabilidad::nombre($m->cuenta, $propias) }}">{{ $m->cuenta }}</span>
          <span class="ell">{{ $m->concepto ?: $m->aconcepto }}</span><span class="mono" style="text-align:right">{{ (float) $m->debe ? Formato::num($m->debe) : '' }}</span><span class="mono" style="text-align:right">{{ (float) $m->haber ? Formato::num($m->haber) : '' }}</span>
          <span class="mono" style="text-align:right;font-weight:600">{{ Formato::num($m->saldo) }}</span>
        </div>
      @empty
        <div style="padding:28px 0;text-align:center;font-size:14px;color:#6A6A65">Sin movimientos en {{ $ej->anio }}.</div>
      @endforelse
      @if($movs->count())<div class="may-cols" style="padding:12px 0 2px;font-weight:700;font-size:13.5px"><span></span><span></span><span></span><span>Totales</span><span class="mono" style="text-align:right">{{ Formato::num($movs->sum('debe')) }}</span><span class="mono" style="text-align:right">{{ Formato::num($movs->sum('haber')) }}</span><span class="mono" style="text-align:right">{{ Formato::num($movs->last()->saldo) }}</span></div>@endif
    </div>
  </div>
  @else
    <div class="card"><div class="empty"><b>Elige una cuenta</b><span>Con 3 cifras ves todas sus subcuentas (por ejemplo, 430 = todos los clientes).</span></div></div>
  @endif
</div>
@endsection
