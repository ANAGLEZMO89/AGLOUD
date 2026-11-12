{{--
  Contrato de trabajo con la estructura de los modelos oficiales del SEPE (Real Decreto-ley 32/2021):
  indefinido (incluye fijo discontinuo), duración determinada, formativo para la obtención de la práctica profesional
  y formación en alternancia. Variables: c, w, P, rep, cl (cláusulas adicionales [título, texto]), extraTxt (texto propio).
--}}
@php
  use App\Services\Nomina;
  use App\Support\Formato;
  $code = Nomina::codigo((string) $w['contrato']);
  $fam = in_array($code, ['420', '520'], true) ? 'practica' : ($code === '421' ? 'alternancia' : (Nomina::temporal($w) ? 'temporal' : 'indefinido'));
  $titulo = ['indefinido' => 'CONTRATO DE TRABAJO INDEFINIDO', 'temporal' => 'CONTRATO DE TRABAJO DE DURACIÓN DETERMINADA', 'practica' => 'CONTRATO FORMATIVO PARA LA OBTENCIÓN DE LA PRÁCTICA PROFESIONAL ADECUADA AL NIVEL DE ESTUDIOS', 'alternancia' => 'CONTRATO DE FORMACIÓN EN ALTERNANCIA'][$fam];
  $parcial = Nomina::parcial($w) || Nomina::n($w['jor']) < 100;
  $fd = str_starts_with($code, '3');
  $sust = in_array($code, ['410', '510'], true);
  $X = fn ($b) => $b ? '☒' : '☐';
  $v = fn ($x) => ($x === null || $x === '') ? '<span class="vac">&nbsp;</span>' : e($x);
  $anual = Nomina::anual($w);
  $conv = $P['conv']['nombre'] ?: '';
  $lugar = $c->municipio ?: $c->provincia;
  $f = \Illuminate\Support\Carbon::parse($w['alta'] ?: now());
  $fechaLarga = $f->day.' de '.mb_strtolower(Nomina::MESES[$f->month - 1]).' de '.$f->year;
  $n = 0;
  $ord = function () use (&$n) { return mb_strtoupper(Nomina::ORDINALES[$n++] ?? ''); };
