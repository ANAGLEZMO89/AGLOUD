{{-- Cuerpo del recibo de salarios (3 modelos). Variables: c, w, n, P, modelo, mesL, per, at, ccc --}}
@php
  use App\Services\Nomina;
  use App\Support\Formato;
  $E = fn ($v) => Formato::num($v ?: 0);
  $useImg = ! empty($P['useImg']);
  $logo = $useImg && $c->logo_documento_id ? route('clientes.imagen.ver', [$c, 'logo']) : null;
  $firma = $useImg && $c->firma_documento_id ? route('clientes.imagen.ver', [$c, 'firma']) : null;
  $img = $firma || $logo ? '<img src="'.e($firma ?: $logo).'" style="max-height:60px;max-width:180px">' : '<div style="height:50px"></div>';
  $head = $logo ? '<img src="'.e($logo).'" style="max-height:44px;max-width:160px;margin-bottom:8px">' : '';
  $dev = array_values(array_filter([['Salario base', $n['sb']], ['Complementos salariales', $n['pl']], ['Horas extraordinarias', $n['hx']], ['Otros devengos', $n['otr']], ['Paga extraordinaria', $n['extra']], ['Prorrata de pagas extraordinarias', $n['pror']]], fn ($r) => $r[1]));
  $nos = array_values(array_filter([['Dietas', $n['die']], ['Plus de distancia y kilometraje', $n['km']]], fn ($r) => $r[1]));
  $ded = array_values(array_filter([['Contingencias comunes 4,70 %', $n['T']['cc']], ['MEI 0,15 %', $n['T']['mei']], ['Desempleo '.($n['tmp'] ? '1,60' : '1,55').' %', $n['T']['des']], ['Formación profesional 0,10 %', $n['T']['fp']], ['IRPF '.Formato::num($n['ip']).' %', $n['irpf']], ['Anticipos', $n['ant']], ['Embargos', $n['emb']]], fn ($r) => $r[1]));
  $totDed = $n['ssT'] + $n['irpf'] + $n['ant'] + $n['emb'];
  $emp = '<b>'.e($c->nombre).'</b><br>CIF '.e($c->nif).'<br>CCC '.e($ccc ?: '—');
  $trab = '<b>'.e($w['nombre']).'</b><br>NIF '.e($w['nif']).' · NAF '.e($w['naf']).'<br>'.e($w['cat'] ?: $w['convCat']).' · grupo '.e($w['grupo']).'<br>Antigüedad '.Formato::fecha($w['alta']).' · '.e(Nomina::codigo($w['contrato']));
  $bases = [['Remuneración mensual', $n['mensual'] + $n['hx'] + $n['otr']], ['Prorrata pagas extraordinarias', $n['prorB']], ['Base contingencias comunes', $n['base']], ['Base AT y EP, desempleo, FP y FOGASA', $n['base']], ['Base sujeta a retención del IRPF', $n['baseIrpf']]];
  $empr = [['Contingencias comunes 23,60 %', $n['E']['cc']], ['MEI 0,75 %', $n['E']['mei']], ['AT y EP '.Formato::num($at).' %', $n['E']['at']], ['Desempleo '.($n['tmp'] ? '6,70' : '5,50').' %', $n['E']['des']], ['Formación profesional 0,60 %', $n['E']['fp']], ['FOGASA 0,20 %', $n['E']['fog']]];
  $rows = fn ($l) => implode('', array_map(fn ($r) => '<tr><td>'.e($r[0]).'</td><td class="r mono">'.$E($r[1]).'</td></tr>', $l));
  $dc = implode('', array_map(fn ($r) => '<tr><td>'.e($r[0]).'</td><td class="r mono">'.$E($r[1]).'</td><td></td></tr>', array_merge($dev, $nos))).implode('', array_map(fn ($r) => '<tr><td>'.e($r[0]).'</td><td></td><td class="r mono">'.$E($r[1]).'</td></tr>', $ded));
  $sign = '<table style="margin-top:22px"><tr><td style="width:50%"><div class="h">Firma y sello de la empresa</div>'.$img.'</td><td><div class="h">Recibí</div><div style="height:50px"></div>'.e($w['nombre']).'</td></tr></table>';
  if ($modelo === 2) {
      $body = $head.'<table><tr><td><span class="b" style="font-size:14px">NÓMINA '.e(mb_strtoupper($mesL)).'</span></td><td class="r">Periodo '.e($per).'</td></tr></table><hr><table><tr><td style="width:50%">'.$emp.'</td><td>'.$trab.'</td></tr></table><hr>'
        .'<table>'.$dc.'<tr class="b"><td>Totales</td><td class="r mono">'.$E($n['dev']).'</td><td class="r mono">'.$E($totDed).'</td></tr></table><p class="b" style="font-size:13px;text-align:right">Líquido a percibir: '.$E($n['liq']).' €</p><p style="font-size:10px">Base CC '.$E($n['base']).' · Base IRPF '.$E($n['baseIrpf']).' · Coste empresa SS '.$E(array_sum(array_column($empr, 1))).'</p>'.$sign;
  } elseif ($modelo === 3) {
      $body = $head.'<div class="b" style="font-size:13px;text-align:center;margin-bottom:8px">RECIBO INDIVIDUAL JUSTIFICATIVO DEL PAGO DE SALARIOS</div><table class="box"><tr><td style="width:50%;border-right:1px solid #000">'.$emp.'</td><td>'.$trab.'</td></tr></table><p>Periodo de liquidación: '.e($per).' · Total días: 30</p>'
        .'<table class="box"><tr><th style="text-align:left">I. DEVENGOS</th><th class="r">Importe</th></tr><tr><td class="b">1. Percepciones salariales</td><td></td></tr>'.$rows($dev).'<tr><td class="b">2. Percepciones no salariales</td><td></td></tr>'.($rows($nos) ?: '<tr><td>—</td><td></td></tr>').'<tr class="b"><td>A. TOTAL DEVENGADO</td><td class="r mono">'.$E($n['dev']).'</td></tr></table>'
        .'<table class="box" style="margin-top:6px"><tr><th style="text-align:left">II. DEDUCCIONES</th><th class="r">Importe</th></tr><tr><td class="b">1. Aportación del trabajador a la Seguridad Social</td><td></td></tr>'.$rows(array_filter($ded, fn ($r) => ! preg_match('/IRPF|Anticipo|Embargo/', $r[0]))).'<tr><td class="b">2. Impuesto sobre la renta de las personas físicas</td><td class="r mono">'.$E($n['irpf']).'</td></tr><tr><td class="b">3. Anticipos</td><td class="r mono">'.$E($n['ant']).'</td></tr><tr><td class="b">4. Otras deducciones</td><td class="r mono">'.$E($n['emb']).'</td></tr><tr class="b"><td>B. TOTAL A DEDUCIR</td><td class="r mono">'.$E($totDed).'</td></tr></table>'
        .'<p class="b" style="font-size:13px">LÍQUIDO TOTAL A PERCIBIR (A − B): '.$E($n['liq']).' €</p><table class="box"><tr><th style="text-align:left">DETERMINACIÓN DE LAS BASES DE COTIZACIÓN Y DEL IRPF</th><th class="r">Importe</th></tr>'.$rows($bases).'</table><table class="box" style="margin-top:6px"><tr><th style="text-align:left">APORTACIÓN DE LA EMPRESA</th><th class="r">Importe</th></tr>'.$rows($empr).'</table>'.$sign;
  } else {
      $body = $head.'<table><tr><td class="b" style="font-size:14px">Recibo de salarios</td><td class="r">'.e($per).'</td></tr></table><table style="margin-top:8px"><tr><td class="box" style="width:50%">'.$emp.'</td><td class="box">'.$trab.'</td></tr></table>'
        .'<table style="margin-top:10px"><tr style="border-bottom:1px solid #000"><th style="text-align:left">Concepto</th><th class="r">Devengos</th><th class="r">Deducciones</th></tr>'.$dc
        .'<tr style="border-top:1px solid #000" class="b"><td>Total</td><td class="r mono">'.$E($n['dev']).'</td><td class="r mono">'.$E($totDed).'</td></tr></table><table class="box" style="margin-top:8px"><tr class="b"><td>Líquido total a percibir</td><td class="r mono" style="font-size:13px">'.$E($n['liq']).' €</td></tr></table>'
        .'<table style="margin-top:10px"><tr><td style="width:50%"><div class="h b">Bases</div><table>'.$rows($bases).'</table></td><td><div class="h b">Aportación de la empresa</div><table>'.$rows($empr).'</table></td></tr></table>'.$sign;
  }
@endphp
{!! $body !!}
