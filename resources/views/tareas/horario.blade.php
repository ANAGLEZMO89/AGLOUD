@extends('layouts.app')
@php
    use App\Http\Controllers\TareaController as TC;
    use App\Models\Solicitud;
    use App\Support\Formato;
    $pct = $jornadaDia ? min(100, round($calc['min'] / $jornadaDia * 100)) : 0;
    $dot = ['trabajando' => '#2E9E44', 'pausa' => '#F5A524', 'fuera' => '#C4C4BE'];
    $estTxt = ['trabajando' => 'Trabajando', 'pausa' => 'En pausa', 'fuera' => 'Fuera'];
    $diasN = ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes'];
@endphp
@section('content')
<div class="page" style="gap:16px">
  <div class="page-h"><div class="t"><span class="crumb">Gestión</span><h1 class="ttl">Tareas y horario</h1></div></div>
  @include('partials.tabs-modulo', ['modulo' => 'tareas', 'actual' => 'tareas.horario'])
  @if($errors->any())<div class="ferr">{{ $errors->first() }}</div>@endif

  <div style="display:flex;flex-wrap:wrap;gap:16px;align-items:flex-start">
    <div style="flex:999 1 560px;min-width:0;display:flex;flex-direction:column;gap:16px">
      <div class="dark" style="border-radius:18px;padding:20px 22px;display:flex;flex-direction:column;gap:12px;background:#0B0B0B;color:#F4F4F0">
        <span style="font:500 11px var(--mono);letter-spacing:.08em;color:#C8F031">JORNADA DE HOY · {{ mb_strtoupper($hoy->format('j')) }}/{{ $hoy->format('m') }}</span>
        <div style="display:flex;align-items:baseline;gap:14px;flex-wrap:wrap"><span style="font:600 34px/1 var(--mono)">{{ TC::hm($calc['min']) }}</span><span style="display:flex;align-items:center;gap:8px;font-size:13.5px"><span style="width:8px;height:8px;border-radius:50%;background:{{ $dot[$calc['estado']] }}"></span>{{ $estTxt[$calc['estado']] }}</span></div>
        <div style="height:6px;border-radius:3px;background:#2A2A2A;overflow:hidden"><div style="height:100%;background:#C8F031;width:{{ $pct }}%"></div></div>
        <span style="font-size:12.5px;color:#BDBDB7">{{ $calc['min'] >= $jornadaDia ? 'Jornada de '.TC::hm($jornadaDia).' cumplida' : 'Quedan '.TC::hm($jornadaDia - $calc['min']).' de una jornada de '.TC::hm($jornadaDia) }}@if($calc['pausa']) · pausas {{ TC::hm($calc['pausa']) }}@endif</span>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
          @php $botones = ['fuera' => [['entrada', 'Fichar entrada', 'lime']], 'trabajando' => [['pausa', 'Iniciar pausa', 'wh'], ['salida', 'Fichar salida', 'lime']], 'pausa' => [['reanuda', 'Reanudar', 'lime'], ['salida', 'Fichar salida', 'wh']]][$calc['estado']]; @endphp
          @foreach($botones as [$tipo, $l, $cls])<form method="POST" action="{{ route('tareas.fichar') }}">@csrf<input type="hidden" name="tipo" value="{{ $tipo }}"><button class="pillbtn {{ $cls }}" type="submit" style="height:40px;padding:0 18px">{{ $l }}</button></form>@endforeach
        </div>
      </div>

      <div class="card" style="padding:16px 18px;gap:6px">
        <b style="font-size:14px">Registros de hoy</b>
        @forelse($marcasHoy as $m)
          <div style="display:flex;justify-content:space-between;font-size:13px;padding:6px 0;border-top:1px solid #F1F1EC"><span>{{ ['entrada' => 'Entrada', 'pausa' => 'Pausa', 'reanuda' => 'Reanuda', 'salida' => 'Salida'][$m->tipo] }}</span><span class="mono">{{ $m->momento->timezone(config('app.timezone'))->format('H:i') }}</span></div>
        @empty
          <span style="font-size:13px;color:#6A6A65">Aún no has fichado hoy.</span>
        @endforelse
      </div>

      <div class="card" style="padding:16px 18px;gap:8px;overflow-x:auto">
        <div style="display:flex;justify-content:space-between;align-items:baseline;gap:10px;flex-wrap:wrap"><b style="font-size:14px">Semana del {{ $semana[0]['d']->format('j') }} al {{ $semana[4]['d']->format('j') }} de {{ ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'][$semana[4]['d']->month - 1] }}</b><span class="mono" style="font-size:13px">{{ TC::hm($semTotal) }} de {{ intdiv($semTeorico, 60) }} h</span></div>
        <div style="min-width:520px">
          <div style="display:grid;grid-template-columns:minmax(0,1fr) 80px 80px 110px 110px;gap:10px;font-size:11px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;color:#6A6A65;padding:6px 0"><span>Día</span><span>Entrada</span><span>Salida</span><span>Pausas</span><span>Total</span></div>
          @foreach($semana as $i => $r)
            <div style="display:grid;grid-template-columns:minmax(0,1fr) 80px 80px 110px 110px;gap:10px;padding:8px 0;border-top:1px solid #F1F1EC;font-size:13px;{{ $r['hoy'] ? 'font-weight:700' : '' }};{{ $r['fest'] ? 'color:#8A1C1C' : '' }}">
              <span>{{ $diasN[$i] }} {{ $r['d']->day }}{{ $r['fest'] ? ' · '.$r['fest'] : '' }}</span><span class="mono">{{ $r['entrada'] ?? '—' }}</span><span class="mono">{{ $r['salida'] ?? '—' }}</span><span class="mono">{{ $r['pausa'] ? TC::hm($r['pausa']) : '—' }}</span><span class="mono">{{ $r['min'] ? TC::hm($r['min']) : '—' }}</span>
            </div>
          @endforeach
        </div>
      </div>
    </div>

    <div style="flex:1 1 300px;min-width:0;display:flex;flex-direction:column;gap:12px">
      <div class="card" style="padding:16px 18px;gap:8px">
        <b style="font-size:14px">Equipo ahora</b>
        @foreach($equipo as $p)
          <div style="display:grid;grid-template-columns:28px minmax(0,1fr) auto;gap:10px;align-items:center;padding:6px 0;border-top:1px solid #F1F1EC">
            <span class="ini24" style="width:28px;height:28px">{{ $p['u']->iniciales() }}</span>
            <div style="min-width:0"><span class="ell" style="font-size:13px;font-weight:600;display:block">{{ $p['u']->name }}</span><span style="font-size:11.5px;color:#6A6A65">{{ $p['det'] }}</span></div>
            <span style="display:flex;align-items:center;gap:6px;font-size:12px"><span style="width:8px;height:8px;border-radius:50%;background:{{ $dot[$p['estado']] }}"></span>{{ $estTxt[$p['estado']] }}</span>
          </div>
        @endforeach
      </div>

      <div class="card" style="padding:16px 18px;gap:10px">
        <div style="display:flex;flex-direction:column;gap:2px"><b style="font-size:14px">Solicitudes</b><span style="font-size:12px;color:#6A6A65">Vacaciones {{ $hoy->year }}: {{ $vacUsadas }} de {{ $vacTotal }} días hábiles</span></div>
        <form method="POST" action="{{ route('tareas.solicitar') }}" style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
          @csrf
          <select class="sel" name="tipo" style="grid-column:1 / -1">@foreach(Solicitud::TIPOS as $t)<option>{{ $t }}</option>@endforeach</select>
          <input class="inp" type="date" name="desde" value="{{ $hoy->toDateString() }}" required title="Desde">
          <input class="inp" type="date" name="hasta" value="{{ $hoy->toDateString() }}" required title="Hasta">
          <input class="inp" name="motivo" maxlength="255" placeholder="Motivo (en correcciones: hora real)" style="grid-column:1 / -1">
          <button class="btn sm" type="submit" style="grid-column:1 / -1;background:#0B0B0B;color:#fff">Enviar solicitud</button>
        </form>
        @foreach($solicitudes as $s)
          @php $col = ['Pendiente' => ['#FCEBCB', '#6E4300'], 'Aprobada' => ['#E6F6A8', '#2E3A00'], 'Rechazada' => ['#FDE7E7', '#8A1C1C']][$s->estado]; @endphp
          <div style="display:flex;flex-direction:column;gap:4px;padding:8px 0;border-top:1px solid #F1F1EC">
            <div style="display:flex;justify-content:space-between;gap:8px"><span style="font-size:13px;font-weight:600">{{ $esAdmin ? $s->usuario?->name.' · ' : '' }}{{ $s->tipo }}</span><span style="font-size:11.5px;font-weight:700;padding:2px 8px;border-radius:10px;background:{{ $col[0] }};color:{{ $col[1] }}">{{ $s->estado }}</span></div>
            <span style="font-size:12px;color:#6A6A65">{{ Formato::fecha($s->desde) }}{{ $s->hasta->ne($s->desde) ? ' – '.Formato::fecha($s->hasta) : '' }}{{ $s->motivo ? ' · '.$s->motivo : '' }}</span>
            @if($esAdmin && $s->estado === 'Pendiente')
              <div style="display:flex;gap:6px">@foreach(['Aprobada' => 'Aprobar', 'Rechazada' => 'Rechazar'] as $k => $l)<form method="POST" action="{{ route('tareas.resolver', $s) }}">@csrf<input type="hidden" name="estado" value="{{ $k }}"><button class="pillbtn {{ $k === 'Aprobada' ? 'lime' : '' }}" style="height:28px;font-size:12px" type="submit">{{ $l }}</button></form>@endforeach</div>
            @endif
          </div>
        @endforeach
      </div>

      <div class="card" style="padding:16px 18px;gap:8px">
        <b style="font-size:14px">Registro de jornada</b>
        <span style="font-size:12.5px;color:#3C3C3C;line-height:1.45">Obligatorio para todo el personal (art. 34.9 ET). Las marcas no se pueden modificar: los errores se corrigen con una solicitud. Se conservan 4 años y deben estar a disposición de los trabajadores y de la Inspección de Trabajo.</span>
        <form method="GET" action="{{ route('tareas.exportar') }}" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
          <input class="inp" type="month" name="m" value="{{ $hoy->format('Y-m') }}" style="height:36px;width:170px;flex:none">
          <button class="pillbtn ink" type="submit" style="height:36px">Exportar registro mensual</button>
        </form>
      </div>
    </div>
  </div>
</div>
@endsection
