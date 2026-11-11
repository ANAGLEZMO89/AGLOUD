@extends('layouts.app')
@php use App\Support\Formato; @endphp
@section('content')
<div class="page" style="gap:16px">
  @include('facturacion._cabecera')
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:14px">
    @foreach($kpis as [$l, $v, $s])
      <div class="card" style="padding:16px 18px;gap:6px"><span style="font-size:13px;color:#6A6A65">{{ $l }}</span><span style="font:600 26px/1.1 var(--mono);letter-spacing:-.03em">{{ $v }}</span><span style="font-size:12.5px;color:#3C3C3C">{{ $s }}</span></div>
    @endforeach
  </div>
  <form method="GET" action="{{ route('facturacion.index') }}" style="display:flex;flex-wrap:wrap;align-items:center;gap:10px" data-autoform>
    @if($chip)<input type="hidden" name="chip" value="{{ $chip }}">@endif
    <input class="inp" name="q" value="{{ $q }}" placeholder="Buscar por número, cliente o NIF" style="flex:1;min-width:220px;max-width:340px;height:38px" data-autosubmit>
    <div class="chipbar" style="border-radius:11px">
      @foreach($chips as $ch)<a class="{{ $ch['on'] ? 'on' : '' }}" style="padding:7px 14px;border-radius:8px" href="{{ route('facturacion.index', array_filter(['chip' => $ch['i'] ?: null, 'q' => $q ?: null])) }}">{{ $ch['l'] }}</a>@endforeach
    </div>
    <div style="margin-left:auto;display:flex;align-items:center;gap:4px;padding:3px;border-radius:20px;background:#fff">
      <span style="padding:0 8px 0 10px;font-size:13px;font-weight:700">Exportar</span>
      <a class="pillbtn" style="height:30px;font-size:12.5px" href="{{ route('facturacion.index', array_filter(['chip' => $chip ?: null, 'q' => $q ?: null, 'exportar' => 'csv'])) }}">Excel / CSV</a>
    </div>
  </form>
  <div class="card" style="padding:6px 18px 10px;overflow-x:auto">
    <div style="min-width:860px">
      <div style="display:grid;grid-template-columns:120px 92px minmax(0,1.6fr) 50px 100px 90px 104px 96px;gap:12px;padding:12px 0 10px;font-size:11px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;color:#6A6A65;border-bottom:1px solid #E6E6E0"><span>Número</span><span>Fecha</span><span>Cliente</span><span>Tipo</span><span style="text-align:right">Base</span><span style="text-align:right">IVA</span><span style="text-align:right">Total</span><span>Estado</span></div>
      @forelse($lista as $f)
        @php [$st, $bg, $fg] = $f->estadoChip(); @endphp
        <a href="{{ route('facturacion.show', $f) }}" style="display:grid;grid-template-columns:120px 92px minmax(0,1.6fr) 50px 100px 90px 104px 96px;gap:12px;align-items:center;padding:11px 8px;margin:0 -8px;border-radius:8px;border-bottom:1px solid #F1F1EC;font-size:13.5px;text-decoration:none;color:var(--ink)" class="rowhov">
          <span style="font:600 12.5px var(--mono)">{{ $f->num }}</span><span style="font:500 12.5px var(--mono);color:#3C3C3C">{{ Formato::fecha($f->fecha) }}</span><span class="ell">{{ $f->receptor_nombre ?: 'Factura simplificada' }}</span><span style="font:500 12px var(--mono);color:#6A6A65">{{ $f->tipo }}</span><span style="text-align:right;font:500 13px var(--mono)">{{ Formato::num($f->base) }}</span><span style="text-align:right;font:500 13px var(--mono)">{{ Formato::num($f->cuota) }}</span><span style="text-align:right;font:700 13px var(--mono)">{{ Formato::num($f->total) }}</span><span><span style="font-size:11.5px;font-weight:700;padding:3px 9px;border-radius:10px;background:{{ $bg }};color:{{ $fg }}">{{ $st }}</span></span>
        </a>
      @empty
        <div style="padding:28px 0;text-align:center;font-size:14px;color:#6A6A65">@if($q === '' && ! $chip) Aún no hay facturas. <a href="{{ route('facturacion.create') }}">Emite la primera</a>. @else No hay facturas con estos filtros. @endif</div>
      @endforelse
    </div>
  </div>
</div>
@endsection
