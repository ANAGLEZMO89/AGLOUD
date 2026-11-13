@extends('layouts.app')
@php
  use App\Services\Nomina;
  use App\Support\Formato;
  $o = fn ($k, $def) => old($k, $def);
  $conv = $P['conv'];
  $cats = old('_enviado') ? array_values((array) old('cats', [])) : $conv['cats'];
@endphp
@section('content')
<div class="page" style="gap:16px">
  @include('laboral._cabecera')
  @php $prop = $P['convPropuesta'] ?? null; $bajo = \App\Http\Controllers\LaboralGestionController::bajoConvenio($c, $conv['cats'] ?? []); @endphp
  @if($prop)
    @php
      $actCats = collect($conv['cats'] ?? [])->keyBy('cat'); $prCats = collect($prop['cats'])->keyBy('cat');
      $nuevas = $prCats->keys()->diff($actCats->keys()); $quitadas = $actCats->keys()->diff($prCats->keys());
      $cambian = $prCats->filter(fn ($x, $k) => $actCats->has($k) && (abs((float) $actCats[$k]['base'] - (float) $x['base']) > 0.004 || ($actCats[$k]['unidad'] ?? 'mes') !== $x['unidad'] || (string) $actCats[$k]['grupo'] !== (string) $x['grupo']));
      $afect = \App\Models\Trabajador::where('cliente_id', $c->id)->where('estado', 'Alta')->get()->map(fn ($t) => $t->datos ?? [])->filter(fn ($d) => ($d['convCat'] ?? '') !== '');
      $sinCat = $afect->filter(fn ($d) => ! $prCats->has($d['convCat']));
      $dash = fn ($v) => $v === null || $v === '' || $v === [] ? 'No localizado' : $v;
    @endphp
    <div class="card" style="gap:12px;border:2px solid #E0A33A" id="propuesta">
      <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:10px;flex-wrap:wrap"><div style="display:flex;flex-direction:column;gap:2px"><span style="font-size:15px;font-weight:700">Propuesta pendiente de revisar</span><span style="font-size:12.5px;color:#6A6A65">Leída el {{ Formato::fecha(substr($prop['_meta']['fecha'] ?? '', 0, 10)) }} · {{ $prop['_meta']['archivo'] ?? '' }}{{ ! empty($prop['anioTablas']) ? ' · tablas de '.$prop['anioTablas'] : '' }}. Todavía no se ha aplicado nada.</span></div></div>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,260px),1fr));gap:10px;font-size:13px;line-height:1.5">
        <div><b>Identificación</b><br>{{ $dash($prop['nombre']) }}<br><span class="mono">{{ $dash($prop['codigo']) }}</span> · {{ $dash($prop['vigencia']) }}</div>
        <div><b>Categorías</b> ({{ count($prop['cats']) }})<br>@if($nuevas->count())Nuevas: {{ $nuevas->implode(', ') }}<br>@endif @if($cambian->count())Cambian: {{ $cambian->map(fn ($x, $k) => $k.' '.Formato::num($actCats[$k]['base']).' → '.Formato::num($x['base']).($x['unidad'] === 'dia' ? ' €/día' : ' €/mes'))->implode(' · ') }}<br>@endif @if($quitadas->count())<span style="color:#8A1C1C">Desaparecen: {{ $quitadas->implode(', ') }}</span>@endif @if(! $nuevas->count() && ! $cambian->count() && ! $quitadas->count())Sin cambios en las tablas @endif</div>
        <div><b>Pagas extra</b><br>{{ $prop['extras'] ? collect($prop['extras'])->map(fn ($x) => $x['nombre'].' ('.\App\Support\Formato::mesNombre($x['mes']).')')->implode(', ') : 'No localizadas en el texto' }}</div>
        <div><b>Antigüedad</b><br>{{ $prop['antig'] ? 'Cada '.$prop['antig']['periodo'].' años · '.($prop['antig']['tipo'] === 'porcentaje' ? Formato::num($prop['antig']['valor']).' %' : Formato::eur($prop['antig']['valor'])) : 'No localizada' }}</div>
        <div><b>Pluses</b><br>{{ $prop['pluses'] ? collect($prop['pluses'])->pluck('nombre')->implode(', ') : 'No localizados' }}</div>
        <div><b>IT / horas extra</b><br>{{ ($prop['it']['cc'] ?? []) || ($prop['it']['at'] ?? []) ? 'Mejora de IT localizada' : 'Mejora de IT no localizada' }} · {{ $prop['hx'] ? 'precio de hora extra localizado' : 'sin precio de hora extra' }}</div>
      </div>
      @if($prop['avisos'])<span class="note-amber"><b>Dudas de la lectura:</b> {{ implode(' · ', $prop['avisos']) }}</span>@endif
      @if($sinCat->count())<span class="note-amber"><b>Trabajadores afectados:</b> {{ $sinCat->map(fn ($d) => ($d['nombre'] ?? '').' («'.$d['convCat'].'»)')->implode(', ') }} tienen una categoría que no está en la propuesta y quedarían sin categoría válida.</span>@endif
      <span class="hint muted">«No localizado» significa que la lectura no lo encontró en el texto: no quiere decir que el convenio no lo tenga. Al aprobar, sustituye las tablas, las pagas, la antigüedad, los pluses, la IT y las horas extra del convenio actual; los salarios de los trabajadores no cambian. Después puedes corregir cualquier dato a mano.</span>
      <form method="POST" action="{{ route('laboral.convenios.propuesta') }}" style="display:flex;gap:8px;justify-content:flex-end;flex-wrap:wrap">@csrf
        <button class="btn white" type="submit" name="accion" value="descartar">Descartar</button>
        <button class="btn lime" type="submit" name="accion" value="aplicar" data-confirm-btn="¿Aplicar la propuesta al convenio de {{ $c->nombre }}? Las nóminas que no estén emitidas se calcularán con estos datos.">Aprobar y aplicar al convenio</button>
      </form>
    </div>
  @endif
  @if($bajo)
    <div class="msg warn" style="font-weight:500">Trabajadores por debajo del convenio (no se ha cambiado ningún salario): @foreach($bajo as $b)<a href="{{ route('laboral.ficha', [$b['id'], 'sec' => 'retrib']) }}">{{ $b['nombre'] }}</a> ({{ $b['cat'] }}: {{ Formato::eur($b['tiene']) }} frente a {{ Formato::eur($b['min']) }} al {{ $b['unidad'] }})@if(! $loop->last) · @endif @endforeach</div>
  @endif
  @php $est = Nomina::estadoConvenio($conv); $nOk = collect($est)->where(1, 'ok')->count(); @endphp
  <div class="card" style="gap:10px">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap"><span style="font-size:15px;font-weight:700">Estado del convenio</span><span class="lb-pill" style="background:{{ $nOk === count($est) ? '#E6F6A8' : '#FCEBCB' }};color:{{ $nOk === count($est) ? '#2E3A00' : '#6E4300' }};font-size:12px;padding:4px 10px">{{ $nOk }} de {{ count($est) }} apartados configurados</span></div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,230px),1fr));gap:8px">
      @foreach($est as [$l, $st, $t])
        <div style="display:flex;gap:8px;align-items:flex-start;padding:8px 10px;border-radius:10px;background:{{ ['ok' => '#F3F9E1', 'pendiente' => '#FBF4E6', 'falta' => '#FBE9E9'][$st] }}">
          <span aria-label="{{ ['ok' => 'Configurado', 'pendiente' => 'Pendiente', 'falta' => 'Falta'][$st] }}" style="font-weight:700;color:{{ ['ok' => '#2E3A00', 'pendiente' => '#6E4300', 'falta' => '#8A1C1C'][$st] }}">{{ ['ok' => '✓', 'pendiente' => '…', 'falta' => '!'][$st] }}</span>
          <span style="display:flex;flex-direction:column;gap:1px;min-width:0"><b style="font-size:13px">{{ $l }}</b><span style="font-size:12px;color:#3C3C3C">{{ $t }}</span></span>
        </div>
      @endforeach
    </div>
  </div>
  @php $iaOk = \App\Services\ConvenioIA::configurada(); $ia = $conv['ia'] ?? null; $art = fn ($x) => ! empty($x) ? ' ('.$x.')' : ''; @endphp
  <div class="card" style="gap:12px">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:10px;flex-wrap:wrap">
      <div style="display:flex;flex-direction:column;gap:3px;min-width:0;flex:1 1 300px"><span style="font-size:15px;font-weight:700">Leer el convenio con IA</span>
        <span style="font-size:12.5px;color:#6A6A65;line-height:1.45">La IA lee el convenio completo (con sus anexos y tablas) y <b>propone</b> las tablas salariales, las pagas extra, la antigüedad, los pluses, el complemento de IT y las horas extra. Nada se aplica hasta que revisas la propuesta y la apruebas, y nunca se cambian los salarios de la plantilla. Solo se envía el texto del convenio, no datos de trabajadores.</span></div>
      @if($iaOk)
        <form method="POST" action="{{ route('laboral.convenios.ia') }}" enctype="multipart/form-data" data-lb-ia>@csrf
          <label class="btn lime" style="cursor:pointer">Subir PDF y leer con IA<input type="file" name="pdf" accept=".pdf,application/pdf" class="hidden" data-autoupload></label>
          <span class="hint muted" style="display:block;margin-top:4px">Tarda de 1 a 3 minutos: no cierres la página.</span>
        </form>
      @endif
    </div>
    <form method="POST" action="{{ route('laboral.convenios.ia.pegar') }}" style="display:flex;flex-direction:column;gap:10px;padding-top:10px;border-top:1px solid #F1F1EC">@csrf
      <span style="font-size:13px;font-weight:700">Sin coste, con tu suscripción de Claude</span>
      <ol style="margin:0;padding-left:18px;font-size:13px;line-height:1.6;color:#3C3C3C">
        <li>Pulsa «Copiar instrucciones».</li>
        <li>Abre <a href="https://claude.ai/new" target="_blank" rel="noopener noreferrer">claude.ai ↗</a>, adjunta el PDF completo del convenio y pega las instrucciones.</li>
        <li>Copia toda la respuesta de Claude (el bloque que empieza por <span class="mono">{</span> y termina por <span class="mono">}</span>), pégala aquí y pulsa «Revisar propuesta».</li>
      </ol>
      <textarea class="hidden" data-lb-instr readonly>{{ \App\Services\ConvenioIA::instrucciones() }}</textarea>
      <div><button type="button" class="btn soft sm" data-lb-copiar>Copiar instrucciones</button> <span class="hint muted" data-lb-copiado hidden>Copiadas. Pégalas en claude.ai junto con el PDF.</span></div>
      <textarea class="txa mono" name="respuesta" rows="5" placeholder="Pega aquí la respuesta de Claude" style="font-size:12px">{{ old('respuesta') }}</textarea>
      <div style="display:flex;justify-content:flex-end"><button class="btn lime" type="submit">Revisar propuesta</button></div>
    </form>
    @if($ia)
      <div style="display:flex;flex-direction:column;gap:6px;padding-top:10px;border-top:1px solid #F1F1EC;font-size:13px;line-height:1.5">
        <span style="font-size:12.5px;color:#6A6A65">Leído con IA el {{ \App\Support\Formato::fecha(substr($ia['fecha'], 0, 10)) }} · {{ $ia['archivo'] }}{{ ! empty($ia['anioTablas']) ? ' · tablas de '.$ia['anioTablas'] : '' }}</span>
        <span><b>Pagas extra:</b> {{ $conv['extras'] ?? [] ? collect($conv['extras'])->map(fn ($x) => $x['nombre'].' en '.\App\Support\Formato::mesNombre($x['mes']).' · '.($x['calculo'] === 'dias' ? \App\Support\Formato::num($x['dias'], 0).' días' : ($x['calculo'] === 'importe' ? \App\Support\Formato::eur($x['importe']) : 'una mensualidad')).($x['plus'] ? ' + plus' : '').($x['antig'] ? ' + antigüedad' : '').$art($x['art']))->implode(' · ') : 'no fija pagas extra' }}</span>
        <span><b>Antigüedad:</b> {{ ($a = $conv['antig'] ?? null) ? ($a['tipo'] === 'porcentaje' ? \App\Support\Formato::num($a['valor']).' % del salario base' : \App\Support\Formato::eur($a['valor']).' al mes').' por cada '.$a['periodo'].' años'.($a['tope'] ? ', máximo '.$a['tope'].' periodos' : '').$art($a['art']) : 'no consta' }}</span>
        <span><b>Pluses:</b> {{ ($conv['pluses'] ?? []) ? collect($conv['pluses'])->map(fn ($p) => $p['nombre'].' '.($p['tipo'] === 'porcentaje_base' ? \App\Support\Formato::num($p['valor']).' %' : \App\Support\Formato::eur($p['valor'])).' por '.['mes' => 'mes', 'dia_trabajado' => 'día trabajado', 'dia_natural' => 'día natural', 'hora' => 'hora'][$p['unidad']].($p['auto'] ? ' (automático)' : ' (se añade en la nómina cuando corresponda)').($p['cotiza'] ? '' : ' · no cotiza').$art($p['art']))->implode(' · ') : 'ninguno' }}</span>
        <span><b>Complemento de IT:</b> {{ ($conv['it']['cc'] ?? []) || ($conv['it']['at'] ?? []) ? collect(['cc' => 'enfermedad común', 'at' => 'accidente de trabajo'])->map(fn ($l, $k) => ($conv['it'][$k] ?? []) ? $l.': '.collect($conv['it'][$k])->map(fn ($t) => 'días '.$t['desde'].($t['hasta'] ? '-'.$t['hasta'] : ' en adelante').' al '.\App\Support\Formato::num($t['pct'], 0).' %')->implode(', ') : null)->filter()->implode(' · ').$art($conv['it']['art'] ?? '') : 'sin mejora sobre la prestación' }}</span>
        <span><b>Horas extra:</b> {{ ($h = $conv['hx'] ?? null) ? ($h['tipo'] === 'importe' ? \App\Support\Formato::eur($h['valor']).' la hora' : 'recargo del '.\App\Support\Formato::num($h['valor'], 0).' % sobre la hora ordinaria').$art($h['art']) : 'no consta precio' }}</span>
        @if(! empty($ia['avisos']))<span class="note-amber"><b>Dudas de la lectura:</b> {{ implode(' · ', $ia['avisos']) }}</span>@endif
      </div>
    @endif
  </div>
  <form method="POST" action="{{ route('laboral.convenios.guardar') }}" style="display:flex;flex-direction:column;gap:16px" data-lb-par>
    @csrf<input type="hidden" name="_enviado" value="1">
      <div class="card">
        <span style="font-size:15px;font-weight:700">Convenio colectivo aplicable</span>
        @if($conv['nombre'])
          <div style="padding:12px 14px;border-radius:12px;background:#0B0B0B;color:#F4F4F0;display:flex;flex-direction:column;gap:2px"><span style="font-size:14px;font-weight:700">{{ $conv['nombre'] }}</span><span style="font-size:12px;color:#BDBDB7">{{ implode(' · ', array_filter([$conv['codigo'] ? 'Código '.$conv['codigo'] : null, $conv['ambito'] ?: null, $conv['vigencia'] ? 'Vigencia '.$conv['vigencia'] : null])) ?: 'Sin código ni vigencia' }}</span></div>
        @else
          <div class="msg warn" style="font-weight:500">Sin convenio seleccionado. Búscalo en REGCON o el BOE y carga su PDF, o introdúcelo a mano.</div>
        @endif
        <div style="display:flex;flex-wrap:wrap;gap:8px">
          <input class="inp" data-lb-convq placeholder="Sector y provincia: hostelería Huelva" style="flex:1 1 200px;height:38px">
          <a class="btn soft" href="{{ Nomina::URL['regcon'] }}" target="_blank" rel="noopener">Buscar en REGCON ↗</a>
          <a class="btn soft" href="{{ Nomina::URL['boe'] }}" target="_blank" rel="noopener" data-lb-boe>Buscar en el BOE ↗</a>
        </div>
        <label class="lb-file"><input type="file" accept=".pdf" data-lb-convpdf hidden><span class="lb-ic" style="background:#0B0B0B;color:#fff">PDF</span><span style="display:flex;flex-direction:column;gap:2px"><span style="font-size:13.5px;font-weight:700">Cargar el PDF del convenio</span><span style="font-size:12px;color:#6A6A65">Se leen el nombre, el código, la vigencia, la jornada y las vacaciones de las primeras páginas</span></span></label>
        <span data-lb-convst style="font-size:12.5px;font-weight:600" hidden></span>
        <div class="lb-fields">
          @foreach([['conv_nombre', 'Nombre del convenio', 'nombre'], ['conv_codigo', 'Código del convenio (REGCON)', 'codigo'], ['conv_ambito', 'Ámbito', 'ambito'], ['conv_vigencia', 'Vigencia', 'vigencia'], ['conv_jornada', 'Jornada anual (horas)', 'jornada'], ['conv_vacaciones', 'Vacaciones (días naturales)', 'vacaciones']] as [$k, $l, $f])
            <label class="lb-f"><span class="lb-l">{{ $l }}</span><input class="inp lb-in" name="{{ $k }}" value="{{ $o($k, $conv[$f]) }}" data-conv="{{ $f }}"></label>
          @endforeach
          <label class="lb-f"><span class="lb-l">Pagas</span><select class="sel lb-in" name="conv_pagas">@foreach(['14', '12', '15', '16'] as $x)<option @selected((string) $o('conv_pagas', $conv['pagas']) === $x)>{{ $x }}</option>@endforeach</select></label>
        </div>
        <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;padding-top:6px;border-top:1px solid #F1F1EC"><span style="font-size:15px;font-weight:700">Tablas salariales</span><button type="button" class="btn soft" data-lb-add="cats">+ Categoría</button></div>
        <div class="lb-cat lb-head" style="padding:0"><span>Categoría</span><span>Grupo</span><span>Salario</span><span>Base €</span><span>Plus €/mes</span><span></span></div>
        <div data-lb-list="cats" data-n="{{ count($cats) }}" style="display:flex;flex-direction:column;gap:8px">
          @foreach($cats as $i => $ct)
            <div class="lb-cat"><input class="inp lb-in" name="cats[{{ $i }}][cat]" value="{{ $ct['cat'] ?? '' }}" title="{{ $ct['art'] ?? '' }}"><input type="hidden" name="cats[{{ $i }}][art]" value="{{ $ct['art'] ?? '' }}"><select class="sel lb-in" name="cats[{{ $i }}][grupo]">@foreach(range(1, 11) as $g)<option @selected((string) ($ct['grupo'] ?? '7') === (string) $g)>{{ $g }}</option>@endforeach</select><select class="sel lb-in" name="cats[{{ $i }}][unidad]"><option value="mes" @selected(($ct['unidad'] ?? 'mes') === 'mes')>Mensual</option><option value="dia" @selected(($ct['unidad'] ?? 'mes') === 'dia')>Diario</option></select><input class="inp lb-in mono" name="cats[{{ $i }}][base]" value="{{ is_numeric($ct['base'] ?? null) ? Formato::num($ct['base']) : ($ct['base'] ?? '') }}" inputmode="decimal" style="text-align:right"><input class="inp lb-in mono" name="cats[{{ $i }}][plus]" value="{{ is_numeric($ct['plus'] ?? null) ? Formato::num($ct['plus']) : ($ct['plus'] ?? '') }}" inputmode="decimal" style="text-align:right"><button type="button" class="lb-del" data-lb-del aria-label="Quitar">×</button></div>
          @endforeach
        </div>
        <template data-lb-tpl="cats"><div class="lb-cat"><input class="inp lb-in" name="cats[__][cat]" value="Nueva categoría"><select class="sel lb-in" name="cats[__][grupo]">@foreach(range(1, 11) as $g)<option @selected($g === 7)>{{ $g }}</option>@endforeach</select><select class="sel lb-in" name="cats[__][unidad]"><option value="mes">Mensual</option><option value="dia">Diario</option></select><input class="inp lb-in mono" name="cats[__][base]" value="1.221,00" inputmode="decimal" style="text-align:right"><input class="inp lb-in mono" name="cats[__][plus]" value="0,00" inputmode="decimal" style="text-align:right"><button type="button" class="lb-del" data-lb-del aria-label="Quitar">×</button></div></template>
        <span class="hint muted">«Diario»: la base es € por día natural y en cada nómina se multiplica por los días del mes.</span>
      </div>

      @php
        $old = old('_enviado');
        $extras = $old ? array_values((array) old('extras', [])) : ($conv['extras'] ?? []);
        $pluses = $old ? array_values((array) old('pluses', [])) : ($conv['pluses'] ?? []);
        $itCc = $old ? array_values((array) old('it_cc', [])) : ($conv['it']['cc'] ?? []);
        $itAt = $old ? array_values((array) old('it_at', [])) : ($conv['it']['at'] ?? []);
        $ant = $conv['antig'] ?? null; $hx = $conv['hx'] ?? null;
        $MES = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
        $nf = fn ($v, $d = 2) => is_numeric($v) ? Formato::num($v, $d) : (string) ($v ?? '');
        $mesSel = fn ($name, $v) => '<select class="sel lb-in" name="'.$name.'" style="width:130px">'.collect($MES)->map(fn ($l, $k) => '<option value="'.($k + 1).'"'.((int) $v === $k + 1 ? ' selected' : '').'>'.$l.'</option>')->implode('').'</select>';
      @endphp

      <div class="card" style="gap:10px">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:10px"><span style="font-size:15px;font-weight:700">Pagas extraordinarias</span><button type="button" class="btn soft" data-lb-add="extras">+ Paga</button></div>
        <span class="hint muted">Sin pagas aquí se aplican verano (junio) y Navidad (diciembre) de una mensualidad. Con pagas aquí, mandan estas.</span>
        <div data-lb-list="extras" data-n="{{ count($extras) }}" style="display:flex;flex-direction:column;gap:8px">
          @foreach($extras as $i => $x)
            <div class="lb-fila"><input class="inp lb-in" name="extras[{{ $i }}][nombre]" value="{{ $x['nombre'] ?? '' }}" style="flex:1 1 180px" placeholder="Nombre">{!! $mesSel('extras['.$i.'][mes]', $x['mes'] ?? 12) !!}<select class="sel lb-in" name="extras[{{ $i }}][calculo]" style="width:150px">@foreach(['mensualidad' => 'Una mensualidad', 'dias' => 'Días de salario', 'importe' => 'Importe fijo'] as $k => $l)<option value="{{ $k }}" @selected(($x['calculo'] ?? 'mensualidad') === $k)>{{ $l }}</option>@endforeach</select><input class="inp lb-in mono" name="extras[{{ $i }}][dias]" value="{{ $nf($x['dias'] ?? '', 0) }}" placeholder="Días" style="width:70px" inputmode="decimal"><input class="inp lb-in mono" name="extras[{{ $i }}][importe]" value="{{ $nf($x['importe'] ?? '') }}" placeholder="Importe €" style="width:100px" inputmode="decimal"><label class="lb-chk"><input type="checkbox" name="extras[{{ $i }}][plus]" value="1" @checked(! empty($x['plus']))>+ plus</label><label class="lb-chk"><input type="checkbox" name="extras[{{ $i }}][antig]" value="1" @checked(! empty($x['antig']))>+ antigüedad</label><button type="button" class="lb-del" data-lb-del aria-label="Quitar">×</button></div>
          @endforeach
        </div>
        <template data-lb-tpl="extras"><div class="lb-fila"><input class="inp lb-in" name="extras[__][nombre]" value="Paga extraordinaria" style="flex:1 1 180px">{!! $mesSel('extras[__][mes]', 12) !!}<select class="sel lb-in" name="extras[__][calculo]" style="width:150px"><option value="mensualidad">Una mensualidad</option><option value="dias">Días de salario</option><option value="importe">Importe fijo</option></select><input class="inp lb-in mono" name="extras[__][dias]" placeholder="Días" style="width:70px" inputmode="decimal"><input class="inp lb-in mono" name="extras[__][importe]" placeholder="Importe €" style="width:100px" inputmode="decimal"><label class="lb-chk"><input type="checkbox" name="extras[__][plus]" value="1">+ plus</label><label class="lb-chk"><input type="checkbox" name="extras[__][antig]" value="1">+ antigüedad</label><button type="button" class="lb-del" data-lb-del aria-label="Quitar">×</button></div></template>
      </div>

      <div class="card" style="gap:10px">
        <span style="font-size:15px;font-weight:700">Antigüedad</span>
        <div class="lb-fila" style="border:0;padding:0">
          <label class="lb-chk"><input type="checkbox" name="ant_si" value="1" @checked(old('_enviado') ? old('ant_si') : (bool) $ant)>El convenio paga antigüedad</label>
          <label class="lb-f" style="width:130px"><span class="lb-l">Cada (años)</span><select class="sel lb-in" name="ant_periodo">@foreach([2 => 'Bienios (2)', 3 => 'Trienios (3)', 4 => 'Cuatrienios (4)', 5 => 'Quinquenios (5)'] as $k => $l)<option value="{{ $k }}" @selected((int) old('ant_periodo', $ant['periodo'] ?? 3) === $k)>{{ $l }}</option>@endforeach</select></label>
          <label class="lb-f" style="width:190px"><span class="lb-l">Importe por periodo</span><select class="sel lb-in" name="ant_tipo"><option value="importe" @selected(old('ant_tipo', $ant['tipo'] ?? 'importe') === 'importe')>€ al mes</option><option value="porcentaje" @selected(old('ant_tipo', $ant['tipo'] ?? '') === 'porcentaje')>% del salario base</option></select></label>
          <label class="lb-f" style="width:110px"><span class="lb-l">Valor</span><input class="inp lb-in mono" name="ant_valor" value="{{ old('ant_valor', $nf($ant['valor'] ?? '')) }}" inputmode="decimal"></label>
          <label class="lb-f" style="width:130px"><span class="lb-l">Tope (nº periodos)</span><input class="inp lb-in mono" name="ant_tope" value="{{ old('ant_tope', $ant['tope'] ?? '') }}" inputmode="numeric" placeholder="Sin tope"></label>
        </div>
        <span class="hint muted">Se cuenta desde la fecha de antigüedad de la ficha o, si no la tiene, desde la fecha de alta.</span>
      </div>

      <div class="card" style="gap:10px">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:10px"><span style="font-size:15px;font-weight:700">Pluses</span><button type="button" class="btn soft" data-lb-add="pluses">+ Plus</button></div>
        <span class="hint muted">«Automático»: sale solo todos los meses (por mes, por día natural o por día laborable de lunes a viernes). Los que no son automáticos o van por horas aparecen en el Integral con su precio para añadirlos cuando toque.</span>
        <div data-lb-list="pluses" data-n="{{ count($pluses) }}" style="display:flex;flex-direction:column;gap:8px">
          @foreach($pluses as $i => $p)
            <div class="lb-fila"><input class="inp lb-in" name="pluses[{{ $i }}][nombre]" value="{{ $p['nombre'] ?? '' }}" style="flex:1 1 170px" placeholder="Nombre"><input class="inp lb-in mono" name="pluses[{{ $i }}][valor]" value="{{ $nf($p['valor'] ?? '') }}" style="width:90px" inputmode="decimal"><select class="sel lb-in" name="pluses[{{ $i }}][tipo]" style="width:120px"><option value="importe" @selected(($p['tipo'] ?? 'importe') === 'importe')>€</option><option value="porcentaje_base" @selected(($p['tipo'] ?? '') === 'porcentaje_base')>% salario base</option></select><select class="sel lb-in" name="pluses[{{ $i }}][unidad]" style="width:150px">@foreach(['mes' => 'por mes', 'dia_trabajado' => 'por día trabajado', 'dia_natural' => 'por día natural', 'hora' => 'por hora'] as $k => $l)<option value="{{ $k }}" @selected(($p['unidad'] ?? 'mes') === $k)>{{ $l }}</option>@endforeach</select><label class="lb-chk"><input type="checkbox" name="pluses[{{ $i }}][auto]" value="1" @checked(! empty($p['auto']))>Automático</label><label class="lb-chk"><input type="checkbox" name="pluses[{{ $i }}][cotiza]" value="1" @checked($p['cotiza'] ?? true)>Cotiza</label><label class="lb-chk"><input type="checkbox" name="pluses[{{ $i }}][tributa]" value="1" @checked($p['tributa'] ?? true)>Tributa</label><input type="hidden" name="pluses[{{ $i }}][art]" value="{{ $p['art'] ?? '' }}"><button type="button" class="lb-del" data-lb-del aria-label="Quitar">×</button></div>
          @endforeach
        </div>
        <template data-lb-tpl="pluses"><div class="lb-fila"><input class="inp lb-in" name="pluses[__][nombre]" value="Plus" style="flex:1 1 170px"><input class="inp lb-in mono" name="pluses[__][valor]" value="0,00" style="width:90px" inputmode="decimal"><select class="sel lb-in" name="pluses[__][tipo]" style="width:120px"><option value="importe">€</option><option value="porcentaje_base">% salario base</option></select><select class="sel lb-in" name="pluses[__][unidad]" style="width:150px"><option value="mes">por mes</option><option value="dia_trabajado">por día trabajado</option><option value="dia_natural">por día natural</option><option value="hora">por hora</option></select><label class="lb-chk"><input type="checkbox" name="pluses[__][auto]" value="1" checked>Automático</label><label class="lb-chk"><input type="checkbox" name="pluses[__][cotiza]" value="1" checked>Cotiza</label><label class="lb-chk"><input type="checkbox" name="pluses[__][tributa]" value="1" checked>Tributa</label><button type="button" class="lb-del" data-lb-del aria-label="Quitar">×</button></div></template>
      </div>

      <div class="card" style="gap:10px">
        <span style="font-size:15px;font-weight:700">Complemento de incapacidad temporal</span>
        <span class="hint muted">Porcentaje del salario que garantiza el convenio en cada tramo de días de baja (incluida la prestación). Vacío: sin mejora.</span>
        @foreach(['it_cc' => ['Enfermedad común o accidente no laboral', $itCc], 'it_at' => ['Accidente de trabajo o enfermedad profesional', $itAt]] as $k => [$l, $rows])
          <div style="display:flex;justify-content:space-between;align-items:center;gap:10px"><span style="font-size:13px;font-weight:600">{{ $l }}</span><button type="button" class="btn soft sm" data-lb-add="{{ $k }}">+ Tramo</button></div>
          <div data-lb-list="{{ $k }}" data-n="{{ count($rows) }}" style="display:flex;flex-direction:column;gap:6px">
            @foreach($rows as $i => $t)
              <div class="lb-fila"><span style="font-size:12.5px">Del día</span><input class="inp lb-in mono" name="{{ $k }}[{{ $i }}][desde]" value="{{ $t['desde'] ?? 1 }}" style="width:70px" inputmode="numeric"><span style="font-size:12.5px">al</span><input class="inp lb-in mono" name="{{ $k }}[{{ $i }}][hasta]" value="{{ $t['hasta'] ?? '' }}" style="width:70px" inputmode="numeric" placeholder="fin"><input class="inp lb-in mono" name="{{ $k }}[{{ $i }}][pct]" value="{{ $nf($t['pct'] ?? 100, 0) }}" style="width:70px" inputmode="decimal"><span style="font-size:12.5px">%</span><button type="button" class="lb-del" data-lb-del aria-label="Quitar">×</button></div>
            @endforeach
          </div>
          <template data-lb-tpl="{{ $k }}"><div class="lb-fila"><span style="font-size:12.5px">Del día</span><input class="inp lb-in mono" name="{{ $k }}[__][desde]" value="1" style="width:70px" inputmode="numeric"><span style="font-size:12.5px">al</span><input class="inp lb-in mono" name="{{ $k }}[__][hasta]" style="width:70px" inputmode="numeric" placeholder="fin"><input class="inp lb-in mono" name="{{ $k }}[__][pct]" value="100" style="width:70px" inputmode="decimal"><span style="font-size:12.5px">%</span><button type="button" class="lb-del" data-lb-del aria-label="Quitar">×</button></div></template>
        @endforeach
      </div>

      <div class="card" style="gap:10px">
        <span style="font-size:15px;font-weight:700">Horas extraordinarias</span>
        <div class="lb-fila" style="border:0;padding:0">
          <label class="lb-f" style="width:230px"><span class="lb-l">Precio</span><select class="sel lb-in" name="hx_tipo">@foreach(['no_consta' => 'No lo fija el convenio', 'importe' => '€ por hora', 'recargo' => '% de recargo sobre la hora ordinaria'] as $k => $l)<option value="{{ $k }}" @selected(old('hx_tipo', $hx['tipo'] ?? 'no_consta') === $k)>{{ $l }}</option>@endforeach</select></label>
          <label class="lb-f" style="width:110px"><span class="lb-l">Valor</span><input class="inp lb-in mono" name="hx_valor" value="{{ old('hx_valor', $nf($hx['valor'] ?? '')) }}" inputmode="decimal"></label>
        </div>
      </div>

    <div style="display:flex;justify-content:flex-end;gap:10px">
      <a class="btn white" href="{{ route('laboral.convenios') }}">Descartar cambios</a>
      <button class="btn lg" type="submit">Guardar convenio</button>
    </div>
  </form>
</div>
@endsection
@section('overlay')
@if($con) @include('laboral._conexion') @endif
@endsection
@push('scripts')
<script src="{{ asset('js/ocr.js') }}?v={{ config('app.version', '1') }}"></script>
@endpush
