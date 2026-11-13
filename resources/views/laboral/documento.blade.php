@php
  use App\Services\Nomina;
  use App\Support\Formato;
  $v = fn ($x) => e($x === null || $x === '' ? '—' : $x);
  $kv = fn (array $rows) => '<table>'.implode('', array_map(fn ($r) => '<tr><td class="l">'.e($r[0]).'</td><td>'.$v($r[1]).'</td></tr>', $rows)).'</table>';
  $rep = collect($c->seccion('reps'))->first(fn ($r) => ! empty($r['rLaboral'])) ?? collect($c->seccion('reps'))->first() ?? [];
  $conv = $P['conv']['nombre'] ?: 'el convenio colectivo aplicable';
  $firmaUrl = ! empty($P['useImg']) ? ($c->firma_documento_id ? route('clientes.imagen.ver', [$c, 'firma']) : ($c->logo_documento_id ? route('clientes.imagen.ver', [$c, 'logo']) : null)) : null;
  $firmas = '<table style="margin-top:30px"><tr><td style="border:0;width:50%">Por la empresa<br>'.($firmaUrl ? '<img src="'.e($firmaUrl).'" style="max-height:60px">' : '<div style="height:60px"></div>').'<br>'.e(($rep['nombre'] ?? '') ?: $c->nombre).'</td><td style="border:0">El trabajador<div style="height:60px"></div>'.e($w['nombre']).'</td></tr></table>';
  $temp = Nomina::temporal($w); $code = Nomina::codigo($w['contrato']); $anual = Nomina::anual($w);
  $lugar = $c->municipio ?: $c->provincia;
  $empRows = [['Empresa', $c->nombre], ['CIF', $c->nif], ['Código de cuenta de cotización', $w['ccc']], ['Domicilio', trim(($c->domicilio ?? '').', '.($c->cp ?? '').' '.($c->municipio ?? ''), ', ')], ['Representante', ! empty($rep['nombre']) ? $rep['nombre'].' · '.(($rep['cargo'] ?? '') ?: 'Administrador') : '—'], ['Actividad', $c->actividadPrincipal()]];
  $trRows = [['Trabajador', $w['nombre']], ['Documento', $w['tipoDoc'].' '.$w['nif']], ['Nº de afiliación', $w['naf']], ['Fecha de nacimiento', Formato::fecha($w['fnac'])], ['Sexo', $w['sexo']], ['Nacionalidad', $w['nac']], ['Domicilio', implode(', ', array_filter([$w['dom'], $w['cp'], $w['mun']]))], ['Nivel formativo', $w['nivForm']]];
  $conRows = array_merge([['Modalidad', $w['contrato']], ['Código de contrato', $code], ['Fecha de inicio', Formato::fecha($w['alta'])], ['Fecha de fin', $temp ? Formato::fecha($w['fin']) : 'Indefinido'], ['Causa de la temporalidad', $temp ? $w['causa'] : 'No aplica']],
    preg_match('/^(410|510)/', $code) ? [['Persona sustituida', $w['sustituido']]] : [],
    [['Categoría y grupo profesional', ($w['convCat'] ?: $w['cat']).' · grupo de cotización '.$w['grupo']], ['Ocupación (CNO-11)', $w['cno']], ['Centro de trabajo', $w['centro']], ['Jornada', Formato::num($w['jor'], 0).' % · '.$w['horas'].' horas semanales'], ['Distribución del horario', $w['horario']], ['Periodo de prueba', $w['prueba']], ['Vacaciones', $w['vac'].' días naturales al año'], ['Retribución', Formato::eur($anual).' brutos anuales en '.$w['pagas'].' pagas'.($w['prorr'] ? ' (extras prorrateadas)' : '')], ['Convenio colectivo', $conv]]);
  $titulos = ['contrato' => 'Contrato', 'copia' => 'Copia básica', 'comunicacion' => 'Comunicación del contrato', 'm145' => 'Modelo 145', 'certificado' => 'Certificado de empresa'];
  if ($tipo === 'contrato') {
      $todas = Nomina::clausulas($P);
      $cl = array_values(array_filter($todas, fn ($k) => in_array($k, $w['claus'] ?? [], true), ARRAY_FILTER_USE_KEY));
      $texto = trim((string) ($w['contratoTexto'] ?? '')) ?: Nomina::plantillaDe($P, $code);
      // Modelo oficial (estructura del SEPE); el texto propio de Parámetros de contrato, si se ha cambiado, va como cláusulas adicionales
      $extraTxt = trim($texto) !== trim(Nomina::PLANTILLA_CONTRATO) ? Nomina::renderContrato($texto, Nomina::valoresContrato($w, $c, $P))[0] : '';
      $body = view('laboral._contrato-oficial', ['c' => $c, 'w' => $w, 'P' => $P, 'rep' => $rep, 'cl' => $cl, 'extraTxt' => $extraTxt,
          'firmaImg' => $firmaUrl ? '<img src="'.e($firmaUrl).'" style="max-height:56px;max-width:180px;margin:0 auto">' : ''])->render();
  } elseif ($tipo === 'copia') {
      $body = '<h1>COPIA BÁSICA DEL CONTRATO DE TRABAJO</h1><p>Artículo 8.4 del Estatuto de los Trabajadores. No incluye el DNI, el domicilio, el estado civil ni otros datos que puedan afectar a la intimidad personal.</p>'
        .$kv([['Empresa', $c->nombre], ['CIF', $c->nif], ['Trabajador', $w['nombre']], ['Modalidad', $w['contrato']], ['Fecha de inicio', Formato::fecha($w['alta'])], ['Fecha de fin', $temp ? Formato::fecha($w['fin']) : 'Indefinido'], ['Categoría', $w['convCat'] ?: $w['cat']], ['Jornada', Formato::num($w['jor'], 0).' % · '.$w['horas'].' h/semana'], ['Retribución bruta anual', Formato::eur($anual)], ['Convenio colectivo', $conv]])
        .'<p>Entregada a la representación legal de los trabajadores en el plazo de 10 días desde la formalización del contrato y enviada al Servicio Público de Empleo.</p>'.$firmas;
  } elseif ($tipo === 'comunicacion') {
      $body = '<h1>COMUNICACIÓN DEL CONTENIDO DEL CONTRATO DE TRABAJO</h1><p>Datos para Contrat@ (SEPE).</p>'
        .$kv(array_merge(array_slice($empRows, 0, 3), array_slice($trRows, 0, 3), [['Código de contrato', $code], ['Fecha de inicio', Formato::fecha($w['alta'])], ['Fecha de fin', $temp ? Formato::fecha($w['fin']) : '—'], ['Jornada', Formato::num($w['jor'], 0).' %'], ['Ocupación (CNO-11)', $w['cno']], ['Nivel formativo', $w['nivForm']], ['Municipio del centro de trabajo', $w['centro']]]))
        .'<p>Estado: '.$v($extra ?: 'Pendiente de comunicar').'</p>';
  } elseif ($tipo === 'certificado') {
      $body = '<h1>CERTIFICADO DE EMPRESA</h1><p>Datos para Certific@2 (SEPE) a efectos de la prestación por desempleo.</p>'
        .$kv(array_merge(array_slice($empRows, 0, 3), array_slice($trRows, 0, 3), [['Fecha de alta', Formato::fecha($w['alta'])], ['Fecha de baja', Formato::fecha($w['baja'])], ['Causa de la baja', $extra['causa'] ?? ''], ['Contrato', $w['contrato']]]))
        .'<h2>Bases de cotización por desempleo de los últimos 180 días</h2><table>'.implode('', array_map(fn ($r) => '<tr><td class="l">'.e($r[0]).'</td><td>'.Formato::eur($r[1]).'</td></tr>', $extra['bases'] ?? [])).'</table>'.$firmas;
  } else {
      $M = $w['m145'];
      $SIT = ['1' => '1 · Soltero, viudo, divorciado o separado con hijos menores a cargo exclusivo', '2' => '2 · Casado y no separado con cónyuge con rentas inferiores a 1.500 €', '3' => '3 · Otras situaciones'];
      $body = '<h1>MODELO 145 · COMUNICACIÓN DE DATOS AL PAGADOR</h1><p>Retenciones sobre rendimientos del trabajo. Artículo 88 del Reglamento del IRPF.</p><h2>1. Datos del perceptor</h2>'
        .$kv([['NIF', $w['nif']], ['Apellidos y nombre', $w['nombre']], ['Año de nacimiento', substr((string) $w['fnac'], 0, 4)], ['Situación familiar', $SIT[(string) $M['sit']] ?? $SIT['3']], ['NIF del cónyuge', (string) $M['sit'] === '2' ? $M['nifCony'] : '—'], ['Discapacidad', $w['disc']], ['Movilidad geográfica', $M['movil'] ? 'Sí' : 'No']])
        .'<h2>2. Hijos y otros descendientes menores de 25 años</h2><table>'.($M['hijos'] ? implode('', array_map(fn ($h) => '<tr><td class="l">Año de nacimiento '.e($h['anio']).'</td><td>Cómputo '.($h['entero'] === false ? 'por mitad' : 'por entero').' · discapacidad: '.e($h['disc']).'</td></tr>', $M['hijos'])) : '<tr><td>Sin descendientes</td></tr>').'</table>'
        .'<h2>3. Ascendientes mayores de 65 años o con discapacidad</h2><table>'.($M['asc'] ? implode('', array_map(fn ($x) => '<tr><td class="l">Año de nacimiento '.e($x['anio']).'</td><td>Discapacidad: '.e($x['disc']).' · convive con '.e($x['conv'] ?? 1).' descendientes</td></tr>', $M['asc'])) : '<tr><td>Sin ascendientes</td></tr>').'</table>'
        .'<h2>4. Pensiones y anualidades</h2>'.$kv([['Pensión compensatoria al cónyuge', $M['pension'] ? $M['pension'].' €' : '—'], ['Anualidades por alimentos a hijos', $M['alimentos'] ? $M['alimentos'].' €' : '—']])
        .'<h2>5. Vivienda habitual</h2><p>'.($M['vivienda'] ? 'Efectúa pagos por préstamo para la adquisición de su vivienda habitual anterior a 2013 con derecho a deducción.' : 'No comunica pagos por préstamo de vivienda habitual.').'</p><p>'.$v($lugar).', '.Formato::fecha($M['fecha'] ?: \App\Support\Plazos::hoy()).'</p>'
        .'<table style="margin-top:30px"><tr><td style="border:0">Firma del perceptor<div style="height:60px"></div>'.e($w['nombre']).'</td><td style="border:0">Recibido por la empresa<div style="height:60px"></div>'.e($c->nombre).'</td></tr></table>';
  }
@endphp
<!DOCTYPE html>
<html lang="es"><head><meta charset="utf-8"><title>{{ $titulos[$tipo] }} · {{ $w['nombre'] }}</title>
<style>body{font:12px/1.55 Georgia,serif;margin:40px;color:#000;background:#fff}h1{font:700 15px Helvetica,Arial,sans-serif;text-align:center;margin:0 0 14px}h2{font:700 12px Helvetica,Arial,sans-serif;margin:14px 0 4px;text-transform:uppercase}table{width:100%;border-collapse:collapse}td{border:1px solid #000;padding:4px 6px;vertical-align:top;font:11px Helvetica,Arial,sans-serif}.l{width:36%;background:#f2f2f2}@page{size:A4;margin:0}@media print{html,body{background:#fff}body{margin:0!important;max-width:none!important;padding:15mm!important;box-decoration-break:clone;-webkit-box-decoration-break:clone}}</style>
</head><body>{!! $body !!}
<script src="{{ asset('js/imprimir.js') }}"></script>
</body></html>
