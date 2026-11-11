@extends('layouts.app')
@php use App\Models\Tarea; use App\Support\Formato; @endphp
@section('content')
<div class="page" style="gap:16px">
  <div class="page-h">
    <div class="t"><span class="crumb">Gestión</span><h1 class="ttl">Tareas y horario</h1></div>
    <a class="btn" href="{{ $url(['nueva' => 1]) }}" style="background:#0B0B0B;color:#fff">+ Nueva tarea</a>
  </div>
  @include('partials.tabs-modulo', ['modulo' => 'tareas', 'actual' => 'tareas.index'])
  @if($errors->any())<div class="ferr">{{ $errors->first() }}</div>@endif

  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:14px">
    @foreach($resumen as [$l, $v])<div class="card" style="padding:14px 18px;gap:4px"><span style="font-size:13px;color:#6A6A65">{{ $l }}</span><span style="font:600 26px/1.1 var(--mono)">{{ Formato::entero($v) }}</span></div>@endforeach
  </div>

  @if($nueva)
  <form method="POST" action="{{ route('tareas.store') }}" class="card" style="padding:18px 20px;gap:12px;border:1.5px solid #0B0B0B">
    @csrf
    <div style="display:flex;justify-content:space-between;align-items:center"><span style="font-size:16px;font-weight:700">Nueva tarea</span><a href="{{ $url() }}" style="font-weight:600;font-size:13.5px">Cancelar</a></div>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(min(100%,190px),1fr));gap:10px">
      <label class="lbl-s" style="grid-column:1 / -1">Qué hay que hacer *<input class="inp" name="titulo" value="{{ old('titulo') }}" maxlength="200" required></label>
      <label class="lbl-s">Cliente<select class="sel" name="cliente_id"><option value="">— Interna —</option>@foreach($clientes as $cl)<option value="{{ $cl->id }}" @selected((string) old('cliente_id') === (string) $cl->id)>{{ $cl->nombre }}</option>@endforeach</select></label>
      <label class="lbl-s">Responsable<select class="sel" name="responsable_id">@foreach($equipo as $e)<option value="{{ $e->id }}" @selected((string) old('responsable_id', auth()->id()) === (string) $e->id)>{{ $e->name }}</option>@endforeach</select></label>
      <label class="lbl-s">Fecha límite<input class="inp" type="date" name="fecha" value="{{ old('fecha') }}"></label>
      <label class="lbl-s">Prioridad<select class="sel" name="prioridad">@foreach(array_keys(Tarea::PRIORIDADES) as $p)<option @selected(old('prioridad', $par['prioridad']) === $p)>{{ $p }}</option>@endforeach</select></label>
      <label class="lbl-s">Repetir<select class="sel" name="repetir">@foreach(Tarea::REPETIR as $k => $l)<option value="{{ $k }}" @selected(old('repetir') === $k)>{{ $l }}</option>@endforeach</select></label>
    </div>
    <div style="display:flex;justify-content:flex-end"><button class="btn lime" type="submit">Crear tarea</button></div>
  </form>
  @endif

  <div style="display:flex;flex-wrap:wrap;gap:16px;align-items:flex-start">
    <div style="flex:999 1 560px;min-width:0;display:flex;flex-direction:column;gap:12px">
      <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
        <div class="chipbar" style="border-radius:11px"><a class="{{ $todo ? '' : 'on' }}" style="padding:6px 14px;border-radius:8px" href="{{ $url(['ver' => null]) }}">Mis tareas</a><a class="{{ $todo ? 'on' : '' }}" style="padding:6px 14px;border-radius:8px" href="{{ $url(['ver' => 'equipo']) }}">Todo el equipo</a></div>
        <a href="{{ $url(['hechas' => $verHechas ? null : 1]) }}" style="font-size:13px;font-weight:600">{{ $verHechas ? 'Ocultar hechas' : 'Ver hechas' }}</a>
      </div>
      @php $hay = false; @endphp
      @foreach($grupos as [$l, $items, $warn])
        @continue($items->isEmpty())
        @php $hay = true; @endphp
        <div class="card" style="padding:12px 18px;gap:0">
          <div style="display:flex;justify-content:space-between;padding:4px 0 8px"><b style="font-size:14px;color:{{ $warn ? '#B42318' : '#0B0B0B' }}">{{ $l }}</b><span class="mono" style="font-size:12px;color:#6A6A65">{{ $items->count() }}</span></div>
          @foreach($items as $t)
            <div style="display:grid;grid-template-columns:24px minmax(0,1fr) auto auto 26px;gap:12px;align-items:center;padding:10px 0;border-top:1px solid #F1F1EC">
              <form method="POST" action="{{ route('tareas.hecha', $t) }}">@csrf<button type="submit" class="chkbox {{ $t->hecha ? 'on' : '' }}" aria-label="{{ $t->hecha ? 'Marcar como pendiente' : 'Marcar como hecha' }}">{{ $t->hecha ? '✓' : '' }}</button></form>
              <div style="display:flex;flex-direction:column;gap:2px;min-width:0">
                <span class="ell" style="font-size:14px;{{ $t->hecha ? 'color:#6A6A65;text-decoration:line-through' : '' }}">{{ $t->titulo }}</span>
                <span style="font-size:12px;color:#6A6A65">@if($t->cliente)<a href="{{ route('clientes.show', $t->cliente_id) }}">{{ $t->cliente->nombre }}</a>@else Interna @endif @if($t->repetir) · ↻ {{ Tarea::REPETIR[$t->repetir] }}@endif</span>
              </div>
              <span style="display:flex;align-items:center;gap:6px;font-size:12px;color:#3C3C3C"><span style="width:8px;height:8px;border-radius:50%;background:{{ Tarea::PRIORIDADES[$t->prioridad] }}"></span>{{ $t->prioridad }}</span>
              <span class="mono" style="font-size:12px;color:{{ $warn ? '#B42318' : '#3C3C3C' }};white-space:nowrap">{{ $t->fecha ? ($t->fecha->isSameDay($hoy) ? 'Hoy' : Formato::fecha($t->fecha)) : '—' }}</span>
              <span class="ini24" title="{{ $t->responsable?->name }}">{{ $t->responsable?->iniciales() ?? '—' }}</span>
            </div>
          @endforeach
        </div>
      @endforeach
      @unless($hay)<div class="card"><div class="empty"><b>No tienes tareas pendientes.</b><span>Crea una con «+ Nueva tarea».</span></div></div>@endunless
    </div>
    <div style="flex:1 1 280px;min-width:0;display:flex;flex-direction:column;gap:12px">
      <div class="card" style="padding:16px 18px;gap:10px">
        <span style="font:500 11px var(--mono);letter-spacing:.08em;color:#6A6A65">CARGA DEL EQUIPO</span>
        @foreach($carga as $c)
          <div style="display:flex;flex-direction:column;gap:4px"><div style="display:flex;justify-content:space-between;font-size:13px"><span>{{ $c['n'] }}</span><b class="mono">{{ $c['c'] }}</b></div><div class="bar5"><i style="width:{{ $c['w'] }}%"></i></div></div>
        @endforeach
      </div>
      <div class="card" style="padding:16px 18px;gap:8px">
        <b style="font-size:14px">Tareas recurrentes</b>
        @forelse($recurrentes as $r)
          <div style="display:flex;justify-content:space-between;gap:8px;font-size:13px;padding:6px 0;border-top:1px solid #F1F1EC"><span class="ell">{{ $r->titulo }}</span><span class="mono" style="font-size:12px;color:#6A6A65;white-space:nowrap">{{ Tarea::REPETIR[$r->repetir] }} · {{ Formato::fecha($r->fecha) }}</span></div>
        @empty
          <span style="font-size:12.5px;color:#6A6A65">Ninguna. Al crear una tarea elige «Repetir» (por ejemplo, cada trimestre) y, al marcarla como hecha, se crea sola la siguiente.</span>
        @endforelse
      </div>
    </div>
  </div>
</div>
@endsection
