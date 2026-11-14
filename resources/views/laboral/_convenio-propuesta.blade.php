{{-- Propuesta leída del PDF (con IA o pegada desde claude.ai): revisión campo a campo antes de guardarla en una versión del convenio --}}
@php
  use App\Services\ConvenioIA;
  use App\Support\Formato;
  $actCats = collect($conv['cats'] ?? [])->keyBy('cat');
  $prCats = collect($prop['cats'])->keyBy('cat');
  $quitadas = $actCats->keys()->diff($prCats->keys())->values();
  $plantilla = \App\Models\Trabajador::where('cliente_id', $c->id)->where('estado', 'Alta')->get()->map(fn ($t) => ['id' => $t->id] + ($t->datos ?? []))->filter(fn ($d) => ($d['convCat'] ?? '') !== '' && ! \App\Services\Nomina::esAutonomo($d));
  $deCat = fn ($cat) => $plantilla->filter(fn ($d) => $d['convCat'] === $cat);
  // Salario del trabajador en la unidad de la tabla, para saber si quedaría por debajo
  $suyo = fn ($d, $unidad) => $unidad === 'dia' ? (\App\Services\Nomina::diario($d) ? \App\Services\Nomina::n($d['salDia'] ?? 0) : \App\Services\Nomina::n($d['salBase'] ?? 0) / 30)
      : (\App\Services\Nomina::diario($d) ? \App\Services\Nomina::n($d['salDia'] ?? 0) * 30 : \App\Services\Nomina::n($d['salBase'] ?? 0) / ((\App\Services\Nomina::n($d['jor'] ?? 100) ?: 100) / 100));
  $ref = fn ($x) => trim(($x['art'] ?? '').(! empty($x['pag']) ? ' · pág. '.$x['pag'] : ''), ' ·');
  $estados = $prop['estados'] ?? [];
  $chip = ['encontrado' => ['#E6F6A8', '#2E3A00'], 'no_localizado' => ['#FCEBCB', '#6E4300'], 'no_existe' => ['#ECECE7', '#3C3C3C'], 'no_aplicable' => ['#ECECE7', '#3C3C3C']];
  $mes = fn ($m) => Formato::mesNombre($m);
  $extrasTxt = fn ($l) => $l ? collect($l)->map(fn ($x) => $x['nombre'].' ('.$mes($x['mes']).', '.($x['calculo'] === 'dias' ? Formato::num((float) $x['dias'], 0).' días' : ($x['calculo'] === 'importe' ? Formato::eur((float) $x['importe']) : 'mensualidad')).')')->implode(' · ') : '—';
  $antTxt = fn ($a) => $a ? 'Cada '.$a['periodo'].' años · '.($a['tipo'] === 'porcentaje' ? Formato::num((float) $a['valor']).' %' : Formato::eur((float) $a['valor'])).($a['tope'] ? ' · tope '.$a['tope'] : '') : '—';
  $plusTxt = fn ($l) => $l ? collect($l)->map(fn ($p) => $p['nombre'].' '.($p['tipo'] === 'porcentaje_base' ? Formato::num((float) $p['valor']).' %' : Formato::eur((float) $p['valor'])).' '.(\App\Models\ConvenioConcepto::UNIDADES[$p['unidad']] ?? ''))->implode(' · ') : '—';
  $itTxt = fn ($it) => collect(['cc' => 'común', 'at' => 'profesional'])->map(fn ($l, $k) => ($it[$k] ?? []) ? $l.': '.collect($it[$k])->map(fn ($t) => $t['desde'].($t['hasta'] ? '-'.$t['hasta'] : '+').' al '.Formato::num((float) $t['pct'], 0).' %')->implode(', ') : null)->filter()->implode(' · ') ?: '—';
  $hxTxt = fn ($h) => $h ? ($h['tipo'] === 'importe' ? Formato::eur((float) $h['valor']).'/h' : 'recargo '.Formato::num((float) $h['valor'], 0).' %') : '—';
  $filas = [
      'identificacion' => [trim(($conv['nombre'] ?? '').' '.($conv['codigo'] ?? '')) ?: '—', trim($prop['nombre'].' '.$prop['codigo'].' '.$prop['vigencia']) ?: '—', ''],
      'tablas' => [count($conv['cats'] ?? []).' categorías', count($prop['cats']).' categorías (detalle abajo)', ''],
      'pagas_extra' => [$extrasTxt($conv['extras'] ?? []), $extrasTxt($prop['extras']), collect($prop['extras'])->map($ref)->filter()->unique()->implode(', ')],
      'antiguedad' => [$antTxt($conv['antig'] ?? null), $antTxt($prop['antig']), $ref($prop['refs']['antiguedad'] ?? [])],
      'pluses' => [$plusTxt($conv['pluses'] ?? []), $plusTxt($prop['pluses']), collect($prop['pluses'])->map($ref)->filter()->unique()->implode(', ')],
      'it' => [$itTxt($conv['it'] ?? []), $itTxt($prop['it']), $ref($prop['refs']['it'] ?? $prop['it'])],
      'horas_extra' => [$hxTxt($conv['hx'] ?? null), $hxTxt($prop['hx']), $ref($prop['refs']['horas_extra'] ?? $prop['hx'] ?? [])],
      'jornada' => [trim(($conv['jornada'] ? $conv['jornada'].' h/año' : '').($conv['vacaciones'] ? ' · '.$conv['vacaciones'].' días de vacaciones' : '')) ?: '—', trim(($prop['jornada'] ? $prop['jornada'].' h/año' : '').($prop['vacaciones'] ? ' · '.$prop['vacaciones'].' días de vacaciones' : '')) ?: '—', ''],
  ];
  $pdf = $prop['_meta']['pdf'] ?? null;
  $verAprob = $ver && $ver->estado === 'aprobado';
  $oldCats = old('cats');
