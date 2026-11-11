@extends('layouts.app')
@php
    use App\Http\Controllers\FacturaController as FC;
    use App\Models\Factura;
    use App\Support\Formato;
    $seg = function ($name, $val, $opts) { $h = '<div class="seg" style="padding:3px">'; foreach ($opts as $v => $l) { $h .= '<label style="cursor:pointer"><input type="radio" name="'.$name.'" value="'.$v.'" '.($val === $v ? 'checked' : '').' class="hidden" data-seg><span class="segopt">'.$l.'</span></label>'; } return $h.'</div>'; };
@endphp
@section('content')
<div class="page" style="gap:16px">
  @include('facturacion._cabecera')
  <form method="POST" action="{{ route('facturacion.config.guardar') }}" style="display:flex;flex-direction:column;gap:16px">
    @csrf
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,360px),1fr));gap:16px">
      <div class="card" style="padding:18px 20px;gap:12px">
        <span style="font-size:16px;font-weight:700">Modalidad de facturación</span>
        @foreach(\App\Models\FacturacionConfig::MODOS as $k => [$l, $d])
          <label class="modo-opt"><input type="radio" name="modo" value="{{ $k }}" @checked(old('modo', $cfg->modo ?: 'normal') === $k)><span class="dot"></span><span style="display:flex;flex-direction:column;gap:2px"><b style="font-size:14px">{{ $l }}</b><span style="font-size:12.5px;color:#3C3C3C">{{ $d }}</span>@if($k === 'verifactu')<span style="font-size:12px;color:#6E4300;font-weight:600">El envío a la AEAT aún no está activado: hay que validar con tu certificado los esquemas oficiales vigentes. Mientras tanto las facturas se emiten sin envío y sin la leyenda VERI*FACTU.</span>@endif @if($k === 'electronica')<span style="font-size:12px;color:#6A6A65">El XML se genera sin firma. Para presentarlo en FACe hay que firmarlo con certificado (se activará más adelante).</span>@endif</span></label>
        @endforeach
        <div style="display:flex;flex-direction:column;gap:2px;padding:10px 12px;border-radius:10px;background:#F4F4F0"><span style="font-size:11px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;color:#6A6A65">Emisor</span><b style="font-size:14px">{{ $c->nombre }}</b><span class="mono" style="font-size:12.5px">{{ $c->nif ?: 'Sin NIF' }}</span></div>
        <label class="lbl-s">Pie de factura<input class="inp" name="pie" value="{{ old('pie', $cfg->pie) }}" maxlength="500" placeholder="Ej.: Pago por transferencia al IBAN indicado."></label>
      </div>
      <div class="card" style="padding:18px 20px;gap:10px">
        <span style="font-size:16px;font-weight:700">Series de facturación</span>
        @foreach($cfg->series as $i => $s)
          <div style="display:grid;grid-template-columns:44px minmax(0,1fr) 170px 70px;gap:8px;align-items:center">
            <input type="hidden" name="series[{{ $i }}][cod]" value="{{ $s['cod'] }}"><span style="font:700 14px var(--mono);text-align:center;padding:8px 0;border-radius:8px;background:#0B0B0B;color:#C8F031">{{ $s['cod'] }}</span>
            <input class="inp" name="series[{{ $i }}][nombre]" value="{{ $s['nombre'] }}" maxlength="60" style="height:38px">
            @if($usos[$s['cod']] ?? 0)
              <input type="hidden" name="series[{{ $i }}][tf]" value="{{ $s['tf'] }}"><span style="font:500 12.5px var(--mono);color:#6A6A65">{{ $s['tf'] }} · {{ Factura::TIPOS[$s['tf']] }}</span>
            @else
              <select class="sel" name="series[{{ $i }}][tf]" style="height:38px">@foreach(Factura::TIPOS as $k => $l)<option value="{{ $k }}" @selected($s['tf'] === $k)>{{ $k }} · {{ $l }}</option>@endforeach</select>
            @endif
            <span style="font-size:12px;color:#6A6A65;text-align:right">{{ Formato::entero($usos[$s['cod']] ?? 0) }} fact.</span>
          </div>
        @endforeach
        <div style="display:flex;gap:8px;align-items:center"><input class="inp mono upper" name="nueva_serie" maxlength="3" placeholder="Código" style="width:90px;height:38px"><span style="font-size:12.5px;color:#6A6A65">Escribe un código nuevo y pulsa «Guardar» para añadir la serie.</span></div>
        <span class="hint muted">La numeración de cada serie es correlativa por año y no admite fechas anteriores a la última factura emitida. Una serie con facturas no cambia de tipo ni se puede quitar.</span>
      </div>
    </div>
    <div class="card" style="padding:14px 18px;flex-direction:row;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap"><span style="font-size:13.5px">El modelo, los colores, la tipografía, el logo y la firma están en la pestaña <b>Diseño de la factura</b>.</span><a class="pillbtn ink" href="{{ route('facturacion.diseno') }}">Ir al diseño</a></div>
        <div class="card" style="padding:14px 18px;flex-direction:row;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap"><span style="font-size:13.5px">Los <b>productos y servicios</b> ({{ count($cfg->catalogo ?? []) }}) se gestionan en Gestión › Productos y se rellenan solos al escribir una línea.</span><a class="pillbtn ink" href="{{ route('productos.index') }}">Ir a Productos</a></div>

    <div class="card" style="padding:14px 20px;flex-direction:row;justify-content:flex-end;align-items:center;gap:12px;position:sticky;bottom:12px;box-shadow:0 6px 24px rgba(0,0,0,.08)">
      <span style="font-size:12.5px;color:#6E4300;display:none" data-dirty>Cambios sin guardar</span>
      <button class="btn" type="submit" style="background:#0B0B0B;color:#fff">Guardar</button>
    </div>
  </form>
</div>
@endsection
