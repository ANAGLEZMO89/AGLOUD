{{-- Integral de nóminas · nómina (o recibo aparte) de un trabajador: borrador editable conectado al convenio --}}
@php
  use App\Services\Nomina;
  use App\Support\Formato;
  use Illuminate\Support\Carbon;
  $w = $sel['w']; $n = $sel['n'];
  $E = fn ($v) => Formato::num($v);
  $rq = $ed['recibo'];
  $qr = $q + ['t' => $w['id']] + ($rq !== 'Nómina' ? ['recibo' => $rq] : []);
  $prev = $idx > 0 ? $noms[$idx - 1]['w']['id'] : null; $next = $idx < count($noms) - 1 ? $noms[$idx + 1]['w']['id'] : null;
  $g = $ed['g']; $doc = $ed['doc']; $av = $ed['avisos'];
  $emitida = $g?->emitida(); $bloq = $emitida || $asiento;
  $nAv = count($av['lineas']) + count($av['generales']);
  $tabUrl = fn ($v) => route('laboral.integral', $qr + ['vista' => $v]);
  $ini = Carbon::create($anio, $mes, 1); $ult = $ini->copy()->endOfMonth();
  $desLegal = ($n['tmp'] ? Nomina::TIPOS['des_tmp'][0] : Nomina::TIPOS['des_ind'][0]) * 100;
  $irTxt = $ir['fijo'] ? 'Tipo fijo del '.Formato::num($ir['tipo']).' % indicado en la ficha del trabajador.'
    : 'Calculado con el algoritmo de la AEAT: '.Formato::num($ir['tipoAnual']).' % anual'.(abs($ir['tipo'] - $ir['tipoAnual']) > 0.001 ? ', regularizado al '.Formato::num($ir['tipo']).' % este mes' : '').'.';
  // Estado y origen reales: la tabla del convenio solo se usa si el trabajador tiene una categoría que existe en ella
  $catConv = ($w['convCat'] ?? '') !== '' ? collect($P['conv']['cats'] ?? [])->firstWhere('cat', $w['convCat']) : null;
  $origen = $catConv ? 'Calculada con la ficha y la tabla del convenio' : (($P['conv']['cats'] ?? []) ? 'Calculada con la ficha (sin categoría del convenio asignada)' : 'Calculada con la ficha (el convenio no tiene tablas salariales)');
  $estado = $emitida ? ['Emitida '.Formato::fechaHora($g->emitida_at), '#E6F6A8', '#2E3A00'] : ($g ? ['Borrador guardado', '#FCEBCB', '#6E4300'] : [$rq === 'Nómina' ? $origen : 'Sin guardar', $catConv || $rq !== 'Nómina' ? '#ECECE7' : '#FCEBCB', $catConv || $rq !== 'Nómina' ? '#3C3C3C' : '#6E4300']);
  [$pDesde, $pHasta, $pDias, $pMes] = Nomina::periodo($w, $anio, $mes);
  $aut = Nomina::esAutonomo($w);
  // Fecha de pago: del día 25 del mes al día 10 del siguiente
  $fpSel = old('fecha_pago', $g?->fecha_pago?->toDateString() ?? $ult->toDateString());
  $fechas = [];
  for ($d = $ini->copy()->day(min(25, $ult->day)); $d->lte($ult->copy()->addDays(10)); $d->addDay()) { $fechas[$d->toDateString()] = $d->day.' de '.mb_strtolower(Nomina::MESES[$d->month - 1]).' de '.$d->year; }
  if (! isset($fechas[$fpSel])) { $f = Carbon::parse($fpSel); $fechas = [$fpSel => $f->day.' de '.mb_strtolower(Nomina::MESES[$f->month - 1]).' de '.$f->year] + $fechas; }
  // Catálogo general + pluses del convenio que dependen de horas o días concretos (nocturnidad, festivos…)
  $convCat = collect($w['_conv']['pluses'] ?? [])->reject(fn ($p) => ! empty($p['auto']) || ($p['soportado'] ?? true) === false)->map(fn ($p) => ['06', $p['nombre'], $p['cotiza'] ? 'salarial' : ($p['tributa'] ? 'tributa' : 'no_salarial'),
      'Convenio: '.($p['tipo'] === 'porcentaje_base' ? Formato::num($p['valor']).' % del salario base' : Formato::eur($p['valor'])).' '.(\App\Models\ConvenioConcepto::UNIDADES[$p['unidad']] ?? '')]);
  if ($h = $w['_conv']['hx'] ?? null) { $convCat->push(['10', 'Horas extraordinarias', 'hx', 'Convenio: '.($h['tipo'] === 'importe' ? Formato::eur($h['valor']).' la hora' : 'recargo del '.Formato::num($h['valor'], 0).' %')]); }
  $cat = collect(Nomina::CATALOGO)->mapWithKeys(fn ($x) => [$x[1] => [$x[0], $x[2]]])->merge($convCat->mapWithKeys(fn ($x) => [$x[1] => [$x[0], $x[2]]]))->all();
  $embImp = collect($n['deducciones'])->filter(fn ($d) => ! empty($d['ref']))->keyBy('ref');
  $claseSel = fn ($name, $val, $dis = false) => '<select class="sel lb-in" name="'.$name.'" title="Cómo cotiza y tributa"'.($dis ? ' disabled' : '').' data-ne-clase>'.collect(Nomina::CLASES)->map(fn ($t, $k) => '<option value="'.$k.'"'.($val === $k ? ' selected' : '').'>'.e($t).'</option>')->implode('').'</select>';
