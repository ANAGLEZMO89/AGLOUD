@php
  use App\Support\Formato;
  // Pestañas de Contabilidad (prototipo): los listados y el Registro Mercantil van en desplegables
  $LISTADOS = [['extracto', 'Extracto de cuenta', 'contabilidad.mayor'], ['pyg', 'Cuenta de explotación (PyG)', 'contabilidad.pyg'], ['sumas', 'Balance de sumas y saldos', 'contabilidad.sumas'],
    ['ldiario', 'Listado diario de movimientos', 'contabilidad.ldiario'], ['lmayor', 'Listado mayor de cuentas', 'contabilidad.lmayor'], ['anuales', 'Balances oficiales', 'contabilidad.anuales']];
  $RM = [['rm-plantillas', 'Plantillas e informes', 'contabilidad.rm', 'plantillas'], ['rm-legalia', 'Emisión de libros contables (Legalia)', 'contabilidad.rm', 'legalia'], ['rm-d2', 'Depósito de cuentas (D2)', 'contabilidad.rm', 'd2']];
  $enListados = in_array($tab, array_column($LISTADOS, 0), true);
  $enRm = in_array($tab, array_column($RM, 0), true);
  $subtitulo = collect($LISTADOS)->firstWhere(0, $tab)[1] ?? (collect($RM)->firstWhere(0, $tab)[1] ?? null);
@endphp
<div class="page-h">
  <div class="t"><span class="crumb">Áreas{{ $subtitulo ? ' · Contabilidad' : '' }}</span><h1 class="ttl">{{ $subtitulo ?? 'Contabilidad' }}</h1></div>
  <div class="row" style="min-width:0;max-width:100%">
    <select class="sel" style="width:auto;height:40px" data-nav aria-label="Ejercicio">
      @foreach($ejercicios as $e)<option value="{{ request()->fullUrlWithQuery(['ej' => $e->anio]) }}" @selected($e->id === $ej->id)>Ejercicio {{ $e->anio }} · {{ $e->estado }}</option>@endforeach
    </select>
    @include('partials.empresa', ['volver' => '/contabilidad'])
  </div>
</div>
@if($ej->cerrado())<div class="msg warn" style="font-weight:600">Ejercicio {{ $ej->anio }} cerrado: solo lectura. Para corregirlo, la administración puede reabrirlo en «Ejercicios».</div>@endif
<div class="chipbar ct-tabs" style="border-radius:11px">
  @foreach([['resumen', 'Resumen', 'contabilidad.index', null], ['bandeja', 'Bandeja de facturas', 'contabilidad.bandeja', $nBandeja], ['diario', 'Libro diario', 'contabilidad.diario', $nAsientos], ['apuntes', 'Mantenimiento de apuntes', 'contabilidad.apuntes', null]] as [$id, $l, $rutaTab, $n])
    <a class="{{ $tab === $id ? 'on' : '' }}" style="padding:7px 12px;border-radius:8px" href="{{ route($rutaTab) }}">{{ $l }}@if($n)<span class="ct-n {{ $tab === $id ? 'on' : '' }}">{{ $n }}</span>@endif</a>
  @endforeach
  <details class="ct-dd {{ $enListados ? 'on' : '' }}">
    <summary>Listados contables ▾</summary>
    <div class="ct-menu"><b>Listados contables</b>@foreach($LISTADOS as [$id, $l, $rutaTab])<a class="{{ $tab === $id ? 'on' : '' }}" href="{{ route($rutaTab) }}">{{ $l }}</a>@endforeach</div>
  </details>
  <a class="{{ $tab === 'pgc' ? 'on' : '' }}" style="padding:7px 12px;border-radius:8px" href="{{ route('contabilidad.pgc') }}">PGC</a>
  <a class="{{ $tab === 'conciliador' ? 'on' : '' }}" style="padding:7px 12px;border-radius:8px" href="{{ route('contabilidad.conciliador') }}">Conciliador bancario</a>
  <details class="ct-dd {{ $enRm ? 'on' : '' }}">
    <summary>Registro Mercantil ▾</summary>
    <div class="ct-menu"><b>Registro Mercantil</b>@foreach($RM as [$id, $l, $rutaTab, $sec])<a class="{{ $tab === $id ? 'on' : '' }}" href="{{ route($rutaTab, $sec) }}">{{ $l }}</a>@endforeach</div>
  </details>
  <a class="{{ $tab === 'ejercicios' ? 'on' : '' }}" style="padding:7px 12px;border-radius:8px" href="{{ route('contabilidad.ejercicios') }}">Ejercicios</a>
  <a style="padding:7px 12px;border-radius:8px" href="{{ route('parametros', 'contabilidad') }}">Parámetros</a>
</div>
@if($errors->any())<div class="ferr">{{ $errors->first() }}</div>@endif
