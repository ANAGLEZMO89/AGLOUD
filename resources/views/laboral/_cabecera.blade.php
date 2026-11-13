@php
  // Cabecera común de Laboral y SS: conexiones RED/SEPE, empresa y menú con desplegables
  $q = ['anio' => $anio, 'mes' => $mes];
  $MENU = [
    ['trab', 'Trabajadores', 'laboral.index', []],
    ['int', 'Integral de nóminas', 'laboral.integral', $q],
    ['Seguridad Social', [
      ['ss-cret', 'Cret@ - Sistema de Liquidación Directa', 'laboral.seguros', $q],
      ['ss-cra', 'CRA - Conceptos Retributivos Abonados', 'laboral.cra', $q],
      ['ss-agr', 'AFI - Régimen Especial Agrario Jornadas', 'laboral.agrario', $q],
      ['ss-pp', 'AFI - Reduc. contribuciones planes de pensiones', 'laboral.planes', $q],
      ['ss-mov', 'AFI - Altas, bajas y variaciones', 'laboral.movimientos', []],
    ]],
    ['Gestión', [
      ['g-remesa', 'Remesa transferencia de nóminas', 'laboral.remesa', $q],
      ['g-ccc', 'CCC', 'laboral.ccc', []],
      ['g-comunica', 'Comunic@', 'laboral.comunica', []],
      ['g-conv', 'Convenios', 'laboral.convenios', []],
      ['g-calc', 'Cálculo de nóminas', 'laboral.calculo', $q],
    ]],
    ['Modelos tributarios', [
      ['m-145', 'Modelo 145', 'laboral.m145', []],
      ['m-111', 'Modelo 111', 'laboral.m111', ['anio' => $anio, 't' => (int) ceil($mes / 3)]],
      ['m-190', 'Modelo 190', 'laboral.m190', ['anio' => $anio]],
    ]],
    ['par', 'Parámetros laboral', 'laboral.parametros', []],
    ['parc', 'Parámetros contrato', 'laboral.contratos', []],
  ];
  $conUrl = fn ($k) => request()->fullUrlWithQuery(['con' => $k]);
  $sub = null;
  foreach ($MENU as $it) { if (is_array($it[1])) { foreach ($it[1] as $x) { if ($x[0] === $tab) $sub = $x[1]; } } }
@endphp
<div class="page-h">
  <div class="t"><span class="crumb">Áreas · Laboral y Seguridad Social</span><h1 class="ttl">{{ $sub ?? 'Laboral y SS' }}</h1></div>
  <div class="row" style="min-width:0;max-width:100%">
    <a href="{{ $conUrl('red') }}" class="lb-conn"><span class="lb-ic">SS</span><span style="display:flex;flex-direction:column;gap:1px"><span style="font-size:13px;font-weight:700;white-space:nowrap">Seguridad Social</span><span style="font-size:11px;color:#6A6A65;white-space:nowrap">Sistema RED</span></span><span class="lb-dot" style="background:{{ $redOk ? '#7CB518' : '#F5A524' }}"></span></a>
    <a href="{{ $conUrl('sepe') }}" class="lb-conn"><span class="lb-ic" style="font-size:9.5px">SEPE</span><span style="display:flex;flex-direction:column;gap:1px"><span style="font-size:13px;font-weight:700;white-space:nowrap">SEPE</span><span style="font-size:11px;color:#6A6A65;white-space:nowrap">Contrat@ · Certific@2</span></span><span class="lb-dot" style="background:{{ $sepeOk ? '#7CB518' : '#F5A524' }}"></span></a>
    @include('partials.empresa', ['volver' => '/laboral'])
  </div>
</div>
<div class="chipbar ct-tabs" style="border-radius:11px">
  @foreach($MENU as $it)
    @if(is_array($it[1]))
      @php $dentro = collect($it[1])->firstWhere(0, $tab); @endphp
      <details class="ct-dd {{ $dentro ? 'on' : '' }}">
        <summary>{{ $it[0] }}@if($it[0] === 'Seguridad Social' && $pendMov)<span class="ct-n {{ $dentro ? 'on' : '' }}">{{ $pendMov }}</span>@endif ▾</summary>
        <div class="ct-menu"><b>{{ $it[0] }}</b>@foreach($it[1] as [$id, $l, $ruta, $p])<a class="{{ $tab === $id ? 'on' : '' }}" href="{{ route($ruta, $p) }}">{{ $l }}@if($id === 'ss-mov' && $pendMov)<span class="ct-n {{ $tab === $id ? 'on' : '' }}">{{ $pendMov }}</span>@endif</a>@endforeach</div>
      </details>
    @else
      <a class="{{ $tab === $it[0] ? 'on' : '' }}" style="padding:7px 13px;border-radius:8px" href="{{ route($it[2], $it[3]) }}">{{ $it[1] }}</a>
    @endif
  @endforeach
</div>
@if($errors->any())<div class="ferr">{{ $errors->first() }}</div>@endif
@if(isset($P) && empty($P['conv']['cats']) && ($tab ?? '') !== 'g-conv')
  <div class="msg warn" style="font-weight:500">El convenio de esta empresa no tiene tablas salariales: las nóminas se calculan solo con los datos de la ficha y no se puede comprobar el salario mínimo. <a href="{{ route('laboral.convenios') }}">Completar el convenio</a></div>
@endif