@endphp
<div class="ne-top">
  <div style="display:flex;flex-direction:column;gap:2px;min-width:0;margin-right:auto"><span style="font-size:18px;font-weight:700">{{ $w['nombre'] }}</span><span style="font-size:12.5px;color:#6A6A65">{{ Nomina::RECIBOS[$rq] }} · {{ Nomina::MESES[$mes - 1] }} {{ $anio }} · @if($aut)<span class="lb-pill" style="background:#E3ECFF;color:#1D3A8A">RETA</span> @endif<span class="lb-pill" style="background:{{ $estado[1] }};color:{{ $estado[2] }}">{{ $estado[0] }}</span></span></div>
  @if($prev)<a class="btn white sm" href="{{ route('laboral.integral', $q + ['t' => $prev]) }}">← Anterior</a>@endif
  @if($next)<a class="btn white sm" href="{{ route('laboral.integral', $q + ['t' => $next]) }}">Siguiente →</a>@endif
</div>
<div class="chipbar" style="border-radius:11px">
  @foreach([['contrato', 'Contrato', route('laboral.ficha', [$w['id'], 'sec' => 'contrato'])], ['nominas', 'Nóminas', $tabUrl('nominas')], ['m145', 'Mod. 145', route('laboral.ficha', [$w['id'], 'sec' => 'irpf'])], ['borrador', 'Borrador', $tabUrl('borrador')], ['variables', 'Variables', $tabUrl('variables')], ['calendario', 'Calendario', $tabUrl('calendario')], ['convenio', 'Convenio', $tabUrl('convenio')]] as [$id, $l, $u])
    <a class="{{ $ed['tab'] === $id ? 'on' : '' }}" style="padding:7px 13px;border-radius:8px" href="{{ $u }}">{{ $l }}</a>
  @endforeach
</div>

<datalist id="ne-cat">@foreach($convCat as [$cc, $cn, $ck, $ct])<option value="{{ $cn }}">{{ $ct }}</option>@endforeach @foreach(Nomina::CATALOGO as [$cc, $cn, $ck])<option value="{{ $cn }}">[{{ $cc }}] {{ Nomina::CLASES[$ck] }}</option>@endforeach</datalist>
<datalist id="ne-ded">@foreach(Nomina::CATALOGO_DED as $x)<option value="{{ $x }}"></option>@endforeach</datalist>

