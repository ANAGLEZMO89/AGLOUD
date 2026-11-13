@extends('layouts.app')
@php use App\Support\Formato; @endphp
@section('content')
<div class="page" style="gap:18px">
  <div class="page-h">
    <div class="t"><span class="crumb">Gestión</span><h1 class="ttl">Expedientes</h1></div>
    <a class="btn lime" href="{{ $url(['nuevo' => 1]) }}">+ Nuevo expediente</a>
  </div>

  @include('partials.tabs-modulo', ['modulo' => 'expedientes', 'actual' => 'modulo'])
  @if($soloCli)
    <div class="msg" style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap"><span>Mostrando solo los expedientes de <b>{{ $soloCli->nombre }}</b>.</span><a href="{{ route('escritorio', ['cambiar' => 1, 'volver' => '/expedientes']) }}" style="font-weight:600">Cambiar empresa</a></div>
  @endif

  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px">
    @foreach($resumen as [$l, $v])
      <div class="card" style="padding:14px 18px;gap:4px"><span style="font-size:13px;color:#6A6A65">{{ $l }}</span><span style="font:600 26px/1.1 var(--mono);letter-spacing:-.03em">{{ $v }}</span></div>
    @endforeach
  </div>

  <form method="GET" action="{{ route('expedientes.index') }}" style="display:flex;align-items:center;gap:12px;flex-wrap:wrap" data-autoform>
    <input class="inp" name="q" value="{{ $q }}" placeholder="Buscar por referencia, trámite o cliente" style="flex:1;min-width:240px;height:38px" data-autosubmit>
    @if($vista === 'lista')<input type="hidden" name="vista" value="lista">@endif
    <div class="chipbar" style="gap:4px;border-radius:11px">
      <a class="{{ $vista === 'tablero' ? 'on' : '' }}" style="padding:6px 14px;border-radius:8px" href="{{ $url(['vista' => null]) }}">Tablero</a>
      <a class="{{ $vista === 'lista' ? 'on' : '' }}" style="padding:6px 14px;border-radius:8px" href="{{ $url(['vista' => 'lista']) }}">Lista</a>
    </div>
    <select class="sel" name="asesor" style="width:auto;height:38px" data-change-submit>
      <option value="Todos">Todos los asesores</option>
      @foreach($equipo as $a)<option value="{{ $a->id }}" @selected($asesor === (string) $a->id)>{{ $a->name }}</option>@endforeach
    </select>
    <select class="sel" name="cat" style="width:auto;height:38px" data-change-submit>
      <option value="Todas">Todas las categorías</option>
      @foreach($cats as $ct)<option @selected($cat === $ct)>{{ $ct }}</option>@endforeach
    </select>
  </form>

  @if($vista === 'tablero')
  <div class="board"><div>
    @foreach($cols as $col)
      <div class="bcol">
        <div class="h"><b>{{ $col['estado'] }}</b><span>{{ $col['items']->count() }}</span></div>
        @foreach($col['items'] as $x)
          <a class="bcard" href="{{ route('expedientes.show', $x) }}">
            <div style="display:flex;justify-content:space-between;align-items:center"><span class="mono11">{{ $x->ref }}</span><span style="font-size:11px;color:#6A6A65">{{ $x->categoria }}</span></div>
            <span style="font-size:14px;font-weight:600;line-height:1.3">{{ $x->tramite }}</span>
            <span style="font-size:12.5px;color:#3C3C3C">{{ $x->cliente?->nombre }}</span>
            <div style="display:flex;align-items:center;gap:8px"><div class="bar5"><i style="width:{{ $x->pct() }}%"></i></div><span class="mono11">{{ $x->progTxt() }}</span></div>
            <div style="display:flex;justify-content:space-between;align-items:center;padding-top:2px"><span class="ini24" title="{{ $x->responsable?->name }}">{{ $x->responsable?->iniciales() ?? '—' }}</span><span style="display:flex;align-items:center;gap:6px;font:500 11.5px var(--mono);color:#3C3C3C"><span style="width:7px;height:7px;border-radius:50%;background:{{ $x->dot() }}"></span>{{ $x->plazoTxt() }}</span></div>
          </a>
        @endforeach
        @if($col['items']->isEmpty())<div style="padding:18px 8px;text-align:center;font-size:12.5px;color:#6A6A65">Sin expedientes</div>@endif
      </div>
    @endforeach
  </div></div>
  @else
  <div class="card" style="padding:16px 18px;overflow-x:auto">
    <div class="exp-cols" style="font-size:11px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;color:#9A9A94;padding:8px 10px;border-bottom:1px solid #EDEDE8"><span>Ref.</span><span>Trámite</span><span>Cliente</span><span>Asesor</span><span>Progreso</span><span>Plazo</span><span>Estado</span></div>
    @forelse($lista as $x)
      @php [$bg, $fg] = $x->colores(); @endphp
      <a class="exp-cols" href="{{ route('expedientes.show', $x) }}" style="padding:11px 10px;border-bottom:1px solid #F1F1EC;border-radius:10px;font-size:13.5px;text-decoration:none;color:var(--ink)">
        <span style="font:500 12px var(--mono);color:#6A6A65">{{ $x->ref }}</span>
        <span style="font-weight:600">{{ $x->tramite }}</span>
        <span class="ell" style="color:#3C3C3C">{{ $x->cliente?->nombre }}</span>
        <span class="ell" style="color:#3C3C3C">{{ $x->responsable?->name ?? '—' }}</span>
        <div style="display:flex;align-items:center;gap:8px"><div class="bar5"><i style="width:{{ $x->pct() }}%"></i></div><span class="mono11">{{ $x->progTxt() }}</span></div>
        <span style="display:flex;align-items:center;gap:6px;font:500 12px var(--mono)"><span style="width:7px;height:7px;border-radius:50%;background:{{ $x->dot() }}"></span>{{ $x->plazoTxt() }}</span>
        <span style="justify-self:start;font-size:12px;font-weight:600;padding:3px 9px;border-radius:20px;background:{{ $bg }};color:{{ $fg }};white-space:nowrap">{{ $x->estado }}</span>
      </a>
    @empty
      <div style="padding:40px 10px;text-align:center;color:#6A6A65;font-size:14px">No hay expedientes con esos filtros.</div>
    @endforelse
  </div>
  @endif
