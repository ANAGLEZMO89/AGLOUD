@extends('layouts.app')
@php use App\Services\Nomina; use App\Support\Formato; @endphp
@section('content')
<div class="page" style="gap:16px">
  @include('laboral._cabecera')
  <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap"><span style="font-size:13.5px;color:#3C3C3C">Plantilla de la empresa. Pulsa un trabajador para abrir su ficha.</span><a class="btn lime" href="{{ route('laboral.ficha', 'nuevo') }}">+ Alta de trabajador</a></div>
  @if($plantilla->isEmpty())
    <div class="card" style="padding:28px;text-align:center;font-size:14px;color:#6A6A65">Esta empresa no tiene trabajadores por cuenta ajena. Usa «Alta de trabajador» para añadir el primero.</div>
  @else
  <div class="card" style="padding:6px 18px 10px;overflow-x:auto">
    <div style="min-width:900px">
      <div class="lb-grid lb-trab lb-head"><span>Trabajador</span><span>NIF</span><span>Nº afiliación</span><span>Contrato</span><span>Grupo</span><span>Alta</span><span style="text-align:right">Bruto anual</span><span>Estado</span></div>
      @foreach($plantilla as $w)
        @php $cod = Nomina::codigo($w['contrato']); $alta = $w['estado'] === 'Alta'; @endphp
        <a class="lb-grid lb-trab lb-row" href="{{ route('laboral.ficha', $w['id']) }}">
          <span class="ell" style="font-weight:600">{{ $w['nombre'] }}</span><span class="mono12">{{ $w['nif'] }}</span><span class="mono12" style="color:#3C3C3C">{{ $w['naf'] }}</span>
          <span style="display:flex;gap:6px;align-items:center;min-width:0"><span class="lb-code">{{ $cod }}</span><span class="ell" style="font-size:12.5px;color:#3C3C3C">{{ implode(' · ', array_slice(explode(' · ', $w['contrato']), 1)) }}</span></span>
          <span class="mono12">{{ $w['grupo'] }}</span><span class="mono12" style="color:#3C3C3C">{{ Formato::fecha($w['alta']) }}</span><span style="text-align:right;font:500 13px var(--mono)">{{ Formato::eur(Nomina::anual($w)) }}</span>
          <span><span class="lb-pill" style="background:{{ $alta ? '#E6F6A8' : '#ECECE7' }};color:{{ $alta ? '#2E3A00' : '#6A6A65' }}">{{ $w['estado'] }}</span></span>
        </a>
      @endforeach
    </div>
  </div>
  @endif
</div>
@endsection
@section('overlay')
@if($con) @include('laboral._conexion') @endif
@endsection
