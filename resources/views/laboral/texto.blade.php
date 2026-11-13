@extends('layouts.app')
@php use App\Services\Nomina; @endphp
@section('content')
<div class="page" style="gap:16px">
  @include('laboral._cabecera')
  <div style="display:flex;flex-wrap:wrap;align-items:center;gap:12px">
    <a class="btn white" href="{{ route('laboral.ficha', [$t->id, 'sec' => 'ss']) }}">← {{ $w['nombre'] }}</a>
    <div style="display:flex;flex-direction:column;gap:2px;min-width:0"><span style="font-size:18px;font-weight:700">Texto del contrato</span><span style="font-size:12.5px;color:#6A6A65">{{ $w['contrato'] }} · {{ $propio ? 'texto propio de este trabajador' : 'plantilla de su modalidad en Parámetros de contrato' }}</span></div>
    <a class="btn" style="margin-left:auto" href="{{ route('laboral.documento', [$t->id, 'contrato']) }}" target="_blank" rel="noopener">Ver el contrato en PDF</a>
  </div>
  <form method="POST" action="{{ route('laboral.contrato.texto.guardar', $t->id) }}" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,460px),1fr));gap:16px;align-items:start">
    @csrf
    <div class="card">
      <span style="font-size:12.5px;color:#6A6A65;line-height:1.45">Los cambios solo afectan al contrato de {{ $w['nombre'] }}. Escribe <b>**así**</b> para negrita y deja una línea en blanco entre cláusulas. Las cláusulas marcadas en su ficha se añaden al final.</span>
      <textarea class="txa mono" name="texto" rows="24" style="font-size:12.5px;line-height:1.5;height:auto;padding:10px 12px" data-lb-texto>{{ old('texto', $texto) }}</textarea>
      <div style="display:flex;flex-wrap:wrap;gap:10px;justify-content:flex-end">
        @if($propio)<button class="btn soft" type="submit" name="restablecer" value="1" formnovalidate>Volver a la plantilla de la modalidad</button>@endif
        <button class="btn" type="submit">Guardar texto</button>
      </div>
    </div>
    <div class="card">
      <span style="font-size:15px;font-weight:700">Marcadores</span>
      <div style="display:flex;flex-wrap:wrap;gap:6px">@foreach(Nomina::MARCADORES as $k => $l)<button type="button" class="lb-chip" data-lb-marca="{{ '{'.$k.'}' }}" title="{{ $l }}">{{ '{'.$k.'}' }}</button>@endforeach</div>
      <span style="font-size:12px;color:#6A6A65">Pulsa un marcador para insertarlo donde esté el cursor. Se rellenan con los datos actuales de la ficha al generar el PDF.</span>
    </div>
  </form>
</div>
@endsection
@section('overlay')
@if($con) @include('laboral._conexion') @endif
@endsection
