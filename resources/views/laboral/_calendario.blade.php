{{-- Integral de nóminas · calendario e incidencias del mes del trabajador (fase 5) --}}
@php
  use App\Services\Calendario;
  use App\Services\Nomina;
  use App\Models\LaboralHora;
  $K = $ed['cal']; $cal = $K['cal'];
  $hid = '<input type="hidden" name="anio" value="'.$anio.'"><input type="hidden" name="mes" value="'.$mes.'">';
  $est = ['propuesta' => ['Pendiente de revisar', '#FCEBCB', '#6E4300'], 'confirmada' => ['En la nómina', '#E6F6A8', '#2E3A00'], 'descartada' => ['Descartada', '#ECECE7', '#55554F']];
  $color = function ($d) {
      if (! $d['alta']) return ['#F7F7F3', '#B5B5AE', 'Fuera de alta'];
      if ($d['it']) return ['#FBE3E3', '#8A1C1C', 'Incapacidad temporal'];
      if ($d['aus'] === 'Vacaciones') return ['#E6F6A8', '#2E3A00', 'Vacaciones'];
      if ($d['noRet']) return ['#FCEBCB', '#6E4300', $d['aus'].' (no retribuida)'];
      if ($d['aus']) return ['#E3ECFF', '#1D3A8A', $d['aus']];
      if ($d['fest']) return ['#EFE6FB', '#4B2A7A', 'Festivo: '.$d['fest']];
      if (! $d['lab']) return ['#ECECE7', '#6A6A65', 'Descanso'];
      return ['#FFFFFF', '#0B0B0B', 'Laborable'];
  };
  $primero = array_key_first($cal['dias']);
  $hueco = \Illuminate\Support\Carbon::parse($primero)->dayOfWeekIso - 1;
  $calBloq = $bloq;
@endphp
@if($errors->any())<div class="ferr" role="alert">{{ implode(' ', $errors->all()) }}</div>@endif
@if($calBloq)<div class="msg warn" style="font-weight:500">{{ $asiento ? 'Las nóminas de este mes están contabilizadas.' : 'La nómina de este mes está emitida: reábrela para cambiar su calendario.' }} El calendario se puede consultar, no modificar.</div>@endif

<div class="card" style="gap:12px">
  <div style="display:flex;justify-content:space-between;align-items:baseline;gap:10px;flex-wrap:wrap">
    <span style="font-size:15px;font-weight:700">Calendario de {{ mb_strtolower(Nomina::MESES[$mes - 1]) }} de {{ $anio }}</span>
    <span style="font-size:12.5px;color:#6A6A65">Trabaja: {{ implode(' ', array_map(fn ($x) => Calendario::DIAS_TXT[$x], $cal['patron'])) }} · <a href="{{ route('laboral.ficha', [$w['id'], 'sec' => 'contrato']) }}">cambiar en la ficha</a></span>
  </div>
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:8px">
    @foreach([['Días en alta', $cal['alta'].' de '.$cal['diasMes']], ['Laborables', $cal['laborables']], ['Trabajados', $cal['trabajados']], ['Efectivos', $cal['efectivos']], ['En IT', $cal['it']], ['No retribuidos', $cal['noRetrib']], ['Salario: días pagados', $cal['pagados']]] as [$l, $v])
      <div style="padding:8px 10px;border-radius:10px;background:#F7F7F3;display:flex;flex-direction:column;gap:2px"><span style="font-size:11.5px;color:#6A6A65">{{ $l }}</span><b class="mono12" style="font-size:15px">{{ $v }}</b></div>
    @endforeach
  </div>
  <div role="grid" aria-label="Días del mes" style="display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:4px">
    @foreach(Calendario::DIAS_TXT as $l)<span role="columnheader" style="font-size:11px;font-weight:600;color:#6A6A65;text-align:center">{{ $l }}</span>@endforeach
    @for($i = 0; $i < $hueco; $i++)<span></span>@endfor
    @foreach($cal['dias'] as $f => $d)
      @php [$bg, $fg, $txt] = $color($d); @endphp
      <span role="gridcell" title="{{ \App\Support\Formato::fecha($f) }}: {{ $txt }}" aria-label="{{ \App\Support\Formato::fecha($f) }}: {{ $txt }}" style="background:{{ $bg }};color:{{ $fg }};border:1px solid #E4E4DE;border-radius:8px;padding:6px 4px;min-height:44px;display:flex;flex-direction:column;gap:2px;font-size:11px;overflow:hidden">
        <b style="font-size:13px">{{ (int) substr($f, 8, 2) }}</b><span class="ell">{{ $d['alta'] ? ($d['aus'] ?: ($d['fest'] ? 'Festivo' : ($d['lab'] ? '' : 'Descanso'))) : '' }}</span>
      </span>
    @endforeach
  </div>
  <span style="font-size:12px;color:#6A6A65;line-height:1.45">Laborables: sus días de trabajo menos los festivos de la empresa ({{ count($K['festivos']) ? implode(', ', array_map(fn ($f, $n) => (int) substr($f, 8, 2).' '.$n, array_keys($K['festivos']), $K['festivos'])) : 'ninguno este mes' }}). Trabajados: laborables menos IT y ausencias no retribuidas. Efectivos: laborables sin ninguna ausencia. Los festivos se configuran en Parámetros › Notificaciones (comunidad autónoma y festivos locales).</span>
