@extends('layouts.app')
@php
  use App\Services\Nomina;
  use App\Support\Formato;
  $o = fn ($k, $def) => old($k, $def);
  $conv = $convEd ?? $P['conv'];
  $cvE = $cv ? \App\Models\Convenio::ESTADOS : [];
  $cats = old('_enviado') ? array_values((array) old('cats', [])) : $conv['cats'];
@endphp
@section('content')
<div class="page" style="gap:16px">
  @include('laboral._cabecera')
  <div class="card" style="gap:12px">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:10px;flex-wrap:wrap">
      <div style="display:flex;flex-direction:column;gap:2px;min-width:0"><span style="font-size:15px;font-weight:700">Convenio de {{ $c->nombre }}</span>
        <span style="font-size:12.5px;color:#6A6A65">{{ $cv ? $cv->nombre.' · '.(\App\Models\Convenio::ESTADOS[$cv->estado] ?? $cv->estado).($nEmpresas > 1 ? ' · lo usan '.$nEmpresas.' empresas: los cambios les afectan a todas' : '') : 'Sin convenio asignado' }}</span></div>
      <form method="POST" action="{{ route('laboral.convenios.asignar') }}" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end">@csrf
        <label class="lbl-s" style="min-width:220px">Asignar convenio del despacho<select class="sel" name="convenio_id" data-lb-asig>@foreach($todos as $t)<option value="{{ $t->id }}" @selected($cv && $cv->id === $t->id)>{{ $t->nombre }}{{ $t->codigo ? ' · '.$t->codigo : '' }}</option>@endforeach<option value="nuevo" @selected(! $cv)>+ Convenio nuevo…</option></select></label>
        <label class="lbl-s">Nombre (si es nuevo)<input class="inp" name="nombre" maxlength="250" placeholder="Convenio de la construcción de Huelva"></label>
        <button class="btn soft sm" type="submit">Asignar</button>
      </form>
    </div>
    @if($cv)
      <div style="overflow-x:auto"><table class="lb-tab" style="width:100%;font-size:13px;border-collapse:collapse">
        <thead><tr style="text-align:left;color:#6A6A65;font-size:11.5px;text-transform:uppercase;letter-spacing:.04em"><th style="padding:6px 8px">Versión</th><th style="padding:6px 8px">Efectos económicos</th><th style="padding:6px 8px">Estado</th><th style="padding:6px 8px">Categorías</th><th></th></tr></thead>
        <tbody>
        @foreach($versiones as $v)
          <tr style="border-top:1px solid #F1F1EC;{{ $ver && $ver->id === $v->id ? 'background:#F7F8EE' : '' }}">
            <td style="padding:7px 8px;font-weight:600">{{ $v->nombre }}</td>
            <td style="padding:7px 8px" class="mono12">{{ $v->efectos_desde ? Formato::fecha($v->efectos_desde) : 'sin fecha' }} – {{ $v->efectos_hasta ? Formato::fecha($v->efectos_hasta) : 'abierta' }}</td>
            <td style="padding:7px 8px">{{ \App\Models\Convenio::ESTADOS[$v->estado] ?? $v->estado }}{{ $v->fuente === 'ia' ? ' · leída con IA' : '' }}</td>
            <td style="padding:7px 8px">{{ $v->categorias_count }}</td>
            <td style="padding:7px 8px;text-align:right">@if($ver && $ver->id === $v->id)<b>Editando</b>@else<a href="{{ route('laboral.convenios', ['version' => $v->id]) }}">Editar</a>@endif</td>
          </tr>
        @endforeach
        </tbody></table></div>
      @if($ver)
      <details><summary style="cursor:pointer;font-size:13px;font-weight:600">+ Nueva versión (revisión salarial, tablas de otro año…)</summary>
        <form method="POST" action="{{ route('laboral.convenios.version') }}" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;margin-top:8px">@csrf<input type="hidden" name="base_id" value="{{ $ver->id }}">
          <label class="lbl-s">Nombre<input class="inp" name="nombre" required maxlength="160" placeholder="Tablas {{ now()->year + 1 }}"></label>
          <label class="lbl-s">Efectos económicos desde<input class="inp" type="date" name="efectos_desde" required></label>
          <label class="chk" style="gap:6px;font-size:12.5px"><input type="checkbox" name="cerrar" value="1" checked>Cerrar «{{ $ver->nombre }}» el día anterior</label>
          <button class="btn soft sm" type="submit">Crear copiando «{{ $ver->nombre }}»</button>
        </form>
      </details>
      @endif
      @if($validacion)
        <div style="display:flex;flex-direction:column;gap:4px">
          @foreach($validacion as [$niv, $txt])<div style="display:flex;gap:8px;font-size:12.5px;align-items:flex-start"><span style="font-weight:700;color:{{ ['error' => '#8A1C1C', 'aviso' => '#6E4300', 'info' => '#3C3C3C'][$niv] }}" aria-label="{{ ['error' => 'Error', 'aviso' => 'Advertencia', 'info' => 'Información'][$niv] }}">{{ ['error' => '!', 'aviso' => '?', 'info' => 'i'][$niv] }}</span><span>{{ $txt }}</span></div>@endforeach
        </div>
      @endif
      <div style="display:flex;flex-direction:column;gap:6px;padding-top:8px;border-top:1px solid #F1F1EC">
        <span style="font-size:13px;font-weight:600">Documentos del convenio</span>
        @forelse($docs as $d)
          <span style="font-size:12.5px">{{ \App\Models\ConvenioDocumento::TIPOS[$d->tipo] ?? $d->tipo }}: @if($d->documento)<a href="{{ route('documentos.ver', $d->documento_id) }}" target="_blank" rel="noopener">{{ $d->documento->nombre }}</a>@endif {{ $d->descripcion ? '· '.$d->descripcion : '' }}</span>
        @empty
          <span class="hint muted">Ningún PDF guardado todavía.</span>
        @endforelse
        <form method="POST" action="{{ route('laboral.convenios.documento') }}" enctype="multipart/form-data" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end">@csrf<input type="hidden" name="version_id" value="{{ $ver?->id }}">
          <label class="lbl-s">Tipo<select class="sel" name="tipo">@foreach(\App\Models\ConvenioDocumento::TIPOS as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></label>
          <label class="lbl-s">Descripción<input class="inp" name="descripcion" maxlength="250" placeholder="BOP de Huelva nº 123, 12/03/2026"></label>
          <label class="lbl-s">PDF<input class="inp" type="file" name="pdf" accept="application/pdf" required style="padding-top:8px"></label>
          <button class="btn soft sm" type="submit">Guardar PDF</button>
        </form>
      </div>
    @endif
  </div>
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
  @include('laboral._convenio-secciones')
  @if($cv && $ver)
  @php $si = fn ($k, $d = '') => $simIn[$k] ?? $d; @endphp
  <div class="card" style="gap:12px" id="simulador">
    <div style="display:flex;flex-direction:column;gap:2px"><span style="font-size:15px;font-weight:700">Simular una nómina con este convenio</span>
      <span style="font-size:12.5px;color:#6A6A65;line-height:1.45">Calcula la nómina de un trabajador tipo con la versión «{{ $ver->nombre }}» ({{ \App\Models\Convenio::ESTADOS[$ver->estado] ?? $ver->estado }}) para comprobar el convenio antes de aplicarlo. No guarda nada ni toca a la plantilla. El salario sale de la tabla de la categoría; el IRPF no se calcula porque depende de cada trabajador.</span></div>
    <form method="GET" action="{{ route('laboral.convenios') }}#simulador" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,150px),1fr));gap:10px;align-items:end">
      @if(request('version'))<input type="hidden" name="version" value="{{ request('version') }}">@endif
      <label class="lbl-s">Categoría<select class="sel" name="sim[cat]" required><option value="">—</option>@foreach($conv['cats'] as $k)<option value="{{ $k['cat'] }}" @selected($si('cat') === $k['cat'])>{{ $k['cat'] }}</option>@endforeach</select></label>
      <label class="lbl-s">Mes<input class="inp" type="month" name="sim[mes]" value="{{ $si('mes', now()->format('Y-m')) }}" required></label>
      <label class="lbl-s">Jornada (%)<input class="inp mono" name="sim[jor]" value="{{ $si('jor', '100') }}" inputmode="decimal" required></label>
      <label class="lbl-s">Alta (opcional)<input class="inp" type="date" name="sim[alta]" value="{{ $si('alta') }}"></label>
      <label class="lbl-s">Baja (opcional)<input class="inp" type="date" name="sim[baja]" value="{{ $si('baja') }}"></label>
      <label class="lbl-s">Antigüedad desde<input class="inp" type="date" name="sim[antig]" value="{{ $si('antig') }}"></label>
      <label class="lbl-s" style="flex-direction:row;gap:6px;align-items:center"><input type="checkbox" name="sim[prorr]" value="1" @checked($si('prorr'))> Pagas prorrateadas</label>
      <button class="btn lime" type="submit">Simular</button>
    </form>
    @if($sim)
      @if($sim['error'] ?? null)
        <span class="note-amber" role="alert">{{ $sim['error'] }}</span>
      @else
        @php $sn = $sim['n']; $sd = $sim['doc']; @endphp
        <span style="font-size:12.5px;color:#3C3C3C">Periodo: {{ Formato::fecha($sd['desde']) }} a {{ Formato::fecha($sd['hasta']) }} · {{ $sd['dias'] }} de {{ $sd['diasMes'] }} días · grupo de cotización {{ $sim['w']['grupo'] }}</span>
        <div class="tbl">
          <div class="th" style="grid-template-columns:minmax(0,1.3fr) minmax(0,1.6fr) minmax(0,1.3fr) 110px;min-width:640px"><span>Concepto</span><span>Cómo se calcula</span><span>Origen</span><span class="r">Importe</span></div>
          @foreach($sim['lineas'] as $l)
            <div class="tr" style="grid-template-columns:minmax(0,1.3fr) minmax(0,1.6fr) minmax(0,1.3fr) 110px;min-width:640px;border-radius:0"><span>{{ $l['concepto'] }}</span><span class="mono12">{{ $l['expl'] ?? '' }}</span><span class="c3" style="font-size:12px">{{ $l['regla'] ?? '' }}</span><span class="mono" style="text-align:right">{{ Formato::eur($l['importe']) }}</span></div>
          @endforeach
          @foreach([['Total devengado', $sn['dev'], true], ['Base de contingencias comunes', $sn['base'], false], ['Seguridad Social a cargo del trabajador', $sn['ssT'], false], ['Seguridad Social a cargo de la empresa (sin AT y EP)', $sn['ssE'], false]] as [$t, $v, $b])
            <div class="tr" style="grid-template-columns:minmax(0,1.3fr) minmax(0,1.6fr) minmax(0,1.3fr) 110px;min-width:640px;border-radius:0;{{ $b ? 'font-weight:700' : '' }}"><span style="grid-column:span 3">{{ $t }}</span><span class="mono" style="text-align:right">{{ Formato::eur($v) }}</span></div>
          @endforeach
        </div>
        @if(! empty($sn['baseExpl']))<ul style="margin:0;padding-left:18px;font-size:12.5px;line-height:1.5;color:#3C3C3C">@foreach($sn['baseExpl'] as $t)<li>{{ $t }}</li>@endforeach</ul>@endif
        @php $fuera = collect($conv['pluses'])->filter(fn ($p) => $p['auto'] && ! $p['soportado']); @endphp
        @if($fuera->isNotEmpty())<span class="note-amber">No entran en la simulación (el motor no los puede calcular): {{ $fuera->pluck('nombre')->implode(', ') }}.</span>@endif
      @endif
    @endif
  </div>
  @endif
</div>
@endsection
@section('overlay')
@if($con) @include('laboral._conexion') @endif
@endsection

