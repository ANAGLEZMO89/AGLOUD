{{-- Integral de nóminas · nómina de un trabajador: borrador editable conectado al convenio --}}
@php
  use App\Services\Nomina;
  use App\Support\Formato;
  $w = $sel['w']; $n = $sel['n'];
  $E = fn ($v) => Formato::num($v);
  $prev = $idx > 0 ? $noms[$idx - 1]['w']['id'] : null; $next = $idx < count($noms) - 1 ? $noms[$idx + 1]['w']['id'] : null;
  $g = $ed['g']; $doc = $ed['doc']; $av = $ed['avisos'];
  $emitida = $g?->emitida(); $bloq = $emitida || $asiento;
  $nAv = count($av['lineas']) + count($av['generales']);
  $tabUrl = fn ($v) => route('laboral.integral', $q + ['t' => $w['id'], 'vista' => $v]);
  $ult = \Illuminate\Support\Carbon::create($anio, $mes, 1)->endOfMonth();
  $desLegal = ($n['tmp'] ? Nomina::TIPOS['des_tmp'][0] : Nomina::TIPOS['des_ind'][0]) * 100;
  $irTxt = $ir['fijo'] ? 'Tipo fijo del '.Formato::num($ir['tipo']).' % indicado en la ficha.'
    : 'Calculado con el algoritmo de la AEAT: '.Formato::num($ir['tipoAnual']).' % anual'.(abs($ir['tipo'] - $ir['tipoAnual']) > 0.001 ? ', regularizado al '.Formato::num($ir['tipo']).' % este mes' : '').'.';
  $estado = $emitida ? ['Emitida '.Formato::fechaHora($g->emitida_at), '#E6F6A8', '#2E3A00'] : ($g ? ['Borrador guardado', '#FCEBCB', '#6E4300'] : ['Calculada desde el convenio', '#ECECE7', '#3C3C3C']);
@endphp
<div class="ne-top">
  <div style="display:flex;flex-direction:column;gap:2px;min-width:0;margin-right:auto"><span style="font-size:18px;font-weight:700">{{ $w['nombre'] }}</span><span style="font-size:12.5px;color:#6A6A65">{{ Nomina::MESES[$mes - 1] }} {{ $anio }} · <span class="lb-pill" style="background:{{ $estado[1] }};color:{{ $estado[2] }}">{{ $estado[0] }}</span></span></div>
  @if($prev)<a class="btn white sm" href="{{ route('laboral.integral', $q + ['t' => $prev]) }}">← Anterior</a>@endif
  @if($next)<a class="btn white sm" href="{{ route('laboral.integral', $q + ['t' => $next]) }}">Siguiente →</a>@endif
</div>
<div class="chipbar" style="border-radius:11px">
  @foreach([['contrato', 'Contrato', route('laboral.ficha', [$w['id'], 'sec' => 'contrato'])], ['nominas', 'Nóminas', $tabUrl('nominas')], ['m145', 'Mod. 145', route('laboral.ficha', [$w['id'], 'sec' => 'irpf'])], ['borrador', 'Borrador', $tabUrl('borrador')], ['variables', 'Variables', $tabUrl('variables')], ['convenio', 'Convenio', $tabUrl('convenio')]] as [$id, $l, $u])
    <a class="{{ $ed['tab'] === $id ? 'on' : '' }}" style="padding:7px 13px;border-radius:8px" href="{{ $u }}">{{ $l }}</a>
  @endforeach
</div>

