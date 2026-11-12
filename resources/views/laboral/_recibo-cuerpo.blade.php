{{-- Recibo de salarios: 3 modelos (1 clásico, 2 moderno, 3 oficial de la Orden ESS/2098/2014). Variables: c, w, n, P, modelo, mesL, per, at, ccc [, recibo, fechaPago] --}}
@php
  use App\Services\Nomina;
  use App\Support\Formato;
  $E = fn ($v) => Formato::num($v ?: 0);
  $Ez = fn ($v) => (float) $v != 0 ? Formato::num($v) : '';
  $recibo = $recibo ?? ($n['recibo'] ?? 'Nómina');
  $titRec = $recibo === 'Nómina' ? 'Nómina' : Nomina::RECIBOS[$recibo] ?? $recibo;
  $fechaPago = $fechaPago ?? null;
  $useImg = ! empty($P['useImg']);
  $logo = $useImg && $c->logo_documento_id ? route('clientes.imagen.ver', [$c, 'logo']) : null;
  $firma = $useImg && $c->firma_documento_id ? route('clientes.imagen.ver', [$c, 'firma']) : null;
  $aut = Nomina::esAutonomo($w);
  $domEmp = trim(implode(' · ', array_filter([$c->domicilio, trim(($c->cp ?? '').' '.($c->municipio ?? '')), $c->provincia])));
  $iban = strtoupper(preg_replace('/\s/', '', (string) ($w['iban'] ?? '')));
  $ibanM = $iban ? substr($iban, 0, 4).' **** **** **** '.substr($iban, -4) : '';
  $cat = ($w['convCat'] ?? '') ?: ($w['cat'] ?? '');
  $contrato = $aut ? 'RETA' : Nomina::codigo((string) $w['contrato']);
  // Devengos de la nómina (las líneas, calculadas o editadas en el Integral)
  $lin = $n['lineas'] ?? [];
  $tag = fn ($l) => $l['concepto'].(($l['clase'] ?? '') === 'extra' && ($l['cobro'] ?? '') === 'prorrateada' ? ' (prorrata)' : '');
  $desP = Formato::num(($n['desT'] ?? ($n['tmp'] ? 0.016 : 0.0155)) * 100);
  // Deducciones del trabajador
  $ss = array_values(array_filter([['Contingencias comunes', '4,70', $n['T']['cc']], ['Desempleo', $desP, $n['T']['des']], ['Formación profesional', '0,10', $n['T']['fp']], ['Horas extraordinarias', '4,70', $n['T']['hx'] ?? 0], ['MEI', '0,15', $n['T']['mei']]], fn ($r) => (float) $r[2] != 0));
  $otras = array_map(fn ($d) => [$d['concepto'], $d['importe']], $n['deducciones'] ?? []);
  if (($n['esp'] ?? 0) > 0) { $otras[] = ['Valor de la retribución en especie', $n['esp']]; }
  $totDed = $n['ssT'] + $n['irpf'] + ($n['ded'] ?? 0) + ($n['esp'] ?? 0);
  // Bases
  $remTotal = $n['dev'] - $n['die'] - $n['km'] - ($n['exe'] ?? 0) - $n['pror'];
  $emprRows = array_values(array_filter([['Contingencias comunes', '23,60', $n['base'], $n['E']['cc']], ['AT y EP', Formato::num($at), $n['baseAT'], $n['E']['at']], ['Desempleo', $n['tmp'] ? '6,70' : '5,50', $n['baseAT'], $n['E']['des']], ['Formación profesional', '0,60', $n['baseAT'], $n['E']['fp']], ['FOGASA', '0,20', $n['baseAT'], $n['E']['fog']], ['Horas extraordinarias', '23,60', $n['hx'], $n['E']['hx'] ?? 0], ['MEI', '0,75', $n['base'], $n['E']['mei']]], fn ($r) => (float) $r[3] != 0));
  $fecha = $fechaPago ? Formato::fecha($fechaPago) : '';
  $firmaHtml = $firma || $logo ? '<img src="'.e($firma ?: $logo).'" style="max-height:58px;max-width:180px">' : '';
  // Agrupación oficial (Orden ESS/2098/2014)
  $g1 = ['Salario base' => 0, 'Complementos salariales' => 0, 'Horas extraordinarias' => 0, 'Gratificaciones extraordinarias' => 0, 'Salario en especie' => 0];
  $g2 = ['Indemnizaciones o suplidos' => 0, 'Prestaciones e indemnizaciones de la Seguridad Social' => 0, 'Indemnizaciones por traslados, suspensiones o despidos' => 0];
  foreach ($lin as $l) {
      $k = match ($l['clase'] ?? 'salarial') { 'hx' => 'Horas extraordinarias', 'extra' => 'Gratificaciones extraordinarias', 'especie' => 'Salario en especie', 'salarial' => ($l['id'] ?? '') === 'sb' ? 'Salario base' : 'Complementos salariales', default => null };
      if ($k) { $g1[$k] += $l['importe']; continue; }
      $k2 = match ($l['clase']) { 'no_salarial' => 'Indemnizaciones o suplidos', 'tributa' => 'Prestaciones e indemnizaciones de la Seguridad Social', default => 'Indemnizaciones por traslados, suspensiones o despidos' };
      $g2[$k2] += $l['importe'];
  }
  $salarialT = array_sum($g1);
