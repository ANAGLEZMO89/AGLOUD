@extends('layouts.app')
@php
    $seg = function ($name, $val, $opts) {
        $h = '<div class="seg" style="padding:3px;flex-wrap:wrap">';
        foreach ($opts as $v => $l) {
            $h .= '<label style="cursor:pointer"><input type="radio" name="'.$name.'" value="'.$v.'" '.($val === $v ? 'checked' : '').' class="hidden" data-seg><span class="segopt">'.$l.'</span></label>';
        }

        return $h.'</div>';
    };
    $img = fn ($slot) => $c->{$slot.'_documento_id'} ? route('clientes.imagen.ver', [$c, $slot]).'?v='.$c->{$slot.'_documento_id'} : null;
@endphp
@section('content')
<div class="page" style="gap:16px">
  @include('facturacion._cabecera')

  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,420px),1fr));gap:16px;align-items:start">
    <div style="display:flex;flex-direction:column;gap:16px;min-width:0">
      {{-- Logo y firma: se suben aquí y quedan en la ficha de la empresa --}}
      <div class="card" style="padding:18px 20px;gap:14px">
        <span style="font-size:16px;font-weight:700">Logo y firma</span>
        @foreach(['logo' => ['Logo', 'Aparece arriba a la izquierda de la factura y en los presupuestos.'], 'firma' => ['Firma', 'Firma escaneada que se imprime al pie de la factura. No sustituye a la firma electrónica con certificado.']] as $slot => [$l, $d])
          <div style="display:grid;grid-template-columns:120px minmax(0,1fr);gap:14px;align-items:center">
            <div style="width:120px;height:72px;border-radius:10px;border:1px dashed #C4C4BE;background:#FAFAF7 center/contain no-repeat;display:flex;align-items:center;justify-content:center;font-size:11.5px;color:#9A9A94{{ $img($slot) ? ';background-image:url('.$img($slot).')' : '' }}">{{ $img($slot) ? '' : 'Sin '.mb_strtolower($l) }}</div>
            <div style="display:flex;flex-direction:column;gap:6px;min-width:0">
              <b style="font-size:13.5px">{{ $l }}</b>
              <span style="font-size:12px;color:#6A6A65">{{ $d }}</span>
              <div style="display:flex;gap:8px;flex-wrap:wrap">
                <form method="POST" action="{{ route('clientes.imagen', [$c, $slot]) }}" enctype="multipart/form-data">
                  @csrf
                  <label class="pillbtn ink" style="height:32px;cursor:pointer">{{ $img($slot) ? 'Cambiar '.mb_strtolower($l) : 'Subir '.mb_strtolower($l) }}<input type="file" name="imagen" accept="image/png,image/jpeg,image/webp" class="hidden" data-autoupload></label>
                </form>
                @if($img($slot))
                  <form method="POST" action="{{ route('clientes.imagen.quitar', [$c, $slot]) }}" data-confirm="¿Quitar {{ $slot === 'logo' ? 'el logo' : 'la firma' }}?">@csrf @method('DELETE')<button class="pillbtn" style="height:32px" type="submit">Quitar</button></form>
                @endif
              </div>
            </div>
          </div>
        @endforeach
        <span class="hint muted">PNG o JPG de hasta 4 MB. Mejor con fondo transparente. Se guardan cifrados en la ficha de {{ $c->nombre }}.</span>
      </div>

      <form method="POST" action="{{ route('facturacion.diseno.guardar') }}" class="card" style="padding:18px 20px;gap:12px" data-dis-form>
        @csrf
        <span style="font-size:16px;font-weight:700">Diseño de la factura</span>
        <div class="cfgrow"><span>Modelo</span>{!! $seg('est[modelo]', $est['modelo'], ['clasica' => 'Clásica', 'moderna' => 'Moderna', 'minimal' => 'Minimal', 'lateral' => 'Lateral']) !!}</div>
        <div class="cfgrow"><span>Color</span><div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">@foreach(['#0B0B0B', '#1F3A5F', '#2F5D50', '#7A2E2E', '#5B4B8A', '#8A5A00'] as $col)<label style="cursor:pointer"><input type="radio" name="est[colorp]" value="{{ $col }}" class="hidden" data-color-pick @checked(strtoupper($est['color']) === $col)><span class="swatch" style="background:{{ $col }}"></span></label>@endforeach<input type="color" name="est[color]" value="{{ $est['color'] }}" title="Otro color" style="width:34px;height:28px;padding:0;border:1px solid #DADAD4;border-radius:6px;background:#fff;cursor:pointer" data-color-input></div></div>
        <div class="cfgrow"><span>Tipografía</span>{!! $seg('est[fuente]', $est['fuente'], ['sans' => 'Sin serifa', 'serif' => 'Con serifa']) !!}</div>
        <div class="cfgrow"><span>Esquinas</span>{!! $seg('est[forma]', $est['forma'], ['recta' => 'Rectas', 'suave' => 'Suaves', 'redondeada' => 'Redondeadas']) !!}</div>
        <div class="cfgrow"><span>Cabecera de tabla</span>{!! $seg('est[cabecera]', $est['cabecera'], ['relleno' => 'Relleno', 'linea' => 'Línea']) !!}</div>
        <div class="cfgrow"><span>Márgenes</span>{!! $seg('est[margen]', $est['margen'], ['estrecho' => 'Estrechos', 'normal' => 'Normales', 'amplio' => 'Amplios']) !!}</div>
        <div class="cfgrow"><span>Cuadros</span><label class="chk" style="gap:8px"><input type="checkbox" name="est[cuadros]" value="1" @checked($est['cuadros']) style="accent-color:#0B0B0B">Enmarcar los datos del cliente y del pago</label></div>
        <div class="cfgrow"><span>Logo</span><label class="chk" style="gap:8px"><input type="checkbox" name="est[logo]" value="1" @checked($est['logo']) style="accent-color:#0B0B0B">Mostrar el logo en la factura</label></div>
        <div class="cfgrow"><span>Firma</span><label class="chk" style="gap:8px"><input type="checkbox" name="est[firma]" value="1" @checked($est['firma']) style="accent-color:#0B0B0B">Mostrar la firma al pie de la factura</label></div>
        <div class="cfgrow"><span>Datos registrales</span><div style="display:flex;flex-direction:column;gap:6px;flex:1"><label class="chk" style="gap:8px"><input type="checkbox" name="est[regShow]" value="1" @checked($est['regShow']) style="accent-color:#0B0B0B">Mostrar al pie de la factura</label><textarea class="txa" name="est[reg]" rows="2" maxlength="300" placeholder="Si lo dejas vacío se toma de los datos registrales de la ficha (Registro Mercantil, tomo, folio, hoja e inscripción)">{{ $est['reg'] }}</textarea></div></div>
        <div style="display:flex;justify-content:flex-end;align-items:center;gap:12px;padding-top:4px">
          <span style="font-size:12.5px;color:#6E4300;display:none" data-dirty>Cambios sin guardar</span>
          <button class="btn" type="submit" style="background:#0B0B0B;color:#fff">Guardar diseño</button>
          <button class="hidden" type="submit" formaction="{{ route('facturacion.muestra') }}" formtarget="disPrev" data-dis-prev tabindex="-1" aria-hidden="true">Vista previa</button>
        </div>
      </form>
    </div>
    <div style="display:flex;flex-direction:column;gap:10px;min-width:0">
      <span style="font-size:14px;font-weight:700">Vista previa <small style="font-weight:400;color:#6A6A65">· se actualiza al cambiar cualquier opción</small></span>
      <iframe name="disPrev" src="{{ route('facturacion.muestra') }}" title="Vista previa del diseño" style="width:100%;height:1000px;border:0;background:#fff;border-radius:6px;box-shadow:0 1px 0 #E2E2DC,0 10px 30px rgba(11,11,11,.08)"></iframe>
    </div>
  </div>
</div>
@endsection