@endphp
<div class="card" style="gap:14px;border:2px solid #E0A33A" id="propuesta">
  <div style="display:flex;flex-direction:column;gap:3px">
    <span style="font-size:15px;font-weight:700">Propuesta pendiente de revisar</span>
    <span style="font-size:12.5px;color:#6A6A65;line-height:1.45">Leída el {{ Formato::fecha(substr($prop['_meta']['fecha'] ?? '', 0, 10)) }} · {{ $prop['_meta']['archivo'] ?? '' }}{{ ! empty($prop['anioTablas']) ? ' · tablas de '.$prop['anioTablas'] : '' }}.
      @if(! empty($prop['_meta']['documento_id'])) El PDF queda guardado en los documentos del convenio. @else Sin PDF adjunto: la procedencia queda como «{{ $prop['_meta']['archivo'] ?? '' }}». @endif
      @if($pdf) PDF de {{ $pdf['paginas'] }} {{ $pdf['paginas'] === 1 ? 'página' : 'páginas' }}{{ $pdf['escaneado'] ? ', escaneado (sin texto)' : ($pdf['texto'] ? ', con texto' : '') }}. @endif
      Todavía no se ha aplicado nada.</span>
  </div>

  @php $rev = collect($prop['revision'] ?? [])->groupBy(0); @endphp
  @if($rev->isNotEmpty() || ! empty($prop['avisos']))
    <div style="display:flex;flex-direction:column;gap:6px;font-size:13px;line-height:1.45">
      @foreach(['contradictorio' => ['Contradicciones', '#FBE3E3', '#8A1C1C'], 'ambiguo' => ['Dudas', '#FCEBCB', '#6E4300'], 'descartado' => ['Descartado (no se aplica)', '#ECECE7', '#3C3C3C']] as $k => [$l, $bg, $fg])
        @if($rev->has($k))<div style="padding:8px 12px;border-radius:10px;background:{{ $bg }};color:{{ $fg }}"><b>{{ $l }}:</b> {{ $rev[$k]->pluck(1)->implode(' · ') }}</div>@endif
      @endforeach
      @if(! empty($prop['avisos']))<div style="padding:8px 12px;border-radius:10px;background:#E3ECFF;color:#1D3A8A"><b>Notas de la lectura:</b> {{ implode(' · ', $prop['avisos']) }}</div>@endif
    </div>
  @endif

  <form method="POST" action="{{ route('laboral.convenios.propuesta') }}" style="display:flex;flex-direction:column;gap:14px" data-un-envio>@csrf
    @if($ver)<input type="hidden" name="version_id" value="{{ $ver->id }}">@endif
    <div class="tbl">
      <div class="th" style="grid-template-columns:44px minmax(0,1fr) 130px minmax(0,1.6fr) minmax(0,1.6fr) minmax(0,.9fr);min-width:860px"><span>Aplicar</span><span>Apartado</span><span>Lectura</span><span>Ahora</span><span>Propuesta</span><span>Artículo · página</span></div>
      @foreach(ConvenioIA::APARTADOS as $k => $l)
        @php $e = $estados[$k] ?? 'encontrado'; [$a, $p, $r] = $filas[$k]; $marcar = old('_revisado') ? in_array($k, (array) old('apartados', []), true) : $e === 'encontrado'; @endphp
        <label class="tr" style="grid-template-columns:44px minmax(0,1fr) 130px minmax(0,1.6fr) minmax(0,1.6fr) minmax(0,.9fr);min-width:860px;border-radius:0;font-size:13px;cursor:pointer">
          <input type="checkbox" name="apartados[]" value="{{ $k }}" @checked($marcar) aria-label="Aplicar {{ $l }}">
          <b>{{ $l }}</b>
          <span class="lb-pill" style="background:{{ $chip[$e][0] }};color:{{ $chip[$e][1] }};justify-self:start">{{ ConvenioIA::ESTADOS[$e] }}</span>
          <span class="c3">{{ $a }}</span><span>{{ $p }}</span><span class="c3" style="font-size:12px">{{ $r }}</span>
        </label>
      @endforeach
    </div>
    <span class="hint muted">«No localizado» = la lectura no lo ha encontrado (puede estar en el convenio); «No existe» = el convenio dice que no hay. Lo que no marques no cambia. Los pluses leídos se añaden o actualizan por nombre; los conceptos creados a mano no se borran.</span>

    <input type="hidden" name="_revisado" value="1">
    <div style="display:flex;flex-direction:column;gap:6px">
      <span style="font-size:14px;font-weight:700">Tablas salariales: revisa y corrige antes de aplicar</span>
      <div class="tbl">
        <div class="th" style="grid-template-columns:40px minmax(0,1.6fr) 80px 120px 90px 100px minmax(0,1fr) minmax(0,1.5fr);min-width:980px"><span>Incl.</span><span>Categoría</span><span>Grupo</span><span>Salario</span><span>Unidad</span><span>Plus conv.</span><span>Ahora</span><span>Trabajadores</span></div>
        @foreach(array_values($prop['cats']) as $i => $x)
          @php
            $o = $oldCats[$i] ?? null;
            $v = fn ($k, $def) => $o !== null ? ($o[$k] ?? '') : $def;
            $act = $actCats[$x['cat']] ?? null;
            $cmp = ! $act ? ['Nueva', '#E3ECFF', '#1D3A8A'] : (abs((float) $act['base'] - (float) $x['base']) > 0.004 || ($act['unidad'] ?? 'mes') !== $x['unidad'] || (string) $act['grupo'] !== (string) $x['grupo']
                ? ['Cambia: '.Formato::num((float) $act['base']).' €/'.(($act['unidad'] ?? 'mes') === 'dia' ? 'día' : 'mes').' · grupo '.$act['grupo'], '#FCEBCB', '#6E4300'] : ['Igual', '#ECECE7', '#55554F']);
            $ws = $deCat($x['cat']);
          @endphp
          <div class="tr" style="grid-template-columns:40px minmax(0,1.6fr) 80px 120px 90px 100px minmax(0,1fr) minmax(0,1.5fr);min-width:980px;border-radius:0;font-size:13px;align-items:start">
            <input type="hidden" name="cats[{{ $i }}][orig]" value="{{ $x['cat'] }}">
            <input type="checkbox" name="cats[{{ $i }}][incluir]" value="1" @checked($o === null || ! empty($o['incluir'])) aria-label="Incluir {{ $x['cat'] }}">
            <span><input class="inp" name="cats[{{ $i }}][cat]" value="{{ $v('cat', $x['cat']) }}" maxlength="120" aria-label="Nombre de la categoría">@error("cats.$i.cat")<small style="color:#8A1C1C">{{ $message }}</small>@enderror<small class="c3">{{ $ref($x) }}</small></span>
            <span><select class="sel" name="cats[{{ $i }}][grupo]" aria-label="Grupo de cotización">@foreach(range(1, 11) as $g)<option value="{{ $g }}" @selected((string) $v('grupo', $x['grupo']) === (string) $g)>{{ $g }}</option>@endforeach</select>@error("cats.$i.grupo")<small style="color:#8A1C1C">{{ $message }}</small>@enderror</span>
            <span><input class="inp mono" name="cats[{{ $i }}][base]" value="{{ $v('base', Formato::num((float) $x['base'], 2)) }}" inputmode="decimal" aria-label="Salario base">@error("cats.$i.base")<small style="color:#8A1C1C">{{ $message }}</small>@enderror</span>
            <select class="sel" name="cats[{{ $i }}][unidad]" aria-label="Unidad"><option value="mes" @selected($v('unidad', $x['unidad']) === 'mes')>€/mes</option><option value="dia" @selected($v('unidad', $x['unidad']) === 'dia')>€/día</option></select>
            <input class="inp mono" name="cats[{{ $i }}][plus]" value="{{ $v('plus', Formato::num((float) $x['plus'], 2)) }}" inputmode="decimal" aria-label="Plus de convenio">
            <span class="lb-pill" style="background:{{ $cmp[1] }};color:{{ $cmp[2] }};justify-self:start;white-space:normal">{{ $cmp[0] }}</span>
            <span style="font-size:12px">@forelse($ws as $d)@php $bajo = $suyo($d, $x['unidad']) + 0.001 < (float) $x['base']; @endphp<span style="display:block;color:{{ $bajo ? '#8A1C1C' : '#3C3C3C' }}">{{ $d['nombre'] ?? '' }}{{ $bajo ? ' · quedaría por debajo' : '' }}</span>@empty<span class="c3">Ninguno</span>@endforelse</span>
          </div>
        @endforeach
      </div>
      @if($quitadas->isNotEmpty())
        <div style="display:flex;flex-direction:column;gap:4px;padding:8px 12px;border-radius:10px;background:#F7F7F3;font-size:13px">
          <b>Categorías actuales que no vienen en la lectura</b><span class="c3">Se conservan salvo que marques «Quitar». Si tienen trabajadores, quedarían sin categoría válida.</span>
          @foreach($quitadas as $q)
            @php $ws = $deCat($q); @endphp
            <label class="chk" style="font-weight:500"><input type="checkbox" name="quitar[]" value="{{ $q }}" @checked(in_array($q, (array) old('quitar', []), true))> Quitar «{{ $q }}»{{ $ws->isNotEmpty() ? ' · '.$ws->count().' '.($ws->count() === 1 ? 'trabajador' : 'trabajadores').': '.$ws->pluck('nombre')->implode(', ') : '' }}</label>
          @endforeach
        </div>
      @endif
    </div>

    <fieldset style="border:1px solid #E4E4DE;border-radius:12px;padding:10px 14px;display:flex;flex-direction:column;gap:8px">
      <legend style="font-size:14px;font-weight:700;padding:0 6px">Dónde se guarda</legend>
      <label class="chk" style="font-weight:500"><input type="radio" name="destino" value="actual" @checked(old('destino', $verAprob ? 'nueva' : 'actual') === 'actual') @disabled($verAprob)> En la versión «{{ $ver?->nombre ?? 'nueva del convenio' }}»{{ $ver ? ' ('.mb_strtolower(\App\Models\Convenio::ESTADOS[$ver->estado] ?? $ver->estado).')' : '' }}{{ $verAprob ? ': está aprobada, no se sobrescribe' : '' }}</label>
      <label class="chk" style="font-weight:500"><input type="radio" name="destino" value="nueva" @checked(old('destino', $verAprob ? 'nueva' : 'actual') === 'nueva')> En una versión nueva (en borrador, copiando la vigente)</label>
      <div style="display:flex;gap:8px;flex-wrap:wrap;padding-left:28px">
        <label class="lbl-s">Nombre<input class="inp" name="nueva_nombre" value="{{ old('nueva_nombre', ! empty($prop['anioTablas']) ? 'Tablas '.$prop['anioTablas'] : 'Versión '.now()->year) }}" maxlength="120">@error('nueva_nombre')<small style="color:#8A1C1C">{{ $message }}</small>@enderror</label>
        <label class="lbl-s">Efectos desde<input class="inp" type="date" name="nueva_efectos" value="{{ old('nueva_efectos', ! empty($prop['anioTablas']) ? $prop['anioTablas'].'-01-01' : '') }}">@error('nueva_efectos')<small style="color:#8A1C1C">{{ $message }}</small>@enderror</label>
      </div>
      @error('destino')<small style="color:#8A1C1C">{{ $message }}</small>@enderror
    </fieldset>

    <span class="hint muted">Al aprobar se guardan los apartados marcados en la versión elegida, con el PDF y el artículo y la página de cada dato. Los salarios de los trabajadores nunca se cambian solos. Una versión en borrador hay que aprobarla en «Versión y vigencia».</span>
    <div style="display:flex;gap:8px;justify-content:flex-end;flex-wrap:wrap">
      <button class="btn white" type="submit" name="accion" value="descartar" formnovalidate>Descartar</button>
      <button class="btn lime" type="submit" name="accion" value="aplicar" data-confirm-btn="¿Guardar los apartados marcados de la lectura en el convenio de {{ $c->nombre }}?">Aprobar y guardar</button>
    </div>
  </form>
</div>
