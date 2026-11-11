@extends('layouts.app')
@php
  use App\Services\Nomina;
  use App\Support\Formato;
  $o = fn ($k, $def) => old($k, $def);
  $conv = $P['conv'];
  $chk = fn ($k, $def) => old('_enviado') ? (bool) old($k) : (bool) $def;
  $habOld = old('_enviado') ? (array) old('hab', []) : $P['hab'];
  $clausOld = old('_enviado') ? (array) old('claus', []) : $P['claus'];
  $cats = old('_enviado') ? array_values((array) old('cats', [])) : $conv['cats'];
  $MODELOS = [[1, 'Clásico', 'Devengos y deducciones en columnas, bases al pie'], [2, 'Compacto', 'Una sola tabla, ideal para enviar por correo'], [3, 'Oficial detallado', 'Estructura del recibo de la Orden ESS/2098/2014']];
  $modelo = (int) $o('modelo', $P['modelo']);
@endphp
@section('content')
<div class="page" style="gap:16px">
  @include('laboral._cabecera')
  <form method="POST" action="{{ route('laboral.parametros.guardar') }}" enctype="multipart/form-data" style="display:flex;flex-direction:column;gap:16px" data-lb-par>
    @csrf<input type="hidden" name="_enviado" value="1">
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,420px),1fr));gap:16px;align-items:start">
      <div class="card">
        <span style="font-size:15px;font-weight:700">Convenio colectivo aplicable</span>
        @if($conv['nombre'])
          <div style="padding:12px 14px;border-radius:12px;background:#0B0B0B;color:#F4F4F0;display:flex;flex-direction:column;gap:2px"><span style="font-size:14px;font-weight:700">{{ $conv['nombre'] }}</span><span style="font-size:12px;color:#BDBDB7">{{ implode(' · ', array_filter([$conv['codigo'] ? 'Código '.$conv['codigo'] : null, $conv['ambito'] ?: null, $conv['vigencia'] ? 'Vigencia '.$conv['vigencia'] : null])) ?: 'Sin código ni vigencia' }}</span></div>
        @else
          <div class="msg warn" style="font-weight:500">Sin convenio seleccionado. Búscalo en REGCON o el BOE y carga su PDF, o introdúcelo a mano.</div>
        @endif
        <div style="display:flex;flex-wrap:wrap;gap:8px">
          <input class="inp" data-lb-convq placeholder="Sector y provincia: hostelería Huelva" style="flex:1 1 200px;height:38px">
          <a class="btn soft" href="{{ Nomina::URL['regcon'] }}" target="_blank" rel="noopener">Buscar en REGCON ↗</a>
          <a class="btn soft" href="{{ Nomina::URL['boe'] }}" target="_blank" rel="noopener" data-lb-boe>Buscar en el BOE ↗</a>
        </div>
        <label class="lb-file"><input type="file" accept=".pdf" data-lb-convpdf hidden><span class="lb-ic" style="background:#0B0B0B;color:#fff">PDF</span><span style="display:flex;flex-direction:column;gap:2px"><span style="font-size:13.5px;font-weight:700">Cargar el PDF del convenio</span><span style="font-size:12px;color:#6A6A65">Se leen el nombre, el código, la vigencia, la jornada y las vacaciones de las primeras páginas</span></span></label>
        <span data-lb-convst style="font-size:12.5px;font-weight:600" hidden></span>
        <div class="lb-fields">
          @foreach([['conv_nombre', 'Nombre del convenio', 'nombre'], ['conv_codigo', 'Código del convenio (REGCON)', 'codigo'], ['conv_ambito', 'Ámbito', 'ambito'], ['conv_vigencia', 'Vigencia', 'vigencia'], ['conv_jornada', 'Jornada anual (horas)', 'jornada'], ['conv_vacaciones', 'Vacaciones (días naturales)', 'vacaciones']] as [$k, $l, $f])
            <label class="lb-f"><span class="lb-l">{{ $l }}</span><input class="inp lb-in" name="{{ $k }}" value="{{ $o($k, $conv[$f]) }}" data-conv="{{ $f }}"></label>
          @endforeach
          <label class="lb-f"><span class="lb-l">Pagas</span><select class="sel lb-in" name="conv_pagas">@foreach(['14', '12', '15', '16'] as $x)<option @selected((string) $o('conv_pagas', $conv['pagas']) === $x)>{{ $x }}</option>@endforeach</select></label>
        </div>
        <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;padding-top:6px;border-top:1px solid #F1F1EC"><span style="font-size:15px;font-weight:700">Tablas salariales</span><button type="button" class="btn soft" data-lb-add="cats">+ Categoría</button></div>
        <div class="lb-cat lb-head" style="padding:0"><span>Categoría</span><span>Grupo</span><span>Base €/mes</span><span>Plus €/mes</span><span></span></div>
        <div data-lb-list="cats" data-n="{{ count($cats) }}" style="display:flex;flex-direction:column;gap:8px">
          @foreach($cats as $i => $ct)
            <div class="lb-cat"><input class="inp lb-in" name="cats[{{ $i }}][cat]" value="{{ $ct['cat'] ?? '' }}"><select class="sel lb-in" name="cats[{{ $i }}][grupo]">@foreach(range(1, 7) as $g)<option @selected((string) ($ct['grupo'] ?? '7') === (string) $g)>{{ $g }}</option>@endforeach</select><input class="inp lb-in mono" name="cats[{{ $i }}][base]" value="{{ is_numeric($ct['base'] ?? null) ? Formato::num($ct['base']) : ($ct['base'] ?? '') }}" inputmode="decimal" style="text-align:right"><input class="inp lb-in mono" name="cats[{{ $i }}][plus]" value="{{ is_numeric($ct['plus'] ?? null) ? Formato::num($ct['plus']) : ($ct['plus'] ?? '') }}" inputmode="decimal" style="text-align:right"><button type="button" class="lb-del" data-lb-del aria-label="Quitar">×</button></div>
          @endforeach
        </div>
        <template data-lb-tpl="cats"><div class="lb-cat"><input class="inp lb-in" name="cats[__][cat]" value="Nueva categoría"><select class="sel lb-in" name="cats[__][grupo]">@foreach(range(1, 7) as $g)<option @selected($g === 7)>{{ $g }}</option>@endforeach</select><input class="inp lb-in mono" name="cats[__][base]" value="1.221,00" inputmode="decimal" style="text-align:right"><input class="inp lb-in mono" name="cats[__][plus]" value="0,00" inputmode="decimal" style="text-align:right"><button type="button" class="lb-del" data-lb-del aria-label="Quitar">×</button></div></template>
      </div>

      <div style="display:flex;flex-direction:column;gap:16px;min-width:0">
        <div class="card">
          <span style="font-size:15px;font-weight:700">Modelo de nómina</span>
          <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:10px">
            @foreach($MODELOS as [$v, $l, $sb])
              <label class="lb-mod"><input type="radio" name="modelo" value="{{ $v }}" @checked($modelo === $v) hidden><div class="lb-mod-p"><span></span><span></span><span></span><span></span></div><span style="font-size:13.5px;font-weight:700">{{ $l }}</span><span style="font-size:11.5px;color:#6A6A65;line-height:1.35">{{ $sb }}</span></label>
            @endforeach
          </div>
          <label class="lb-chk"><input type="checkbox" name="useImg" value="1" @checked($chk('useImg', $P['useImg']))>Firmar con la firma o el logo de la empresa</label>
          <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
            @if($firma || $logo)<img src="{{ route('clientes.imagen.ver', [$c, $firma ? 'firma' : 'logo']) }}" alt="Firma" style="max-height:56px;max-width:200px;display:block">@endif
            <span style="font-size:12.5px;color:#6A6A65">{{ $firma || $logo ? 'Se usará '.($firma ? 'la firma' : 'el logo').' subido en los datos de alta de '.$c->nombre.'.' : $c->nombre.' no tiene logo ni firma. Súbelos en Clientes › Datos de alta › Logo y firma.' }}</span>
          </div>
        </div>

        <div class="card">
          <span style="font-size:15px;font-weight:700">Conexión con el Sistema RED</span>
          <div class="lb-fields">
            <label class="lb-f"><span class="lb-l">Correo electrónico del autorizado</span><input class="inp lb-in" type="email" name="red_email" value="{{ $o('red_email', $P['red']['email']) }}"></label>
            <label class="lb-f"><span class="lb-l">Número de autorizado RED</span><input class="inp lb-in" name="red_aut" value="{{ $o('red_aut', $P['red']['aut']) }}"></label>
            <label class="lb-f"><span class="lb-l">Caducidad del certificado</span><input class="inp lb-in" type="date" name="red_certCad" value="{{ $o('red_certCad', $P['red']['certCad']) }}"></label>
          </div>
          <label class="lb-file"><input type="file" name="red_cert" accept=".pfx,.p12,.cer,.crt" hidden data-lb-fname><span class="lb-ic">🔐</span><span style="display:flex;flex-direction:column;gap:2px"><span style="font-size:13.5px;font-weight:700">Certificado digital del autorizado</span><span style="font-size:12px;color:#6A6A65" data-lb-fname-t>{{ $P['red']['cert'] ?: 'Elige el fichero del certificado' }}</span></span></label>
          <label class="lb-f"><span class="lb-l">Códigos de cuenta de cotización de la empresa (separados por comas)</span><input class="inp lb-in mono" name="cccs" value="{{ $o('cccs', implode(', ', $P['cccs'])) }}"></label>
        </div>

        <div class="card">
          <span style="font-size:15px;font-weight:700">Conexión con el SEPE</span>
          <label class="lb-f"><span class="lb-l">Usuario autorizado de Contrat@ y Certific@2</span><input class="inp lb-in" name="sepe_aut" value="{{ $o('sepe_aut', $P['sepe']['aut']) }}"></label>
          <label class="lb-chk"><input type="checkbox" name="sepe_mismoCert" value="1" @checked($chk('sepe_mismoCert', $P['sepe']['mismoCert'])) data-lb-mismo>Usar el mismo certificado digital que el Sistema RED</label>
          <label class="lb-file" data-lb-sepecert @if($chk('sepe_mismoCert', $P['sepe']['mismoCert'])) hidden @endif><input type="file" name="sepe_cert" accept=".pfx,.p12,.cer,.crt" hidden data-lb-fname><span class="lb-ic">🔐</span><span style="display:flex;flex-direction:column;gap:2px"><span style="font-size:13.5px;font-weight:700">Certificado para el SEPE</span><span style="font-size:12px;color:#6A6A65" data-lb-fname-t>{{ $P['sepe']['cert'] ?: 'Elige el fichero del certificado' }}</span></span></label>
          <span style="font-size:12px;color:#6A6A65;line-height:1.45">Del certificado solo se guarda el nombre del fichero para saber con cuál se trabaja: los trámites se firman en la sede oficial.</span>
        </div>
      </div>
    </div>

    <div class="card">
      <span style="font-size:15px;font-weight:700">Contratos que usa la empresa</span>
      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(min(100%,340px),1fr));gap:8px 16px">
        @foreach(Nomina::CONTRATOS as $x)
          @php $k = Nomina::codigo($x); @endphp
          <label class="lb-chk" style="align-items:flex-start"><input type="checkbox" name="hab[]" value="{{ $k }}" @checked(in_array($k, $habOld, true))><span><span class="lb-code" style="margin-right:6px">{{ $k }}</span>{{ implode(' · ', array_slice(explode(' · ', $x), 1)) }}</span></label>
        @endforeach
      </div>
    </div>
    <div class="card">
      <span style="font-size:15px;font-weight:700">Cláusulas por defecto en los contratos</span>
      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(min(100%,260px),1fr));gap:8px 16px">
        @foreach(Nomina::CLAUSULAS as $k => [$l])<label class="lb-chk"><input type="checkbox" name="claus[]" value="{{ $k }}" @checked(in_array($k, $clausOld, true))>{{ $l }}</label>@endforeach
      </div>
    </div>
    <div style="display:flex;justify-content:flex-end;gap:10px">
      <a class="btn white" href="{{ route('laboral.parametros') }}">Descartar cambios</a>
      <button class="btn lg" type="submit">Guardar parámetros</button>
    </div>
  </form>
</div>
@endsection
@section('overlay')
@if($con) @include('laboral._conexion') @endif
@endsection
@push('scripts')
<script src="{{ asset('js/ocr.js') }}?v={{ config('app.version', '1') }}"></script>
@endpush
