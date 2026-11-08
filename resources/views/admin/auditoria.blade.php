@extends('layouts.app')
@php use App\Support\Formato; @endphp
@section('content')
<div class="page">
  <div class="page-h">
    <div class="t"><span class="crumb">Despacho · RGPD</span><h1 class="ttl">Registro de accesos</h1></div>
    <a class="btn white" href="{{ route('admin.auditoria.exportar', request()->query()) }}">Exportar</a>
  </div>
  <div class="card pad16" style="gap:14px">
    <form method="GET" class="row" style="gap:10px">
      <input class="inp inp-soft" name="q" value="{{ request('q') }}" placeholder="Buscar por usuario, descripción o IP" style="flex:1;min-width:220px">
      <select class="sel" name="tipo" style="width:auto">@foreach($tipos as $v => $l)<option value="{{ $v }}" @selected(request('tipo', '') === $v)>{{ $l }}</option>@endforeach</select>
      <input class="inp" type="date" name="desde" value="{{ request('desde') }}" style="width:auto" title="Desde">
      <input class="inp" type="date" name="hasta" value="{{ request('hasta') }}" style="width:auto" title="Hasta">
      <button class="btn sm" type="submit">Filtrar</button>
    </form>
    <div class="tbl">
      <div class="th" style="min-width:900px;grid-template-columns:130px minmax(0,1.1fr) minmax(0,1fr) minmax(0,2.2fr) 120px;padding:8px 0"><span>Fecha y hora</span><span>Usuario</span><span>Cliente</span><span>Acción</span><span>IP</span></div>
      @forelse($logs as $l)
        <div class="tr" style="min-width:900px;grid-template-columns:130px minmax(0,1.1fr) minmax(0,1fr) minmax(0,2.2fr) 120px;padding:9px 0;border-radius:0">
          <span class="mono" style="font-size:12px">{{ Formato::fechaHora($l->created_at) }}</span>
          <span class="ell c3" title="{{ $l->user_email }}">{{ $l->user?->name ?? ($l->user_email ?: 'Anónimo') }}</span>
          <span class="ell c3">{{ $l->cliente?->nombre ?? '—' }}</span>
          <span class="ell" title="{{ $l->descripcion }}"><span class="mono" style="font-size:11px;color:#6A6A65">{{ $l->accion }}</span> · {{ $l->descripcion }}</span>
          <span class="mono c2" style="font-size:12px">{{ $l->ip }}</span>
        </div>
      @empty
        <div style="padding:30px;text-align:center;color:#6A6A65">Sin registros con esos filtros.</div>
      @endforelse
    </div>
    @include('partials.pager', ['p' => $logs])
    <span class="hint muted">El registro solo admite anotaciones nuevas: la aplicación no permite modificar ni borrar entradas. Conserva al menos el tiempo que fije tu política de privacidad.</span>
  </div>
</div>
@endsection