@if($ed['tab'] === 'borrador')
<form method="POST" action="{{ route('laboral.nomina.guardar', $w['id']) }}" class="card ne" data-ne>
  @csrf<input type="hidden" name="anio" value="{{ $anio }}"><input type="hidden" name="mes" value="{{ $mes }}">
  <div class="ne-bar no-print">
    @unless($bloq)
      <button class="btn" type="submit" name="accion" value="guardar">Guardar</button>
      <button class="btn lime" type="submit" name="accion" value="emitir" @if($nAv) data-confirm-btn="Esta nómina tiene {{ $nAv }} {{ $nAv === 1 ? 'concepto que no es' : 'conceptos que no son' }} por convenio. ¿Emitirla igualmente?" @endif>Emitir nómina</button>
    @endunless
    @if($emitida && ! $asiento)<button class="btn white" type="submit" name="accion" value="reabrir">Reabrir</button>@endif
    <a class="btn white" href="{{ route('laboral.recibo', [$w['id']] + $q + ['imprimir' => 1]) }}" target="_blank" rel="noopener">Vista previa / PDF</a>
    <a class="btn white" href="{{ route('laboral.ficha', [$w['id'], 'sec' => 'irpf']) }}">IRPF</a>
    @if($g && ! $bloq)<button class="btn soft" type="submit" name="accion" value="recalcular" data-confirm-btn="Se descartan los cambios y se vuelve a calcular desde el convenio y la ficha. ¿Seguir?">Recalcular desde convenio</button>@endif
  </div>
  @if($asiento)<div class="msg warn" style="font-weight:500">Las nóminas de este mes están contabilizadas (asiento {{ $asiento->numero }}): para cambiarlas, borra antes el asiento en Contabilidad.</div>@endif
  @if($nAv)
    <div class="ne-aviso"><b>⚠ {{ $nAv }} {{ $nAv === 1 ? 'concepto no es' : 'conceptos no son' }} por convenio</b>@foreach($av['generales'] as $x)<span>{{ $x }}</span>@endforeach<span>Las líneas afectadas están marcadas en amarillo.</span></div>
  @endif

  @unless($ed['cat'])
    <div class="msg warn" style="font-weight:500">{{ $w['nombre'] }} no tiene asignada una categoría de un convenio cargado: el salario no se puede comparar con el convenio. Asígnala en la ficha › Contrato (las tablas se cargan en Gestión › Convenios).</div>
  @endunless
  <div class="ne-head">
    @foreach([['C.C.C.', $w['ccc'] ?: '—'], ['Grupo', $w['grupo']], ['Contrato', Nomina::codigo($w['contrato'])], ['Ocupación', $w['cno'] ?: '—'], ['Categoría profesional', ($w['convCat'] ?: $w['cat']) ?: '—'], ['Nº afiliación', $w['naf']], ['Nº documento', $w['nif']]] as [$l, $v])
      <div><span>{{ $l }}</span><b class="mono12">{{ $v }}</b></div>
    @endforeach
  </div>
  <div class="ne-head">
    <div><span>Recibo</span><b>Nómina</b></div>
    <div><span>Mes de cálculo</span><b>{{ Nomina::MESES[$mes - 1] }} de {{ $anio }}</b></div>
    <label class="ne-f"><span>Fecha de pago</span><input class="inp lb-in" type="date" name="fecha_pago" value="{{ old('fecha_pago', $g?->fecha_pago?->toDateString() ?? $ult->toDateString()) }}" @disabled($bloq)></label>
    <div><span>Periodo de liquidación</span><b class="mono12">01/{{ str_pad((string) $mes, 2, '0', STR_PAD_LEFT) }}/{{ $anio }} - {{ $ult->format('d/m/Y') }}</b></div>
    <div><span>Días</span><b class="mono12">30</b></div>
    <div><span>Antigüedad</span><b class="mono12">{{ Formato::fecha($w['alta']) }}</b></div>
    <div><span>Coeficiente de parcialidad</span><b class="mono12">{{ Formato::num(Nomina::n($w['jor']) / 100) }}</b></div>
  </div>

  <div class="ne-grid ne-hd"><span></span><span>Concepto</span><span>Tipo</span><span class="r">Devengos</span><span class="r">Deducciones</span><span></span></div>
  @foreach($doc['lineas'] as $i => $l)
    @php $aviso = $av['lineas'][$i] ?? null; $act = ($l['activo'] ?? true) !== false; @endphp
    <div class="ne-grid ne-row {{ $aviso ? 'ne-warn' : '' }} {{ $act ? '' : 'ne-off' }}">
      <span class="mono12" style="color:#6A6A65">{{ $l['cod'] ? '['.$l['cod'].']' : '·' }}</span>
      <span style="display:flex;flex-direction:column;gap:3px;min-width:0">
        <input type="hidden" name="l[{{ $i }}][id]" value="{{ $l['id'] }}"><input type="hidden" name="l[{{ $i }}][cod]" value="{{ $l['cod'] }}">
        <input class="inp lb-in" name="l[{{ $i }}][concepto]" value="{{ $l['concepto'] }}" @disabled($bloq)>
        @if($aviso)<small class="ne-av">⚠ {{ $aviso }}</small>@endif
      </span>
      <span style="display:flex;gap:6px;align-items:center">
        <select class="sel lb-in" name="l[{{ $i }}][clase]" @disabled($bloq) title="Cómo cotiza y tributa">@foreach(Nomina::CLASES as $k => $t)<option value="{{ $k }}" @selected($l['clase'] === $k)>{{ $t }}</option>@endforeach</select>
        @if($l['clase'] === 'extra')<select class="sel lb-in" name="l[{{ $i }}][cobro]" style="width:130px" @disabled($bloq) title="Cobro"><option value="prorrateada" @selected(($l['cobro'] ?? '') === 'prorrateada')>Prorrateada</option><option value="en_su_mes" @selected(($l['cobro'] ?? '') !== 'prorrateada')>En su mes</option></select>@endif
      </span>
      <input class="inp lb-in mono" name="l[{{ $i }}][importe]" value="{{ $E($l['importe']) }}" style="text-align:right" inputmode="decimal" @disabled($bloq)>
      <span></span>
      <label class="ne-sw" title="Incluir en la nómina"><input type="checkbox" name="l[{{ $i }}][activo]" value="1" @checked($act) @disabled($bloq)><i></i></label>
    </div>
  @endforeach
  @unless($bloq)
  <div class="ne-grid ne-row ne-new">
    <span style="font-size:18px;color:#6A6A65;text-align:center">+</span>
    <input class="inp lb-in" name="nl[concepto]" placeholder="Añadir concepto (p. ej. recargo por trabajar festivo)">
    <select class="sel lb-in" name="nl[clase]">@foreach(Nomina::CLASES as $k => $t)<option value="{{ $k }}">{{ $t }}</option>@endforeach</select>
    <input class="inp lb-in mono" name="nl[importe]" placeholder="0,00" style="text-align:right" inputmode="decimal">
    <span></span><span></span>
  </div>
  @endunless

  <div class="ne-sep"></div>
  @foreach([['Contingencias comunes', Formato::num(Nomina::TIPOS['cc'][0] * 100).' %', $n['T']['cc'], null], ['Desempleo', null, $n['T']['des'], 'des_t'], ['Formación profesional', Formato::num(Nomina::TIPOS['fp'][0] * 100).' %', $n['T']['fp'], null], ['IRPF', null, $n['irpf'], 'irpf'], ['Mecanismo de Equidad Intergeneracional (MEI)', Formato::num(Nomina::TIPOS['mei'][0] * 100).' %', $n['T']['mei'], null]] as [$l, $pct, $v, $campo])
    <div class="ne-grid ne-row">
      <span></span><span>{{ $l }}</span>
      <span>
        @if($campo === 'des_t')<span style="display:flex;align-items:center;gap:6px"><input class="inp lb-in mono" name="tipos[des_t]" value="{{ isset($doc['tipos']['des_t']) ? Formato::num($doc['tipos']['des_t']) : '' }}" placeholder="{{ Formato::num($desLegal) }}" style="width:90px;text-align:right" @disabled($bloq)> %</span>
        @elseif($campo === 'irpf')<span style="display:flex;align-items:center;gap:6px"><input class="inp lb-in mono" name="tipos[irpf]" value="{{ isset($doc['tipos']['irpf']) ? Formato::num($doc['tipos']['irpf']) : '' }}" placeholder="{{ Formato::num($ir['tipo']) }}" style="width:90px;text-align:right" @disabled($bloq)> % <small style="color:#6A6A65" title="{{ $irTxt }}">AEAT {{ Formato::num($ir['tipo']) }} %</small></span>
        @else<span class="mono12">{{ $pct }}</span>@endif
      </span>
      <span></span><span class="r m">{{ $E($v) }}</span><span></span>
    </div>
  @endforeach
  @if($n['T']['hx'] ?? 0)<div class="ne-grid ne-row"><span></span><span>Cotización adicional por horas extraordinarias</span><span class="mono12">4,70 %</span><span></span><span class="r m">{{ $E($n['T']['hx']) }}</span><span></span></div>@endif
  @foreach($doc['deducciones'] as $i => $d)
    <div class="ne-grid ne-row">
      <span></span>
      <span><input type="hidden" name="d[{{ $i }}][id]" value="{{ $d['id'] }}"><input class="inp lb-in" name="d[{{ $i }}][concepto]" value="{{ $d['concepto'] }}" @disabled($bloq)></span>
      <span style="font-size:12px;color:#6A6A65">Deducción</span><span></span>
      <input class="inp lb-in mono" name="d[{{ $i }}][importe]" value="{{ $E($d['importe']) }}" style="text-align:right" inputmode="decimal" @disabled($bloq)>
      <span>@unless($bloq)<button type="button" class="lb-del" data-ne-del title="Quitar">×</button>@endunless</span>
    </div>
  @endforeach
  @unless($bloq)
  <div class="ne-grid ne-row ne-new">
    <span style="font-size:18px;color:#6A6A65;text-align:center">+</span>
    <input class="inp lb-in" name="nd[concepto]" placeholder="Añadir deducción (embargo, anticipo, préstamo…)">
    <span></span><span></span>
    <input class="inp lb-in mono" name="nd[importe]" placeholder="0,00" style="text-align:right" inputmode="decimal"><span></span>
  </div>
  @endunless

  <div class="ne-tot">
    @foreach([['Remuneración total', $n['dev'] - $n['die'] - $n['km']], ['Prorrata pagas', $n['prorB']], ['Base total cotización', $n['base']], ['Base IRPF', $n['baseIrpf']], ['Base H.E.', $n['hx']], ['Base AT y EP', $n['baseAT']]] as [$l, $v])
      <div><span>{{ $l }}</span><b class="mono12">{{ $E($v) }}</b></div>
    @endforeach
  </div>
  <div class="ne-tot ne-tot2">
    <div><span>A. Total devengado</span><b>{{ $E($n['dev']) }}</b></div>
    <div><span>B. Total a deducir</span><b>{{ $E($n['ssT'] + $n['irpf'] + $n['ded']) }}</b></div>
    <div class="liq"><span>Líquido a percibir (A − B)</span><b>{{ $E($n['liq']) }} €</b></div>
    <div><span>C. Total empresa</span><b>{{ $E($n['ssE']) }}</b></div>
    <div><span>Total (A + C)</span><b>{{ $E($n['dev'] + $n['ssE']) }}</b></div>
  </div>
  @unless($g)<span style="font-size:12px;color:#6A6A65">Nómina calculada automáticamente desde el convenio, la ficha y las variables del mes. Si cambias algo y pulsas «Guardar», queda como borrador propio de este mes.</span>@endunless
