@extends('layouts.app')
@php
  use App\Services\Nomina; use App\Support\Formato;
  $agr = $tipo === 'agr';
  $mesL = Nomina::MESES[$mes - 1].' '.$anio;
@endphp
@section('content')
<div class="page" style="gap:16px">
  @include('laboral._cabecera')
  @include('laboral._meses', ['ruta' => $agr ? 'laboral.agrario' : 'laboral.planes'])
  <div style="display:flex;flex-wrap:wrap;align-items:center;gap:10px">
    <span style="font-size:13.5px;color:#3C3C3C;flex:1 1 300px">
      @if($agr) Jornadas reales de {{ mb_strtolower($mesL) }} de los trabajadores del Sistema Especial Agrario. Se comunican por el Sistema RED (AFI).
      @else Contribuciones empresariales a planes de pensiones de empleo de {{ mb_strtolower($mesL) }}, para aplicar la reducción de cuotas. Se comunican por el Sistema RED (AFI). @endif
    </span>
    @if($redOk)<a class="btn" href="{{ Nomina::URL['red'] }}" target="_blank" rel="noopener">Abrir el Sistema RED ↗</a>@endif
    @include('laboral._barra')
  </div>
  @if($lista->isEmpty())
    <div class="card" style="padding:28px;text-align:center;font-size:14px;color:#6A6A65">
      @if($agr && $nTodos) Ningún trabajador en alta está marcado en el Sistema Especial Agrario. Márcalo en su ficha › Afiliación.
      @else No hay trabajadores en alta este mes. @endif
    </div>
  @else
  <form class="card" method="POST" action="{{ route('laboral.afi.guardar', $tipo) }}" style="padding:6px 18px 14px;overflow-x:auto">
    @csrf<input type="hidden" name="anio" value="{{ $anio }}"><input type="hidden" name="mes" value="{{ $mes }}">
    <div style="min-width:640px">
      <div class="lb-grid lb-afi lb-head"><span>NAF</span><span>Trabajador</span><span>CCC</span><span class="r">{{ $agr ? 'Jornadas reales' : 'Contribución (€)' }}</span></div>
      @foreach($lista as $w)
        <div class="lb-grid lb-afi lb-row" style="cursor:default"><span class="mono12">{{ $w['naf'] }}</span><span class="ell" style="font-weight:600">{{ $w['nombre'] }}</span><span class="mono12">{{ $w['ccc'] }}</span>
          <span style="display:flex;justify-content:flex-end"><input class="inp lb-in mono" name="v[{{ $w['id'] }}]" value="{{ isset($datos[$w['id']]) ? ($agr ? $datos[$w['id']] : Formato::num($datos[$w['id']])) : '' }}" placeholder="{{ $agr ? '0' : '0,00' }}" inputmode="decimal" style="width:130px;text-align:right"></span></div>
      @endforeach
      <div class="lb-grid lb-afi" style="padding:12px 0 4px;font-weight:700"><span>Total</span><span></span><span></span><span class="r m">{{ $agr ? array_sum($datos) : Formato::eur(array_sum($datos)) }}</span></div>
    </div>
    <div style="display:flex;justify-content:flex-end"><button class="btn" type="submit">Guardar {{ $agr ? 'jornadas' : 'contribuciones' }}</button></div>
  </form>
  @endif
</div>
@endsection
@section('overlay')
@if($con) @include('laboral._conexion') @endif
@endsection