@if($ed['tab'] === 'borrador')
<form method="POST" action="{{ route('laboral.nomina.guardar', $w['id']) }}" class="card ne" data-ne data-ne-cat="{{ json_encode($cat, JSON_UNESCAPED_UNICODE) }}">
  @csrf<input type="hidden" name="anio" value="{{ $anio }}"><input type="hidden" name="mes" value="{{ $mes }}"><input type="hidden" name="recibo" value="{{ $rq }}">
  <div class="ne-bar no-print">
    @unless($bloq)
      <button class="btn" type="submit" name="accion" value="guardar">Guardar</button>
      <button class="btn lime" type="submit" name="accion" value="emitir" @if($nAv) data-confirm-btn="Este recibo tiene {{ $nAv }} {{ $nAv === 1 ? 'concepto que no es' : 'conceptos que no son' }} por convenio. ¿Emitirlo igualmente?" @endif>Emitir {{ $rq === 'Nómina' ? 'nómina' : 'recibo' }}</button>
    @endunless
    @if($emitida && ! $asiento)<input class="inp" name="motivo" required minlength="5" maxlength="255" placeholder="Motivo de la rectificación" aria-label="Motivo de la rectificación" style="max-width:260px"><button class="btn white" type="submit" name="accion" value="reabrir">Reabrir para rectificar</button>@endif
    <a class="btn white" href="{{ route('laboral.recibo', [$w['id']] + $q + ($rq !== 'Nómina' ? ['recibo' => $rq] : []) + ['imprimir' => 1]) }}" target="_blank" rel="noopener">Vista previa / PDF</a>
    <a class="btn white" href="{{ route('laboral.ficha', [$w['id'], 'sec' => 'irpf']) }}">IRPF y mod. 145</a>
    @if($g && ! $bloq)<button class="btn soft" type="submit" name="accion" value="recalcular" data-confirm-btn="Se descartan los cambios de este recibo y se vuelve a calcular desde el convenio y la ficha. ¿Seguir?">Recalcular desde convenio</button>@endif
    @if($g && ! $bloq && $rq !== 'Nómina')<button class="btn white" type="submit" name="accion" value="eliminar" data-confirm-btn="¿Eliminar este recibo de {{ mb_strtolower(Nomina::RECIBOS[$rq]) }}?">Eliminar recibo</button>@endif
  </div>
  @if($asiento)<div class="msg warn" style="font-weight:500">Las nóminas de este mes están contabilizadas (asiento {{ $asiento->numero }}): para cambiarlas, borra antes el asiento en Contabilidad.</div>@endif
  @if($nAv)
    <div class="ne-aviso"><b>⚠ {{ $nAv }} {{ $nAv === 1 ? 'concepto no es' : 'conceptos no son' }} por convenio</b>@foreach($av['generales'] as $x)<span>{{ $x }}</span>@endforeach<span>Las líneas afectadas están marcadas en amarillo.</span></div>
  @endif
  @foreach($av['errores'] ?? [] as $x)<div class="ferr" role="alert" style="font-weight:500"><b>Error:</b> {{ $x }}</div>@endforeach
  @foreach($av['calendario'] ?? [] as $x)<div class="msg warn" style="font-weight:500">{{ $x }}</div>@endforeach
  @foreach($av['info'] ?? [] as $x)<div class="msg" style="font-weight:500;background:#E3ECFF;color:#1D3A8A">{{ $x }}</div>@endforeach
  @if($rq !== 'Nómina' && ! $g)<div class="msg" style="font-weight:500;background:#E3ECFF;color:#1D3A8A">Este recibo todavía no existe: revisa los conceptos propuestos y pulsa «Guardar» para crearlo. {{ ['Extra' => 'Mientras exista, la paga de este mes no se repite en la nómina.', 'Finiquito' => 'Las vacaciones, las partes proporcionales y la indemnización se proponen con la fecha y la causa de la baja: compruébalas con tu convenio.', 'Atrasos' => 'Los atrasos cotizan en una liquidación complementaria (L03) del mes al que corresponden.', 'Sal. tramitación' => 'Los salarios de tramitación cotizan en la liquidación correspondiente al periodo que cubren.'][$rq] ?? '' }}</div>@endif
  @unless($ed['cat'] || $aut || $rq !== 'Nómina')
    <div class="msg warn" style="font-weight:500">{{ $w['nombre'] }} no tiene asignada una categoría de un convenio cargado: el salario no se puede comparar con el convenio. Asígnala en la ficha › Contrato (las tablas se cargan en Gestión › Convenios).</div>
  @endunless

  <div class="ne-head">
    @foreach([['C.C.C.', $w['ccc'] ?: '—'], ['Grupo', $w['grupo']], ['Contrato', $aut ? 'RETA · '.($w['autTipo'] ?? 'Autónomo') : Nomina::codigo($w['contrato'])], ['Ocupación', $w['cno'] ?: '—'], ['Categoría profesional', ($w['convCat'] ?: $w['cat']) ?: '—'], ['Nº afiliación', $w['naf']], ['Nº documento', $w['nif']]] as [$l, $v])
      <div><span>{{ $l }}</span><b class="mono12">{{ $v }}</b></div>
    @endforeach
  </div>
  <div class="ne-head">
    <label class="ne-f"><span>Recibo</span>
      <select class="sel lb-in" data-nav aria-label="Tipo de recibo">
        @foreach(Nomina::RECIBOS as $k => $t)<option value="{{ route('laboral.integral', $q + ['t' => $w['id']] + ($k !== 'Nómina' ? ['recibo' => $k] : [])) }}" @selected($rq === $k)>{{ $t }}{{ in_array($k, $ed['recibos'], true) ? ' ✓' : '' }}</option>@endforeach
      </select></label>
    <label class="ne-f"><span>Mes de cálculo</span>
      <select class="sel lb-in" data-nav aria-label="Mes de cálculo">
        @foreach(range(1, 12) as $x)<option value="{{ route('laboral.integral', ['anio' => $anio, 'mes' => $x, 't' => $w['id']] + ($rq !== 'Nómina' ? ['recibo' => $rq] : [])) }}" @selected($x === $mes)>{{ mb_strtolower(Nomina::MESES[$x - 1]) }} de {{ $anio }}</option>@endforeach
      </select></label>
    <label class="ne-f"><span>Fecha de pago</span>
      <select class="sel lb-in" name="fecha_pago" @disabled($bloq)>@foreach($fechas as $k => $t)<option value="{{ $k }}" @selected($k === $fpSel)>{{ $t }}</option>@endforeach</select></label>
    <div><span>Periodo de liquidación</span><b class="mono12">{{ Formato::fecha($pDesde) }} - {{ Formato::fecha($pHasta) }}</b></div>
    <div><span>Días en alta</span><b class="mono12">{{ $pDias }}{{ $pDias < $pMes ? ' de '.$pMes : '' }}</b></div>
    <div><span>Antigüedad</span><b class="mono12">{{ Formato::fecha($w['alta']) }}</b></div>
    <div><span>Coeficiente de parcialidad</span><b class="mono12">{{ Formato::num(Nomina::n($w['jor']) / 100) }}</b></div>
  </div>

  <div class="ne-wrap">
  <div class="ne-grid ne-hd"><span></span><span>Concepto</span><span class="r">Cuantía</span><span class="r">Precio</span><span>Tipo</span><span class="r">Devengos</span><span class="r">Deducciones</span><span></span></div>
  @foreach($doc['lineas'] as $i => $l)
    @php $aviso = $av['lineas'][$i] ?? null; $act = ($l['activo'] ?? true) !== false; $manual = in_array($l['origen'] ?? '', ['manual', 'fijo'], true); @endphp
    <div class="ne-grid ne-row {{ $aviso ? 'ne-warn' : '' }} {{ $act ? '' : 'ne-off' }}">
      <span class="mono12" style="color:#6A6A65">{{ $l['cod'] ? '['.$l['cod'].']' : '·' }}</span>
      <span style="display:flex;flex-direction:column;gap:3px;min-width:0">
        <input type="hidden" name="l[{{ $i }}][id]" value="{{ $l['id'] }}"><input type="hidden" name="l[{{ $i }}][cod]" value="{{ $l['cod'] }}">
        <input class="inp lb-in" name="l[{{ $i }}][concepto]" value="{{ $l['concepto'] }}" @disabled($bloq)>
        @if($aviso)<small class="ne-av">⚠ {{ $aviso }}</small>@endif
        @if(! empty($l['expl']) || ! empty($l['regla']))<details class="ne-expl"><summary>Cómo se calcula</summary>@if(! empty($l['expl']))<span class="mono12">{{ $l['expl'] }}</span>@endif @if(! empty($l['regla']))<span>Origen: {{ $l['regla'] }}</span>@endif</details>@endif
      </span>
      <input class="inp lb-in mono" name="l[{{ $i }}][cant]" value="{{ isset($l['cant']) && $l['cant'] !== null ? $E($l['cant']) : '' }}" style="text-align:right" inputmode="decimal" data-ne-cant @disabled($bloq)>
      <input class="inp lb-in mono" name="l[{{ $i }}][precio]" value="{{ isset($l['precio']) && $l['precio'] !== null ? Formato::num($l['precio'], 4) : '' }}" style="text-align:right" inputmode="decimal" data-ne-precio @disabled($bloq)>
      <span style="display:flex;gap:6px;align-items:center;min-width:0">
        {!! $claseSel('l['.$i.'][clase]', $l['clase'], $bloq) !!}
        @if($l['clase'] === 'extra')<select class="sel lb-in" name="l[{{ $i }}][cobro]" style="width:92px;flex:none" @disabled($bloq) title="Cobro de la paga"><option value="prorrateada" @selected(($l['cobro'] ?? '') === 'prorrateada')>Prorrat.</option><option value="en_su_mes" @selected(($l['cobro'] ?? '') !== 'prorrateada')>En su mes</option></select>@endif
      </span>
      <input class="inp lb-in mono" name="l[{{ $i }}][importe]" value="{{ $E($l['importe']) }}" style="text-align:right" inputmode="decimal" data-ne-imp @disabled($bloq)>
      <span></span>
      <span class="ne-acc">
        <label class="ne-sw" title="Incluir en el recibo"><input type="checkbox" name="l[{{ $i }}][activo]" value="1" @checked($act) @disabled($bloq)><i></i></label>
        @if($rq === 'Nómina' && $manual)<label class="ne-fijo" title="Repetir todos los meses"><input type="checkbox" name="l[{{ $i }}][fijo]" value="1" @checked(! empty($l['fijo'])) @disabled($bloq)> Fijo</label>@endif
        @if($manual && ! $bloq)<button type="button" class="lb-del" data-ne-del title="Quitar">×</button>@endif
      </span>
    </div>
  @endforeach
  @unless($bloq)
    @foreach([0, '__i__'] as $k)
    <div class="ne-grid ne-row ne-new" @if($k === '__i__') data-ne-tpl="conc" hidden @else data-ne-list="conc" @endif>
      <span style="font-size:18px;color:#6A6A65;text-align:center">+</span>
      <span style="display:flex;flex-direction:column;gap:3px;min-width:0"><input type="hidden" name="nl[{{ $k }}][cod]" value="" data-ne-cod><input class="inp lb-in" name="nl[{{ $k }}][concepto]" list="ne-cat" placeholder="Elige un concepto o escríbelo" data-ne-concepto autocomplete="off" @disabled($k === '__i__')></span>
      <input class="inp lb-in mono" name="nl[{{ $k }}][cant]" style="text-align:right" inputmode="decimal" placeholder="Cant." data-ne-cant @disabled($k === '__i__')>
      <input class="inp lb-in mono" name="nl[{{ $k }}][precio]" style="text-align:right" inputmode="decimal" placeholder="Precio" data-ne-precio @disabled($k === '__i__')>
      <span>{!! $claseSel('nl['.$k.'][clase]', 'salarial', $k === '__i__') !!}</span>
      <input class="inp lb-in mono" name="nl[{{ $k }}][importe]" placeholder="0,00" style="text-align:right" inputmode="decimal" data-ne-imp @disabled($k === '__i__')>
      <span></span>
      <span class="ne-acc">@if($rq === 'Nómina')<label class="ne-fijo" title="Repetir todos los meses"><input type="checkbox" name="nl[{{ $k }}][fijo]" value="1" @disabled($k === '__i__')> Fijo</label>@endif</span>
    </div>
    @endforeach
    <div class="ne-add"><button type="button" class="btn white sm" data-ne-add="conc">+ Añadir concepto</button><span>Elige de la lista de conceptos retributivos o escribe uno nuevo. «Fijo» lo repite en las nóminas de los meses siguientes.</span></div>
  @endunless

  <div class="ne-sep"></div>
  @php
    $filasDed = [['Contingencias comunes', Formato::num(Nomina::TIPOS['cc'][0] * 100).' %', $n['T']['cc'], null], ['Desempleo', null, $n['T']['des'], 'des_t'], ['Formación profesional', Formato::num(Nomina::TIPOS['fp'][0] * 100).' %', $n['T']['fp'], null], ['IRPF', null, $n['irpf'], 'irpf'], ['Mecanismo de Equidad Intergeneracional (MEI)', Formato::num(Nomina::TIPOS['mei'][0] * 100).' %', $n['T']['mei'], null]];
    if ($aut || $rq === 'Extra') { $filasDed = array_values(array_filter($filasDed, fn ($f) => $f[3] === 'irpf')); }
  @endphp
  @foreach($filasDed as [$l, $pct, $v, $campo])
    <div class="ne-grid ne-row">
      <span></span><span>{{ $l }}</span><span></span><span></span>
      <span>
        @if($campo === 'des_t')<span style="display:flex;align-items:center;gap:6px"><input class="inp lb-in mono" name="tipos[des_t]" value="{{ isset($doc['tipos']['des_t']) ? Formato::num($doc['tipos']['des_t']) : '' }}" placeholder="{{ Formato::num($desLegal) }}" style="width:90px;text-align:right" @disabled($bloq)> %</span>
        @elseif($campo === 'irpf')<span style="display:flex;align-items:center;gap:6px;flex-wrap:wrap"><input class="inp lb-in mono" name="tipos[irpf]" value="{{ isset($doc['tipos']['irpf']) ? Formato::num($doc['tipos']['irpf']) : '' }}" placeholder="{{ Formato::num($ir['tipo']) }}" style="width:90px;text-align:right" data-ne-irpf @disabled($bloq)> %
          @unless($bloq)<button type="button" class="ne-aeat" data-ne-aeat title="{{ $irTxt }}">{{ $ir['fijo'] ? 'Ficha' : 'AEAT' }} {{ Formato::num($ir['tipo']) }} %</button>@endunless</span>
        @else<span class="mono12">{{ $pct }}</span>@endif
      </span>
      <span></span><span class="r m">{{ $E($v) }}</span><span></span>
    </div>
  @endforeach
  @if(($n['T']['hx'] ?? 0) > 0)<div class="ne-grid ne-row"><span></span><span>Cotización adicional por horas extraordinarias</span><span></span><span></span><span class="mono12">4,70 %</span><span></span><span class="r m">{{ $E($n['T']['hx']) }}</span><span></span></div>@endif
  @foreach($doc['deducciones'] as $i => $d)
    @if(! empty($d['ref']))
      @php $ei = (float) ($embImp[$d['ref']]['importe'] ?? 0); @endphp
      <div class="ne-grid ne-row ne-embr">
        <span class="mono12" style="color:#6A6A65">EMB</span>
        <span style="display:flex;flex-direction:column;gap:2px;min-width:0"><input type="hidden" name="d[{{ $i }}][ref]" value="{{ $d['ref'] }}"><b style="font-size:13.5px">{{ $d['concepto'] }}</b>
          <small style="color:#6A6A65">{{ ($d['modo'] ?? 'lec') === 'fijo' ? 'Cantidad fija de '.Formato::eur($d['cuota']).' al mes' : 'Según la escala del art. 607 LEC (el SMI es inembargable)' }} · pendiente {{ Formato::eur($d['pend']) }} de {{ Formato::eur($d['total']) }}</small></span>
        <span></span><span></span>
        <span>@unless($bloq)<label class="ne-fijo"><input type="checkbox" name="emb_lev[]" value="{{ $d['ref'] }}"> Levantar embargo</label>@endunless</span>
        <span></span><span class="r m">{{ $E($ei) }}</span><span></span>
      </div>
    @else
      <div class="ne-grid ne-row">
        <span></span>
        <span><input type="hidden" name="d[{{ $i }}][id]" value="{{ $d['id'] }}"><input class="inp lb-in" name="d[{{ $i }}][concepto]" value="{{ $d['concepto'] }}" list="ne-ded" @disabled($bloq)></span>
        <span></span><span></span><span style="font-size:12px;color:#6A6A65">Deducción</span><span></span>
        <input class="inp lb-in mono" name="d[{{ $i }}][importe]" value="{{ $E($d['importe']) }}" style="text-align:right" inputmode="decimal" @disabled($bloq)>
        <span class="ne-acc">@if($rq === 'Nómina')<label class="ne-fijo" title="Repetir todos los meses"><input type="checkbox" name="d[{{ $i }}][fijo]" value="1" @checked(! empty($d['fijo'])) @disabled($bloq)> Fijo</label>@endif @unless($bloq)<button type="button" class="lb-del" data-ne-del title="Quitar">×</button>@endunless</span>
      </div>
    @endif
  @endforeach
  @unless($bloq)
    @foreach([0, '__i__'] as $k)
    <div class="ne-grid ne-row ne-new" @if($k === '__i__') data-ne-tpl="ded" hidden @else data-ne-list="ded" @endif>
      <span style="font-size:18px;color:#6A6A65;text-align:center">+</span>
      <input class="inp lb-in" name="nd[{{ $k }}][concepto]" list="ne-ded" placeholder="Añadir deducción (anticipo, préstamo, cuota sindical…)" autocomplete="off" @disabled($k === '__i__')>
      <span></span><span></span><span></span><span></span>
      <input class="inp lb-in mono" name="nd[{{ $k }}][importe]" placeholder="0,00" style="text-align:right" inputmode="decimal" @disabled($k === '__i__')>
      <span class="ne-acc">@if($rq === 'Nómina')<label class="ne-fijo" title="Repetir todos los meses"><input type="checkbox" name="nd[{{ $k }}][fijo]" value="1" checked @disabled($k === '__i__')> Fijo</label>@endif</span>
    </div>
    @endforeach
    <div class="ne-add">
      <button type="button" class="btn white sm" data-ne-add="ded">+ Añadir deducción</button>
      <details class="ne-emb" @if(old('emb_new.total')) open @endif>
        <summary class="btn white sm">+ Embargo</summary>
        <div class="ne-embf">
          <b>Nuevo embargo de salario</b>
          <label class="ne-f"><span>Organismo que lo ordena</span><select class="sel lb-in" name="emb_new[organismo]"><option value="">Elige…</option>@foreach(Nomina::ORGANISMOS_EMBARGO as $o)<option @selected(old('emb_new.organismo') === $o)>{{ $o }}</option>@endforeach</select></label>
          <label class="ne-f"><span>Nº de expediente o diligencia</span><input class="inp lb-in" name="emb_new[expediente]" value="{{ old('emb_new.expediente') }}" maxlength="60"></label>
          <label class="ne-f"><span>Importe total a retener (€)</span><input class="inp lb-in mono" name="emb_new[total]" value="{{ old('emb_new.total') }}" inputmode="decimal" placeholder="0,00"></label>
          <label class="ne-f"><span>Cálculo</span><select class="sel lb-in" name="emb_new[modo]"><option value="lec">Escala del art. 607 LEC (automático)</option><option value="fijo" @selected(old('emb_new.modo') === 'fijo')>Cantidad fija mensual</option></select></label>
          <label class="ne-f"><span>Cantidad fija mensual (€)</span><input class="inp lb-in mono" name="emb_new[cuota]" value="{{ old('emb_new.cuota') }}" inputmode="decimal" placeholder="Solo si es cantidad fija"></label>
          <span class="ne-note">Se retiene cada mes desde esta nómina hasta cubrir el total. Con la escala del art. 607 LEC el SMI ({{ Formato::eur(Nomina::SMI_ANUAL / 14) }} al mes) es inembargable; sobre el resto: 30 %, 50 %, 60 %, 75 % y 90 % por tramos. Las pensiones de alimentos (art. 608 LEC) las fija el juzgado: usa la cantidad fija. Pulsa «Guardar» para darlo de alta.</span>
        </div>
      </details>
    </div>
  @endunless
  </div>

  <div class="ne-tot">
    @foreach([['Remuneración total', $n['dev'] - $n['die'] - $n['km'] - $n['exe']], ['Prorrata pagas', $n['prorB']], ['Base total cotización', $n['base']], ['Base IRPF', $n['baseIrpf']], ['Base H.E.', $n['hx']], ['Base AT y EP', $n['baseAT']]] as [$l, $v])
      <div><span>{{ $l }}</span><b class="mono12">{{ $E($v) }}</b></div>
    @endforeach
  </div>
  @if(! empty($n['baseExpl']))
    <details class="ne-expl" style="font-size:12.5px;color:#3C3C3C;padding:6px 2px"><summary style="cursor:pointer;font-weight:600">Cómo se ha calculado la base de cotización</summary>
      <ul style="margin:6px 0 0;padding-left:18px;line-height:1.5">@foreach($n['baseExpl'] as $t)<li>{{ $t }}</li>@endforeach</ul>
    </details>
  @endif
  <div class="ne-tot ne-tot2">
    <div><span>A. Total devengado</span><b>{{ $E($n['dev']) }}</b></div>
    <div><span>B. Total a deducir</span><b>{{ $E($n['ssT'] + $n['irpf'] + $n['ded'] + $n['esp']) }}</b></div>
    <div class="liq"><span>Líquido a percibir (A − B)</span><b>{{ $E($n['liq']) }} €</b></div>
    <div><span>C. Total empresa</span><b>{{ $E($n['ssE']) }}</b></div>
    <div><span>Total (A + C)</span><b>{{ $E($n['dev'] + $n['ssE']) }}</b></div>
  </div>
  @if($n['esp'] > 0)<span style="font-size:12px;color:#6A6A65">La retribución en especie ({{ Formato::eur($n['esp']) }}) cotiza y tributa, pero no se cobra: se descuenta del líquido.</span>@endif
  @if(! empty($ed['prev']))
    @php
      $pv = $ed['prev']['n'];
      $limpia = fn ($c) => trim(preg_replace(['/ \(del \d+ al \d+\)$/', '/ \(\d+ días\)/'], '', $c));
      $porC = fn ($ls) => collect($ls)->groupBy(fn ($l) => $limpia($l['concepto']))->map(fn ($g) => round($g->sum(fn ($l) => (float) $l['importe']), 2));
      $a1 = $porC($pv['lineas'] ?? []); $a2 = $porC($n['lineas'] ?? []);
      $difC = $a1->keys()->merge($a2->keys())->unique()->map(fn ($k) => [$k, $a1[$k] ?? 0, $a2[$k] ?? 0])->filter(fn ($x) => abs($x[2] - $x[1]) >= 0.01)->values();
    @endphp
    <details class="ne-expl" style="font-size:12.5px;color:#3C3C3C;padding:6px 2px"><summary style="cursor:pointer;font-weight:600">Diferencias con {{ $ed['prev']['txt'] }}</summary>
      <div style="display:grid;grid-template-columns:minmax(0,2fr) repeat(3,minmax(0,1fr));gap:4px 12px;margin-top:6px">
        <b>Concepto</b><b class="r">{{ $ed['prev']['txt'] }}</b><b class="r">Este mes</b><b class="r">Diferencia</b>
        @foreach([['Total devengado', $pv['dev'], $n['dev']], ['Base de cotización', $pv['base'], $n['base']], ['SS trabajador', $pv['ssT'], $n['ssT']], ['IRPF', $pv['irpf'], $n['irpf']], ['Líquido', $pv['liq'], $n['liq']]] as [$l, $x, $y])
          <span style="font-weight:600">{{ $l }}</span><span class="mono12" style="text-align:right">{{ $E($x) }}</span><span class="mono12" style="text-align:right">{{ $E($y) }}</span><span class="mono12" style="text-align:right;color:{{ $y - $x < -0.005 ? '#8A1C1C' : ($y - $x > 0.005 ? '#2E3A00' : '#6A6A65') }}">{{ $y - $x > 0.005 ? '+' : '' }}{{ $E($y - $x) }}</span>
        @endforeach
        @foreach($difC as [$k, $x, $y])
          <span>{{ $k }}</span><span class="mono12" style="text-align:right">{{ $E($x) }}</span><span class="mono12" style="text-align:right">{{ $E($y) }}</span><span class="mono12" style="text-align:right">{{ $y - $x > 0.005 ? '+' : '' }}{{ $E($y - $x) }}</span>
        @endforeach
      </div>
      @if($difC->isEmpty())<span>Mismos conceptos e importes que {{ $ed['prev']['txt'] }}.</span>@endif
    </details>
  @endif
  @if(! empty($doc['historial']))
    <details class="ne-expl" style="font-size:12.5px;color:#3C3C3C;padding:6px 2px" open><summary style="cursor:pointer;font-weight:600">Historial de rectificaciones ({{ count($doc['historial']) }})</summary>
      @foreach(array_reverse($doc['historial']) as $h)
        <span style="display:block;padding:4px 0;border-top:1px solid #F1F1EC">Emitida {{ $h['emitida_at'] ? Formato::fechaHora(\Illuminate\Support\Carbon::parse($h['emitida_at'])) : '' }} · reabierta {{ Formato::fechaHora(\Illuminate\Support\Carbon::parse($h['reabierta_at'])) }} por {{ $h['usuario'] }}: «{{ $h['motivo'] }}» · devengado {{ Formato::eur((float) $h['dev']) }} · líquido {{ Formato::eur((float) $h['liq']) }}</span>
      @endforeach
    </details>
  @endif
  @unless($g)<span style="font-size:12px;color:#6A6A65">{{ $rq === 'Nómina' ? 'Nómina calculada automáticamente desde el convenio, la ficha y las variables del mes.' : 'Conceptos propuestos para este recibo.' }} Si cambias algo y pulsas «Guardar», queda como borrador propio de este mes.</span>@endunless
