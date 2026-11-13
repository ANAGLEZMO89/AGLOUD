@extends('layouts.app')
@php use App\Support\Formato; $u = auth()->user(); @endphp
@section('content')
<div class="page" style="gap:16px">
  <div style="display:flex;flex-direction:column;gap:4px"><span class="crumb">Comunicación · Notificaciones</span><h1 class="ttl">Notificaciones</h1></div>
  <span class="note-grey">Aquí ves las notificaciones de {{ $c->nombre }} que tu asesor ha leído en la DEHú con tu certificado digital o que ha registrado por ti. Solo puedes consultarlas: si alguna sigue sin abrir, tu asesor la abrirá y te avisará del plazo.</span>
  @if($u->esEmpleado())
    <div class="emptybox">Solo el administrador de la empresa puede ver las notificaciones.</div>
  @else
  <div class="card" style="gap:0;padding:6px 18px">
    @forelse($lista as $n)
      @php [$st, $bg, $fg] = $n->estadoChip(); $ids = $n->dehu ? (json_decode($n->dehu, true)['documentos'] ?? []) : ($n->documento_id ? [$n->documento_id] : []); @endphp
      <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-start;justify-content:space-between;padding:14px 0;border-top:{{ $loop->first ? '0' : '1px solid #F1F1EC' }}">
        <div style="display:flex;flex-direction:column;gap:3px;min-width:0;flex:1 1 260px">
          <span style="font-size:12px;color:#6A6A65">{{ $n->organismoTexto() }} · {{ $n->canal }}</span>
          <span style="font-size:14.5px;font-weight:700">{{ $n->tipo }}</span>
          @if($n->asunto)<span style="font-size:13px;color:#3C3C3C">{{ $n->asunto }}</span>@endif
          <span style="font-size:12.5px;color:#6A6A65">Puesta a disposición {{ Formato::fecha($n->puesta) }}@if($n->acceso) · {{ $n->caducada ? 'Notificada por caducidad' : 'Abierta' }} el {{ Formato::fecha($n->acceso) }}@endif @if($n->vence) · Plazo hasta el <b>{{ Formato::fecha($n->vence) }}</b>@endif</span>
          @if($ids)
            <div style="display:flex;flex-wrap:wrap;gap:6px;margin-top:4px">
              @foreach($ids as $id)@if($docs->has($id))<a class="pillbtn" href="{{ route('documentos.ver', $id) }}" target="_blank" rel="noopener">{{ \Illuminate\Support\Str::limit($docs[$id]->nombre, 46) }}</a>@endif @endforeach
            </div>
          @endif
        </div>
        <span class="stpill" style="background:{{ $bg }};color:{{ $fg }}">{{ $st }}</span>
      </div>
    @empty
      <div class="emptybox" style="margin:12px 0">No hay notificaciones registradas.</div>
    @endforelse
  </div>
  @endif
</div>
@endsection