</div>

<div class="card" style="gap:10px">
  <span style="font-size:15px;font-weight:700">Ausencias del mes</span>
  @forelse($K['ausencias'] as $a)
    @php [$et, $eb, $ef] = $est[$a->estado] ?? $est['propuesta']; $esIt = $a->tipo === 'Incapacidad temporal'; @endphp
    <form method="POST" action="{{ route('laboral.cal.revisar', $a->id) }}" style="display:flex;flex-wrap:wrap;gap:8px;align-items:flex-end;padding:8px 0;border-top:1px solid #F1F1EC">@csrf{!! $hid !!}
      <span style="display:flex;flex-direction:column;gap:2px;min-width:200px;flex:1 1 220px"><b style="font-size:13.5px">{{ $a->tipo }}</b>
        <span style="font-size:12.5px;color:#3C3C3C">{{ \App\Support\Formato::fecha($a->desde) }} a {{ \App\Support\Formato::fecha($a->hasta) }} · {{ $a->origen === 'nomina' ? 'registrada en la nómina' : 'del control horario' }}</span>
        <span class="lb-pill" style="align-self:flex-start;background:{{ $eb }};color:{{ $ef }}">{{ $et }}</span></span>
      @if($esIt)
        <label class="lbl-s">Contingencia<select class="sel" name="contingencia" @disabled($calBloq)><option value="">—</option>@foreach(Calendario::CONTINGENCIAS as $k => $l)<option value="{{ $k }}" @selected($a->contingencia === $k)>{{ $l }}</option>@endforeach</select></label>
        <label class="lbl-s">Base reguladora diaria (€)<input class="inp mono" name="base_reguladora" value="{{ $a->base_reguladora !== null ? \App\Support\Formato::num((float) $a->base_reguladora, 4) : '' }}" inputmode="decimal" placeholder="De la nómina anterior" style="width:150px" @disabled($calBloq)></label>
        <label class="chk"><input type="checkbox" name="hospitalizacion" value="1" @checked($a->hospitalizacion) @disabled($calBloq)> Con hospitalización</label>
      @endif
      @unless($calBloq)
        @if($a->estado !== 'confirmada')<button class="btn lime sm" type="submit" name="accion" value="confirmar">Llevar a la nómina</button>@endif
        @if($a->estado === 'confirmada' && $esIt)<button class="btn soft sm" type="submit" name="accion" value="guardar">Guardar datos</button>@endif
        @if($a->estado !== 'descartada' && $a->origen !== 'nomina')<button class="btn white sm" type="submit" name="accion" value="descartar">No llevar a la nómina</button>@endif
      @endunless
    </form>
    @if($a->origen === 'nomina' && ! $calBloq)
      <form method="POST" action="{{ route('laboral.cal.ausencia.borrar', $a->id) }}" data-confirm="¿Eliminar esta ausencia?" style="margin-top:-6px">@csrf @method('DELETE'){!! $hid !!}<button class="btn white sm" type="submit">Eliminar</button></form>
    @endif
  @empty
    <span style="font-size:13px;color:#6A6A65">No hay ausencias este mes.</span>
  @endforelse
  <span style="font-size:12px;color:#6A6A65;line-height:1.45">Las ausencias que se registran en el control horario llegan como «pendiente de revisar» y no cuentan en la nómina hasta que pulsas «Llevar a la nómina». Vacaciones, permisos retribuidos y asuntos propios no descuentan salario; la IT y las ausencias no retribuidas descuentan 1/30 del salario mensual por día.</span>
  @unless($calBloq)
  <form method="POST" action="{{ route('laboral.cal.ausencia', $w['id']) }}" style="display:flex;flex-wrap:wrap;gap:8px;align-items:flex-end;padding-top:10px;border-top:1px solid #F1F1EC">@csrf{!! $hid !!}
    <label class="lbl-s">Nueva ausencia<select class="sel" name="tipo" required>@foreach(array_keys(Calendario::AUSENCIAS) as $tp)<option value="{{ $tp }}" @selected(old('tipo') === $tp)>{{ $tp }}</option>@endforeach</select></label>
    <label class="lbl-s">Desde<input class="inp" type="date" name="desde" value="{{ old('desde') }}" required></label>
    <label class="lbl-s">Hasta<input class="inp" type="date" name="hasta" value="{{ old('hasta') }}" required></label>
    <label class="lbl-s">Contingencia (IT)<select class="sel" name="contingencia"><option value="">—</option>@foreach(Calendario::CONTINGENCIAS as $k => $l)<option value="{{ $k }}" @selected(old('contingencia') === $k)>{{ $l }}</option>@endforeach</select></label>
    <label class="lbl-s">Base reguladora diaria (IT)<input class="inp mono" name="base_reguladora" value="{{ old('base_reguladora') }}" inputmode="decimal" placeholder="Vacía: nómina anterior" style="width:150px"></label>
    <label class="chk"><input type="checkbox" name="hospitalizacion" value="1"> Hospitalización</label>
    <label class="lbl-s" style="flex:1 1 180px">Nota<input class="inp" name="nota" maxlength="255" value="{{ old('nota') }}"></label>
    <button class="btn sm" type="submit">Añadir</button>
  </form>
  @endunless
