@php
    use App\Support\Formato;
    $st = $cfg->estiloCompleto();
    $R = ['recta' => '0', 'suave' => '6px', 'redondeada' => '14px'][$st['forma']] ?? '6px';
    $M = ['estrecho' => '10mm', 'normal' => '16mm', 'amplio' => '24mm'][$st['margen']] ?? '16mm';
    $FT = $st['fuente'] === 'serif' ? "Georgia,'Times New Roman',serif" : 'Helvetica,Arial,sans-serif';
    $col = $st['color'];
    $mod = $st['modelo'];
    $logo = $st['logo'] && $c->logo_documento_id ? route('clientes.imagen.ver', [$c, 'logo']) : null;
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
@endphp
<!DOCTYPE html>
<html lang="es"><head><meta charset="utf-8"><title>{{ $f->num ?: 'Factura' }}</title>
<style>
@page{size:A4;margin:{{ $M }}}
body{font:11px/1.45 {!! $FT !!};color:#111;margin:0;padding:{{ $M }};background:#fff}
@media print{body{padding:0}}
table{width:100%;border-collapse:collapse}td,th{padding:6px 8px;text-align:left;vertical-align:top}.r{text-align:right}
.lb{font-size:9px;letter-spacing:.08em;text-transform:uppercase;color:#777;margin-bottom:2px}
.hd{display:flex;gap:16px;align-items:flex-start;justify-content:space-between;margin-bottom:18px}.em{flex:1}.lg{display:block;max-height:52px;max-width:170px;margin-bottom:8px}.tt{text-align:right}
.ti{font-size:{{ $mod === 'minimal' ? '24px;font-weight:300' : '18px;font-weight:700' }};color:{{ $col }};text-transform:{{ $mod === 'clasica' ? 'uppercase' : 'none' }}}.nu{font-weight:700}
.qr{width:35mm;text-align:center;flex:none}.qr svg{width:35mm;height:35mm;display:block}.qph{width:35mm;height:35mm;border:1px dashed #999;display:flex;align-items:center;justify-content:center;color:#999;font-size:9px}.qt{font-size:8.5px;margin-bottom:2px}
.cb{ {{ $box }};margin-bottom:16px}.lines th{ {{ $th }};font-size:9.5px;letter-spacing:.06em;text-transform:uppercase}
@if($R !== '0' && $st['cabecera'] === 'relleno').lines th:first-child{border-top-left-radius:{{ $R }}}.lines th:last-child{border-top-right-radius:{{ $R }}}@endif
.lines td{border-bottom:1px solid #E3E3E3}@if($mod === 'moderna').lines tr:nth-child(even) td{background:#F6F6F6}@endif
.tot{width:290px;margin:12px 0 0 auto}.tot td{padding:4px 8px}.gt td{font-weight:700;font-size:13px;{{ $mod === 'moderna' ? 'background:'.$col.';color:#fff' : 'border-top:2px solid '.$col }}}
.pg{ {{ $box }};margin-top:18px}.ft{margin-top:22px;font-size:9px;color:#666;border-top:1px solid #E3E3E3;padding-top:8px}
@if($mod === 'moderna').band{height:8px;background:{{ $col }};margin:-{{ $M }} -{{ $M }} {{ $M }}}@endif
.borr{position:fixed;top:40%;left:0;right:0;text-align:center;font-size:60px;font-weight:800;color:rgba(0,0,0,.06);transform:rotate(-20deg);pointer-events:none}
</style>
@if($imprimir)<script src="{{ asset('js/imprimir.js') }}"></script>@endif
</head><body>
@if($draft)<div class="borr">BORRADOR</div>@endif
@if($mod === 'moderna')<div class="band"></div>@endif
<div class="hd">
  <div class="em">
    @if($logo)<img class="lg" src="{{ $logo }}" alt="">@endif
    <b style="font-size:13px">{{ $c->nombre }}</b><br>NIF {{ $c->nif }}<br>{{ $c->domicilio }}<br>{{ trim($c->cp.' '.$c->municipio) }}
    @if($c->email)<br>{{ $c->email }}@endif @if($c->telefono) · {{ $c->telefono }}@endif
  </div>
  <div class="tt">
    <div class="ti">{{ $f->tipoTexto() }}</div>
    <div class="nu">Nº {{ $f->num ?: 'Se asigna al emitir' }}</div>
    <div>Fecha {{ Formato::fecha($f->fecha) }}</div>
    @if($f->rectificada)<div>Rectifica la factura {{ $f->rectificada->num }} de {{ Formato::fecha($f->rectificada->fecha) }}</div>@endif
  </div>
  <div class="qr"><div class="qt">QR tributario:</div>@if($qrSvg && ! $draft){!! $qrSvg !!}@else<div class="qph">{{ $draft ? 'Se genera al emitir' : 'QR' }}</div>@endif</div>
</div>
@if($f->receptor_nombre || $f->tipo !== 'F2')
<div class="cb"><div class="lb">Cliente</div><b>{{ $f->receptor_nombre ?: '—' }}</b>@if($f->receptor_nif)<br>NIF {{ $f->receptor_nif }}@endif @if($f->receptor_dir)<br>{{ $f->receptor_dir }}@endif</div>
@endif
@if($f->estado === 'Anulada')<p style="text-align:center;font-weight:700;color:#8A1C1C;letter-spacing:.1em">FACTURA ANULADA</p>@endif
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
@if($f->forma_pago)
<div class="pg"><div class="lb">Forma de pago</div>{{ $f->forma_pago }}@if($venc) · vencimiento a {{ $f->pago_dias }} días ({{ Formato::fecha($venc) }})@else · al contado @endif
  @if($f->forma_pago === 'Domiciliación SEPA' && $f->iban)<br>Cargo en la cuenta del cliente {{ trim(chunk_split($f->iban, 4, ' ')) }}@endif
  @if($f->forma_pago === 'Transferencia' && $f->iban_emisor)<br>Ingreso en la cuenta {{ trim(chunk_split($f->iban_emisor, 4, ' ')) }}@endif
</div>
@endif
@if($f->observaciones)<p>{{ $f->observaciones }}</p>@endif
<div class="ft">@if($cfg->pie){{ $cfg->pie }}<br>@endif{{ $reg }}</div>
</body></html>
