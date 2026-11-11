<div class="page-h">
  <div class="t"><span class="crumb">Gestión</span><h1 class="ttl">{{ $tituloH }}</h1></div>
  @include('partials.empresa', ['volver' => $volver])
</div>
@include('partials.tabs-modulo', ['modulo' => $modulo, 'actual' => $actual])
@if($errors->any())<div class="ferr">{{ $errors->first() }}</div>@endif
