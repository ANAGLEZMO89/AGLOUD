@php
    use App\Support\Formato;
    $st = $cfg->estiloCompleto();
    $col = $st['color'];
    $q = fn ($n) => rtrim(rtrim(number_format((float) $n, 2, ',', '.'), '0'), ',');
@endphp
<!DOCTYPE html>
<html lang="es"><head><meta charset="utf-8"><title>{{ $p->num }}</title>
<style>
@page{size:A4;margin:16mm}body{font:12px/1.5 Helvetica,Arial,sans-serif;padding:16mm;color:#111;margin:0;background:#fff}@media print{body{padding:0}}
table{width:100%;border-collapse:collapse;margin-top:16px}th,td{padding:7px 8px;text-align:left;border-bottom:1px solid #ddd}
th{background:{{ $col }};color:#fff;font-size:10px;text-transform:uppercase;letter-spacing:.06em}.r{text-align:right}
</style>
@if($imprimir)<script src="{{ asset('js/imprimir.js') }}"></script>@endif
</head><body>
<div style="display:flex;justify-content:space-between;gap:16px">
  <div><b style="font-size:14px">{{ $c->nombre }}</b><br>NIF {{ $c->nif }}<br>{{ $c->domicilio }}<br>{{ trim($c->cp.' '.$c->municipio) }}</div>
  <div style="text-align:right"><div style="font-size:20px;font-weight:700;color:{{ $col }}">PRESUPUESTO</div>Nº {{ $p->num }}<br>Fecha {{ Formato::fecha($p->fecha) }}<br>Válido hasta {{ Formato::fecha($p->vence()) }}</div>
</div>
<div style="margin-top:18px;padding:10px 12px;border:1px solid #ccc;border-radius:6px"><div style="font-size:9px;color:#777;text-transform:uppercase;letter-spacing:.08em">Cliente</div><b>{{ $p->receptor_nombre }}</b>@if($p->receptor_nif)<br>NIF {{ $p->receptor_nif }}@endif</div>
<table><tr><th>Descripción</th><th class="r">Cant.</th><th class="r">Precio</th><th class="r">IVA</th><th class="r">Importe</th></tr>
@foreach($p->lineas as $l)<tr><td>{{ $l['d'] }}</td><td class="r">{{ $q($l['q']) }}</td><td class="r">{{ Formato::num($l['p']) }}</td><td class="r">{{ 0 + $l['iva'] }} %</td><td class="r">{{ Formato::num(round($l['q'] * $l['p'], 2)) }}</td></tr>@endforeach
</table>
<table style="width:280px;margin-left:auto"><tr><td>Base imponible</td><td class="r">{{ Formato::num($p->base) }}</td></tr><tr><td>IVA</td><td class="r">{{ Formato::num($p->cuota) }}</td></tr><tr><td><b>Total</b></td><td class="r"><b>{{ Formato::eur($p->total) }}</b></td></tr></table>
<p style="margin-top:30px;color:#666;font-size:10.5px">Para aceptar el presupuesto, devuélvalo firmado o confírmelo por escrito antes de la fecha de validez.</p>
</body></html>
