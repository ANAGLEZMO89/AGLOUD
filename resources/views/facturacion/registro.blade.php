@extends('layouts.app')
@php use App\Support\Formato; $n = $regs->count(); @endphp
@section('content')
<div class="page" style="gap:16px">
  @include('facturacion._cabecera')
  <div class="card" style="padding:16px 18px;flex-direction:row;flex-wrap:wrap;align-items:center;gap:12px 22px">
    <div style="display:flex;flex-direction:column;gap:2px"><span style="font-size:12.5px;color:#6A6A65">Registros</span><span style="font:600 22px var(--mono)">{{ Formato::entero($total) }}</span></div>
    <div style="display:flex;flex-direction:column;gap:2px"><span style="font-size:12.5px;color:#6A6A65">Último registro</span><span style="font:600 14px var(--mono)">{{ $regs->first() ? str_replace('T', ' ', substr($regs->first()->fecha_hora_huso, 0, 16)) : '—' }}</span></div>
    <form method="POST" action="{{ route('facturacion.verificar') }}">@csrf<button class="pillbtn ink" type="submit">Verificar cadena de huellas</button></form>
    <div style="margin-left:auto;display:flex;align-items:center;gap:4px;padding:3px;border-radius:20px;background:#F4F4F0"><span style="padding:0 8px 0 10px;font-size:13px;font-weight:700">Exportar</span><a class="pillbtn wh" style="height:30px;font-size:12.5px" href="{{ route('facturacion.registro', ['exportar' => 'csv']) }}">Excel / CSV</a></div>
  </div>
  @if($chk)<div class="{{ $chk['ok'] ? 'msg' : 'ferr' }}" style="font-weight:600">{{ $chk['ok'] ? '✓ ' : '' }}{{ $chk['t'] }}</div>@endif
  <p style="margin:0;font-size:13px;color:#3C3C3C;line-height:1.5">Cada registro incluye la huella SHA-256 del anterior, de modo que cualquier alteración rompe la cadena. Las altas y anulaciones nunca se borran. <b>Modo No VERI*FACTU:</b> los registros se conservan en AG Cloud y no se envían a la AEAT.</p>
  <div class="card" style="padding:6px 18px 10px;overflow-x:auto">
    <div style="min-width:980px">
      <div class="reg-cols" style="font-size:11px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;color:#6A6A65;border-bottom:1px solid #E6E6E0;padding:12px 0 10px"><span>Nº</span><span>Fecha y hora</span><span>Tipo</span><span>Factura</span><span>Tipo</span><span style="text-align:right">Importe</span><span>Huella</span><span>Anterior</span><span>Estado</span></div>
      @forelse($regs as $i => $r)
        <div class="reg-cols" style="padding:10px 0;border-bottom:1px solid #F1F1EC;font-size:13px">
          <span class="mono">{{ $total - $i }}</span><span class="mono" style="font-size:12px">{{ str_replace('T', ' ', substr($r->fecha_hora_huso, 0, 19)) }}</span><span>{{ $r->tipo }}</span><span class="mono" style="font-weight:600">{{ $r->num }}</span><span class="mono">{{ $r->tipo_factura }}</span><span class="mono" style="text-align:right">{{ $r->tipo === 'Alta' ? Formato::num($r->total) : '—' }}</span><span class="mono" style="font-size:11.5px" title="{{ $r->huella }}">{{ substr($r->huella, 0, 16) }}…</span><span class="mono" style="font-size:11.5px;color:#6A6A65" title="{{ $r->huella_anterior }}">{{ $r->huella_anterior ? substr($r->huella_anterior, 0, 10).'…' : 'Primer registro' }}</span><span><span style="font-size:11.5px;font-weight:700;padding:3px 9px;border-radius:10px;background:#ECECE7;color:#3C3C3C">{{ $r->estado }}</span></span>
        </div>
      @empty
        <div style="padding:28px 0;text-align:center;font-size:14px;color:#6A6A65">Todavía no hay registros: se crean al emitir o anular facturas.</div>
      @endforelse
    </div>
  </div>
</div>
@endsection