</form>
<iframe class="lb-recibo" title="Nómina" src="{{ route('laboral.recibo', [$w['id']] + $q) }}"></iframe>

@elseif($ed['tab'] === 'variables')
<form class="card" method="POST" action="{{ route('laboral.incidencias', $w['id']) }}">
  @csrf<input type="hidden" name="anio" value="{{ $anio }}"><input type="hidden" name="mes" value="{{ $mes }}">
  <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap"><span style="font-size:15px;font-weight:700">Variables del mes</span>@if(! $asiento)<button class="btn sm" type="submit">Guardar variables</button>@endif</div>
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(min(100%,180px),1fr));gap:12px">
    @foreach(Nomina::INCIDENCIAS as $k => $l)
      <label class="lb-f"><span class="lb-l">{{ $l }}</span><input class="inp lb-in mono" name="{{ $k }}" value="{{ isset($inc[$k]) ? Formato::num($inc[$k]) : '' }}" placeholder="0,00" inputmode="decimal" style="text-align:right" @disabled($asiento)></label>
    @endforeach
  </div>
  @if($g)<div class="msg warn" style="font-weight:500">Esta nómina tiene un borrador guardado: las variables nuevas se aplican al pulsar «Recalcular desde convenio» en el Borrador, o añadiéndolas allí como concepto.</div>@endif
