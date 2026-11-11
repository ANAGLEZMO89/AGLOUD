<div class="page-h">
  <div class="t"><span class="crumb">Áreas · Facturación</span><h1 class="ttl">Facturación</h1></div>
  @include('partials.empresa', ['volver' => '/facturacion'])
</div>
<div style="display:flex;flex-wrap:wrap;align-items:center;gap:10px 16px;padding:12px 16px;border-radius:14px;background:#0B0B0B;color:#F4F4F0">
  <span style="font:700 12px var(--mono);padding:4px 9px;border-radius:6px;background:#C8F031;color:#0B0B0B">{{ $modoCfg === 'verifactu' ? 'VERI*FACTU' : 'NO VERI*FACTU' }}</span>
  <span style="font-size:13.5px;font-weight:600">{{ ['normal' => 'Facturación normal', 'electronica' => 'Factura electrónica (Facturae 3.2.2)', 'verifactu' => 'VERI*FACTU elegido · envío a la AEAT pendiente de activar'][$modoCfg] }} · registros encadenados y conservados{{ $modoCfg === 'verifactu' ? ': mientras no se active el envío, se emiten como No VERI*FACTU' : ', sin envío a la AEAT' }}</span>
  <span style="font-size:13px;color:#BDBDB7">{{ $obligTxt }}</span>
</div>
<div class="chipbar" style="border-radius:11px">
  @foreach([['lista', 'Facturas', 'facturacion.index'], ['nueva', 'Nueva factura', 'facturacion.create'], ['reg', 'Registro de facturación', 'facturacion.registro'], ['cfg', 'Parámetros', 'facturacion.config']] as [$id, $l, $r])
    <a class="{{ $tab === $id ? 'on' : '' }}" style="padding:7px 14px;border-radius:8px" href="{{ route($r) }}">{{ $l }}</a>
  @endforeach
</div>
@if(! $c->nif)<div class="ferr">Esta empresa no tiene NIF en su ficha: no se pueden emitir facturas hasta completarlo.</div>@endif
@if($errors->any())<div class="ferr">{{ $errors->first() }}</div>@endif
