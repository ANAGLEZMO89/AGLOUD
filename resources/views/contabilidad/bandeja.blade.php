@extends('layouts.app')
@php use App\Services\Contabilidad; use App\Support\Formato; @endphp
@section('content')
<div class="page" style="gap:16px">
  @include('contabilidad._cabecera')
  <p style="margin:0;font-size:13px;color:#3C3C3C;line-height:1.5">Aquí llegan solas las facturas emitidas en <b>Facturación</b> y las recibidas registradas en <b>Proveedores</b> y <b>Acreedores</b>. Revisa la cuenta propuesta (en las recibidas puedes cambiarla) y pulsa «Contabilizar».</p>
  @if($lista->isEmpty())
    <div class="card"><div class="empty"><b>Bandeja vacía</b><span>Todas las facturas de {{ $ej->anio }} están contabilizadas.</span></div></div>
  @else
  <form method="POST" action="{{ route('contabilidad.contabilizar') }}" class="card" style="padding:6px 18px 14px;overflow-x:auto;gap:0" data-bandeja>
    @csrf
    <div style="min-width:1040px">
      <div class="band-cols" style="font-size:11px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;color:#6A6A65;padding:12px 0 10px;border-bottom:1px solid #E6E6E0"><label class="chk"><input type="checkbox" data-band-todas checked style="accent-color:#0B0B0B"></label><span>Tipo</span><span>Fecha</span><span>Nº</span><span>Cliente / proveedor</span><span style="text-align:right">Base</span><span style="text-align:right">IVA</span><span style="text-align:right">Total</span><span>Cuenta</span></div>
      @foreach($lista as $x)
        @php $col = ['Emitida' => ['#E6F6A8', '#2E3A00'], 'Compra' => ['#FCEBCB', '#6E4300'], 'Gasto' => ['#ECECE7', '#3C3C3C']][$x['tipo']]; @endphp
        <div class="band-cols" style="padding:10px 0;border-bottom:1px solid #F1F1EC;font-size:13.5px">
          <label class="chk"><input type="checkbox" name="k[]" value="{{ $x['k'] }}" checked data-band-k style="accent-color:#0B0B0B"></label>
          <span><span style="font-size:11.5px;font-weight:700;padding:3px 9px;border-radius:10px;background:{{ $col[0] }};color:{{ $col[1] }}">{{ $x['tipo'] }}</span></span>
          <span class="mono" style="font-size:12.5px">{{ Formato::fecha($x['fecha']) }}</span>
          <a class="mono ell" style="font-size:12.5px;font-weight:600" href="{{ $x['url'] }}">{{ $x['num'] }}</a>
          <span class="ell">{{ $x['ter'] }}@if(isset($x['deducible']) && ! $x['deducible']) <small style="color:#8A1C1C">· IVA no deducible</small>@endif</span>
          <span class="mono" style="text-align:right">{{ Formato::num($x['base']) }}</span><span class="mono" style="text-align:right">{{ Formato::num($x['iva']) }}</span><span class="mono" style="text-align:right;font-weight:700">{{ Formato::num($x['total']) }}</span>
          @if($x['tipo'] === 'Emitida')
            <span class="mono ell" style="font-size:12px" title="{{ Contabilidad::nombre($x['cuenta']) }}">{{ $x['cuenta'] }} · {{ Contabilidad::nombre($x['cuenta']) }}</span>
          @else
            <input class="inp mono" name="cta[{{ $x['k'] }}]" value="{{ $x['cuenta'] }}" maxlength="9" list="ctas6" style="height:32px;font-size:12.5px" title="{{ Contabilidad::nombre($x['cuenta']) }}">
          @endif
        </div>
      @endforeach
      <datalist id="ctas6">@foreach(config('pgc') as $k => $n)@if(in_array(((string) $k)[0], ['6', '2'], true) && strlen((string) $k) === 3)<option value="{{ Contabilidad::a9($k) }}">{{ $n }}</option>@endif @endforeach</datalist>
      <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;padding-top:14px">
        <span style="font-size:12.5px;color:#6A6A65">Emitidas: 430 (cliente) al debe; 705, 477 y suplidos al haber. Recibidas: gasto o compra y 472 al debe; 4751 (retención) y 400/410 al haber.</span>
        <button class="btn" type="submit" style="background:#0B0B0B;color:#fff" @disabled($ej->cerrado())>Contabilizar las marcadas</button>
      </div>
    </div>
  </form>
  @endif
</div>
@endsection