</form>

@elseif($ed['tab'] === 'convenio')
<div class="card">
  <span style="font-size:15px;font-weight:700">{{ $P['conv']['nombre'] ?: 'Sin convenio colectivo cargado' }}</span>
  @if($ed['cat'])
    <div class="ne-head">
      <div><span>Categoría</span><b>{{ $ed['cat']['cat'] }}</b></div><div><span>Grupo de cotización</span><b>{{ $ed['cat']['grupo'] }}</b></div>
      <div><span>Salario base convenio (jornada completa)</span><b class="mono12">{{ Formato::eur($ed['cat']['base']) }}</b></div><div><span>Plus convenio</span><b class="mono12">{{ Formato::eur($ed['cat']['plus'] ?? 0) }}</b></div>
      <div><span>Coeficiente de parcialidad</span><b class="mono12">{{ Formato::num(Nomina::n($w['jor']) / 100) }}</b></div><div><span>Pagas</span><b>{{ $P['conv']['pagas'] }}</b></div>
    </div>
    <span style="font-size:12.5px;color:#6A6A65">El Borrador toma de aquí el salario base y el plus, proporcionales a la jornada. Lo que difiera se marca como «No es por convenio».</span>
  @else
    <div class="msg warn" style="font-weight:500">{{ $w['nombre'] }} no tiene una categoría del convenio asignada ({{ $w['convCat'] ?: 'sin categoría' }}). Asígnala en su ficha › Contrato, o carga las tablas en Gestión › Convenios.</div>
  @endif
  <div style="display:flex;gap:10px;flex-wrap:wrap"><a class="btn white" href="{{ route('laboral.convenios') }}">Ver el convenio</a><a class="btn white" href="{{ route('laboral.ficha', [$w['id'], 'sec' => 'contrato']) }}">Categoría en la ficha</a></div>
