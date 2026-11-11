@extends('layouts.app')
@php use App\Support\Formato; $SIT = ['1' => '1 · Monoparental', '2' => '2 · Cónyuge sin rentas', '3' => '3 · Otras']; @endphp
@section('content')
<div class="page" style="gap:16px">
  @include('laboral._cabecera')
  <span style="font-size:13.5px;color:#3C3C3C">Comunicación de datos al pagador (art. 88 del Reglamento del IRPF). Los datos se editan en la ficha de cada trabajador › IRPF · modelo 145 y sirven para calcular su tipo de retención.</span>
  <div class="card" style="padding:6px 18px 10px;overflow-x:auto">
    <div style="min-width:900px">
      <div class="lb-grid lb-145 lb-head"><span>Trabajador</span><span>NIF</span><span>Situación familiar</span><span>Hijos</span><span>Asc.</span><span>Comunicado</span><span></span></div>
      @forelse($plantilla as $w)
        @php $M = $w['m145']; @endphp
        <div class="lb-grid lb-145 lb-row" style="cursor:default">
          <span style="display:flex;flex-direction:column;min-width:0"><a class="ell" style="font-weight:600;color:inherit" href="{{ route('laboral.ficha', [$w['id'], 'sec' => 'irpf']) }}">{{ $w['nombre'] }}</a>@if($w['estado'] !== 'Alta')<span style="font-size:12px;color:#6A6A65">De baja</span>@endif</span>
          <span class="mono12">{{ $w['nif'] }}</span><span class="ell" style="font-size:13px">{{ $SIT[(string) ($M['sit'] ?? '3')] ?? $SIT['3'] }}{{ $w['disc'] !== 'Sin discapacidad' ? ' · discapacidad '.$w['disc'] : '' }}</span>
          <span class="mono12">{{ count($M['hijos'] ?? []) }}</span><span class="mono12">{{ count($M['asc'] ?? []) }}</span><span class="mono12">{{ ! empty($M['fecha']) ? Formato::fecha($M['fecha']) : '—' }}</span>
          <span style="display:flex;gap:6px;justify-content:flex-end"><a class="btn soft xs" href="{{ route('laboral.ficha', [$w['id'], 'sec' => 'irpf']) }}">Editar</a><a class="btn xs" href="{{ route('laboral.documento', [$w['id'], 'm145']) }}" target="_blank" rel="noopener">PDF</a></span>
        </div>
      @empty
        <div style="padding:24px 0;text-align:center;font-size:14px;color:#6A6A65">No hay trabajadores en la plantilla.</div>
      @endforelse
    </div>
  </div>
</div>
@endsection
@section('overlay')
@if($con) @include('laboral._conexion') @endif
@endsection
