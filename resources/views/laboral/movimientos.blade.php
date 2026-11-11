@extends('layouts.app')
@php use App\Services\Nomina; use App\Support\Formato; use App\Support\Plazos; @endphp
@section('content')
<div class="page" style="gap:16px">
  @include('laboral._cabecera')
  <div style="display:flex;flex-wrap:wrap;align-items:center;gap:10px">
    <a class="btn lime" href="{{ route('laboral.movimientos', ['baja' => 1]) }}">Tramitar baja</a>
    @if($pendMov)
      @if($redOk)<form method="POST" action="{{ route('laboral.comunicar') }}" data-abrir="{{ Nomina::URL['red'] }}">@csrf<button class="btn" type="submit">Comunicar {{ $pendMov }} pendientes a la TGSS</button></form>
      @else<a class="btn" href="{{ request()->fullUrlWithQuery(['con' => 'red']) }}">Comunicar {{ $pendMov }} pendientes a la TGSS</a>@endif
    @endif
    <span style="font-size:12.5px;color:#6A6A65">Altas antes del inicio de la actividad · bajas y variaciones en 3 días naturales.</span>
  </div>
  @if($bajaOpen)
    <form class="card" method="POST" action="{{ route('laboral.baja') }}">
      @csrf<input type="hidden" name="_baja" value="1">
      <div style="display:flex;justify-content:space-between;align-items:center;gap:10px"><span style="font-size:15px;font-weight:700">Baja de trabajador</span><a href="{{ route('laboral.movimientos') }}" style="font-size:13px;font-weight:600;color:#3C3C3C">Cancelar</a></div>
      @if($activos->isEmpty())
        <span style="font-size:13.5px;color:#6A6A65">No hay trabajadores en alta.</span>
      @else
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,200px),1fr));gap:12px">
        <label class="lb-f"><span class="lb-l">Trabajador</span><select class="sel lb-in" name="trabajador">@foreach($activos as $a)<option value="{{ $a->id }}" @selected((int) old('trabajador') === $a->id)>{{ $a->datos['nombre'] ?? '' }}</option>@endforeach</select></label>
        <label class="lb-f"><span class="lb-l">Fecha de baja</span><input class="inp lb-in" type="date" name="fecha" value="{{ old('fecha', Plazos::hoy()->toDateString()) }}"></label>
        <label class="lb-f"><span class="lb-l">Causa</span><select class="sel lb-in" name="causa">@foreach(Nomina::CAUSAS_BAJA as $x)<option @selected(old('causa') === $x)>{{ $x }}</option>@endforeach</select></label>
      </div>
      <div style="display:flex;justify-content:flex-end"><button class="btn" type="submit">Registrar baja</button></div>
      @endif
    </form>
  @endif
  <div class="card" style="padding:6px 18px 10px;overflow-x:auto">
    <div style="min-width:720px">
      <div class="lb-grid lb-mov lb-head"><span>Fecha</span><span>Movimiento</span><span>Trabajador</span><span>Detalle</span><span>TGSS</span></div>
      @forelse($movs as $m)
        @php $ok = $m->estado === 'Comunicado'; $tbg = $m->tipo === 'Baja' ? '#FDE7E7' : ($m->tipo === 'Alta' ? '#E6F6A8' : '#ECECE7'); @endphp
        <div class="lb-grid lb-mov lb-row" style="cursor:default">
          <span class="mono12">{{ Formato::fecha($m->fecha) }}</span><span><span class="lb-pill" style="background:{{ $tbg }};color:#0B0B0B">{{ $m->tipo }}</span></span>
          <span class="ell" style="font-weight:600">@if($m->trabajador)<a href="{{ route('laboral.ficha', $m->trabajador_id) }}" style="color:inherit">{{ $m->trabajador->datos['nombre'] ?? '' }}</a>@endif</span>
          <span class="ell" style="font-size:12.5px;color:#3C3C3C">{{ $m->detalle }}</span>
          <span><span class="lb-pill" style="background:{{ $ok ? '#E6F6A8' : '#FCEBCB' }};color:{{ $ok ? '#2E3A00' : '#6E4300' }}">{{ $m->estado }}</span></span>
        </div>
      @empty
        <div style="padding:24px 0;text-align:center;font-size:14px;color:#6A6A65">Todavía no hay movimientos de afiliación.</div>
      @endforelse
    </div>
  </div>
</div>
@endsection
@section('overlay')
@if($con) @include('laboral._conexion') @endif
@endsection
