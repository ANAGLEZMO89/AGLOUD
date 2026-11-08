@extends('layouts.app')
@php use App\Support\Formato; @endphp
@section('content')
<div class="page">
  <div class="page-h">
    <div class="t"><span class="crumb">Gestión</span><h1 class="ttl">Clientes</h1></div>
    <div class="row">
      <a class="btn white" href="{{ route('clientes.exportar', request()->query()) }}">Exportar</a>
      <a class="btn lime" href="{{ route('clientes.create') }}">+ Nuevo cliente</a>
    </div>
  </div>
  <div class="kpis">
    @foreach($resumen as [$l, $v])
      <div class="kpi"><span>{{ $l }}</span><b>{{ Formato::entero($v) }}</b></div>
    @endforeach
  </div>
  <div class="card pad16" style="gap:14px">
    <form method="GET" action="{{ route('clientes.index') }}" class="row" style="gap:12px" data-autoform>
      <input class="inp inp-soft" name="q" value="{{ $q }}" placeholder="Buscar por nombre, NIF o actividad" style="flex:1;min-width:240px" data-autosubmit>
      <input type="hidden" name="tipo" value="{{ $tipo }}">
      <div class="seg">
        @foreach(array_keys(config('agcloud.filtros_tipo')) as $l)
          <a class="{{ $tipo === $l ? 'on' : '' }}" href="{{ route('clientes.index', array_filter(['q' => $q, 'tipo' => $l === 'Todos' ? null : $l, 'asesor' => $asesor === 'Todos' ? null : $asesor, 'estado' => $estado === 'Todos' ? null : $estado])) }}">{{ $l }}</a>
        @endforeach
      </div>
      <select class="sel" name="asesor" style="width:auto;height:38px" data-change-submit>
        <option value="Todos">Todos los asesores</option>
        @foreach($asesores as $a)<option value="{{ $a->id }}" @selected((string) $asesor === (string) $a->id)>{{ $a->name }}</option>@endforeach
      </select>
      <select class="sel" name="estado" style="width:auto;height:38px" data-change-submit>
        <option value="Todos">Todos los estados</option>
        @foreach(array_keys(config('agcloud.estados_cliente')) as $e)<option value="{{ $e }}" @selected($estado === $e)>{{ $e }}</option>@endforeach
      </select>
    </form>
    <div class="tbl">
      <div class="th cli-cols"><span>Cliente</span><span>Tipo</span><span>Actividad</span><span>Asesor</span><span>Cuota</span><span>Pendiente</span><span>Estado</span></div>
      @forelse($lista as $c)
        @php [$ebg, $efg] = $c->estadoColores(); @endphp
        <a class="tr cli-cols" href="{{ route('clientes.show', $c) }}">
          <div style="display:flex;align-items:center;gap:12px;min-width:0">
            <span class="avsq">{{ $c->iniciales() }}</span>
            <div style="display:flex;flex-direction:column;gap:1px;min-width:0"><span class="ell" style="font-weight:600">{{ $c->nombre }}</span><span style="font:500 11.5px var(--mono);color:#6A6A65">{{ $c->nif ?: 'Pendiente' }}</span></div>
          </div>
          <span class="c3">{{ $c->tipo }}</span>
          <span class="c3 ell">{{ $c->actividadPrincipal() ?: '—' }}</span>
          <span class="c3 ell">{{ $c->asesor?->name ?? '—' }}</span>
          <span style="font:500 13px var(--mono)">{{ $c->cuotaTexto() }}</span>
          <span>@if($c->por_revisar)<span class="dpill">{{ $c->por_revisar }} docs</span>@else<span style="color:#9A9A94">Al día</span>@endif</span>
          <span class="pill" style="background:{{ $ebg }};color:{{ $efg }}">{{ $c->estado }}</span>
        </a>
      @empty
        <div style="padding:40px 10px;text-align:center;color:#6A6A65;font-size:14px">
          @if($q !== '' || $tipo !== 'Todos' || $asesor !== 'Todos' || $estado !== 'Todos') No hay clientes con esos filtros. @else Aún no hay clientes. <a href="{{ route('clientes.create') }}">Da de alta el primero</a>. @endif
        </div>
      @endforelse
    </div>
    @include('partials.pager', ['p' => $lista])
  </div>
</div>
@endsection

@if(isset($drawer))
  @section('overlay')
    @include('clientes.partials.drawer', $drawer)
  @endsection
@endif