</div>

@else
<div class="card" style="padding:6px 18px 10px;overflow-x:auto">
  <div style="min-width:760px">
    <div class="lb-grid lb-nom lb-head"><span>Mes</span><span class="r">Devengado</span><span class="r">Base CC</span><span class="r">SS trabajador</span><span class="r">IRPF</span><span class="r">Líquido</span><span class="r">Estado</span></div>
    @foreach($ed['anio'] as $x)
      <a class="lb-grid lb-nom lb-row" href="{{ route('laboral.integral', ['anio' => $anio, 'mes' => $x['m'], 't' => $w['id']]) }}">
        <span style="font-weight:600">{{ Nomina::MESES[$x['m'] - 1] }}</span><span class="r m">{{ $E($x['n']['dev']) }}</span><span class="r m">{{ $E($x['n']['base']) }}</span><span class="r m">{{ $E($x['n']['ssT']) }}</span><span class="r m">{{ $E($x['n']['irpf']) }}</span><span class="r m" style="font-weight:700">{{ $E($x['n']['liq']) }}</span>
        <span class="r"><span class="lb-pill" style="background:{{ $x['n']['estado'] === 'Emitida' ? '#E6F6A8' : ($x['n']['estado'] ? '#FCEBCB' : '#ECECE7') }};color:#0B0B0B">{{ $x['n']['estado'] ?? 'Calculada' }}</span></span>
      </a>
    @endforeach
  </div>
</div>
@endif
