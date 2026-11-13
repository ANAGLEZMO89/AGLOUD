@extends('layouts.app')
@php use App\Support\Formato; $admin = auth()->user()->esAdmin(); @endphp
@section('content')
<div class="page" style="gap:16px">
  @include('contabilidad._cabecera')
  <form method="POST" action="{{ route('contabilidad.ejercicios.abrir') }}" class="card" style="padding:14px 18px;flex-direction:row;align-items:center;gap:10px;flex-wrap:wrap">
    @csrf
    <b style="font-size:14px">Abrir ejercicio</b>
    <input class="inp mono" type="number" name="anio" min="2000" max="2100" value="{{ ($ejercicios->max('anio') ?? now()->year) + 1 }}" style="width:110px;height:38px">
    <button class="pillbtn ink" type="submit">+ Abrir</button>
    <span class="hint muted">Al cerrar un ejercicio se abre solo el siguiente con su asiento de apertura.</span>
  </form>
  @foreach($ejercicios as $e)
    @php $x = $res[$e->id]; @endphp
    <div class="card" style="padding:16px 20px;gap:12px;{{ $e->id === $ej->id ? 'box-shadow:0 0 0 2px #0B0B0B' : '' }}">
      <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
        <div style="display:flex;align-items:center;gap:10px"><b style="font-size:18px" class="mono">{{ $e->anio }}</b><span style="font-size:12px;font-weight:700;padding:3px 9px;border-radius:10px;background:{{ $e->cerrado() ? '#ECECE7' : '#E6F6A8' }};color:{{ $e->cerrado() ? '#3C3C3C' : '#2E3A00' }}">{{ $e->estado }}</span>@if($e->id === $ej->id)<span style="font-size:12px;color:#6A6A65">En uso</span>@else<a href="{{ route('contabilidad.ejercicios', ['ej' => $e->anio]) }}" style="font-size:12.5px;font-weight:600">Trabajar en este</a>@endif</div>
        <span style="font-size:13px;color:#3C3C3C">{{ Formato::entero($x['n']) }} asientos · ingresos {{ Formato::eur($x['ingresos'], 0) }} · gastos {{ Formato::eur($x['gastos'], 0) }} · resultado <b class="mono">{{ Formato::eur($x['resultado'], 0) }}</b></span>
      </div>
      <div style="display:flex;gap:12px;align-items:end;flex-wrap:wrap">
        <form method="POST" action="{{ route('contabilidad.ejercicios.bloqueo', $e) }}" style="display:flex;gap:8px;align-items:end;flex-wrap:wrap;flex:1">
          @csrf
          <label class="lbl-s">Fecha de bloqueo<input class="inp" type="date" name="bloqueo" value="{{ $e->bloqueo?->toDateString() }}" min="{{ $e->anio }}-01-01" max="{{ $e->anio }}-12-31" style="height:38px" @disabled($e->cerrado())></label>
          <label class="lbl-s" style="flex:1;min-width:220px">Observaciones<input class="inp" name="observaciones" value="{{ $e->observaciones }}" maxlength="2000" style="height:38px"></label>
          <button class="pillbtn" type="submit" style="height:38px" @disabled($e->cerrado())>Guardar</button>
        </form>
        @if($admin)
          @if($e->cerrado())
            <form method="POST" action="{{ route('contabilidad.ejercicios.reabrir', $e) }}" data-confirm="¿Reabrir {{ $e->anio }}? Se borrarán la regularización, el cierre y la apertura de {{ $e->anio + 1 }}.">@csrf<button class="pillbtn" type="submit" style="height:38px">Reabrir</button></form>
          @else
            <form method="POST" action="{{ route('contabilidad.ejercicios.cerrar', $e) }}" data-confirm="¿Cerrar el ejercicio {{ $e->anio }}? Se generarán la regularización (6 y 7 contra 129), el cierre y la apertura de {{ $e->anio + 1 }}.">@csrf<button class="pillbtn ink" type="submit" style="height:38px">Regularizar y cerrar</button></form>
          @endif
        @endif
      </div>
      @if($pendientesEj[$e->id] && ! $e->cerrado())<span style="font-size:12.5px;color:#6E4300">{{ $pendientesEj[$e->id] }} facturas sin contabilizar en la bandeja.</span>@endif
      <span class="hint muted">Fecha de bloqueo: no se admiten asientos con fecha anterior (por ejemplo, el último trimestre ya declarado).</span>
    </div>
  @endforeach
</div>
@endsection
