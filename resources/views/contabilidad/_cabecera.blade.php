@php use App\Support\Formato; @endphp
<div class="page-h">
  <div class="t"><span class="crumb">Áreas</span><h1 class="ttl">Contabilidad</h1></div>
  <div class="row" style="min-width:0;max-width:100%">
    <select class="sel" style="width:auto;height:40px" data-nav aria-label="Ejercicio">
      @foreach($ejercicios as $e)<option value="{{ request()->fullUrlWithQuery(['ej' => $e->anio]) }}" @selected($e->id === $ej->id)>Ejercicio {{ $e->anio }} · {{ $e->estado }}</option>@endforeach
    </select>
    @include('partials.empresa', ['volver' => '/contabilidad'])
  </div>
</div>
@if($ej->cerrado())<div class="msg warn" style="font-weight:600">Ejercicio {{ $ej->anio }} cerrado: solo lectura. Para corregirlo, la administración puede reabrirlo en «Ejercicios».</div>@endif
<div class="chipbar" style="border-radius:11px">
  @foreach([['resumen', 'Resumen', 'contabilidad.index', null], ['bandeja', 'Bandeja de facturas', 'contabilidad.bandeja', $nBandeja], ['diario', 'Libro diario', 'contabilidad.diario', $nAsientos], ['apuntes', 'Mantenimiento de apuntes', 'contabilidad.apuntes', null], ['mayor', 'Mayor', 'contabilidad.mayor', null], ['sumas', 'Sumas y saldos', 'contabilidad.sumas', null], ['anuales', 'PyG y balance', 'contabilidad.anuales', null], ['ejercicios', 'Ejercicios', 'contabilidad.ejercicios', null]] as [$id, $l, $rutaTab, $n])
    <a class="{{ $tab === $id ? 'on' : '' }}" style="padding:7px 12px;border-radius:8px" href="{{ route($rutaTab) }}">{{ $l }}@if($n)<span style="font:600 11px var(--mono);margin-left:6px;padding:1px 6px;border-radius:8px;background:{{ $tab === $id ? '#C8F031' : '#F4F4F0' }};color:#0B0B0B">{{ $n }}</span>@endif</a>
  @endforeach
  <a style="padding:7px 12px;border-radius:8px" href="{{ route('parametros', 'contabilidad') }}">Parámetros</a>
</div>
@if($errors->any())<div class="ferr">{{ $errors->first() }}</div>@endif
