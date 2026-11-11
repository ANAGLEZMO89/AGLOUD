@extends('layouts.app')
@php use App\Support\Formato; [$st, $bg, $fg] = $f->estadoChip(); @endphp
@section('content')
<div class="page" style="gap:16px">
  @include('facturacion._cabecera')
  <div style="display:flex;flex-wrap:wrap;gap:16px;align-items:flex-start">
    <div style="flex:999 1 520px;min-width:0;display:flex;flex-direction:column;gap:12px">
      <a href="{{ route('facturacion.index') }}" class="pillbtn wh" style="align-self:flex-start;height:36px">← Facturas</a>
      <iframe src="{{ route('facturacion.documento', $f) }}" title="Factura {{ $f->num }}" style="width:100%;height:1000px;border:0;background:#fff;border-radius:6px;box-shadow:0 1px 0 #E2E2DC,0 10px 30px rgba(11,11,11,.08)"></iframe>
    </div>
    <div style="flex:1 1 260px;min-width:0;display:flex;flex-direction:column;gap:12px;padding-top:48px">
      <div class="card" style="padding:16px 18px;gap:10px">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:8px"><span style="font:600 14px var(--mono)">{{ $f->num }}</span><span style="font-size:11.5px;font-weight:700;padding:3px 9px;border-radius:10px;background:{{ $bg }};color:{{ $fg }}">{{ $st }}</span></div>
        <a class="pillbtn ink" style="height:40px" href="{{ route('facturacion.documento', [$f, 'imprimir' => 1]) }}" target="_blank" rel="noopener">Descargar PDF</a>
        @if($f->estado === 'Emitida')
          <form method="POST" action="{{ route('facturacion.cobro', $f) }}">@csrf<button class="pillbtn lime" style="width:100%;font-weight:700" type="submit">{{ $f->cobrada ? 'Anular cobro' : 'Marcar como cobrada' }}</button></form>
          @if($f->tipo !== 'R1')<a class="pillbtn" href="{{ route('facturacion.create', ['rectifica' => $f->id]) }}">Rectificar</a>@endif
          <form method="POST" action="{{ route('facturacion.anular', $f) }}" data-confirm="Se generará un registro de anulación encadenado. La factura se conservará marcada como ANULADA. ¿Continuar?">@csrf<button type="submit" class="pillbtn" style="width:100%;background:#fff;border:1px solid #E5B4B4;color:#8A1C1C">Anular registro</button></form>
        @endif
        @if($f->cobrada && $f->cobrada_at)<span style="font-size:12.5px;color:#3C3C3C">Cobrada el {{ Formato::fecha($f->cobrada_at) }}</span>@endif
        @if($f->rectificada)<span style="font-size:12.5px;color:#3C3C3C">Rectifica la factura <a href="{{ route('facturacion.show', $f->rectificada) }}">{{ $f->rectificada->num }}</a></span>@endif
        <div style="display:flex;flex-direction:column;gap:2px;padding:10px 12px;border-radius:10px;background:#F4F4F0"><span style="font-size:11px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;color:#6A6A65">Contabilidad</span><span style="font-size:13px;font-weight:600">Se enlazará en la fase de Contabilidad</span></div>
        <span style="font-size:12px;color:#6A6A65;line-height:1.45">Una factura emitida no se modifica: se corrige con una rectificativa o se anula su registro. No se borra: hay que conservarla (art. 19 RD 1619/2012).</span>
      </div>
      <div class="card" style="padding:16px 18px;gap:10px">
        <span style="font-size:14px;font-weight:700">Registros de facturación</span>
        @foreach($regs as $rg)
          <div style="display:flex;flex-direction:column;gap:3px;padding:10px 0;border-top:1px solid #F1F1EC"><div style="display:flex;justify-content:space-between;gap:8px;font-size:12.5px;font-weight:600"><span>{{ $rg->tipo }} · {{ str_replace('T', ' ', substr($rg->fecha_hora_huso, 0, 19)) }}</span><span>{{ $rg->estado }}</span></div><span style="font:500 10.5px var(--mono);color:#6A6A65;overflow-wrap:anywhere">{{ $rg->huella }}</span></div>
        @endforeach
        <span style="font-size:11.5px;color:#6A6A65;overflow-wrap:anywhere">QR: {{ $qrUrl }}</span>
      </div>
    </div>
  </div>
</div>
@endsection
