@php
  // Cabecera común de Laboral y SS (prototipo): conexiones RED/SEPE, empresa y pestañas
  $TABS = [['trab', 'Trabajadores', 'laboral.index'], ['int', 'Integral de nóminas', 'laboral.integral'], ['ss', 'Seguros sociales', 'laboral.seguros'], ['mov', 'Altas y bajas', 'laboral.movimientos'], ['par', 'Parámetros de laboral', 'laboral.parametros']];
  $conUrl = fn ($k) => request()->fullUrlWithQuery(['con' => $k]);
@endphp
<div class="page-h">
  <div class="t"><span class="crumb">Áreas · Laboral y Seguridad Social</span><h1 class="ttl">Laboral y SS</h1></div>
  <div class="row" style="min-width:0;max-width:100%">
    <a href="{{ $conUrl('red') }}" class="lb-conn"><span class="lb-ic">SS</span><span style="display:flex;flex-direction:column;gap:1px"><span style="font-size:13px;font-weight:700;white-space:nowrap">Seguridad Social</span><span style="font-size:11px;color:#6A6A65;white-space:nowrap">Sistema RED</span></span><span class="lb-dot" style="background:{{ $redOk ? '#7CB518' : '#F5A524' }}"></span></a>
    <a href="{{ $conUrl('sepe') }}" class="lb-conn"><span class="lb-ic" style="font-size:9.5px">SEPE</span><span style="display:flex;flex-direction:column;gap:1px"><span style="font-size:13px;font-weight:700;white-space:nowrap">SEPE</span><span style="font-size:11px;color:#6A6A65;white-space:nowrap">Contrat@ · Certific@2</span></span><span class="lb-dot" style="background:{{ $sepeOk ? '#7CB518' : '#F5A524' }}"></span></a>
    @include('partials.empresa', ['volver' => '/laboral'])
  </div>
</div>
<div class="chipbar" style="border-radius:11px">
  @foreach($TABS as [$id, $l, $ruta])
    <a class="{{ $tab === $id ? 'on' : '' }}" style="padding:7px 13px;border-radius:8px" href="{{ route($ruta, in_array($id, ['int', 'ss'], true) ? ['anio' => $anio, 'mes' => $mes] : []) }}">{{ $l }}</a>
  @endforeach
</div>
@if($errors->any())<div class="ferr">{{ $errors->first() }}</div>@endif
