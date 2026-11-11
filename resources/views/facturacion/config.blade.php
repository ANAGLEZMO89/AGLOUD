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
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,420px),1fr));gap:16px;align-items:start">
      <div style="display:flex;flex-direction:column;gap:16px">
        <div class="card" style="padding:18px 20px;gap:12px">
          <span style="font-size:16px;font-weight:700">Diseño de la factura</span>
          <div class="cfgrow"><span>Modelo</span>{!! $seg('est[modelo]', $est['modelo'], ['clasica' => 'Clásica', 'moderna' => 'Moderna', 'minimal' => 'Minimal']) !!}</div>
          <div class="cfgrow"><span>Color</span><div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">@foreach(['#0B0B0B', '#1F3A5F', '#2F5D50', '#7A2E2E', '#5B4B8A', '#8A5A00'] as $col)<label style="cursor:pointer"><input type="radio" name="est[colorp]" value="{{ $col }}" class="hidden" data-color-pick @checked(strtoupper($est['color']) === $col)><span class="swatch" style="background:{{ $col }}"></span></label>@endforeach<input type="color" name="est[color]" value="{{ $est['color'] }}" title="Otro color" style="width:34px;height:28px;padding:0;border:1px solid #DADAD4;border-radius:6px;background:#fff;cursor:pointer" data-color-input></div></div>
          <div class="cfgrow"><span>Esquinas</span>{!! $seg('est[forma]', $est['forma'], ['recta' => 'Rectas', 'suave' => 'Suaves', 'redondeada' => 'Redondeadas']) !!}</div>
          <div class="cfgrow"><span>Cabecera de tabla</span>{!! $seg('est[cabecera]', $est['cabecera'], ['relleno' => 'Relleno', 'linea' => 'Línea']) !!}</div>
          <div class="cfgrow"><span>Márgenes</span>{!! $seg('est[margen]', $est['margen'], ['estrecho' => 'Estrechos', 'normal' => 'Normales', 'amplio' => 'Amplios']) !!}</div>
          <div class="cfgrow"><span>Tipografía</span>{!! $seg('est[fuente]', $est['fuente'], ['sans' => 'Sin serifa', 'serif' => 'Con serifa']) !!}</div>
          <div class="cfgrow"><span>Cuadros</span><label class="chk" style="gap:8px"><input type="checkbox" name="est[cuadros]" value="1" @checked($est['cuadros']) style="accent-color:#0B0B0B">Enmarcar los datos del cliente y del pago</label></div>
          <div class="cfgrow"><span>Logo</span><div style="display:flex;flex-direction:column;gap:6px"><label class="chk" style="gap:8px"><input type="checkbox" name="est[logo]" value="1" @checked($est['logo']) style="accent-color:#0B0B0B">Mostrar el logo en la factura</label><span class="hint muted">{{ $c->logo_documento_id ? 'Se usa el logo de la ficha de la empresa.' : 'Sube el logo en la ficha de la empresa (Editar ficha › Logo y firma).' }}</span></div></div>
          <div class="cfgrow"><span>Datos registrales</span><div style="display:flex;flex-direction:column;gap:6px;flex:1"><label class="chk" style="gap:8px"><input type="checkbox" name="est[regShow]" value="1" @checked($est['regShow']) style="accent-color:#0B0B0B">Mostrar al pie de la factura</label><textarea class="txa" name="est[reg]" rows="2" maxlength="300" placeholder="Inscrita en el Registro Mercantil de Huelva, tomo, folio, hoja e inscripción (si lo dejas vacío se toma de los datos registrales de la ficha)">{{ $est['reg'] }}</textarea></div></div>
        </div>
        <div class="card" style="padding:18px 20px;gap:10px">
          <div style="display:flex;justify-content:space-between;align-items:center"><span style="font-size:16px;font-weight:700">Productos y servicios</span><button type="button" class="pillbtn" style="height:32px" data-cat-add>+ Añadir</button></div>
          <span class="hint muted">Aparecen al escribir la descripción de una línea y rellenan el precio y el IVA.</span>
          <div data-cat-box style="display:flex;flex-direction:column;gap:8px">
            @foreach($cfg->catalogo ?? [] as $i => $p)
              <div class="cat-row" data-cat-row><input class="inp" name="cat[{{ $i }}][nombre]" value="{{ $p['nombre'] }}" maxlength="120"><input class="inp mono" name="cat[{{ $i }}][precio]" value="{{ Formato::num($p['precio']) }}" inputmode="decimal"><select class="sel" name="cat[{{ $i }}][iva]">@foreach(FC::IVAS as $v)<option @selected((string) $p['iva'] === $v)>{{ $v }}</option>@endforeach</select><button type="button" class="lnk" style="font-size:18px;color:#6A6A65;text-decoration:none" data-cat-del>×</button></div>
            @endforeach
          </div>
          <template data-cat-tpl><div class="cat-row" data-cat-row><input class="inp" name="cat[__][nombre]" maxlength="120" placeholder="Nombre"><input class="inp mono" name="cat[__][precio]" inputmode="decimal" placeholder="0,00"><select class="sel" name="cat[__][iva]">@foreach(FC::IVAS as $v)<option>{{ $v }}</option>@endforeach</select><button type="button" class="lnk" style="font-size:18px;color:#6A6A65;text-decoration:none" data-cat-del>×</button></div></template>
        </div>
      </div>
      <div style="display:flex;flex-direction:column;gap:10px">
        <span style="font-size:14px;font-weight:700">Vista previa del diseño <small style="font-weight:400;color:#6A6A65">(con el diseño guardado)</small></span>
        <iframe src="{{ route('facturacion.muestra') }}" title="Vista previa del diseño" style="width:100%;height:1000px;border:0;background:#fff;border-radius:6px;box-shadow:0 1px 0 #E2E2DC,0 10px 30px rgba(11,11,11,.08)"></iframe>
      </div>
    </div>
    <div class="card" style="padding:14px 20px;flex-direction:row;justify-content:flex-end;align-items:center;gap:12px;position:sticky;bottom:12px;box-shadow:0 6px 24px rgba(0,0,0,.08)">
      <span style="font-size:12.5px;color:#6E4300;display:none" data-dirty>Cambios sin guardar</span>
      <button class="btn" type="submit" style="background:#0B0B0B;color:#fff">Guardar</button>
    </div>
  </form>
</div>
@endsection
