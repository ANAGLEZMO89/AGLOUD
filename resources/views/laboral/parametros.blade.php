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
          <span style="font-size:12.5px;color:#6A6A65">Los códigos de cuenta de cotización están en <a href="{{ route('laboral.ccc') }}">Gestión › CCC</a> y el convenio en <a href="{{ route('laboral.convenios') }}">Gestión › Convenios</a>.</span>
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
