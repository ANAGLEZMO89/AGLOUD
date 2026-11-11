{{-- Pestañas del módulo: la pantalla principal y «Parámetros» --}}
@php [$__n, $__g, $__r] = \App\Support\Parametros::MODULOS[$modulo]; @endphp
<div class="chipbar" style="border-radius:11px;align-self:flex-start">
  <a class="{{ $actual === 'modulo' ? 'on' : '' }}" style="padding:7px 14px;border-radius:8px" href="{{ route($__r) }}">{{ $__n }}</a>
  <a class="{{ $actual === 'param' ? 'on' : '' }}" style="padding:7px 14px;border-radius:8px" href="{{ route('parametros', $modulo) }}">Parámetros</a>
</div>
