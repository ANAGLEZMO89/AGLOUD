@extends('layouts.app')
@php
  use App\Services\Nomina;
  use App\Support\Formato;
  use Illuminate\Support\HtmlString;
  $cod = Nomina::codigo($d['contrato']);
  $temp = Nomina::temporal($d);
  $PROVS = config('agcloud.provincias') ?? [];
  $hab = array_values(array_filter(Nomina::CONTRATOS, fn ($x) => in_array(Nomina::codigo($x), $P['hab'], true) || $x === $d['contrato']));
  $cents = $centros; if ($d['centro'] && ! in_array($d['centro'], $cents, true)) array_unshift($cents, $d['centro']); if (! $cents) $cents = ['Centro principal'];
  $cats = array_column($P['conv']['cats'], 'cat'); if ($d['convCat'] && ! in_array($d['convCat'], $cats, true)) array_unshift($cats, $d['convCat']);
  $catsData = collect($P['conv']['cats'])->mapWithKeys(fn ($x) => [$x['cat'] => [Formato::num($x['base']), Formato::num($x['plus'] ?? 0), (string) $x['grupo']]])->all();
  $val = fn ($k) => is_float($d[$k] ?? null) || is_int($d[$k] ?? null) ? (in_array($k, ['salBase', 'salDia', 'plus', 'irpf', 'jor'], true) ? Formato::num($d[$k], in_array($k, ['jor'], true) ? 0 : 2) : (string) $d[$k]) : (string) ($d[$k] ?? '');
  // Campo de la ficha (fb del prototipo)
  $fb = function (string $k, string $l, $type = 'text', bool $wide = false, string $hint = '', array $attr = []) use ($d, $val) {
      $a = ''; foreach ($attr as $ak => $av) { $a .= ' '.$ak.'="'.e($av).'"'; }
      $h = '<div class="lb-f" style="grid-column:'.($wide ? '1 / -1' : 'auto').'"'.$a.'>';
      if ($type === 'bool') {
          $h .= '<label class="lb-chk"><input type="checkbox" name="'.$k.'" value="1"'.(! empty($d[$k]) ? ' checked' : '').'><span>'.e($l).'</span></label>';
      } elseif (is_array($type)) {
          $h .= '<span class="lb-l" data-lbl>'.e($l).'</span><select class="sel lb-in" name="'.$k.'">';
          foreach ($type as $o) { [$ov, $ol] = is_array($o) ? $o : [$o, $o]; $h .= '<option value="'.e($ov).'"'.((string) $ov === $val($k) ? ' selected' : '').'>'.e($ol).'</option>'; }
          $h .= '</select>';
      } else {
          $h .= '<span class="lb-l" data-lbl>'.e($l).'</span><input class="inp lb-in" '.($type === 'date' ? 'type="date"' : 'type="text"'.($type === 'num' ? ' inputmode="decimal"' : '')).' name="'.$k.'" value="'.e($val($k)).'">';
      }
      if ($hint) { $h .= '<span class="lb-h">'.e($hint).'</span>'; }
      return new HtmlString($h.'</div>');
  };
  // Secciones: [id, título, régimen al que aplica (gen = cuenta ajena, aut = autónomo, '' = los dos)]
  $aut = Nomina::esAutonomo($d);
  $d['autAlta'] = $d['alta'];
  $SECS = [['personal', 'Datos personales', ''], ['afil', 'Afiliación', ''], ['aut', 'Autónomo (RETA)', 'aut'], ['contrato', 'Contrato', 'gen'], ['retrib', 'Retribución', 'gen'], ['irpf', 'IRPF · modelo 145', 'gen']];
  if (! $nuevo) $SECS[] = ['ss', 'Seguridad Social y SEPE', 'gen'];
  $vis = fn ($r) => $r === '' || ($r === 'aut') === $aut;
  if (! collect($SECS)->contains(fn ($x) => $x[0] === $sec && $vis($x[2]))) $sec = 'personal';
  $anual = Nomina::anual($d);
  $catC = collect($P['conv']['cats'])->firstWhere('cat', $d['convCat']);
  $M = $d['m145'];
  $DISC = ['No', '33 % a 64 %', '65 % o más'];
  $legalOk = collect($legal)->every(fn ($x) => $x['ok']);
  $ss = [['TGSS · Sistema RED', $redOk, [['idc', 'Descargar el IDC', 'Informe de datos para la cotización'], ['ta', 'Descargar la resolución de alta (TA)', 'Alta del NAF en el CCC de la empresa'], ['alta', 'Comunicar el alta', 'Antes del inicio de la actividad'], ['var', 'Cambiar datos de afiliación', 'Contrato, jornada, grupo u ocupación']]],
    ['SEPE · Contrat@ y Certific@2', $sepeOk, [['contrata', 'Comunicar el contrato', 'Plazo de 10 días desde la firma'], ['comunicacion', 'Descargar la comunicación del contrato', 'Resumen de los datos enviados'], ['copia', 'Copia básica del contrato', 'Para la representación legal y el SEPE'], ['cert', 'Certificado de empresa', 'Tras la baja · prestación por desempleo']]],
    ['Documentos', true, [['texto', 'Editar el texto del contrato', ! empty($d['contratoTexto']) ? 'Texto propio de este trabajador' : 'Parte de la plantilla de su modalidad'], ['contrato', 'Contrato de trabajo en PDF', 'Con las cláusulas y la firma de la empresa'], ['m145', 'Modelo 145 en PDF', 'Comunicación de datos al pagador']]]];
  $locales = ['comunicacion', 'copia', 'contrato', 'm145'];
