{{-- Pestañas del módulo y «Parámetros» --}}
@php
    [$__n, $__g, $__r] = \App\Support\Parametros::MODULOS[$modulo];
    $__tabs = \App\Support\Parametros::PESTANAS[$modulo] ?? [[$__n, $__r]];
@endphp
<div class="chipbar" style="border-radius:11px;align-self:flex-start">
  @foreach($__tabs as [$__l, $__ruta])
    <a class="{{ $actual === $__ruta || ($actual === 'modulo' && $loop->first) ? 'on' : '' }}" style="padding:7px 14px;border-radius:8px" href="{{ route($__ruta) }}">{{ $__l }}</a>
  @endforeach
  <a class="{{ $actual === 'param' ? 'on' : '' }}" style="padding:7px 14px;border-radius:8px" href="{{ route('parametros', $modulo) }}">Parámetros</a>
</div>
