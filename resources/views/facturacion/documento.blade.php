@php
    use App\Support\Formato;
    $st = $cfg->estiloCompleto();
    $R = ['recta' => '0', 'suave' => '6px', 'redondeada' => '14px'][$st['forma']] ?? '6px';
    $M = ['estrecho' => '10mm', 'normal' => '16mm', 'amplio' => '24mm'][$st['margen']] ?? '16mm';
    $FT = $st['fuente'] === 'serif' ? "Georgia,'Times New Roman',serif" : 'Helvetica,Arial,sans-serif';
    $col = $st['color'];
    $mod = in_array($st['modelo'], ['clasica', 'moderna', 'minimal', 'lateral'], true) ? $st['modelo'] : 'clasica';
    $logo = $st['logo'] && $c->logo_documento_id ? route('clientes.imagen.ver', [$c, 'logo']) : null;
    $firma = ! empty($st['firma']) && $c->firma_documento_id ? route('clientes.imagen.ver', [$c, 'firma']) : null;
    $r0 = $c->seccion('regs')[0] ?? [];
    $regDef = ($c->tipo === 'Sociedad' && ! empty($r0['rm'])) ? 'Inscrita en el Registro Mercantil de '.$r0['rm'].(! empty($r0['tomo']) ? ', tomo '.$r0['tomo'] : '').(! empty($r0['folio']) ? ', folio '.$r0['folio'] : '').(! empty($r0['hoja']) ? ', hoja '.$r0['hoja'] : '').(! empty($r0['insc']) ? ', inscripción '.$r0['insc'] : '').'.' : '';
    $reg = $st['regShow'] ? ($st['reg'] ?: $regDef) : '';
    $norm = collect($f->lineas)->reject(fn ($l) => ! empty($l['sup']));
    $sups = collect($f->lineas)->filter(fn ($l) => ! empty($l['sup']));
    $q = fn ($n) => rtrim(rtrim(number_format((float) $n, 2, ',', '.'), '0'), ',');
    $th = $st['cabecera'] === 'relleno' ? 'background:'.$col.';color:#fff' : 'border-bottom:2px solid '.$col.';color:'.$col;
    $box = $st['cuadros'] ? 'border:1px solid #CFCFCF;border-radius:'.$R.';padding:10px 12px' : 'padding:4px 0';
    $qrSvg = $qr ? preg_replace('/^<\?xml[^>]*>\s*/', '', $qr) : null;
    $venc = $f->pago_dias ? $f->fecha->addDays((int) $f->pago_dias) : null;
    // Bloques comunes
    $qrHtml = '<div class="qr"><div class="qt">QR tributario:</div>'.($qrSvg && ! $draft ? $qrSvg : '<div class="qph">'.($draft ? 'Se genera al emitir' : 'QR').'</div>').'</div>';
    $emisor = '<b class="enom">'.e($c->nombre).'</b><br>NIF '.e($c->nif).'<br>'.e($c->domicilio).'<br>'.e(trim($c->cp.' '.$c->municipio)).($c->email ? '<br>'.e($c->email) : '').($c->telefono ? ' · '.e($c->telefono) : '');
    $receptor = '<b>'.e($f->receptor_nombre ?: '—').'</b>'.($f->receptor_nif ? '<br>NIF '.e($f->receptor_nif) : '').($f->receptor_dir ? '<br>'.e($f->receptor_dir) : '');
    $verRec = $f->receptor_nombre || $f->tipo !== 'F2';
    $numero = $f->num ?: 'Se asigna al emitir';
    $rect = $f->rectificada ? 'Rectifica la factura '.e($f->rectificada->num).' de '.Formato::fecha($f->rectificada->fecha) : '';
    $pago = $f->forma_pago ? e($f->forma_pago).($venc ? ' · vencimiento a '.$f->pago_dias.' días ('.Formato::fecha($venc).')' : ' · al contado')
        .($f->forma_pago === 'Domiciliación SEPA' && $f->iban ? '<br>Cargo en la cuenta del cliente '.e(trim(chunk_split($f->iban, 4, ' '))) : '')
        .($f->forma_pago === 'Transferencia' && $f->iban_emisor ? '<br>Ingreso en la cuenta '.e(trim(chunk_split($f->iban_emisor, 4, ' '))) : '') : '';
