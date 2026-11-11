@php
  use App\Support\Formato;
  $fJunta = ! empty($d['junta']) ? Formato::fechaLarga(\Carbon\CarbonImmutable::parse($d['junta'])) : '____ de __________ de '.($anio + 1);
  $cargo = $admin['cargo'] ?? 'Administrador único';
  $nomAdm = $admin['nombre'] ?? '______________________';
  $nifAdm = $admin['nif'] ?? '__________';
  $domicilio = trim(implode(', ', array_filter([$c->domicilio, trim(($c->cp ?? '').' '.($c->municipio ?? '')), $c->provincia])));
  $inscrip = ($reg['rm'] ?? $c->provincia) ? 'inscrita en el Registro Mercantil de '.($reg['rm'] ?? $c->provincia).(! empty($reg['tomo']) ? ', tomo '.$reg['tomo'] : '').(! empty($reg['folio']) ? ', folio '.$reg['folio'] : '').(! empty($reg['hoja']) ? ', hoja '.$reg['hoja'] : '').(! empty($reg['insc']) ? ', inscripción '.$reg['insc'] : '') : '';
  $junta = ($d['tipo_junta'] ?? 'universal') === 'universal' ? 'Junta General Ordinaria y Universal, con la asistencia, presentes o representados, de todos los socios, que aceptaron por unanimidad su celebración y el orden del día' : 'Junta General Ordinaria, debidamente convocada conforme a los estatutos y a la Ley de Sociedades de Capital';
  $acuerdo = ($d['acuerdo'] ?? 'unanimidad') === 'unanimidad' ? 'por unanimidad' : 'por la mayoría legalmente exigida';
  $ap = $d['aplic'] ?? [];
  $rl = (float) ($ap['reserva_legal'] ?? 0); $dv = (float) ($ap['dividendos'] ?? 0);
  $vol = $res > 0 ? (isset($ap['voluntarias']) && ($ap['voluntarias'] || $rl || $dv) ? (float) $ap['voluntarias'] : round($res - $rl - $dv, 2)) : 0;
  $cuadra = $res <= 0 || abs($rl + $vol + $dv - $res) < 0.01;
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $titulo }} · {{ $c->nombre }} · {{ $anio }}</title>
<style>
  body{font-family:Georgia,"Times New Roman",serif;color:#111;margin:48px auto;max-width:720px;font-size:14.5px;line-height:1.7;padding:0 24px}
  h1{font-size:18px;text-align:center;text-transform:uppercase;letter-spacing:.04em;margin:0 0 24px}
  p{margin:0 0 14px;text-align:justify}
  table{width:100%;border-collapse:collapse;margin:8px 0 18px}td{padding:6px 4px;border-bottom:1px solid #ccc}td.v{text-align:right;font-family:"Courier New",monospace}
  .firma{margin-top:56px;display:flex;justify-content:space-between;gap:40px}.firma div{flex:1;border-top:1px solid #111;padding-top:6px;font-size:13px;text-align:center}
  .aviso{font-family:system-ui,sans-serif;font-size:12px;color:#8A1C1C;background:#FDE7E7;padding:8px 12px;border-radius:8px;margin-bottom:18px}
  .nota{font-family:system-ui,sans-serif;font-size:11.5px;color:#666;margin-top:36px;border-top:1px solid #ddd;padding-top:8px}
  @media print{.aviso,.nota{display:none}body{margin:0 auto}}
</style>
</head>
<body>
@if(! $cuadra)<div class="aviso">La aplicación del resultado no suma el resultado del ejercicio ({{ Formato::eur($res) }}). Revísala en Registro Mercantil › Plantillas.</div>@endif

@if($doc === 'cert')
  <h1>Certificado de aprobación de las cuentas anuales</h1>
  <p>D./D.ª <b>{{ $nomAdm }}</b>, con NIF {{ $nifAdm }}, en su calidad de {{ mb_strtolower($cargo) }} de la sociedad <b>{{ $c->nombre }}</b>, con NIF {{ $c->nif }}, domicilio social en {{ $domicilio ?: '______________________' }}{{ $inscrip ? ', '.$inscrip : '' }},</p>
  <p style="text-align:center"><b>CERTIFICA:</b></p>
  <p>1.º Que el día {{ $fJunta }}, en el domicilio social, se celebró {{ $junta }}.</p>
  <p>2.º Que en dicha Junta se aprobaron {{ $acuerdo }} las cuentas anuales correspondientes al ejercicio cerrado el 31 de diciembre de {{ $anio }}, formuladas en modelo abreviado, que comprenden el balance, la cuenta de pérdidas y ganancias y la memoria, con un resultado de <b>{{ Formato::eur($res) }}</b>.</p>
  <p>3.º Que se aprobó {{ $acuerdo }} la siguiente aplicación del resultado: @if($res > 0) a reserva legal, {{ Formato::eur($rl) }}; a reservas voluntarias, {{ Formato::eur($vol) }}; a dividendos, {{ Formato::eur($dv) }}. @else a resultados negativos de ejercicios anteriores, {{ Formato::eur($res) }}. @endif</p>
  <p>4.º Que la sociedad [no está obligada a auditar sus cuentas (art. 263 LSC) / ha sometido sus cuentas a auditoría y las depositadas se corresponden con las auditadas] <i>(táchese lo que no proceda)</i>.</p>
  <p>5.º Que el acta fue aprobada al término de la reunión y firmada por los asistentes.</p>
  <p>Y para que conste, a los efectos de su depósito en el Registro Mercantil, expido la presente certificación en {{ $c->municipio ?: '__________' }}, a {{ Formato::fechaLarga(\App\Support\Plazos::hoy()) }}.</p>
  <div class="firma"><div>{{ $nomAdm }}<br>{{ $cargo }}</div></div>
@elseif($doc === 'acta')
  <h1>Acta de la Junta General Ordinaria</h1>
  <p>En {{ $c->municipio ?: '__________' }}, en el domicilio social de <b>{{ $c->nombre }}</b> (NIF {{ $c->nif }}), el día {{ $fJunta }}, se reúne la {{ $junta }}.</p>
  <p>Actúa como presidente D./D.ª {{ $nomAdm }} y como secretario D./D.ª ______________________.</p>
  <p><b>Orden del día:</b> 1. Examen y, en su caso, aprobación de las cuentas anuales del ejercicio {{ $anio }}. 2. Aplicación del resultado. 3. Aprobación de la gestión del órgano de administración. 4. Lectura y aprobación del acta.</p>
  <p><b>Acuerdos:</b></p>
  <p>Primero. Aprobar {{ $acuerdo }} las cuentas anuales del ejercicio cerrado el 31 de diciembre de {{ $anio }}, que arrojan un resultado de {{ Formato::eur($res) }}.</p>
  <p>Segundo. Aplicar el resultado: @if($res > 0) a reserva legal, {{ Formato::eur($rl) }}; a reservas voluntarias, {{ Formato::eur($vol) }}; a dividendos, {{ Formato::eur($dv) }}. @else a resultados negativos de ejercicios anteriores, {{ Formato::eur($res) }}. @endif</p>
  <p>Tercero. Aprobar la gestión del órgano de administración durante el ejercicio.</p>
  <p>Cuarto. Facultar al {{ mb_strtolower($cargo) }} para expedir certificaciones y realizar el depósito de las cuentas en el Registro Mercantil.</p>
  <p>Sin más asuntos que tratar, se levanta la sesión, redactándose la presente acta que, leída, es aprobada por los asistentes.</p>
  <div class="firma"><div>El presidente</div><div>El secretario</div></div>
@elseif($doc === 'aplic')
  <h1>Propuesta de aplicación del resultado · ejercicio {{ $anio }}</h1>
  <p>El órgano de administración de <b>{{ $c->nombre }}</b> propone a la Junta General la siguiente aplicación del resultado del ejercicio cerrado el 31 de diciembre de {{ $anio }}:</p>
  <table>
    <tr><td><b>Base de reparto</b></td><td></td></tr>
    <tr><td>Saldo de la cuenta de pérdidas y ganancias</td><td class="v">{{ Formato::eur($res) }}</td></tr>
    <tr><td><b>Aplicación</b></td><td></td></tr>
    @if($res > 0)
      <tr><td>A reserva legal</td><td class="v">{{ Formato::eur($rl) }}</td></tr>
      <tr><td>A reservas voluntarias</td><td class="v">{{ Formato::eur($vol) }}</td></tr>
      <tr><td>A dividendos</td><td class="v">{{ Formato::eur($dv) }}</td></tr>
    @else
      <tr><td>A resultados negativos de ejercicios anteriores</td><td class="v">{{ Formato::eur($res) }}</td></tr>
    @endif
    <tr><td><b>Total</b></td><td class="v"><b>{{ Formato::eur($res) }}</b></td></tr>
  </table>
  <div class="firma"><div>{{ $nomAdm }}<br>{{ $cargo }}</div></div>
@elseif($doc === 'titular')
  <h1>Declaración de identificación del titular real</h1>
  <p>D./D.ª <b>{{ $nomAdm }}</b>, como {{ mb_strtolower($cargo) }} de <b>{{ $c->nombre }}</b> (NIF {{ $c->nif }}), declara a los efectos de la Ley 10/2010, de prevención del blanqueo de capitales, que las personas físicas que poseen o controlan, directa o indirectamente, más del 25 % del capital o de los derechos de voto de la sociedad a la fecha de cierre del ejercicio {{ $anio }} son:</p>
  <table>
    <tr><td><b>Nombre</b></td><td><b>NIF</b></td><td class="v"><b>% directo</b></td></tr>
    @forelse($socios->filter(fn ($s) => (float) str_replace(',', '.', (string) ($s['pct'] ?? 0)) > 25) as $s)
      <tr><td>{{ $s['nombre'] ?? '' }}</td><td>{{ $s['nif'] ?? '' }}</td><td class="v">{{ $s['pct'] ?? '' }} %</td></tr>
    @empty
      <tr><td colspan="3">Ningún socio supera el 25 %: se considera titular real a quien ejerce la administración ({{ $nomAdm }}, {{ $nifAdm }}).</td></tr>
    @endforelse
  </table>
  <p>Esta declaración se presenta con el depósito de las cuentas anuales en el modelo oficial del Registro Mercantil.</p>
  <div class="firma"><div>{{ $nomAdm }}<br>{{ $cargo }}</div></div>
@elseif($doc === 'instancia')
  <h1>Instancia de presentación</h1>
  <p><b>Al Registro Mercantil de {{ $reg['rm'] ?? ($c->provincia ?: '__________') }}</b></p>
  <p>D./D.ª <b>{{ $nomAdm }}</b>, con NIF {{ $nifAdm }}, como {{ mb_strtolower($cargo) }} de <b>{{ $c->nombre }}</b>, con NIF {{ $c->nif }} y domicilio en {{ $domicilio ?: '______________________' }}{{ $inscrip ? ', '.$inscrip : '' }},</p>
  <p style="text-align:center"><b>SOLICITA:</b></p>
  <p>El depósito de las cuentas anuales del ejercicio cerrado el 31 de diciembre de {{ $anio }}, aprobadas por la Junta General el día {{ $fJunta }}, para lo que se acompaña la documentación exigida: certificación del acuerdo de aprobación y aplicación del resultado, balance, cuenta de pérdidas y ganancias y memoria abreviados, hoja de identificación y declaración de titularidad real.</p>
  <p>En {{ $c->municipio ?: '__________' }}, a {{ Formato::fechaLarga(\App\Support\Plazos::hoy()) }}.</p>
  <div class="firma"><div>{{ $nomAdm }}<br>{{ $cargo }}</div></div>
@else
  <h1>Informe de gestión · ejercicio {{ $anio }}</h1>
  <p>Las sociedades que pueden formular balance abreviado no están obligadas a elaborar el informe de gestión (art. 262.3 LSC). Esta plantilla es para las que presentan cuentas normales.</p>
  <p><b>1. Evolución de los negocios y situación de la sociedad.</b> Durante el ejercicio {{ $anio }}, {{ $c->nombre }} ha obtenido un resultado de {{ Formato::eur($res) }}. ______________________</p>
  <p><b>2. Principales riesgos e incertidumbres.</b> ______________________</p>
  <p><b>3. Acontecimientos posteriores al cierre.</b> ______________________</p>
  <p><b>4. Evolución previsible.</b> ______________________</p>
  <p><b>5. Actividades de I+D, adquisición de participaciones propias y periodo medio de pago a proveedores.</b> ______________________</p>
  <div class="firma"><div>{{ $nomAdm }}<br>{{ $cargo }}</div></div>
@endif
<div class="nota">Plantilla preparada por AG Cloud con los datos de la ficha y de la contabilidad. Revísala y complétala antes de firmar.</div>
<script src="{{ asset('js/imprimir.js') }}"></script>
</body>
</html>