@endphp
<style>
  .of-doc{font:10.5px/1.45 Arial,Helvetica,sans-serif;color:#000}
  .of-doc .top{display:flex;justify-content:space-between;align-items:flex-start;gap:14px;border-bottom:2px solid #000;padding-bottom:8px;margin-bottom:10px}
  .of-doc .top h1{font:700 14px Arial,Helvetica,sans-serif;margin:0;text-align:left;letter-spacing:.02em}
  .of-doc .cod{border:1.5px solid #000;text-align:center;min-width:120px}
  .of-doc .cod span{display:block;font-size:8.5px;background:#e6e6e6;border-bottom:1px solid #000;padding:2px 6px}
  .of-doc .cod b{display:block;font-size:16px;padding:3px 6px;letter-spacing:.1em}
  .of-doc .sec{border:1px solid #000;margin-bottom:8px}
  .of-doc .sec>.h{background:#e6e6e6;border-bottom:1px solid #000;font-weight:700;padding:3px 8px;font-size:10px;letter-spacing:.04em}
  .of-doc .sec table{width:100%;border-collapse:collapse}
  .of-doc .sec td{border:0;border-top:1px solid #bbb;padding:3px 8px;font:10.5px Arial,Helvetica,sans-serif;vertical-align:top;background:none;width:auto}
  .of-doc .sec tr:first-child td{border-top:0}
  .of-doc .sec td small{display:block;font-size:8px;color:#444;text-transform:uppercase}
  .of-doc .vac{display:inline-block;min-width:120px;border-bottom:1px dotted #000}
  .of-doc h2{font:700 11px Arial,Helvetica,sans-serif;text-align:center;margin:10px 0 6px;letter-spacing:.06em;text-transform:uppercase}
  .of-doc p{margin:0 0 6px;text-align:justify}
  .of-doc .cl b{font-weight:700}
  .of-doc .cl p{break-inside:avoid}
  .of-doc .op{margin:2px 0 2px 14px}
  .of-doc .firmas{display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px;margin-top:22px;text-align:center;font-size:10px}
  .of-doc .firmas div{min-height:90px;display:flex;flex-direction:column;justify-content:space-between}
  .of-doc .nota{font-size:8.5px;color:#333;border-top:1px solid #000;margin-top:16px;padding-top:6px}
</style>
<div class="of-doc">
  <div class="top">
    <div><div style="font-size:9px;letter-spacing:.06em">MODELO DE CONTRATO DE TRABAJO</div><h1>{{ $titulo }}</h1></div>
    <div class="cod"><span>CÓDIGO DE CONTRATO</span><b>{{ $code }}</b></div>
  </div>

  <div class="sec"><div class="h">DATOS DE LA EMPRESA</div>
    <table>
      <tr><td colspan="2"><small>D./Dña.</small>{!! $v($rep['nombre'] ?? '') !!}</td><td><small>NIF/NIE</small>{!! $v($rep['nif'] ?? '') !!}</td><td><small>En concepto de (1)</small>{!! $v(($rep['cargo'] ?? '') ?: ($c->tipo === 'Autónomo' ? 'Titular' : '')) !!}</td></tr>
      <tr><td colspan="2"><small>De la empresa</small><b>{{ $c->nombre }}</b></td><td colspan="2"><small>NIF/CIF</small>{{ $c->nif }}</td></tr>
      <tr><td colspan="2"><small>Domicilio social</small>{!! $v($c->domicilio) !!}</td><td><small>País</small>España</td><td><small>Municipio · C. postal</small>{!! $v(trim(($c->municipio ?? '').' · '.($c->cp ?? ''), ' ·')) !!}</td></tr>
      <tr><td colspan="2"><small>Código de cuenta de cotización</small>{!! $v($w['ccc']) !!}</td><td colspan="2"><small>Actividad económica</small>{!! $v($c->actividadPrincipal()) !!}</td></tr>
    </table>
  </div>

  <div class="sec"><div class="h">DATOS DE LA PERSONA TRABAJADORA</div>
    <table>
      <tr><td colspan="2"><small>D./Dña.</small><b>{{ $w['nombre'] }}</b></td><td><small>{{ $w['tipoDoc'] ?: 'NIF/NIE' }}</small>{!! $v($w['nif']) !!}</td><td><small>Fecha de nacimiento</small>{!! $v(Formato::fecha($w['fnac'])) !!}</td></tr>
      <tr><td><small>Nº de afiliación a la S. Social</small>{!! $v($w['naf']) !!}</td><td><small>Nivel formativo</small>{!! $v($w['nivForm']) !!}</td><td><small>Nacionalidad</small>{!! $v($w['nac']) !!}</td><td><small>Sexo</small>{!! $v($w['sexo']) !!}</td></tr>
      <tr><td colspan="2"><small>Domicilio</small>{!! $v($w['dom']) !!}</td><td><small>País</small>España</td><td><small>Municipio · C. postal</small>{!! $v(trim(($w['mun'] ?? '').' · '.($w['cp'] ?? ''), ' ·')) !!}</td></tr>
    </table>
  </div>

  <h2>Declaran</h2>
  <p>Que reúnen los requisitos exigidos para la celebración del presente contrato y, en su consecuencia, acuerdan formalizarlo con arreglo a las siguientes</p>
  <h2>Cláusulas</h2>
  <div class="cl">
    <p><b>{{ $ord() }}.</b> La persona trabajadora prestará sus servicios como {!! $v($w['cno'] ? $w['cno'].' · '.($w['convCat'] ?: $w['cat']) : ($w['convCat'] ?: $w['cat'])) !!}, incluido en el grupo profesional / categoría / nivel profesional {!! $v($w['convCat'] ?: $w['cat']) !!} (grupo de cotización {{ $w['grupo'] }}), de acuerdo con el sistema de clasificación profesional vigente en la empresa, en el centro de trabajo ubicado en {!! $v($w['centro'] ?: trim(($c->domicilio ?? '').', '.($c->municipio ?? ''), ', ')) !!}.</p>

    <p><b>{{ $ord() }}.</b> La jornada de trabajo será:</p>
    <p class="op">{{ $X(! $parcial) }} <b>A tiempo completo:</b> {{ ! $parcial ? $w['horas'] : '____' }} horas semanales, prestadas {!! ! $parcial ? $v($w['horario']) : '________________' !!}, con los descansos que establece la ley.</p>
    <p class="op">{{ $X($parcial) }} <b>A tiempo parcial:</b> {{ $parcial ? $w['horas'] : '____' }} horas a la semana, inferior a la jornada de trabajo de una persona trabajadora a tiempo completo comparable ({{ $parcial ? Formato::num($w['jor'], 0).' %' : '____ %' }}). La distribución del tiempo de trabajo será: {!! $parcial ? $v($w['horario']) : '________________' !!}. Se llevará el registro diario de la jornada (art. 12.4.c y 34.9 ET).</p>
    @if($fam === 'indefinido')<p class="op">{{ $X($fd) }} <b>Fijo discontinuo</b> (art. 16 ET): los periodos de actividad y el orden y la forma de llamamiento serán los previstos en el convenio colectivo o, en su defecto, en el acuerdo de empresa.</p>@endif

    @if($fam === 'indefinido')
      <p><b>{{ $ord() }}.</b> La duración del presente contrato será <b>INDEFINIDA</b>, iniciándose la relación laboral el {{ Formato::fecha($w['alta']) }}. Se establece un periodo de prueba de {!! $v($w['prueba']) !!} (art. 14 ET).</p>
    @elseif($fam === 'temporal')
      <p><b>{{ $ord() }}.</b> La duración del presente contrato se extenderá desde el {{ Formato::fecha($w['alta']) }} hasta el {!! $v(Formato::fecha($w['fin'])) !!}, por la siguiente causa (art. 15 ET):</p>
      <p class="op">{{ $X(! $sust) }} <b>Circunstancias de la producción</b> (art. 15.2 ET): {!! ! $sust ? $v($w['causa']) : '________________' !!}.</p>
      <p class="op">{{ $X($sust) }} <b>Sustitución de persona trabajadora</b> (art. 15.3 ET): {{ $sust ? 'sustituye a '.($w['sustituido'] ?: '________') : '________________' }}{!! $sust && $w['causa'] ? ', causa: '.$v($w['causa']) : '' !!}.</p>
      <p>Se establece un periodo de prueba de {!! $v($w['prueba']) !!} (art. 14 ET).</p>
    @elseif($fam === 'practica')
      <p><b>{{ $ord() }}.</b> El presente contrato se concierta para la obtención de la práctica profesional adecuada al nivel de estudios de la persona trabajadora ({!! $v($w['nivForm']) !!}), con una duración desde el {{ Formato::fecha($w['alta']) }} hasta el {!! $v(Formato::fecha($w['fin'])) !!}, que no podrá ser inferior a seis meses ni superior a un año (art. 11.3 ET). Se establece un periodo de prueba de {!! $v($w['prueba']) !!}, que no podrá superar un mes salvo lo dispuesto en el convenio colectivo.</p>
      <p>La empresa elaborará el plan formativo individual en el que se especifique el contenido de la práctica profesional y asignará una persona tutora que cuente con la formación o experiencia adecuadas.</p>
    @else
      <p><b>{{ $ord() }}.</b> El presente contrato tiene por objeto compatibilizar la actividad laboral retribuida con los correspondientes procesos formativos (art. 11.2 ET), desde el {{ Formato::fecha($w['alta']) }} hasta el {!! $v(Formato::fecha($w['fin'])) !!}, con una duración mínima de tres meses y máxima de dos años. El tiempo de trabajo efectivo no podrá ser superior al 65 % durante el primer año ni al 85 % durante el segundo de la jornada máxima prevista en el convenio colectivo. Existirá una persona tutora en la empresa y otra en el centro o entidad formativa, y un plan formativo individual.</p>
    @endif

    <p><b>{{ $ord() }}.</b> La persona trabajadora percibirá una retribución total de {{ Formato::eur($anual) }} brutos ☒ anuales, que se distribuyen en los siguientes conceptos salariales: salario base de {{ Formato::eur(Nomina::n($w['salBase'])) }} mensuales{{ Nomina::n($w['plus'] ?? 0) ? ', complementos de '.Formato::eur(Nomina::n($w['plus'])).' mensuales' : '' }} y {{ $w['pagas'] }} pagas{{ $w['prorr'] ? ' con las extraordinarias prorrateadas' : '' }}{{ $fam === 'practica' ? ', de acuerdo con la retribución fijada en el convenio colectivo para estos contratos o, en su defecto, la del grupo profesional y nivel retributivo correspondiente a las funciones desempeñadas' : '' }}.</p>

    <p><b>{{ $ord() }}.</b> La duración de las vacaciones anuales será de {!! $v($w['vac']) !!} días naturales.</p>

    <p><b>{{ $ord() }}.</b> En lo no previsto en este contrato se estará a la legislación vigente que resulte de aplicación y, particularmente, al Estatuto de los Trabajadores (Real Decreto Legislativo 2/2015, de 23 de octubre) y al convenio colectivo {!! $v($conv) !!}.</p>

    @if($fam === 'temporal' && ! $sust)
      <p><b>{{ $ord() }}.</b> A la finalización del contrato la persona trabajadora tendrá derecho a una indemnización de cuantía equivalente a la parte proporcional de doce días de salario por cada año de servicio, o la establecida en la normativa específica que sea de aplicación (art. 49.1.c ET).</p>
    @endif

    <p><b>{{ $ord() }}.</b> El contenido del presente contrato se comunicará al Servicio Público de Empleo en el plazo de los diez días siguientes a su concertación, y se entregará copia básica a la representación legal de las personas trabajadoras (art. 8.4 ET).</p>
  </div>

  @if($cl || $extraTxt)
    <h2>Cláusulas adicionales</h2>
    <div class="cl">
      @foreach($cl as [$t, $txt])<p><b>{{ $ord() }}. {{ $t }}.</b> {{ $txt }}</p>@endforeach
      {!! $extraTxt !!}
    </div>
  @endif

  <div style="break-inside:avoid;page-break-inside:avoid">
  <p style="margin-top:12px">Y para que conste, se extiende este contrato por cuadruplicado ejemplar en el lugar y fecha a continuación indicados, firmando las partes interesadas.</p>
  <p style="text-align:center">En {!! $v($lugar) !!}, a {{ $fechaLarga }}</p>
  <div class="firmas">
    <div><span>La persona trabajadora</span><span>{{ $w['nombre'] }}</span></div>
    <div><span>El/la representante de la empresa</span>{!! $firmaImg ?? '' !!}<span>{{ ($rep['nombre'] ?? '') ?: $c->nombre }}</span></div>
    <div><span>El/la representante legal de la persona menor, si procede</span><span>&nbsp;</span></div>
  </div>
  </div>
  <div class="nota">(1) Indíquese si es titular, administrador/a, apoderado/a u otro concepto que acredite la representación de la empresa.<br>
    Protección de datos: los datos personales de este contrato se tratarán conforme al Reglamento (UE) 2016/679 y a la Ley Orgánica 3/2018 para la gestión de la relación laboral y su comunicación al Servicio Público de Empleo.</div>
</div>