@endphp
<!DOCTYPE html>
<html lang="es"><head><meta charset="utf-8"><title>{{ $f->num ?: 'Factura' }}</title>
<style>
@page{size:A4;margin:0}
html,body{margin:0;background:#fff}
body{font:11px/1.45 {!! $FT !!};color:#111}
.hoja{padding:{{ $M }};box-decoration-break:clone;-webkit-box-decoration-break:clone}
table{width:100%;border-collapse:collapse}td,th{padding:6px 8px;text-align:left;vertical-align:top}.r{text-align:right}
.lb{font-size:9px;letter-spacing:.08em;text-transform:uppercase;color:#777;margin-bottom:2px}
.lg{display:block;max-height:52px;max-width:170px;margin-bottom:8px}
.qr{width:33mm;text-align:center;flex:none}.qr svg{width:33mm;height:33mm;display:block;background:#fff}.qph{width:33mm;height:33mm;border:1px dashed #999;display:flex;align-items:center;justify-content:center;color:#999;font-size:9px;background:#fff}.qt{font-size:8.5px;margin-bottom:2px}
.lines th{font-size:9.5px;letter-spacing:.06em;text-transform:uppercase}
.tot td{padding:4px 8px}
.ft{margin-top:22px;font-size:9px;color:#666;padding-top:8px}
.borr{position:fixed;top:40%;left:0;right:0;text-align:center;font-size:60px;font-weight:800;color:rgba(0,0,0,.06);transform:rotate(-20deg);pointer-events:none}
.anul{text-align:center;font-weight:700;color:#8A1C1C;letter-spacing:.1em}
/* ---------- Clásica: cuadrícula formal ---------- */
.m-clasica .cab{display:grid;grid-template-columns:1fr auto 33mm;gap:14px;align-items:start;margin-bottom:14px}
.m-clasica .tit{border:1.5px solid {{ $col }};border-radius:{{ $R }};min-width:200px}
.m-clasica .tit .ti{background:{{ $col }};color:#fff;text-align:center;font-weight:700;letter-spacing:.14em;padding:6px 10px;text-transform:uppercase;font-size:14px}
.m-clasica .tit table td{border-top:1px solid #DDD;padding:5px 10px}
.m-clasica .cb{ {{ $box }};margin-bottom:14px}
.m-clasica .lines th{ {{ $th }} }
.m-clasica .lines td{border:1px solid #D6D6D6}
.m-clasica .lines th{border:1px solid {{ $col }}}
.m-clasica .tot{width:300px;margin:12px 0 0 auto;border:1px solid #D6D6D6}.m-clasica .tot td{border-bottom:1px solid #EEE}
.m-clasica .gt td{font-weight:700;font-size:13px;background:{{ $col }};color:#fff}
.m-clasica .pg{ {{ $box }};margin-top:16px}
.m-clasica .ft{border-top:1px solid #DDD}
/* ---------- Moderna: banda de color a sangre ---------- */
.m-moderna .banda{background:{{ $col }};color:#fff;padding:{{ $M }} {{ $M }} 18px;display:flex;gap:18px;align-items:flex-start}
.m-moderna .banda .em{flex:1}.m-moderna .banda .enom{font-size:15px}
.m-moderna .banda .ti{font-size:30px;font-weight:800;letter-spacing:-.02em;line-height:1;text-align:right}
.m-moderna .banda .nu{text-align:right;margin-top:6px;opacity:.9}
.m-moderna .banda .qt{color:#fff}
.m-moderna .tarjetas{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin:0 0 16px}
.m-moderna .tarj{background:#F4F4F2;border-radius:{{ $R === '0' ? '0' : '10px' }};padding:12px 14px;border-left:4px solid {{ $col }}}
.m-moderna .lines th{color:{{ $col }};border-bottom:2px solid {{ $col }}}
.m-moderna .lines td{border-bottom:1px solid #EEE}.m-moderna .lines tr:nth-child(even) td{background:#F8F8F6}
.m-moderna .tot{width:320px;margin:14px 0 0 auto}
.m-moderna .gt td{background:{{ $col }};color:#fff;font-size:16px;font-weight:800;padding:10px 12px}
.m-moderna .gt td:first-child{border-radius:{{ $R }} 0 0 {{ $R }}}.m-moderna .gt td:last-child{border-radius:0 {{ $R }} {{ $R }} 0}
.m-moderna .pg{margin-top:18px;background:#F4F4F2;border-radius:{{ $R }};padding:10px 14px}
.m-moderna .ft{border-top:3px solid {{ $col }}}
/* ---------- Minimal: aire y tipografía ---------- */
.m-minimal{color:#222}
.m-minimal .cab{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;margin-bottom:34px}
.m-minimal .ti{font-size:38px;font-weight:200;letter-spacing:-.02em;color:{{ $col }};line-height:1}
.m-minimal .nu{font-size:12px;color:#777;margin-top:6px;letter-spacing:.04em}
.m-minimal .tres{display:grid;grid-template-columns:1fr 1fr 1fr;gap:20px;margin-bottom:34px}
.m-minimal .tres .lb{color:{{ $col }}}
.m-minimal .lines th{font-weight:400;color:#888;border-bottom:1px solid #222}
.m-minimal .lines td{border-bottom:1px solid #EFEFEF;padding:9px 8px}
.m-minimal .tot{width:280px;margin:20px 0 0 auto}.m-minimal .tot td{color:#666}
.m-minimal .gt td{font-size:22px;font-weight:300;color:{{ $col }};border-top:1px solid #222;padding-top:10px}
.m-minimal .pg{margin-top:30px;color:#555}
.m-minimal .ft{color:#999}
/* ---------- Lateral: columna de color ---------- */
.m-lateral .doscol{display:grid;grid-template-columns:62mm 1fr;min-height:297mm}
.m-lateral .side{background:{{ $col }};color:#fff;padding:{{ $M }} 14px;display:flex;flex-direction:column;gap:16px}
.m-lateral .side .lb{color:rgba(255,255,255,.7)}.m-lateral .side .qt{color:#fff}
.m-lateral .side .enom{font-size:14px}
.m-lateral .cuerpo{padding:{{ $M }}}
.m-lateral .ti{font-size:26px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:{{ $col }}}
.m-lateral .cb{border-bottom:2px solid {{ $col }};padding:0 0 10px;margin:14px 0 16px}
.m-lateral .lines th{ {{ $th }} }
.m-lateral .lines td{border-bottom:1px solid #E6E6E6}
.m-lateral .tot{width:280px;margin:12px 0 0 auto}
.m-lateral .gt td{font-weight:800;font-size:15px;border-top:2px solid {{ $col }};color:{{ $col }}}
</style>
@if($imprimir)<script src="{{ asset('js/imprimir.js') }}"></script>@endif
</head><body class="m-{{ $mod }}">
@if($draft)<div class="borr">BORRADOR</div>@endif

{{-- Tabla de líneas, totales y pago: comunes a todos los modelos --}}
@php ob_start(); @endphp
@if($f->estado === 'Anulada')<p class="anul">FACTURA ANULADA</p>@endif
<table class="lines"><tr><th>Descripción</th><th class="r">Cant.</th><th class="r">Precio</th><th class="r">IVA</th><th class="r">Importe</th></tr>
@forelse($norm as $l)<tr><td>{{ $l['d'] }}</td><td class="r">{{ $q($l['q']) }}</td><td class="r">{{ Formato::num($l['p']) }}</td><td class="r">{{ 0 + $l['iva'] }} %</td><td class="r">{{ Formato::num(round($l['q'] * $l['p'], 2)) }}</td></tr>
@empty<tr><td colspan="5" style="color:#999">Sin líneas</td></tr>@endforelse
</table>
@if($sups->count())
<div class="lb" style="margin-top:14px">Suplidos · gastos pagados en nombre del cliente (art. 78.Tres.3º LIVA)</div>
<table class="lines"><tr><th>Concepto</th><th class="r">Cant.</th><th class="r">Importe</th><th class="r">IVA</th><th class="r">Total</th></tr>
@foreach($sups as $l)<tr><td>{{ $l['d'] }}</td><td class="r">{{ $q($l['q']) }}</td><td class="r">{{ Formato::num($l['p']) }}</td><td class="r">—</td><td class="r">{{ Formato::num(round($l['q'] * $l['p'], 2)) }}</td></tr>@endforeach
</table>
@endif
<table class="tot">
  <tr><td>Base imponible</td><td class="r">{{ Formato::num($f->base) }}</td></tr>
  @foreach($f->ivas ?? [] as $t => $x)@if($x['base'])<tr><td>IVA {{ 0 + $t }} % sobre {{ Formato::num($x['base']) }}</td><td class="r">{{ Formato::num($x['cuota']) }}</td></tr>@endif @endforeach
  @if((float) $f->retencion)<tr><td>Retención IRPF {{ 0 + $f->ret_pct }} %</td><td class="r">−{{ Formato::num($f->retencion) }}</td></tr>@endif
  @if((float) $f->suplidos)<tr><td>Suplidos</td><td class="r">{{ Formato::num($f->suplidos) }}</td></tr>@endif
  <tr class="gt"><td>Total factura</td><td class="r">{{ Formato::eur($f->total) }}</td></tr>
</table>
@if($f->observaciones)<p>{{ $f->observaciones }}</p>@endif
@php $lineas = ob_get_clean(); @endphp

@if($mod === 'moderna')
  <div class="banda">
    <div class="em">@if($logo)<img class="lg" src="{{ $logo }}" alt="" style="background:#fff;border-radius:6px;padding:3px">@endif{!! $emisor !!}</div>
    <div><div class="ti">{{ $f->tipoTexto() }}</div><div class="nu">Nº {{ $numero }}<br>{{ Formato::fecha($f->fecha) }}@if($rect)<br>{!! $rect !!}@endif</div></div>
    {!! $qrHtml !!}
  </div>
  <div class="hoja" style="padding-top:18px">
    <div class="tarjetas">
      <div class="tarj"><div class="lb">Facturar a</div>{!! $verRec ? $receptor : '—' !!}</div>
      <div class="tarj"><div class="lb">Datos de la factura</div>Número <b>{{ $numero }}</b><br>Fecha de expedición <b>{{ Formato::fecha($f->fecha) }}</b>@if($venc)<br>Vencimiento <b>{{ Formato::fecha($venc) }}</b>@endif</div>
    </div>
    {!! $lineas !!}
    @if($pago)<div class="pg"><div class="lb">Forma de pago</div>{!! $pago !!}</div>@endif
    @if($firma)<div style="margin-top:22px;text-align:right"><img src="{{ $firma }}" alt="Firma" style="max-height:70px;max-width:220px"></div>@endif
    <div class="ft">@if($cfg->pie){{ $cfg->pie }}<br>@endif{{ $reg }}</div>
  </div>

@elseif($mod === 'minimal')
  <div class="hoja">
    <div class="cab">
      <div>@if($logo)<img class="lg" src="{{ $logo }}" alt="">@endif<div class="ti">{{ $f->tipoTexto() }}</div><div class="nu">{{ $numero }} · {{ Formato::fecha($f->fecha) }}</div></div>
      {!! $qrHtml !!}
    </div>
    <div class="tres">
      <div><div class="lb">De</div>{!! $emisor !!}</div>
      <div><div class="lb">Para</div>{!! $verRec ? $receptor : '—' !!}</div>
      <div><div class="lb">Detalles</div>Nº {{ $numero }}<br>Fecha {{ Formato::fecha($f->fecha) }}@if($venc)<br>Vence {{ Formato::fecha($venc) }}@endif @if($rect)<br>{!! $rect !!}@endif</div>
    </div>
    {!! $lineas !!}
    @if($pago)<div class="pg"><div class="lb">Pago</div>{!! $pago !!}</div>@endif
    @if($firma)<div style="margin-top:22px"><img src="{{ $firma }}" alt="Firma" style="max-height:60px;max-width:200px"></div>@endif
    <div class="ft">@if($cfg->pie){{ $cfg->pie }}<br>@endif{{ $reg }}</div>
  </div>

@elseif($mod === 'lateral')
  <div class="doscol">
    <div class="side">
      {!! $qrHtml !!}
      @if($logo)<img class="lg" src="{{ $logo }}" alt="" style="background:#fff;border-radius:6px;padding:4px">@endif
      <div>{!! $emisor !!}</div>
      <div><div class="lb">Nº de factura</div><b style="font-size:13px">{{ $numero }}</b></div>
      <div><div class="lb">Fecha de expedición</div>{{ Formato::fecha($f->fecha) }}@if($venc)<div class="lb" style="margin-top:8px">Vencimiento</div>{{ Formato::fecha($venc) }}@endif</div>
      @if($rect)<div><div class="lb">Rectificativa</div>{!! $rect !!}</div>@endif
      @if($pago)<div><div class="lb">Forma de pago</div>{!! $pago !!}</div>@endif
    </div>
    <div class="cuerpo">
      <div class="ti">{{ $f->tipoTexto() }}</div>
      @if($verRec)<div class="cb"><div class="lb">Cliente</div>{!! $receptor !!}</div>@endif
      {!! $lineas !!}
      @if($firma)<div style="margin-top:22px;text-align:right"><img src="{{ $firma }}" alt="Firma" style="max-height:70px;max-width:220px"></div>@endif
      <div class="ft">@if($cfg->pie){{ $cfg->pie }}<br>@endif{{ $reg }}</div>
    </div>
  </div>

@else
  <div class="hoja">
    <div class="cab">
      <div>@if($logo)<img class="lg" src="{{ $logo }}" alt="">@endif{!! $emisor !!}</div>
      <div class="tit"><div class="ti">{{ $f->tipoTexto() }}</div>
        <table><tr><td>Número</td><td class="r"><b>{{ $numero }}</b></td></tr><tr><td>Fecha</td><td class="r">{{ Formato::fecha($f->fecha) }}</td></tr>@if($venc)<tr><td>Vencimiento</td><td class="r">{{ Formato::fecha($venc) }}</td></tr>@endif</table>
        @if($rect)<div style="padding:5px 10px;border-top:1px solid #DDD;font-size:10px">{!! $rect !!}</div>@endif</div>
      {!! $qrHtml !!}
    </div>
    @if($verRec)<div class="cb"><div class="lb">Cliente</div>{!! $receptor !!}</div>@endif
    {!! $lineas !!}
    @if($pago)<div class="pg"><div class="lb">Forma de pago</div>{!! $pago !!}</div>@endif
    @if($firma)<div style="margin-top:22px;text-align:right"><img src="{{ $firma }}" alt="Firma" style="max-height:70px;max-width:220px"><div style="font-size:9px;color:#777;margin-top:2px">{{ $c->nombre }}</div></div>@endif
    <div class="ft">@if($cfg->pie){{ $cfg->pie }}<br>@endif{{ $reg }}</div>
  </div>
@endif
</body></html>
