@extends('layouts.app')
@php
  $REG = ['General', 'Sistema Especial Agrario', 'Sistema Especial de Empleados de Hogar', 'Otro'];
  $filas = old('f') ? array_values(old('f')) : $filas;
  $fila = function ($i, $f) use ($REG, $cuenta) {
      $ccc = preg_replace('/\D/', '', (string) ($f['ccc'] ?? ''));
      $o = '<div class="lb-ccc"><input class="inp lb-in mono" name="f['.$i.'][ccc]" value="'.e($f['ccc'] ?? '').'" placeholder="21 1234567 89" maxlength="16">'
        .'<input class="inp lb-in" name="f['.$i.'][denom]" value="'.e($f['denom'] ?? '').'" placeholder="Centro o actividad">'
        .'<select class="sel lb-in" name="f['.$i.'][regimen]">';
      foreach ($REG as $r) { $o .= '<option'.(($f['regimen'] ?? 'General') === $r ? ' selected' : '').'>'.e($r).'</option>'; }
      $o .= '</select><input class="inp lb-in mono" name="f['.$i.'][cnae]" value="'.e($f['cnae'] ?? '').'" placeholder="CNAE"><input class="inp lb-in mono" name="f['.$i.'][at]" value="'.e($f['at'] ?? '').'" placeholder="%">'
        .'<span class="mono12" style="text-align:center">'.($ccc !== '' ? (int) ($cuenta[$ccc] ?? 0) : '').'</span><button type="button" class="lb-del" data-lb-del aria-label="Quitar">×</button></div>';
      return new \Illuminate\Support\HtmlString($o);
  };
@endphp
@section('content')
<div class="page" style="gap:16px">
  @include('laboral._cabecera')
  <form class="card" method="POST" action="{{ route('laboral.ccc.guardar') }}">
    @csrf
    <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap"><span style="font-size:15px;font-weight:700">Códigos de cuenta de cotización de {{ $c->nombre }}</span><button type="button" class="btn soft" data-lb-add="ccc">+ Añadir CCC</button></div>
    <span style="font-size:12.5px;color:#6A6A65">11 dígitos: provincia (2), número (7) y control (2). Los CCC se eligen en la ficha de cada trabajador y salen en las nóminas, los seguros sociales y los contratos.</span>
    <div style="overflow-x:auto"><div style="min-width:760px;display:flex;flex-direction:column;gap:8px">
      <div class="lb-ccc lb-head" style="padding:0 0 8px"><span>CCC</span><span>Denominación</span><span>Régimen</span><span>CNAE</span><span>AT %</span><span>Trab.</span><span></span></div>
      <div data-lb-list="ccc" data-n="{{ count($filas) }}" style="display:flex;flex-direction:column;gap:8px">
        @foreach($filas as $i => $f){{ $fila($i, $f) }}@endforeach
      </div>
    </div></div>
    <template data-lb-tpl="ccc">{{ $fila('__', ['regimen' => 'General']) }}</template>
    @if(! $filas)<span style="font-size:13px;color:#6A6A65">Sin códigos de cuenta de cotización. Añade el primero.</span>@endif
    <div style="display:flex;justify-content:flex-end"><button class="btn" type="submit">Guardar CCC</button></div>
  </form>
</div>
@endsection
@section('overlay')
@if($con) @include('laboral._conexion') @endif
@endsection