</div>

<div class="card" style="gap:10px">
  <span style="font-size:15px;font-weight:700">Horas del mes</span>
  @forelse($K['horas'] as $h)
    <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;padding:6px 0;border-top:1px solid #F1F1EC;font-size:13px">
      <b class="mono12" style="min-width:80px">{{ \App\Support\Formato::fecha($h->fecha) }}</b>
      <span style="flex:1 1 220px">{{ \App\Support\Formato::num((float) $h->horas) }} h · {{ LaboralHora::TIPOS[$h->tipo] ?? $h->tipo }}{{ $h->concepto ? ': '.$h->concepto : '' }}{{ $h->origen === 'control_horario' ? ' · desde los fichajes' : '' }}{{ $h->nota ? ' · '.$h->nota : '' }}</span>
      @unless($calBloq)<form method="POST" action="{{ route('laboral.cal.horas.borrar', $h->id) }}">@csrf @method('DELETE'){!! $hid !!}<button class="btn white sm" type="submit" aria-label="Eliminar horas del {{ \App\Support\Formato::fecha($h->fecha) }}">Eliminar</button></form>@endunless
    </div>
  @empty
    <span style="font-size:13px;color:#6A6A65">No hay horas registradas este mes.</span>
  @endforelse
  @unless($calBloq)
  <form method="POST" action="{{ route('laboral.cal.horas', $w['id']) }}" style="display:flex;flex-wrap:wrap;gap:8px;align-items:flex-end;padding-top:10px;border-top:1px solid #F1F1EC">@csrf{!! $hid !!}
    <label class="lbl-s">Día<input class="inp" type="date" name="fecha" value="{{ old('fecha') }}" min="{{ $cal['desde'] }}" max="{{ $cal['hasta'] }}" required></label>
    <label class="lbl-s">Tipo<select class="sel" name="tipo">@foreach(LaboralHora::TIPOS as $k => $l)<option value="{{ $k }}" @selected(old('tipo') === $k)>{{ $l }}</option>@endforeach</select></label>
    <label class="lbl-s">Horas<input class="inp mono" name="horas" value="{{ old('horas') }}" inputmode="decimal" placeholder="2,5" style="width:90px" required></label>
    <label class="lbl-s">Concepto del convenio (por hora)<select class="sel" name="concepto"><option value="">—</option>@foreach($K['conceptosHora'] as $cn)<option value="{{ $cn }}" @selected(old('concepto') === $cn)>{{ $cn }}</option>@endforeach</select></label>
    <label class="lbl-s" style="flex:1 1 160px">Nota<input class="inp" name="nota" maxlength="255"></label>
    <button class="btn sm" type="submit">Añadir</button>
  </form>
  <span style="font-size:12px;color:#6A6A65;line-height:1.45">Extraordinarias: con el precio o el recargo del convenio sobre la hora ordinaria (salario anual / jornada anual); no a tiempo parcial. Complementarias: solo a tiempo parcial, al valor de la hora ordinaria. Nocturnas, festivas u otras: elige el concepto del convenio por hora{{ $K['conceptosHora'] ? '' : ' (este convenio no tiene ninguno: créalo en Gestión › Convenios con la unidad «por hora»)' }}.</span>
  @endunless