@endphp
@section('content')
<div class="page" style="gap:16px">
  @include('laboral._cabecera')
  <div style="display:flex;flex-wrap:wrap;align-items:center;gap:12px">
    <a class="btn white" href="{{ route('laboral.index') }}">← Trabajadores</a>
    <div style="display:flex;flex-direction:column;gap:2px;min-width:0"><span style="font-size:18px;font-weight:700">{{ $nuevo ? 'Alta de trabajador' : $d['nombre'] }}</span><span style="font-size:12.5px;color:#6A6A65">{{ $nuevo ? 'Elige el régimen y completa los apartados antes de comunicar el alta' : ($aut ? 'Autónomo (RETA) · '.$d['autTipo'] : $d['contrato']).' · alta '.Formato::fecha($d['alta']).($t->estado === 'Baja' ? ' · baja '.Formato::fecha($t->baja) : '') }}</span></div>
    <button class="btn lg" form="lbf" type="submit" style="margin-left:auto"><span style="width:8px;height:8px;border-radius:50%;background:#C8F031"></span>Guardar ficha</button>
  </div>
  <div style="display:flex;flex-wrap:wrap;gap:16px;align-items:flex-start" data-lb-ficha>
    <div class="card lb-secs" style="flex:0 1 230px;padding:8px;gap:2px">
      @foreach($SECS as [$v, $l, $r])<button type="button" class="lb-sec {{ $sec === $v ? 'on' : '' }}" data-lb-sec="{{ $v }}" data-lb-reg="{{ $r }}" @unless($vis($r)) hidden @endunless>{{ $l }}</button>@endforeach
      @if(! $nuevo && auth()->user()->esAdmin())
        <form method="POST" action="{{ route('laboral.eliminar', $t->id) }}" data-confirm="¿Eliminar a {{ $d['nombre'] }} de la plantilla? Se borran su ficha y sus movimientos. Si ha trabajado, es mejor tramitar la baja." style="margin-top:8px;border-top:1px solid #F1F1EC;padding-top:8px">@csrf @method('DELETE')<button class="lb-sec" type="submit" style="color:#8A1C1C">Eliminar trabajador</button></form>
      @endif
    </div>
    <div style="flex:1 1 560px;display:flex;flex-direction:column;gap:16px;min-width:0">
      <form id="lbf" method="POST" action="{{ route('laboral.guardar', $nuevo ? 'nuevo' : $t->id) }}" style="display:contents" data-lb-cats='@json($catsData)' @if($nuevo) data-lb-clausdef='@json(collect($P['hab'])->mapWithKeys(fn ($h) => [$h => Nomina::clausulasDe($P, $h)]))' @endif>
        @csrf
        <input type="hidden" name="_ficha" value="1"><input type="hidden" name="_sec" value="{{ $sec }}" data-lb-secin>

        {{-- Datos personales --}}
        <div class="card lb-panel" data-lb-panel="personal" @if($sec !== 'personal') hidden @endif><div class="lb-fields">
          {{ $fb('regimen', 'Régimen', [['General', 'Cuenta ajena (Régimen General)'], ['Autónomo', 'Autónomo (RETA)']], true, 'Un autónomo no tiene contrato ni nómina: cotiza en el RETA con su base y cuota.', ['data-lb-regimen' => '1']) }}
          {{ $fb('nom1', 'Nombre') }}{{ $fb('ape1', 'Primer apellido') }}{{ $fb('ape2', 'Segundo apellido') }}{{ $fb('tipoDoc', 'Tipo de documento', ['DNI', 'NIE', 'Pasaporte']) }}
          {{ $fb('nif', 'Nº de documento') }}{{ $fb('fnac', 'Fecha de nacimiento', 'date') }}{{ $fb('sexo', 'Sexo', ['Mujer', 'Hombre']) }}{{ $fb('nac', 'Nacionalidad') }}
          {{ $fb('ecivil', 'Estado civil', ['Soltero/a', 'Casado/a', 'Viudo/a', 'Divorciado/a', 'Separado/a', 'Pareja de hecho']) }}{{ $fb('dom', 'Domicilio', 'text', true) }}
          {{ $fb('cp', 'Código postal') }}{{ $fb('mun', 'Municipio') }}{{ $PROVS ? $fb('prov', 'Provincia', $PROVS) : $fb('prov', 'Provincia') }}{{ $fb('tel', 'Teléfono') }}{{ $fb('email', 'Correo electrónico') }}
          {{ $fb('nivForm', 'Nivel formativo', Nomina::NIVEL_FORMATIVO) }}{{ $fb('iban', 'IBAN para la nómina') }}{{ $fb('disc', 'Discapacidad', ['Sin discapacidad', '33 % a 64 %', '65 % o más']) }}
        </div></div>

        {{-- Afiliación --}}
        <div class="card lb-panel" data-lb-panel="afil" @if($sec !== 'afil') hidden @endif><div class="lb-fields">
          {{ $fb('naf', 'Nº de afiliación a la Seguridad Social', 'text', false, '12 dígitos') }}{{ $fb('ccc', 'Código de cuenta de cotización', array_values(array_unique(array_filter(array_merge([$d['ccc']], $P['cccs'])))) ?: [''], false, $P['cccs'] ? '' : 'Añade los CCC en Parámetros de laboral o en los centros de trabajo del cliente') }}
          {{ $fb('altaSS', 'Fecha de alta en la Seguridad Social', 'date') }}{{ $fb('grupo', 'Grupo de cotización', array_map(fn ($x, $i) => [(string) ($i + 1), $x], Nomina::GRUPOS, array_keys(Nomina::GRUPOS))) }}
          {{ $fb('cno', 'Código de ocupación (CNO-11)', 'text', false, '4 dígitos') }}
          {{ $fb('epig', 'Ocupación a efectos de AT y EP', ['Tarifa general por CNAE', 'a · Personal en trabajos exclusivos de oficina', 'd · Conductores de vehículos de transporte de mercancías', 'f · Conductores de vehículos de transporte de personas', 'g · Personal de limpieza', 'h · Vigilantes y guardas']) }}
          {{ $fb('relEsp', 'Relación laboral', ['Común', 'Especial de alta dirección', 'Especial de servicio del hogar', 'Otra relación laboral especial']) }}
          {{ $fb('agrario', 'Sistema Especial Agrario (jornadas reales)', 'bool', true) }}
        </div></div>

        {{-- Autónomo (RETA) --}}
        <div class="lb-panel" data-lb-panel="aut" @if($sec !== 'aut') hidden @endif style="display:flex;flex-direction:column;gap:16px">
          <input type="hidden" name="_aut" value="1">
          <div class="card"><div class="lb-fields">
            {{ $fb('autTipo', 'Tipo de autónomo', Nomina::TIPOS_AUTONOMO, true) }}
            {{ $fb('autAlta', 'Fecha de alta en el RETA', 'date') }}{{ $fb('iae', 'Actividad (epígrafe IAE / CNAE)') }}
            {{ $fb('autRend', 'Rendimientos netos mensuales previstos (€)', 'num', false, 'Para elegir el tramo de cotización por ingresos reales') }}
            {{ $fb('autBase', 'Base de cotización mensual elegida (€)', 'num') }}{{ $fb('autCuota', 'Cuota mensual (€)', 'num', false, 'La que figura en la resolución de la TGSS') }}
            {{ $fb('autMutua', 'Mutua colaboradora') }}
            {{ $fb('autCese', 'Cotiza por cese de actividad', 'bool') }}{{ $fb('autTarifa', 'Tarifa plana', 'bool') }}{{ $fb('autTarifaFin', 'Fin de la tarifa plana', 'date') }}
          </div></div>
          <p style="margin:0;font-size:12.5px;color:#6A6A65;line-height:1.5">Los autónomos no tienen contrato ni nómina: no salen en Integral de nóminas, seguros sociales, remesas, modelos 111/145/190 ni control horario. La base y la cuota se indican como constan en la resolución de alta de la TGSS.</p>
        </div>

        {{-- Contrato --}}
        <div class="lb-panel" data-lb-panel="contrato" @if($sec !== 'contrato') hidden @endif style="display:flex;flex-direction:column;gap:16px">
          <div class="card"><div class="lb-fields">
            {{ $fb('contrato', 'Tipo de contrato', $hab, true, '', ['data-lb-contrato' => '1']) }}{{ $fb('alta', 'Fecha de inicio', 'date') }}
            {{ $fb('fin', 'Fecha de fin', 'date', false, '', ['data-lb-temp' => '1']) }}
            {{ $fb('causa', preg_match('/^(410|510)/', $cod) ? 'Causa de la sustitución' : (preg_match('/^(42|52)/', $cod) ? 'Titulación y puesto formativo' : 'Causa concreta de la temporalidad'), 'text', true, 'Obligatorio: describe la causa y su conexión con la duración (art. 15 ET)', ['data-lb-temp' => '1', 'data-lb-causa' => '1']) }}
            {{ $fb('sustituido', 'Persona sustituida · NIF y nombre', 'text', true, '', ['data-lb-sust' => '1']) }}
            {{ $fb('jor', 'Jornada %', 'num', false, Nomina::parcial($d) ? 'Tiempo parcial: menos del 100 %' : 'Tiempo completo') }}{{ $fb('horas', 'Horas semanales') }}{{ $fb('horario', 'Distribución del horario', 'text', true) }}
            {{ $fb('centro', 'Centro de trabajo', $cents) }}{{ $fb('convCat', 'Categoría según el convenio', $cats ?: ['Sin categorías en el convenio'], false, 'Rellena el salario y el grupo') }}
            {{ $fb('prueba', 'Periodo de prueba', ['Sin periodo de prueba', '15 días', '1 mes', '2 meses', '3 meses', '6 meses']) }}{{ $fb('vac', 'Vacaciones (días naturales)') }}
          </div></div>
          <div class="card">
            <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap"><span style="font-size:15px;font-weight:700">Comprobación legal del contrato</span>@if($legalOk)<span class="lb-pill" style="background:#E6F6A8;color:#2E3A00;font-size:12px;padding:4px 10px">Todo correcto</span>@endif</div>
            <div style="display:flex;flex-direction:column">
              @foreach($legal as $lg)<div style="display:flex;align-items:center;gap:10px;padding:7px 0;border-top:1px solid #F1F1EC;font-size:13.5px">@if($lg['ok'])<span class="lb-ok">✓</span>@else<span class="lb-ko">!</span>@endif<span>{{ $lg['l'] }}</span></div>@endforeach
            </div>
            <span style="font-size:12px;color:#6A6A65">La comprobación se actualiza al guardar la ficha.</span>
          </div>
          <div class="card">
            <span style="font-size:15px;font-weight:700">Cláusulas del contrato</span>
            @foreach(Nomina::clausulas($P) as $k => [$l, $txt])
              <label style="display:flex;align-items:flex-start;gap:10px;cursor:pointer"><input type="checkbox" name="claus[]" value="{{ $k }}" @checked(in_array($k, $d['claus'] ?? [], true)) style="width:18px;height:18px;margin:2px 0 0;accent-color:#0B0B0B;flex:none"><span style="display:flex;flex-direction:column;gap:2px"><span style="font-size:13.5px;font-weight:600">{{ $l }}</span><span style="font-size:12.5px;color:#6A6A65;line-height:1.45">{{ $txt }}</span></span></label>
            @endforeach
          </div>
        </div>

        {{-- Retribución --}}
        <div class="lb-panel" data-lb-panel="retrib" @if($sec !== 'retrib') hidden @endif style="display:flex;flex-direction:column;gap:16px">
          <div class="card"><div class="lb-fields">
            {{ $fb('salTipo', 'Forma de retribución', [['Mensual', 'Salario mensual'], ['Diario', 'Salario diario (por día natural)']]) }}
            {{ $fb('salBase', 'Salario base mensual (€)', 'num', false, '', Nomina::diario($d) ? ['data-lb-mens' => '1', 'hidden' => 'hidden'] : ['data-lb-mens' => '1']) }}
            {{ $fb('salDia', 'Salario base diario (€/día)', 'num', false, 'En cada nómina se multiplica por los días naturales del mes', Nomina::diario($d) ? ['data-lb-dia' => '1'] : ['data-lb-dia' => '1', 'hidden' => 'hidden']) }}
            {{ $fb('diasExtra', 'Días de salario por paga extra', 'num', false, 'Lo que diga el convenio (habitual: 30)', Nomina::diario($d) ? ['data-lb-dia' => '1'] : ['data-lb-dia' => '1', 'hidden' => 'hidden']) }}
            {{ $fb('plus', 'Complementos salariales mensuales (€)', 'num') }}{{ $fb('pagas', 'Número de pagas', ['14', '12']) }}{{ $fb('prorr', 'Prorratear las pagas extraordinarias', 'bool') }}
          </div></div>
          <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:14px">
            <div style="background:#0B0B0B;color:#F4F4F0;border-radius:18px;padding:16px 18px;display:flex;flex-direction:column;gap:6px"><span style="font-size:13px;color:#BDBDB7">Bruto anual</span><span style="font:700 26px/1.1 var(--mono);color:#C8F031" data-lb-anual>{{ Formato::eur($anual) }}</span><span style="font-size:12.5px">{{ $d['pagas'] }} pagas{{ $d['prorr'] ? ' · extras prorrateadas' : '' }}{{ $catC ? ' · convenio: '.Formato::eur((float) $catC['base']).' de salario base' : '' }}</span></div>
            <div class="card" style="gap:6px;padding:16px 18px"><span style="font-size:13px;color:#6A6A65">Devengo mensual ordinario</span><span style="font:600 24px/1.1 var(--mono)" data-lb-mensual>{{ Formato::eur((Nomina::diario($d) ? Nomina::n($d['salDia']) * 30 : Nomina::n($d['salBase'])) + Nomina::n($d['plus']) + ($d['prorr'] && (int) $d['pagas'] === 14 ? Nomina::pagaExtra($d) * 2 / 12 : 0)) }}</span><span style="font-size:12.5px;color:#3C3C3C">Salario base y complementos{{ Nomina::diario($d) ? ' (mes de 30 días)' : '' }}</span></div>
          </div>
        </div>

        {{-- IRPF · modelo 145 --}}
        <div class="lb-panel" data-lb-panel="irpf" @if($sec !== 'irpf') hidden @endif style="display:flex;flex-direction:column;gap:16px">
          <div class="card"><div class="lb-fields">
            {{ $fb('irpfModo', 'Cálculo del IRPF', [['auto', 'Automático con el algoritmo de la AEAT'], ['fijo', 'Tipo fijo']], true) }}
            {{ $fb('irpf', 'Tipo fijo de retención %', 'num', false, 'Mínimo 2 % en contratos de duración inferior a un año', ['data-lb-fijo' => '1']) }}
          </div></div>
          <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,300px),1fr));gap:16px;align-items:start">
            <div style="background:#0B0B0B;color:#F4F4F0;border-radius:18px;padding:18px 20px;display:flex;flex-direction:column;gap:8px">
              <span style="font-size:13px;color:#BDBDB7">Tipo de retención</span><span style="font:700 32px/1.1 var(--mono);color:#C8F031">{{ Formato::num($ir ? $ir['tipo'] : $d['irpf']) }} %</span>
              @if($ir)
                <div style="display:flex;flex-direction:column;padding-top:6px">
                  @foreach([['Retribución anual prevista', $ir['RT']], ['Cotizaciones a la Seguridad Social', $ir['SS']], ['Reducción por rendimientos del trabajo', $ir['red']], ['Otros gastos deducibles', $ir['gastos']], ['Base para calcular el tipo', $ir['base']], ['Mínimo personal y familiar', $ir['minimo']], ['Cuota anual de retención', $ir['cuota']], ['Límite excluido de retención', $ir['lim']]] as [$l, $v])
                    <div style="display:flex;justify-content:space-between;gap:10px;padding:5px 0;border-top:1px solid #2A2A2A;font-size:12.5px"><span style="color:#BDBDB7">{{ $l }}</span><span style="font-family:var(--mono)">{{ Formato::eur($v) }}</span></div>
                  @endforeach
                </div>
                <span style="font-size:12px;color:#BDBDB7;line-height:1.45">{{ $ir['exento'] ? 'La retribución no supera el límite excluido de retención para su situación familiar.' : 'Se recalcula cada mes con los cambios de la nómina y regulariza lo ya retenido.' }}</span>
              @endif
            </div>
            <div class="card">
              <span style="font-size:15px;font-weight:700">Modelo 145 · datos del perceptor</span>
              <label class="lb-f"><span class="lb-l">Situación familiar</span><select class="sel lb-in" name="m145[sit]" data-lb-sit>@foreach([['1', '1 · Soltero, viudo, divorciado o separado con hijos a cargo exclusivo'], ['2', '2 · Casado con cónyuge con rentas inferiores a 1.500 €'], ['3', '3 · Otras situaciones']] as [$v, $l])<option value="{{ $v }}" @selected((string) $M['sit'] === $v)>{{ $l }}</option>@endforeach</select></label>
              <label class="lb-f" data-lb-cony @if((string) $M['sit'] !== '2') hidden @endif><span class="lb-l">NIF del cónyuge</span><input class="inp lb-in" name="m145[nifCony]" value="{{ $M['nifCony'] }}"></label>
              <label class="lb-chk"><input type="checkbox" name="m145[movil]" value="1" @checked($M['movil'])>Ha aceptado un puesto que exige cambiar de municipio (movilidad geográfica)</label>
              <label class="lb-chk"><input type="checkbox" name="m145[vivienda]" value="1" @checked($M['vivienda'])>Paga un préstamo de vivienda habitual anterior a 2013 con deducción</label>
              <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,160px),1fr));gap:12px">
                <label class="lb-f"><span class="lb-l">Pensión compensatoria al cónyuge (€/año)</span><input class="inp lb-in" name="m145[pension]" value="{{ $M['pension'] }}" inputmode="decimal"></label>
                <label class="lb-f"><span class="lb-l">Anualidades por alimentos (€/año)</span><input class="inp lb-in" name="m145[alimentos]" value="{{ $M['alimentos'] }}" inputmode="decimal"></label>
                <label class="lb-f"><span class="lb-l">Fecha de la comunicación</span><input class="inp lb-in" type="date" name="m145[fecha]" value="{{ $M['fecha'] ?: \App\Support\Plazos::hoy()->toDateString() }}"></label>
              </div>
            </div>
            <div class="card">
              <div style="display:flex;justify-content:space-between;align-items:center;gap:10px"><span style="font-size:15px;font-weight:700">Hijos y descendientes</span><button type="button" class="btn soft" data-lb-add="hijos">+ Añadir</button></div>
              <div data-lb-list="hijos" data-n="{{ count($M['hijos']) }}" style="display:flex;flex-direction:column;gap:10px">
                @foreach($M['hijos'] as $i => $h)
                  <div class="lb-h4"><input class="inp lb-in mono" name="m145[hijos][{{ $i }}][anio]" value="{{ $h['anio'] }}" placeholder="Año nac." maxlength="4"><select class="sel lb-in" name="m145[hijos][{{ $i }}][disc]">@foreach($DISC as $o)<option value="{{ $o }}" @selected($h['disc'] === $o)>Discapacidad: {{ $o }}</option>@endforeach</select><label style="display:flex;align-items:center;gap:6px;font-size:12.5px;white-space:nowrap"><input type="checkbox" name="m145[hijos][{{ $i }}][entero]" value="1" @checked($h['entero'] !== false) style="accent-color:#0B0B0B">Por entero</label><button type="button" class="lb-del" data-lb-del aria-label="Quitar">×</button></div>
                @endforeach
              </div>
              <template data-lb-tpl="hijos"><div class="lb-h4"><input class="inp lb-in mono" name="m145[hijos][__][anio]" placeholder="Año nac." maxlength="4"><select class="sel lb-in" name="m145[hijos][__][disc]">@foreach($DISC as $o)<option value="{{ $o }}">Discapacidad: {{ $o }}</option>@endforeach</select><label style="display:flex;align-items:center;gap:6px;font-size:12.5px;white-space:nowrap"><input type="checkbox" name="m145[hijos][__][entero]" value="1" checked style="accent-color:#0B0B0B">Por entero</label><button type="button" class="lb-del" data-lb-del aria-label="Quitar">×</button></div></template>
              <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;padding-top:6px;border-top:1px solid #F1F1EC"><span style="font-size:15px;font-weight:700">Ascendientes</span><button type="button" class="btn soft" data-lb-add="asc">+ Añadir</button></div>
              <div data-lb-list="asc" data-n="{{ count($M['asc']) }}" style="display:flex;flex-direction:column;gap:10px">
                @foreach($M['asc'] as $i => $h)
                  <div class="lb-h4 asc"><input class="inp lb-in mono" name="m145[asc][{{ $i }}][anio]" value="{{ $h['anio'] }}" placeholder="Año nac." maxlength="4"><select class="sel lb-in" name="m145[asc][{{ $i }}][disc]">@foreach($DISC as $o)<option value="{{ $o }}" @selected($h['disc'] === $o)>Discapacidad: {{ $o }}</option>@endforeach</select><input class="inp lb-in" name="m145[asc][{{ $i }}][conv]" value="{{ $h['conv'] }}" title="Descendientes con los que convive"><button type="button" class="lb-del" data-lb-del aria-label="Quitar">×</button></div>
                @endforeach
              </div>
              <template data-lb-tpl="asc"><div class="lb-h4 asc"><input class="inp lb-in mono" name="m145[asc][__][anio]" placeholder="Año nac." maxlength="4"><select class="sel lb-in" name="m145[asc][__][disc]">@foreach($DISC as $o)<option value="{{ $o }}">Discapacidad: {{ $o }}</option>@endforeach</select><input class="inp lb-in" name="m145[asc][__][conv]" value="1" title="Descendientes con los que convive"><button type="button" class="lb-del" data-lb-del aria-label="Quitar">×</button></div></template>
              <div style="display:flex;gap:10px;flex-wrap:wrap;padding-top:6px;border-top:1px solid #F1F1EC">
                @if(! $nuevo)<a class="btn" href="{{ route('laboral.documento', [$t->id, 'm145']) }}" target="_blank" rel="noopener">Descargar modelo 145</a>@else<span class="btn" style="opacity:.5" title="Guarda primero la ficha del trabajador.">Descargar modelo 145</span>@endif
                <a class="btn soft" href="{{ Nomina::URL['aeat'] }}" target="_blank" rel="noopener">Contrastar en la AEAT ↗</a>
              </div>
            </div>
          </div>
        </div>
      </form>

      {{-- Seguridad Social y SEPE --}}
      @if(! $nuevo)
      <div class="lb-panel" data-lb-panel="ss" @if($sec !== 'ss') hidden @endif style="display:flex;flex-direction:column;gap:16px">
        @foreach($ss as [$g, $ok, $items])
          <div class="card">
            <div style="display:flex;justify-content:space-between;align-items:center;gap:10px"><span style="font-size:15px;font-weight:700">{{ $g }}</span><span class="lb-pill" style="background:{{ $ok ? '#E6F6A8' : '#FCEBCB' }};color:{{ $ok ? '#2E3A00' : '#6E4300' }}">{{ $ok ? 'Conectado' : 'Sin configurar' }}</span></div>
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(min(100%,240px),1fr));gap:10px">
              @foreach($items as [$k, $l, $sb])
                @php $last = collect($docs)->firstWhere('k', $k); $sub = $last ? $last['l'].' el '.Formato::fecha($last['fecha']) : $sb; @endphp
                @if($k === 'texto')
                  <a class="lb-act" href="{{ route('laboral.contrato.texto', $t->id) }}"><span class="lb-act-t">{{ $l }}</span><span class="lb-act-s">{{ $sb }}</span></a>
                @elseif(in_array($k, $locales, true))
                  <a class="lb-act" href="{{ route('laboral.documento', [$t->id, $k === 'comunicacion' ? 'comunicacion' : $k]) }}" target="_blank" rel="noopener"><span class="lb-act-t">{{ $l }}</span><span class="lb-act-s">{{ $sub }}</span></a>
                @else
                  <form method="POST" action="{{ route('laboral.accion', [$t->id, $k]) }}" @if(in_array($k, ['idc', 'ta', 'alta', 'var'], true) ? $redOk : $sepeOk) data-abrir="{{ in_array($k, ['idc', 'ta', 'alta', 'var'], true) ? Nomina::URL['red'] : Nomina::URL['sepe'] }}" @endif>@csrf
                    <button type="submit" class="lb-act" style="width:100%;text-align:left"><span class="lb-act-t">{{ $l }}@if($last)<span style="color:#2E3A00">✓</span>@endif</span><span class="lb-act-s">{{ $sub }}</span></button>
                  </form>
                  @if($k === 'cert' && $t->baja)<a href="{{ route('laboral.documento', [$t->id, 'certificado']) }}" target="_blank" rel="noopener" style="font-size:12.5px;font-weight:600;align-self:center">Ver el certificado de empresa ↗</a>@endif
                @endif
              @endforeach
            </div>
          </div>
        @endforeach
        <p style="margin:0;font-size:12px;color:#6A6A65;line-height:1.45">Los trámites con la TGSS y el SEPE se hacen en su sede con el certificado del autorizado; AG Cloud abre la sede y deja constancia de lo hecho. No se envía ningún fichero.</p>
      </div>
      @endif
    </div>
  </div>
</div>
@endsection
@section('overlay')
@if($con) @include('laboral._conexion') @endif
@endsection