@endphp
<style>
.rc{font:10.5px/1.35 Helvetica,Arial,sans-serif;color:#000}
.rc table{width:100%;border-collapse:collapse}
.rc td,.rc th{padding:3px 6px;vertical-align:top}
.rc .r{text-align:right}.rc .c{text-align:center}.rc .b{font-weight:700}.rc .m{font-family:'Courier New',Courier,monospace;white-space:nowrap}
.rc .lbl{font-size:8px;text-transform:uppercase;letter-spacing:.04em;color:#444;display:block}
.rc .bx{border:1px solid #000}
.rc .cl th{font-size:8.5px;text-transform:uppercase;border-bottom:1px solid #000;background:#eee}
.rc .cl .col td{border-left:1px solid #000}.rc .cl .col td:first-child{border-left:0}
.rc .grid td{border:1px solid #000}
.rc .liq{font-size:14px;font-weight:700}
.rc .md{border-radius:8px;border:1px solid #c9c9c4}
.rc .md-h{background:#0B0B0B;color:#fff;border-radius:8px;padding:10px 14px}
.rc .md-k{display:flex;gap:8px}.rc .md-k>div{flex:1;border:1px solid #c9c9c4;border-radius:8px;padding:8px 10px}
.rc .md-k .v{font:700 15px 'Courier New',monospace}
.rc .md th{font-size:8.5px;text-transform:uppercase;color:#555;border-bottom:1px solid #c9c9c4;text-align:left}
.rc .md td{border-bottom:1px solid #eee}
.rc .of td{border:1px solid #000}
.rc .of .sec{background:#eee;font-weight:700}
.rc .of .ind{padding-left:18px}
.rc .pie{margin-top:14px;font-size:9px;color:#333}
</style>
<div class="rc">
@if($modelo === 2)
  {{-- ============================ MODELO 2 · MODERNO ============================ --}}
  <div class="md-h"><table><tr><td style="padding:0">@if($logo)<img src="{{ $logo }}" style="max-height:34px;max-width:140px;background:#fff;border-radius:4px;padding:2px;margin-bottom:4px"><br>@endif<b style="font-size:14px">{{ $c->nombre }}</b><br><span style="color:#bbb">CIF {{ $c->nif }} · CCC {{ $ccc ?: '—' }}</span></td>
    <td class="r" style="padding:0"><b style="font-size:15px">{{ $titRec }}</b><br><span style="color:#bbb">{{ $mesL }} · {{ $per }}</span>@if($fecha)<br><span style="color:#bbb">Pago: {{ $fecha }}</span>@endif</td></tr></table></div>
  <table style="margin:10px 0" class="md"><tr>
    @foreach([['Trabajador', $w['nombre']], ['NIF', $w['nif']], ['Nº afiliación', $w['naf']], ['Categoría', $cat ?: '—'], ['Grupo', $w['grupo']], ['Contrato', $contrato], ['Antigüedad', Formato::fecha($w['alta'])]] as [$l, $v])
      <td style="border:0"><span class="lbl">{{ $l }}</span><b>{{ $v }}</b></td>
    @endforeach
  </tr></table>
  <table><tr>
    <td style="width:56%;padding:0 8px 0 0"><table class="md"><tr><th>Devengos</th><th class="r">Cuantía</th><th class="r">Precio</th><th class="r">Importe</th></tr>
      @foreach($lin as $l)<tr><td>{{ $l['cod'] ? '['.$l['cod'].'] ' : '' }}{{ $tag($l) }}</td><td class="r m">{{ isset($l['cant']) && $l['cant'] ? Formato::num($l['cant']) : '' }}</td><td class="r m">{{ isset($l['precio']) && $l['precio'] ? Formato::num($l['precio']) : '' }}</td><td class="r m">{{ $E($l['importe']) }}</td></tr>@endforeach
      <tr><td class="b" colspan="3">Total devengado</td><td class="r m b">{{ $E($n['dev']) }}</td></tr></table></td>
    <td style="width:44%;padding:0 0 0 8px"><table class="md"><tr><th>Deducciones</th><th class="r">%</th><th class="r">Importe</th></tr>
      @foreach($ss as [$l, $p, $v])<tr><td>{{ $l }}</td><td class="r m">{{ $p }}</td><td class="r m">{{ $E($v) }}</td></tr>@endforeach
      @if($n['irpf'] || $n['ip'])<tr><td>IRPF</td><td class="r m">{{ Formato::num($n['ip']) }}</td><td class="r m">{{ $E($n['irpf']) }}</td></tr>@endif
      @foreach($otras as [$l, $v])<tr><td>{{ $l }}</td><td></td><td class="r m">{{ $E($v) }}</td></tr>@endforeach
      <tr><td class="b" colspan="2">Total deducciones</td><td class="r m b">{{ $E($totDed) }}</td></tr></table></td>
  </tr></table>
  <div class="md-k" style="margin:12px 0">
    <div><span class="lbl">Total devengado</span><span class="v">{{ $E($n['dev']) }} €</span></div>
    <div><span class="lbl">Total deducciones</span><span class="v">{{ $E($totDed) }} €</span></div>
    <div style="background:#E6F6A8;border-color:#C8F031"><span class="lbl">Líquido a percibir</span><span class="v" style="font-size:18px">{{ $E($n['liq']) }} €</span></div>
  </div>
  <table><tr>
    <td style="width:50%;padding:0 8px 0 0"><table class="md"><tr><th>Bases</th><th class="r">Importe</th></tr>
      @foreach([['Remuneración mensual', $remTotal], ['Prorrata pagas extra', $n['prorB']], ['Base contingencias comunes', $n['base']], ['Base AT y EP y desempleo', $n['baseAT']], ['Base horas extra', $n['hx']], ['Base sujeta a IRPF', $n['baseIrpf']]] as [$l, $v])<tr><td>{{ $l }}</td><td class="r m">{{ $E($v) }}</td></tr>@endforeach</table></td>
    <td style="width:50%;padding:0 0 0 8px"><table class="md"><tr><th>Aportación de la empresa</th><th class="r">%</th><th class="r">Importe</th></tr>
      @foreach($emprRows as [$l, $p, $b, $v])<tr><td>{{ $l }}</td><td class="r m">{{ $p }}</td><td class="r m">{{ $E($v) }}</td></tr>@endforeach
      <tr><td class="b" colspan="2">Coste total empresa</td><td class="r m b">{{ $E($n['dev'] + $n['ssE']) }}</td></tr></table></td>
  </tr></table>
  <table style="margin-top:16px"><tr><td style="width:50%"><span class="lbl">Firma y sello de la empresa</span>{!! $firmaHtml ?: '<div style="height:46px"></div>' !!}</td><td><span class="lbl">Recibí</span><div style="height:46px"></div>{{ $w['nombre'] }}</td></tr></table>
  <div class="pie">@if($ibanM)Transferencia a la cuenta {{ $ibanM }}. @endif Recibo de salarios según la Orden ESS/2098/2014.</div>

@elseif($modelo === 3)
  {{-- ============================ MODELO 3 · OFICIAL (Orden ESS/2098/2014) ============================ --}}
  @if($logo)<img src="{{ $logo }}" style="max-height:40px;max-width:160px;margin-bottom:6px">@endif
  <table class="of">
    <tr><td style="width:50%"><span class="lbl">Empresa</span><b>{{ $c->nombre }}</b><br>{{ $domEmp }}<br>CIF {{ $c->nif }} · CCC {{ $ccc ?: '—' }}</td>
      <td><span class="lbl">Trabajador</span><b>{{ $w['nombre'] }}</b><br>NIF {{ $w['nif'] }} · Nº afiliación {{ $w['naf'] }}<br>Categoría {{ $cat ?: '—' }} · Grupo de cotización {{ $w['grupo'] }}<br>Antigüedad {{ Formato::fecha($w['alta']) }} · Contrato {{ $contrato }}</td></tr>
    <tr><td colspan="2">Periodo de liquidación: <b>{{ $per }}</b> &nbsp; · &nbsp; Total días: <b>30</b> &nbsp; · &nbsp; Recibo: <b>{{ $titRec }}</b>@if($fecha) &nbsp; · &nbsp; Fecha de pago: <b>{{ $fecha }}</b>@endif</td></tr>
  </table>
  <table class="of" style="margin-top:6px">
    <tr class="sec"><td>I. DEVENGOS</td><td class="r" style="width:110px">TOTALES</td></tr>
    <tr><td class="b">1. Percepciones salariales</td><td></td></tr>
    @foreach($g1 as $k => $v)
      <tr><td class="ind">{{ $k }}</td><td class="r m">{{ $Ez($v) }}</td></tr>
      @php $det = array_filter($lin, fn ($l) => match ($k) { 'Complementos salariales' => ($l['clase'] ?? 'salarial') === 'salarial' && ($l['id'] ?? '') !== 'sb', 'Gratificaciones extraordinarias' => ($l['clase'] ?? '') === 'extra', 'Salario en especie' => ($l['clase'] ?? '') === 'especie', default => false }); @endphp
      @if(count($det) > 1 || ($det && $k !== 'Complementos salariales'))@foreach($det as $l)<tr><td class="ind" style="padding-left:34px;color:#333">· {{ $tag($l) }}</td><td class="r m" style="color:#333">{{ $E($l['importe']) }}</td></tr>@endforeach @elseif($det)@foreach($det as $l)<tr><td class="ind" style="padding-left:34px;color:#333">· {{ $tag($l) }}</td><td class="r m" style="color:#333">{{ $E($l['importe']) }}</td></tr>@endforeach @endif
    @endforeach
    <tr><td class="b">2. Percepciones no salariales</td><td></td></tr>
    @foreach($g2 as $k => $v)<tr><td class="ind">{{ $k }}</td><td class="r m">{{ $Ez($v) }}</td></tr>@endforeach
    <tr class="sec"><td>A. TOTAL DEVENGADO</td><td class="r m">{{ $E($n['dev']) }}</td></tr>
    <tr class="sec"><td>II. DEDUCCIONES</td><td></td></tr>
    <tr><td class="b">1. Aportación del trabajador a las cotizaciones a la Seguridad Social y conceptos de recaudación conjunta</td><td></td></tr>
    @foreach($ss as [$l, $p, $v])<tr><td class="ind">{{ $l }} ({{ $p }} %)</td><td class="r m">{{ $E($v) }}</td></tr>@endforeach
    <tr><td class="ind b">Total aportaciones</td><td class="r m b">{{ $E($n['ssT']) }}</td></tr>
    <tr><td class="b">2. Impuesto sobre la renta de las personas físicas ({{ Formato::num($n['ip']) }} %)</td><td class="r m">{{ $E($n['irpf']) }}</td></tr>
    <tr><td class="b">3. Anticipos</td><td class="r m">{{ $Ez($n['ant']) }}</td></tr>
    <tr><td class="b">4. Valor de los productos recibidos en especie</td><td class="r m">{{ $Ez($n['esp'] ?? 0) }}</td></tr>
    <tr><td class="b">5. Otras deducciones</td><td class="r m">{{ $Ez($n['emb']) }}</td></tr>
    @foreach(array_filter($n['deducciones'] ?? [], fn ($d) => ($d['id'] ?? '') !== 'ant') as $d)<tr><td class="ind" style="color:#333">· {{ $d['concepto'] }}</td><td class="r m" style="color:#333">{{ $E($d['importe']) }}</td></tr>@endforeach
    <tr class="sec"><td>B. TOTAL A DEDUCIR</td><td class="r m">{{ $E($totDed) }}</td></tr>
    <tr><td class="liq">LÍQUIDO TOTAL A PERCIBIR (A − B)</td><td class="r m liq">{{ $E($n['liq']) }}</td></tr>
  </table>
  <table style="margin-top:8px"><tr><td style="width:50%">{{ $c->municipio ?: $c->provincia }}, {{ $fecha ?: '____ de ____________ de ______' }}<br><span class="lbl" style="margin-top:4px">Firma y sello de la empresa</span>{!! $firmaHtml ?: '<div style="height:42px"></div>' !!}</td><td><span class="lbl">Recibí</span><div style="height:42px"></div>{{ $w['nombre'] }}</td></tr></table>
  <table class="of" style="margin-top:8px">
    <tr class="sec"><td colspan="4">DETERMINACIÓN DE LAS BASES DE COTIZACIÓN A LA SEGURIDAD SOCIAL Y CONCEPTOS DE RECAUDACIÓN CONJUNTA Y DE LA BASE SUJETA A RETENCIÓN DEL IRPF Y APORTACIÓN DE LA EMPRESA</td></tr>
    <tr class="b"><td>Concepto</td><td class="r">Base</td><td class="r">Tipo %</td><td class="r">Aportación empresa</td></tr>
    <tr><td class="b" colspan="4">1. Base de cotización por contingencias comunes</td></tr>
    <tr><td class="ind">Remuneración mensual</td><td class="r m">{{ $E($remTotal) }}</td><td></td><td></td></tr>
    <tr><td class="ind">Prorrata de pagas extraordinarias</td><td class="r m">{{ $E($n['prorB']) }}</td><td></td><td></td></tr>
    <tr><td class="ind b">Total</td><td class="r m b">{{ $E($n['base']) }}</td><td class="r m">23,60</td><td class="r m">{{ $E($n['E']['cc']) }}</td></tr>
    <tr><td class="ind">Mecanismo de Equidad Intergeneracional</td><td class="r m">{{ $E($n['base']) }}</td><td class="r m">0,75</td><td class="r m">{{ $E($n['E']['mei']) }}</td></tr>
    <tr><td class="b" colspan="4">2. Base de cotización por contingencias profesionales (AT y EP) y conceptos de recaudación conjunta</td></tr>
    @foreach([['AT y EP', Formato::num($at), $n['E']['at']], ['Desempleo', $n['tmp'] ? '6,70' : '5,50', $n['E']['des']], ['Formación profesional', '0,60', $n['E']['fp']], ['Fondo de Garantía Salarial', '0,20', $n['E']['fog']]] as [$l, $p, $v])
      <tr><td class="ind">{{ $l }}</td><td class="r m">{{ $E($n['baseAT']) }}</td><td class="r m">{{ $p }}</td><td class="r m">{{ $E($v) }}</td></tr>
    @endforeach
    <tr><td class="b">3. Cotización adicional por horas extraordinarias</td><td class="r m">{{ $E($n['hx']) }}</td><td class="r m">23,60</td><td class="r m">{{ $E($n['E']['hx'] ?? 0) }}</td></tr>
    <tr><td class="b">4. Base sujeta a retención del IRPF</td><td class="r m">{{ $E($n['baseIrpf']) }}</td><td></td><td></td></tr>
    <tr class="sec"><td colspan="3">TOTAL APORTACIÓN DE LA EMPRESA</td><td class="r m">{{ $E($n['ssE']) }}</td></tr>
  </table>
  @if($ibanM)<div class="pie">Líquido abonado por transferencia a la cuenta {{ $ibanM }}.</div>@endif

@else
  {{-- ============================ MODELO 1 · CLÁSICO ============================ --}}
  <table class="grid">
    <tr><td style="width:55%">@if($logo)<img src="{{ $logo }}" style="max-height:36px;max-width:150px;float:right">@endif<span class="lbl">Empresa</span><b style="font-size:12px">{{ $c->nombre }}</b><br>{{ $domEmp }}</td>
      <td><span class="lbl">CIF</span><b class="m">{{ $c->nif }}</b></td><td><span class="lbl">C.C.C.</span><b class="m">{{ $ccc ?: '—' }}</b></td></tr>
  </table>
  <table class="grid" style="margin-top:-1px">
    <tr><td style="width:40%"><span class="lbl">Trabajador</span><b style="font-size:12px">{{ $w['nombre'] }}</b></td><td><span class="lbl">N.I.F.</span><b class="m">{{ $w['nif'] }}</b></td><td><span class="lbl">Nº afiliación S.S.</span><b class="m">{{ $w['naf'] }}</b></td><td><span class="lbl">Grupo cot.</span><b class="m">{{ $w['grupo'] }}</b></td></tr>
    <tr><td><span class="lbl">Categoría profesional</span><b>{{ $cat ?: '—' }}</b></td><td><span class="lbl">Contrato</span><b class="m">{{ $contrato }}</b></td><td><span class="lbl">Antigüedad</span><b class="m">{{ Formato::fecha($w['alta']) }}</b></td><td><span class="lbl">Coef. parcialidad</span><b class="m">{{ Formato::num(Nomina::n($w['jor'] ?? 100) / 100) }}</b></td></tr>
    <tr><td><span class="lbl">Periodo de liquidación</span><b class="m">{{ $per }}</b></td><td><span class="lbl">Total días</span><b class="m">30</b></td><td><span class="lbl">Recibo</span><b>{{ $titRec }}</b></td><td><span class="lbl">Fecha de pago</span><b class="m">{{ $fecha ?: '—' }}</b></td></tr>
  </table>
  <table class="bx cl" style="margin-top:6px">
    <tr><th class="r" style="width:58px">Cuantía</th><th class="r" style="width:66px">Precio</th><th style="width:34px">Cód.</th><th style="text-align:left">Concepto</th><th class="r" style="width:90px">Devengos</th><th class="r" style="width:90px">Deducciones</th></tr>
    @php $filas = 0; @endphp
    @foreach($lin as $l)
      <tr class="col"><td class="r m">{{ isset($l['cant']) && $l['cant'] ? Formato::num($l['cant']) : '' }}</td><td class="r m">{{ isset($l['precio']) && $l['precio'] ? Formato::num($l['precio']) : '' }}</td><td class="m">{{ $l['cod'] }}</td><td>{{ $tag($l) }}</td><td class="r m">{{ $E($l['importe']) }}</td><td></td></tr>
      @php $filas++; @endphp
    @endforeach
    @foreach($ss as [$l, $p, $v])
      <tr class="col"><td class="r m">{{ $p }}</td><td class="r m">{{ $E($n['base']) }}</td><td></td><td>{{ $l === 'MEI' ? 'Mecanismo de Equidad Intergeneracional' : 'Cotización '.mb_strtolower($l) }}</td><td></td><td class="r m">{{ $E($v) }}</td></tr>
      @php $filas++; @endphp
    @endforeach
    @if($n['irpf'] || $n['ip'])<tr class="col"><td class="r m">{{ Formato::num($n['ip']) }}</td><td class="r m">{{ $E($n['baseIrpf']) }}</td><td></td><td>Tributación I.R.P.F.</td><td></td><td class="r m">{{ $E($n['irpf']) }}</td></tr>@php $filas++; @endphp @endif
    @foreach($otras as [$l, $v])<tr class="col"><td></td><td></td><td></td><td>{{ $l }}</td><td></td><td class="r m">{{ $E($v) }}</td></tr>@php $filas++; @endphp @endforeach
    @for($i = $filas; $i < 16; $i++)<tr class="col"><td>&nbsp;</td><td></td><td></td><td></td><td></td><td></td></tr>@endfor
    <tr style="border-top:1px solid #000" class="b"><td colspan="4" class="r">TOTALES</td><td class="r m">{{ $E($n['dev']) }}</td><td class="r m">{{ $E($totDed) }}</td></tr>
  </table>
  <table class="grid" style="margin-top:6px">
    <tr class="c">@foreach(['Rem. total', 'P.P. extras', 'Base S.S.', 'Base A.T. y desemp.', 'Base sujeta I.R.P.F.', 'T. devengado', 'T. a deducir'] as $l)<td><span class="lbl" style="text-align:center">{{ $l }}</span></td>@endforeach</tr>
    <tr>@foreach([$remTotal, $n['prorB'], $n['base'], $n['baseAT'], $n['baseIrpf'], $n['dev'], $totDed] as $v)<td class="r m">{{ $E($v) }}</td>@endforeach</tr>
  </table>
  <table style="margin-top:8px"><tr>
    <td style="width:62%;padding:0"><table class="grid"><tr><td style="width:50%"><span class="lbl">Firma y sello de la empresa</span>{!! $firmaHtml ?: '<div style="height:46px"></div>' !!}</td><td><span class="lbl">Recibí</span><div style="height:46px"></div><span style="font-size:9px">{{ $w['nombre'] }}</span></td></tr></table></td>
    <td style="padding:0 0 0 8px"><table class="grid"><tr><td class="c"><span class="lbl" style="text-align:center">Líquido a percibir</span><span class="liq m">{{ $E($n['liq']) }} €</span></td></tr>
      @if($ibanM)<tr><td style="font-size:9px">Transferencia a {{ $ibanM }}</td></tr>@endif</table></td>
  </tr></table>
  <table class="grid" style="margin-top:8px;font-size:9px">
    <tr><td colspan="{{ count($emprRows) + 1 }}" class="b">Aportación de la empresa · coste total {{ $E($n['dev'] + $n['ssE']) }} €</td></tr>
    <tr>@foreach($emprRows as [$l, $p, $b, $v])<td><span class="lbl">{{ $l }} {{ $p }} %</span><span class="m">{{ $E($v) }}</span></td>@endforeach<td><span class="lbl">Total</span><b class="m">{{ $E($n['ssE']) }}</b></td></tr>
  </table>
@endif
</div>
