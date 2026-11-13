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
  $MODELOS = [[1, 'Clásico', 'Cuantía, precio, devengos y deducciones en columnas, con las bases al pie'], [2, 'Moderno', 'Devengos y deducciones lado a lado, con resumen y coste de empresa'], [3, 'Oficial', 'Estructura de la Orden ESS/2098/2014, con la aportación de la empresa'], [4, 'Justificativo', 'Recibo individual justificativo: devengos y deducciones agrupados con su detalle']];
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
          </div>
          @php $gc = app(\App\Services\VerificacionNif::class)->certificado(); @endphp
          <div class="lb-file" style="cursor:default"><span class="lb-ic">🔐</span><span style="display:flex;flex-direction:column;gap:2px"><span style="font-size:13.5px;font-weight:700">Certificado del despacho (global)</span><span style="font-size:12px;color:#6A6A65">{{ $gc ? $gc['titular'].($gc['caduca'] ? ' · caduca el '.\App\Support\Formato::fecha($gc['caduca']) : '') : 'Aún no se ha subido' }}. Es el mismo para todos los clientes y se gestiona en <a href="{{ route('parametros', 'clientes') }}#aeat">Parámetros de Clientes</a>.</span></span></div>
          <span style="font-size:12.5px;color:#6A6A65">Los códigos de cuenta de cotización están en <a href="{{ route('laboral.ccc') }}">Gestión › CCC</a> y el convenio en <a href="{{ route('laboral.convenios') }}">Gestión › Convenios</a>.</span>
        </div>

        <div class="card">
          <span style="font-size:15px;font-weight:700">Conexión con el SEPE</span>
          <label class="lb-f"><span class="lb-l">Usuario autorizado de Contrat@ y Certific@2</span><input class="inp lb-in" name="sepe_aut" value="{{ $o('sepe_aut', $P['sepe']['aut']) }}"></label>
          <span style="font-size:12.5px;color:#6A6A65">Se usa el certificado global del despacho, el mismo que para el Sistema RED.</span>
          <span style="font-size:12px;color:#6A6A65;line-height:1.45">De momento los trámites se firman en la sede oficial: AG Cloud no envía nada al SEPE.</span>
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
