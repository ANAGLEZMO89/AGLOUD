@extends('layouts.app')
@php use App\Support\Formato; @endphp

@section('topbar')
<header class="topbar">
  <button class="menu-btn" type="button" data-menu aria-label="Menú">☰</button>
  <form class="search" method="GET" action="{{ route('clientes.index') }}" style="padding:0 6px 0 16px">
    <input name="q" placeholder="Buscar cliente, NIF, expediente o modelo…" style="flex:1;min-width:0;border:0;outline:none;font-size:14px;background:none" data-hotkey="k">
    <span class="k" style="flex:none;white-space:nowrap;font:500 11px var(--mono);color:#6A6A65;padding-right:10px">Ctrl K</span>
  </form>
  <div class="topbar-r">
    @if($ctx)
      <a class="emp" href="{{ route('escritorio', ['cambiar' => 1]) }}" title="Cambiar empresa"><span class="ini">{{ $ctx->iniciales() }}</span><b>{{ $ctx->nombre }}</b></a>
    @endif
    <a class="btn" href="{{ route('clientes.create') }}"><span class="plus">+</span>Nuevo cliente</a>
  </div>
</header>
@endsection

@section('content')
<div class="page">
  @if($elegir)
    <div style="display:flex;flex-direction:column;gap:4px"><span class="crumb">{{ Formato::fechaLarga($hoy) }}</span><h1 class="ttl">{{ Formato::saludo($hoy) }}, {{ explode(' ', auth()->user()->name)[0] }}</h1></div>
    <div class="card" style="border-radius:20px;padding:22px 24px;gap:16px">
      <form method="GET" action="{{ route('escritorio') }}" style="display:flex;flex-wrap:wrap;align-items:flex-end;justify-content:space-between;gap:14px">
        <input type="hidden" name="cambiar" value="1"><input type="hidden" name="tab" value="{{ $tab }}">
        @if(request('volver'))<input type="hidden" name="volver" value="{{ request('volver') }}">@endif
        <div style="display:flex;flex-direction:column;gap:4px;min-width:0;flex:1 1 320px"><span class="lbl-mono">SELECCIÓN DE ENTORNO</span><span style="font-size:22px;font-weight:700;letter-spacing:-.01em">¿Con quién vas a trabajar?</span><span style="font-size:13.5px;color:#6A6A65">Contabilidad, fiscal, laboral y facturación se adaptan al cliente o al despacho que elijas.</span></div>
        <input class="inp" name="q" value="{{ $q }}" placeholder="Buscar por nombre o NIF" style="flex:0 1 320px;min-width:200px" data-autosubmit>
      </form>
      <div class="seg" style="align-self:flex-start;padding:3px">
        @foreach([['act', 'Clientes activos'], ['baja', 'Dados de baja'], ['desp', 'Despacho']] as [$id, $l])
          <a class="{{ $tab === $id ? 'on' : '' }}" style="padding:7px 14px" href="{{ route('escritorio', array_filter(['cambiar' => 1, 'tab' => $id, 'q' => $q, 'volver' => request('volver')])) }}">{{ $l }} ({{ $n[$id] }})</a>
        @endforeach
      </div>
      <div style="display:flex;flex-direction:column;gap:8px">
        @forelse($lista as $c)
          <form method="POST" action="{{ route('escritorio.seleccionar', $c) }}">
            @csrf
            @if(request('volver'))<input type="hidden" name="volver" value="{{ request('volver') }}">@endif
            <button class="pick" type="submit">
              <span class="av {{ $c->es_despacho ? 'd' : '' }}">{{ $c->iniciales() }}</span>
              <span class="nm"><b>{{ $c->nombre }}</b><small>{{ $c->subtitulo() }}</small></span>
              <span class="act">{{ $c->es_despacho ? 'Gestión interna: clientes, facturación propia, equipo y tesorería' : $c->actividadPrincipal() }}</span>
              <span style="flex:none;font-size:12.5px;color:#6A6A65">{{ $c->es_despacho ? 'Todo el equipo' : ($c->asesor?->name ?? 'Sin asesor') }}</span>
              @if($c->por_revisar)<span class="pill" style="background:#FCEBCB;color:#6E4300">{{ $c->por_revisar }} doc. por revisar</span>@endif
              @if($ctx && $ctx->id === $c->id)<span class="pill" style="background:#C8F031;color:#0B0B0B;font-weight:700">Actual</span>@endif
              <span class="chev">›</span>
            </button>
          </form>
        @empty
          <span style="padding:18px;text-align:center;font-size:13.5px;color:#6A6A65">
            @if($q !== '') Ningún cliente coincide con la búsqueda. @elseif($tab === 'desp') Aún no se ha creado la ficha del despacho. Ejecuta la instalación (ver guía). @else Todavía no hay clientes en este grupo. <a href="{{ route('clientes.create') }}">Dar de alta el primero</a>. @endif
          </span>
        @endforelse
      </div>
    </div>
  @else
    <div style="display:flex;align-items:flex-end;justify-content:space-between;gap:20px;flex-wrap:wrap">
      <div style="display:flex;flex-direction:column;gap:4px"><span class="crumb">{{ Formato::fechaLarga($hoy) }}</span><h1 class="ttl">{{ Formato::saludo($hoy) }}, {{ explode(' ', auth()->user()->name)[0] }}</h1></div>
    </div>
    <div class="dark" style="border-radius:20px;padding:18px 20px;display:flex;flex-direction:column;gap:14px">
      <div style="display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:14px">
        <div style="display:flex;align-items:center;gap:14px;min-width:0">
          <span style="width:48px;height:48px;flex:none;border-radius:50%;background:#C8F031;color:#0B0B0B;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:16px">{{ $ctx->iniciales() }}</span>
          <div style="display:flex;flex-direction:column;gap:3px;min-width:0"><span style="font:500 11px var(--mono);letter-spacing:.08em;color:#C8F031">{{ $ctx->es_despacho ? 'DESPACHO PROPIO' : 'CLIENTE SELECCIONADO' }}</span><span style="font-size:19px;font-weight:700" class="ell">{{ $ctx->nombre }}</span><span style="font-size:13px;color:#BDBDB7">{{ $ctx->nif ?: 'Sin NIF' }} · {{ $ctx->es_despacho ? 'Gestión interna del despacho' : $ctx->tipo.' · '.($ctx->actividadPrincipal() ?: 'Sin actividad') }}</span></div>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
          @if($ctx->es_despacho)
            <a class="btn sm" style="background:#1C1C1C" href="{{ route('clientes.index') }}">Clientes</a>
            <a class="btn sm" style="background:#1C1C1C" href="{{ route('documentos.index') }}">Documentos</a>
            <a class="btn sm lime" href="{{ route('clientes.edit', [$ctx, 'sec' => 'general']) }}">Datos del despacho</a>
            @if(auth()->user()->esAdmin())<a class="btn sm" style="background:#1C1C1C" href="{{ route('admin.equipo') }}">Gestión del despacho</a>@endif
          @else
            <a class="btn sm" style="background:#1C1C1C" href="{{ route('documentos.index') }}">Documentos</a>
            <a class="btn sm lime" href="{{ route('clientes.show', $ctx) }}">Ficha del cliente</a>
          @endif
          <a class="btn sm" style="background:none;border:1px solid #4A4A4A" href="{{ route('escritorio', ['cambiar' => 1]) }}">⇄ {{ $ctx->es_despacho ? 'Cambiar entorno' : 'Cambiar cliente' }}</a>
        </div>
      </div>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px 18px;padding-top:12px;border-top:1px solid #2A2A2A">
        @foreach($facts as [$l, $v, $col])
          <div style="display:flex;flex-direction:column;gap:3px;min-width:0"><span style="font:500 10.5px var(--mono);letter-spacing:.08em;color:#8A8A85;text-transform:uppercase">{{ $l }}</span><span style="font-size:13.5px;font-weight:600;color:{{ $col }};overflow-wrap:anywhere">{{ $v }}</span></div>
        @endforeach
      </div>
    </div>
    <div class="card" style="align-items:flex-start">
      <span class="lbl-mono">PRÓXIMAS FASES</span>
      <span style="font-size:14px;color:#3C3C3C;line-height:1.5">Aquí aparecerán los plazos, las notificaciones y las tareas de {{ $ctx->nombre }} cuando activemos esos módulos. De momento están disponibles Clientes y Documentos.</span>
    </div>
  @endif
</div>
@endsection
