@extends('layouts.app')
@php use App\Support\Formato; @endphp
@section('content')
<div class="page" style="gap:16px">
  @include('contabilidad._cabecera')
  <form method="GET" action="{{ route('contabilidad.sumas') }}" style="display:flex;flex-wrap:wrap;align-items:center;gap:10px">
    <div class="chipbar" style="border-radius:11px"><a class="{{ $nivel === 9 ? 'on' : '' }}" style="padding:6px 12px;border-radius:8px" href="{{ request()->fullUrlWithQuery(['nivel' => 9]) }}">Subcuentas</a><a class="{{ $nivel === 3 ? 'on' : '' }}" style="padding:6px 12px;border-radius:8px" href="{{ request()->fullUrlWithQuery(['nivel' => 3]) }}">Cuentas (3 cifras)</a></div>
    <input type="hidden" name="nivel" value="{{ $nivel }}">
    <label style="display:flex;align-items:center;gap:6px;font-size:13px">Hasta<input class="inp" type="date" name="hasta" value="{{ $hasta }}" style="height:38px" data-change-submit></label>
    <a class="pillbtn" style="margin-left:auto" href="{{ request()->fullUrlWithQuery(['exportar' => 'csv']) }}">Excel / CSV</a>
  </form>
  <div class="card" style="padding:6px 18px 10px;overflow-x:auto">
    <div style="min-width:860px">
      <div class="sys-cols" style="font-size:11px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;color:#6A6A65;padding:12px 0 10px;border-bottom:1px solid #E6E6E0"><span>Cuenta</span><span>Nombre</span><span style="text-align:right">Sumas debe</span><span style="text-align:right">Sumas haber</span><span style="text-align:right">Saldo deudor</span><span style="text-align:right">Saldo acreedor</span></div>
      @forelse($filas as $f)
        <a class="sys-cols rowhov" href="{{ route('contabilidad.mayor', ['c' => $f['cuenta']]) }}" style="padding:8px 6px;margin:0 -6px;border-radius:6px;border-bottom:1px solid #F1F1EC;font-size:13px;text-decoration:none;color:var(--ink)">
          <span class="mono">{{ $f['cuenta'] }}</span><span class="ell">{{ $f['nombre'] }}</span>
          <span class="mono" style="text-align:right">{{ Formato::num($f['d']) }}</span><span class="mono" style="text-align:right">{{ Formato::num($f['h']) }}</span>
          <span class="mono" style="text-align:right">{{ $f['sd'] ? Formato::num($f['sd']) : '' }}</span><span class="mono" style="text-align:right">{{ $f['sa'] ? Formato::num($f['sa']) : '' }}</span>
        </a>
      @empty
        <div style="padding:28px 0;text-align:center;font-size:14px;color:#6A6A65">Sin movimientos.</div>
      @endforelse
      @if($filas->count())<div class="sys-cols" style="padding:12px 0 2px;font-weight:700;font-size:13.5px"><span></span><span>Totales</span><span class="mono" style="text-align:right">{{ Formato::num($filas->sum('d')) }}</span><span class="mono" style="text-align:right">{{ Formato::num($filas->sum('h')) }}</span><span class="mono" style="text-align:right">{{ Formato::num($filas->sum('sd')) }}</span><span class="mono" style="text-align:right">{{ Formato::num($filas->sum('sa')) }}</span></div>@endif
    </div>
  </div>
</div>
@endsection
