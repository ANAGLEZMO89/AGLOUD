<div class="page-h">
  <div class="t"><span class="crumb">{{ $crumb }}</span><h1 class="ttl">{{ $h1 }}</h1></div>
  <div class="row" style="min-width:0;max-width:100%">
    <a href="{{ route('fiscal.index', ['parametros' => 1]) }}" style="height:44px;padding:0 18px;border-radius:22px;background:#0B0B0B;color:#fff;display:flex;align-items:center;gap:8px;font-weight:700;font-size:14px;white-space:nowrap;text-decoration:none"><span style="width:8px;height:8px;border-radius:50%;background:#C8F031"></span>Parámetros</a>
    @include('partials.empresa', ['volver' => '/fiscal'])
  </div>
</div>
@if($errors->any() && ! ($abrirParam ?? false))<div class="ferr">{{ $errors->first() }}</div>@endif