</div>
@endsection

@section('overlay')
@if($nuevo)
@php $tpls = \App\Support\Parametros::get('expedientes')['plantillas']; $t0 = $tpls[$tplDef] ?? reset($tpls); @endphp
<div class="drawer sm" role="dialog" aria-modal="true" aria-label="Nuevo expediente">
  <a class="shade" href="{{ $url() }}" aria-label="Cerrar"></a>
  <div class="panel">
    <div class="ph"><b>Nuevo expediente</b><a class="x" href="{{ $url() }}" aria-label="Cerrar">×</a></div>
    <form method="POST" action="{{ route('expedientes.store') }}" style="display:flex;flex-direction:column;flex:1;min-height:0" data-exp-form data-tpl='@json($tpls)'>
      @csrf
      @if($notif)<input type="hidden" name="notificacion_id" value="{{ $notif->id }}">@endif
      <div class="pc" style="overflow-y:auto">
        @if($errors->any())<div class="ferr">{{ $errors->first() }}</div>@endif
        @if($notif)<div class="msg">A partir de la notificación: {{ $notif->organismo }} · {{ $notif->tipo }}</div>@endif
        <label class="lbl-s">Cliente *
          <select class="sel" name="cliente_id" required>
            <option value="">Selecciona un cliente</option>
            @foreach($clientes as $cl)<option value="{{ $cl->id }}" @selected((string) $cliDef === (string) $cl->id)>{{ $cl->nombre }}</option>@endforeach
          </select>
        </label>
        <label class="lbl-s">Tipo de trámite
          <select class="sel" name="tramite" data-exp-tpl>@foreach(array_keys($tpls) as $k)<option @selected($tplDef === $k)>{{ $k }}</option>@endforeach</select>
        </label>
        <div style="display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:12px">
          <label class="lbl-s">Asesor responsable<select class="sel" name="responsable_id"><option value="">El asesor del cliente</option>@foreach($equipo as $a)<option value="{{ $a->id }}" @selected((string) old('responsable_id') === (string) $a->id)>{{ $a->name }}</option>@endforeach</select></label>
          <label class="lbl-s">Fecha límite<input class="inp" type="date" name="plazo" value="{{ old('plazo', $notif?->vence?->toDateString()) }}"></label>
        </div>
        <div style="display:grid;grid-template-columns:minmax(0,1fr) 130px;gap:12px">
          <label class="lbl-s">Qué vence en esa fecha<input class="inp" name="plazo_lbl" value="{{ old('plazo_lbl', $notif ? ($notif->accion ?: 'Respuesta a la notificación') : '') }}" maxlength="160" placeholder="Ej.: Respuesta al requerimiento"></label>
          <label class="lbl-s">Honorarios (€)<input class="inp mono" name="honorarios" value="{{ old('honorarios', Formato::num($t0['hon'])) }}" inputmode="decimal" data-exp-hon></label>
        </div>
        <div style="border-radius:14px;background:#F4F4F0;padding:16px;display:flex;flex-direction:column;gap:10px">
          <div style="display:flex;justify-content:space-between;align-items:baseline"><span style="font:500 11px var(--mono);letter-spacing:.08em;color:#6A6A65" data-prev-cat>PLANTILLA · {{ $t0['cat'] }}</span><span style="font:600 15px var(--mono)" data-prev-hon>{{ Formato::entero($t0['hon']) }} €</span></div>
          <div style="display:flex;flex-direction:column;gap:10px" data-prev-pasos>
            @foreach($t0['pasos'] as $p)<div class="prev-paso"><span></span>{{ $p }}</div>@endforeach
          </div>
          <span style="font-size:12.5px;color:#3C3C3C;padding-top:6px;border-top:1px solid #E2E2DC" data-prev-docs>Se pedirá al cliente: {{ $t0['docs'] ? implode(', ', $t0['docs']) : 'nada por ahora' }}</span>
        </div>
      </div>
      <div class="pf">
        <a class="btn soft" href="{{ $url() }}">Cancelar</a>
        <button class="btn lime" type="submit">Abrir expediente</button>
      </div>
    </form>
  </div>
</div>
@endif
@endsection
