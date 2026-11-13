@extends('layouts.app')
@php use App\Services\Nomina; use App\Support\Formato; @endphp
@section('content')
<div class="page" style="gap:16px">
  @include('laboral._cabecera')
  <div style="display:flex;flex-wrap:wrap;align-items:center;gap:10px">
    <span style="font-size:13.5px;color:#3C3C3C;flex:1 1 300px">Comunicación de los contratos al SEPE: plazo de 10 días desde su concertación (art. 16.1 ET). Se hace en Contrat@ con el autorizado; aquí se controla qué falta.</span>
    <a class="btn white" href="{{ request()->fullUrlWithQuery(['con' => 'sepe']) }}">Conexión con el SEPE</a>
  </div>
  <div class="card" style="padding:6px 18px 10px;overflow-x:auto">
    <div style="min-width:980px">
      <div class="lb-grid lb-com lb-head"><span>Trabajador</span><span>Inicio</span><span>Contrato</span><span>Plazo</span><span>Estado</span><span></span></div>
      @forelse($filas as $f)
        @php $w = $f['w']; @endphp
        <div class="lb-grid lb-com lb-row" style="cursor:default">
          <a class="ell" style="font-weight:600;color:inherit" href="{{ route('laboral.ficha', [$w['id'], 'sec' => 'ss']) }}">{{ $w['nombre'] }}</a>
          <span class="mono12">{{ Formato::fecha($w['alta']) }}</span>
          <span style="display:flex;gap:6px;align-items:center;min-width:0"><span class="lb-code">{{ Nomina::codigo($w['contrato']) }}</span><span class="ell" style="font-size:12.5px;color:#3C3C3C">{{ implode(' · ', array_slice(explode(' · ', $w['contrato']), 1)) }}</span></span>
          <span class="mono12" style="color:{{ $f['vencido'] ? '#8A1C1C' : '#3C3C3C' }}">{{ Formato::fecha($f['plazo']) }}</span>
          <span><span class="lb-pill" style="background:{{ $f['com'] ? '#E6F6A8' : ($f['vencido'] ? '#FDE7E7' : '#FCEBCB') }};color:{{ $f['com'] ? '#2E3A00' : ($f['vencido'] ? '#8A1C1C' : '#6E4300') }}">{{ $f['com'] ? 'Comunicado '.Formato::fecha($f['com']['fecha']) : ($f['vencido'] ? 'Fuera de plazo' : 'Pendiente') }}</span></span>
          <span style="display:flex;gap:6px;justify-content:flex-end;flex-wrap:wrap">
            @if(! $f['com'])<form method="POST" action="{{ route('laboral.accion', [$w['id'], 'contrata']) }}" @if($sepeOk) data-abrir="{{ Nomina::URL['sepe'] }}" @endif>@csrf<input type="hidden" name="volver" value="comunica"><button class="btn xs" type="submit">Comunicar</button></form>@endif
            <a class="btn soft xs" href="{{ route('laboral.documento', [$w['id'], 'comunicacion']) }}" target="_blank" rel="noopener">Comunicación</a>
            <a class="btn soft xs" href="{{ route('laboral.documento', [$w['id'], 'copia']) }}" target="_blank" rel="noopener">Copia básica</a>
          </span>
        </div>
      @empty
        <div style="padding:24px 0;text-align:center;font-size:14px;color:#6A6A65">No hay contratos en la plantilla.</div>
      @endforelse
    </div>
  </div>
</div>
@endsection
@section('overlay')
@if($con) @include('laboral._conexion') @endif
@endsection
