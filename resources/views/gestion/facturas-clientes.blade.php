@extends('layouts.app')
@php use App\Support\Formato; @endphp
@section('content')
<div class="page" style="gap:16px">
  <div class="page-h">
    <div class="t"><span class="crumb">Gestión</span><h1 class="ttl">Clientes</h1></div>
    @include('partials.empresa', ['volver' => '/clientes/facturas'])
  </div>
  @include('partials.tabs-modulo', ['modulo' => 'clientes', 'actual' => 'clientes.facturas'])
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:14px">
    @foreach($kpis as [$l, $val, $s])<div class="card" style="padding:16px 18px;gap:6px"><span style="font-size:13px;color:#6A6A65">{{ $l }}</span><span style="font:600 26px/1.1 var(--mono);letter-spacing:-.03em">{{ $val }}</span><span style="font-size:12.5px;color:#3C3C3C">{{ $s }}</span></div>@endforeach
  </div>
  <form method="GET" action="{{ route('clientes.facturas') }}" style="display:flex;flex-wrap:wrap;align-items:center;gap:10px" data-autoform>
    @foreach(['y' => $y, 'per' => $per === 'Año' ? null : $per, 'chip' => $chip ?: null] as $k => $val)@if($val)<input type="hidden" name="{{ $k }}" value="{{ $val }}">@endif @endforeach
    <input class="inp" name="q" value="{{ $q }}" placeholder="Buscar por número, cliente o NIF" style="flex:1;min-width:180px;max-width:300px;height:38px" data-autosubmit>
    <select class="sel" style="width:auto;height:38px" data-nav>@foreach($anios as $a)<option value="{{ $url(['y' => $a]) }}" @selected($a === $y)>{{ $a }}</option>@endforeach</select>
    <div class="chipbar" style="border-radius:11px">@foreach(['Año', '1T', '2T', '3T', '4T'] as $p)<a class="{{ $per === $p ? 'on' : '' }}" style="padding:7px 12px;border-radius:8px" href="{{ $url(['per' => $p === 'Año' ? null : $p]) }}">{{ $p }}</a>@endforeach</div>
    <div class="chipbar" style="border-radius:11px">@foreach($chips as $i => $l)<a class="{{ $chip === $i ? 'on' : '' }}" style="padding:7px 12px;border-radius:8px" href="{{ $url(['chip' => $i ?: null]) }}">{{ $l }}</a>@endforeach</div>
    <div style="margin-left:auto;display:flex;gap:10px;align-items:center">
      <div style="display:flex;align-items:center;gap:4px;padding:3px;border-radius:20px;background:#fff"><span style="padding:0 8px 0 10px;font-size:13px;font-weight:700">Exportar</span><a class="pillbtn" style="height:30px;font-size:12.5px" href="{{ $url(['exportar' => 'csv']) }}">Excel / CSV</a></div>
      <a class="btn" href="{{ route('facturacion.create') }}" style="background:#0B0B0B;color:#fff">+ Nueva factura</a>
    </div>
  </form>
  <div class="card" style="padding:6px 18px 10px;overflow-x:auto">
    <div style="min-width:880px">
      <div class="fcl-cols" style="font-size:11px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;color:#6A6A65;padding:12px 0 10px;border-bottom:1px solid #E6E6E0"><span>Nº</span><span>Fecha</span><span>Cliente</span><span style="text-align:right">Base</span><span style="text-align:right">IVA</span><span style="text-align:right">Total</span><span>Vence</span><span>Estado</span></div>
      @forelse($lista as $f)
        @php $vt = $venc($f); [$st, $bg, $fg] = $f->cobrada ? ['Cobrada', '#E6F6A8', '#2E3A00'] : ($vt->lt($hoy) ? ['Vencida', '#FDE7E7', '#8A1C1C'] : ['Pendiente', '#FCEBCB', '#6E4300']); @endphp
        <a class="fcl-cols rowhov" href="{{ route('facturacion.show', $f) }}" style="padding:11px 8px;margin:0 -8px;border-radius:8px;border-bottom:1px solid #F1F1EC;font-size:13.5px;text-decoration:none;color:var(--ink)">
          <span class="mono" style="font-size:12.5px;font-weight:600">{{ $f->num }}</span><span class="mono" style="font-size:12.5px;color:#3C3C3C">{{ Formato::fecha($f->fecha) }}</span><span class="ell">{{ $f->receptor_nombre ?: 'Factura simplificada' }}</span>
          <span class="mono" style="text-align:right">{{ Formato::num($f->base) }}</span><span class="mono" style="text-align:right">{{ Formato::num($f->cuota) }}</span><span class="mono" style="text-align:right;font-weight:700">{{ Formato::num($f->total) }}</span>
          <span class="mono" style="font-size:12.5px">{{ Formato::fecha($vt) }}</span><span><span style="font-size:11.5px;font-weight:700;padding:3px 9px;border-radius:10px;background:{{ $bg }};color:{{ $fg }}">{{ $st }}</span></span>
        </a>
      @empty
        <div style="padding:28px 0;text-align:center;font-size:14px;color:#6A6A65">No hay facturas emitidas en este periodo con estos filtros. Se emiten en Áreas › Facturación.</div>
      @endforelse
    </div>
  </div>
</div>
@endsection