</div>

@if($K['exceso'])
<div class="card" style="gap:10px">
  <span style="font-size:15px;font-weight:700">Propuesta desde el control horario</span>
  <span style="font-size:12.5px;color:#6A6A65;line-height:1.45">Días cerrados en los que lo fichado supera su jornada del día en 15 minutos o más. Revisa cada día y marca los que corresponden a horas pagadas; no se lleva nada a la nómina sin tu confirmación.</span>
  <form method="POST" action="{{ route('laboral.cal.fichajes', $w['id']) }}" style="display:flex;flex-direction:column;gap:6px">@csrf{!! $hid !!}
    @foreach($K['exceso'] as $x)
      <label class="chk" style="font-weight:500;border-top:1px solid #F1F1EC;padding-top:6px"><input type="checkbox" name="dias[]" value="{{ $x['fecha'] }}" @disabled($calBloq)> <span class="mono12" style="min-width:80px">{{ \App\Support\Formato::fecha($x['fecha']) }}</span> fichado {{ intdiv($x['mins'], 60) }}:{{ str_pad((string) ($x['mins'] % 60), 2, '0', STR_PAD_LEFT) }} · jornada {{ intdiv($x['teo'], 60) }}:{{ str_pad((string) ($x['teo'] % 60), 2, '0', STR_PAD_LEFT) }} · <b>{{ \App\Support\Formato::num($x['exceso']) }} h de más</b></label>
    @endforeach
    @unless($calBloq)
      <div style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap"><label class="lbl-s">Llevar como<select class="sel" name="tipo"><option value="{{ Nomina::parcial($w) ? 'complementaria' : 'extra' }}">{{ Nomina::parcial($w) ? 'Horas complementarias' : 'Horas extraordinarias' }}</option></select></label><button class="btn lime sm" type="submit">Llevar a la nómina</button></div>
    @endunless
  </form>
</div>
@endif
