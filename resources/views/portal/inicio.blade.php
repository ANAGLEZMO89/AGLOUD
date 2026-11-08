@extends('layouts.app')
@php use App\Support\Formato; $u = auth()->user(); @endphp
@section('content')
<div class="page">
  <div style="display:flex;flex-direction:column;gap:4px"><span class="crumb">{{ Formato::fechaLarga(now()) }}</span><h1 class="ttl">Hola, {{ explode(' ', $u->name)[0] }}</h1></div>
  <div class="g2">
    <div class="col">
      <div class="card" style="gap:6px">
        <div class="card-h" style="padding-bottom:6px"><span class="ct">{{ $u->esEmpleado() ? 'Lo último que has enviado' : 'Documentos recientes' }}</span><a class="lnk" href="{{ route('portal.documentos') }}">Ver todos</a></div>
        @forelse($recientes as $d)
          <a class="drow" style="padding:10px 0;border-top:1px solid #F1F1EC" href="{{ route('portal.documentos', ['sel' => $d->id]) }}">
            <span class="n">{{ $d->nombre }}</span><span class="r">{{ Formato::fecha($d->fecha) }}</span>
            <div class="m"><span>{{ $d->carpetaTexto() }}</span></div><span class="r">{{ $d->origen === 'Portal del cliente' ? 'Enviado por ti' : 'De tu asesor' }}</span>
          </a>
        @empty
          <div class="emptybox">Todavía no hay documentos. Cuando tu asesor comparta algo contigo, aparecerá aquí.</div>
        @endforelse
      </div>
    </div>
    <div class="col">
      <form class="card limecard" method="POST" action="{{ route('portal.documentos.subir') }}" enctype="multipart/form-data" data-upload-form style="gap:10px">
        @csrf
        <span class="ct">Envía documentación a tu asesor</span>
        <span style="font-size:13.5px">Facturas, extractos, nóminas, contratos… Los clasificamos nosotros.</span>
        <label class="btn" style="align-self:flex-start;cursor:pointer"><span class="plus" style="font-size:14px">+</span>Subir documentos<input type="file" name="archivos[]" multiple class="hidden" data-autoupload></label>
      </form>
      <div class="card dark" style="gap:8px">
        <span style="font:500 11px var(--mono);letter-spacing:.08em;color:#C8F031">TU ASESOR</span>
        <span style="font-size:18px;font-weight:700">{{ $c->asesor?->name ?? 'GA AGOPYME' }}</span>
        <span style="font-size:13px;color:#BDBDB7">{{ config('agcloud.despacho.email') }}{{ config('agcloud.despacho.telefono') ? ' · '.config('agcloud.despacho.telefono') : '' }}</span>
      </div>
    </div>
  </div>
</div>
@endsection