</form>
<iframe class="lb-recibo" title="Recibo" src="{{ route('laboral.recibo', [$w['id']] + $q + ($rq !== 'Nómina' ? ['recibo' => $rq] : [])) }}"></iframe>

@elseif($ed['tab'] === 'variables')
<form class="card" method="POST" action="{{ route('laboral.incidencias', $w['id']) }}" data-ne data-ne-cat="{{ json_encode($cat, JSON_UNESCAPED_UNICODE) }}">
  @csrf<input type="hidden" name="anio" value="{{ $anio }}"><input type="hidden" name="mes" value="{{ $mes }}">
  <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap"><span style="font-size:15px;font-weight:700">Variables de {{ mb_strtolower(Nomina::MESES[$mes - 1]) }}</span>@if(! $asiento)<button class="btn sm" type="submit">Guardar variables</button>@endif</div>
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(min(100%,180px),1fr));gap:12px">
    @foreach(Nomina::INCIDENCIAS as $k => $l)
      <label class="lb-f"><span class="lb-l">{{ $l }}</span><input class="inp lb-in mono" name="{{ $k }}" value="{{ isset($inc[$k]) ? Formato::num($inc[$k]) : '' }}" placeholder="0,00" inputmode="decimal" style="text-align:right" @disabled($asiento)></label>
    @endforeach
  </div>
  <span style="font-size:14px;font-weight:700;margin-top:6px">Otras variables del mes</span>
  <span style="font-size:12.5px;color:#6A6A65;margin-top:-6px">Elige un concepto retributivo de la lista o escríbelo a mano, con su tipo y su importe.</span>
  <div class="ne-vars">
    @php $vl = array_values($inc['lineas'] ?? []); @endphp
    @foreach(array_merge($vl, array_fill(0, 3, [])) as $i => $x)
      <div class="ne-var">
        <input class="inp lb-in" name="vl[{{ $i }}][concepto]" value="{{ $x['concepto'] ?? '' }}" list="ne-cat" placeholder="Concepto" data-ne-concepto autocomplete="off" @disabled($asiento)>
        {!! $claseSel('vl['.$i.'][clase]', $x['clase'] ?? 'salarial', (bool) $asiento) !!}
        <input class="inp lb-in mono" name="vl[{{ $i }}][importe]" value="{{ isset($x['importe']) ? Formato::num($x['importe']) : '' }}" placeholder="0,00" inputmode="decimal" style="text-align:right" @disabled($asiento)>
      </div>
    @endforeach
  </div>
  @if(Nomina::guardada((int) $w['id'], $anio, $mes))<div class="msg warn" style="font-weight:500">Esta nómina tiene un borrador guardado: las variables nuevas se aplican al pulsar «Recalcular desde convenio» en el Borrador, o añadiéndolas allí como concepto.</div>@endif
</form>

@elseif($ed['tab'] === 'calendario')
@include('laboral._calendario')
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
