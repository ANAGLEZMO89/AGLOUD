@extends('layouts.app')
@php
    use App\Http\Controllers\CalendarioController as CC;
    use App\Support\Formato;
    $meses = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
    $dias = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
    $titMes = ucfirst($meses[$mes->month - 1]).' '.$mes->year;
    $chipEv = fn ($e) => CC::TIPOS[$e['tipo']];
@endphp
@section('content')
<div class="page" style="gap:16px">
  <div class="page-h">
    <div class="t"><span class="crumb">Gestión</span><h1 class="ttl">Calendario</h1></div>
    <div class="row">
      <div style="display:flex;align-items:center;gap:4px;padding:3px;border-radius:20px;background:#fff">
        <a class="pillbtn wh" style="height:32px;width:32px;padding:0" href="{{ $url(['m' => $mes->subMonth()->format('Y-m')]) }}" aria-label="Mes anterior">‹</a>
        <span style="min-width:150px;text-align:center;font-weight:700">{{ $titMes }}</span>
        <a class="pillbtn wh" style="height:32px;width:32px;padding:0" href="{{ $url(['m' => $mes->addMonth()->format('Y-m')]) }}" aria-label="Mes siguiente">›</a>
        <a class="pillbtn" style="height:32px" href="{{ $url(['m' => $hoy->format('Y-m'), 'dia' => $hoy->toDateString()]) }}">Hoy</a>
      </div>
      <div class="chipbar" style="border-radius:11px">@foreach(['mes' => 'Mes', 'agenda' => 'Agenda'] as $k => $l)<a class="{{ $vista === $k ? 'on' : '' }}" style="padding:6px 14px;border-radius:8px" href="{{ $url(['vista' => $k]) }}">{{ $l }}</a>@endforeach</div>
      <a class="btn" href="{{ $url(['nuevo' => 1, 'dia' => $dia->toDateString()]) }}" style="background:#0B0B0B;color:#fff">+ Nuevo evento</a>
    </div>
  </div>
  @include('partials.tabs-modulo', ['modulo' => 'calendario', 'actual' => 'modulo'])
  @if($errors->any())<div class="ferr">{{ $errors->first() }}</div>@endif

  <div style="display:flex;gap:8px;flex-wrap:wrap">
    @foreach(CC::TIPOS as $k => [$l, $bg, $fg])
      @php $on = in_array($k, $activos, true); $nuevos = $on ? array_values(array_diff($activos, [$k])) : array_merge($activos, [$k]); @endphp
      <a href="{{ $url(['t' => $nuevos ?: ['ninguno']]) }}" style="display:flex;align-items:center;gap:8px;padding:6px 12px;border-radius:16px;background:{{ $on ? '#fff' : 'transparent' }};border:1px solid {{ $on ? '#E6E6E0' : '#DADAD4' }};font-size:13px;text-decoration:none;color:{{ $on ? '#0B0B0B' : '#9A9A94' }}"><span style="width:10px;height:10px;border-radius:3px;background:{{ $on ? $bg : '#DADAD4' }}"></span>{{ $l }} <b style="font:600 11.5px var(--mono)">{{ $cuenta[$k] }}</b></a>
    @endforeach
  </div>

  @if($nuevo)
  <form method="POST" action="{{ route('calendario.store') }}" class="card" style="padding:18px 20px;gap:12px;border:1.5px solid #0B0B0B">
    @csrf
    <div style="display:flex;justify-content:space-between;align-items:center"><span style="font-size:16px;font-weight:700">Nuevo evento</span><a href="{{ $url(['dia' => $dia->toDateString()]) }}" style="font-weight:600;font-size:13.5px">Cancelar</a></div>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(min(100%,180px),1fr));gap:10px">
      <label class="lbl-s">Fecha<input class="inp" type="date" name="fecha" value="{{ old('fecha', $dia->toDateString()) }}" required></label>
      <label class="lbl-s">Hora<input class="inp" type="time" name="hora" value="{{ old('hora') }}"></label>
      <label class="lbl-s">Tipo<select class="sel" name="tipo"><option value="cita">Cita</option><option value="interno" @selected(old('tipo') === 'interno')>Interno</option></select></label>
      <label class="lbl-s">Cliente (opcional)<select class="sel" name="cliente_id"><option value="">—</option>@foreach($clientes as $cl)<option value="{{ $cl->id }}" @selected((string) old('cliente_id') === (string) $cl->id)>{{ $cl->nombre }}</option>@endforeach</select></label>
      <label class="lbl-s" style="grid-column:1 / -1">Título *<input class="inp" name="titulo" value="{{ old('titulo') }}" maxlength="160" required placeholder="Ej.: Reunión cierre trimestral"></label>
      <label class="lbl-s" style="grid-column:1 / -1">Detalle<input class="inp" name="detalle" value="{{ old('detalle') }}" maxlength="255"></label>
    </div>
    <div style="display:flex;justify-content:flex-end"><button class="btn lime" type="submit">Añadir</button></div>
  </form>
  @endif

  <div style="display:flex;flex-wrap:wrap;gap:16px;align-items:flex-start">
    <div style="flex:999 1 620px;min-width:0">
      @if($vista === 'mes')
      <div class="card" style="padding:12px;gap:0;overflow-x:auto">
        <div class="cal-grid" style="min-width:700px">
          @foreach(['LUN', 'MAR', 'MIÉ', 'JUE', 'VIE', 'SÁB', 'DOM'] as $h)<span class="cal-h">{{ $h }}</span>@endforeach
          @foreach($celdas as $cel)
            <a class="cal-c {{ $cel['fuera'] ? 'fuera' : '' }} {{ $cel['sel'] ? 'sel' : '' }} {{ $cel['fest'] || $cel['d']->isWeekend() ? 'inh' : '' }}" href="{{ $url(['dia' => $cel['k'], 'm' => $cel['d']->format('Y-m')]) }}">
              <span class="n {{ $cel['hoy'] ? 'hoy' : '' }}">{{ $cel['d']->day }}</span>
              @if($cel['fest'])<span class="cal-fest" title="{{ $cel['fest'] }}">Festivo</span>@endif
              @foreach($cel['evs']->take(3) as $e)@php [, $bg, $fg] = $chipEv($e); @endphp<span class="cal-ev" style="background:{{ $bg }};color:{{ $fg }}" title="{{ $e['t'] }}">{{ $e['hora'] ? $e['hora'].' ' : '' }}{{ $e['t'] }}</span>@endforeach
              @if($cel['evs']->count() > 3)<span style="font-size:11px;color:#6A6A65">+{{ $cel['evs']->count() - 3 }} más</span>@endif
            </a>
          @endforeach
        </div>
      </div>
      @else
      <div class="card" style="padding:8px 18px 14px;gap:0">
        @forelse($agenda as $f => $evs)
          @php $d = \Carbon\CarbonImmutable::parse($f); @endphp
          <div style="display:grid;grid-template-columns:140px minmax(0,1fr);gap:14px;padding:12px 0;border-bottom:1px solid #F1F1EC">
            <a href="{{ $url(['dia' => $f]) }}" style="display:flex;flex-direction:column;text-decoration:none;color:var(--ink)"><b style="font-size:14px">{{ ucfirst($dias[$d->dayOfWeek]) }} {{ $d->day }}</b>@if($d->isSameDay($hoy))<span style="font-size:12px;font-weight:700;color:#2E3A00">Hoy</span>@endif @if($fest[$f] ?? null)<span style="font-size:12px;color:#8A1C1C">{{ $fest[$f] }}</span>@endif</a>
            <div style="display:flex;flex-direction:column;gap:8px">
              @foreach($evs as $e)@php [$tl, $bg, $fg] = $chipEv($e); @endphp
                <div style="display:grid;grid-template-columns:50px minmax(0,1fr) auto;gap:10px;align-items:center">
                  <span class="mono" style="font-size:12.5px;color:#6A6A65">{{ $e['hora'] ?? '—' }}</span>
                  <div style="min-width:0">@if($e['url'])<a href="{{ $e['url'] }}" style="font-weight:600;font-size:13.5px">{{ $e['t'] }}</a>@else<b style="font-size:13.5px">{{ $e['t'] }}</b>@endif<div class="ell" style="font-size:12px;color:#6A6A65">{{ $e['s'] }}</div></div>
                  <span style="font-size:11.5px;font-weight:700;padding:3px 9px;border-radius:10px;background:{{ $bg }};color:{{ $fg }}">{{ $tl }}</span>
                </div>
              @endforeach
            </div>
          </div>
        @empty
          <div style="padding:28px 0;text-align:center;font-size:14px;color:#6A6A65">No hay eventos este mes con los filtros activos.</div>
        @endforelse
      </div>
      @endif
    </div>

    <div style="flex:1 1 280px;min-width:0;display:flex;flex-direction:column;gap:12px">
      <div class="card" style="padding:16px 18px;gap:10px">
        <span style="font:500 11px var(--mono);letter-spacing:.08em;color:#6A6A65">DÍA SELECCIONADO</span>
        <b style="font-size:17px">{{ ucfirst($dias[$dia->dayOfWeek]) }}, {{ $dia->day }} de {{ $meses[$dia->month - 1] }}</b>
        @if($fest[$dia->toDateString()] ?? null)<span style="font-size:12.5px;font-weight:600;color:#8A1C1C">{{ $fest[$dia->toDateString()] }} · inhábil</span>@endif
        @forelse($diaEvs as $e)@php [$tl, $bg, $fg] = $chipEv($e); @endphp
          <div style="display:flex;flex-direction:column;gap:3px;padding:10px 0;border-top:1px solid #F1F1EC">
            <div style="display:flex;justify-content:space-between;gap:8px"><span style="font-size:11.5px;font-weight:700;padding:2px 8px;border-radius:10px;background:{{ $bg }};color:{{ $fg }}">{{ $tl }}</span><span class="mono" style="font-size:12px;color:#6A6A65">{{ $e['hora'] }}</span></div>
            <b style="font-size:13.5px">{{ $e['t'] }}</b><span style="font-size:12px;color:#6A6A65">{{ $e['s'] }}</span>
            @if($e['url'])<a href="{{ $e['url'] }}" style="font-size:12.5px;font-weight:600">Abrir</a>@endif
            @if(! empty($e['id']))<form method="POST" action="{{ route('calendario.destroy', $e['id']) }}" data-confirm="¿Eliminar este evento?">@csrf @method('DELETE')<button class="lnk red" type="submit" style="font-size:12px">Eliminar</button></form>@endif
          </div>
        @empty
          <span style="font-size:13px;color:#6A6A65">Sin eventos este día.</span>
        @endforelse
        <a class="pillbtn" href="{{ $url(['nuevo' => 1, 'dia' => $dia->toDateString()]) }}">+ Añadir a este día</a>
      </div>
      <div class="card" style="padding:16px 18px;gap:6px;font-size:12.5px;color:#3C3C3C;line-height:1.5">
        <b style="font-size:13.5px;color:#0B0B0B">Cómo se alimenta</b>
        <span>Solo: vencimientos y caducidades de <b>Notificaciones</b>, fechas límite de <b>Expedientes</b> y <b>Tareas</b> con fecha. A mano: citas y eventos internos. Las obligaciones fiscales y de Seguridad Social llegarán con las fases Fiscal y Laboral.</span>
      </div>
    </div>
  </div>
</div>
@endsection
