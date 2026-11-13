@extends('layouts.auth', ['titulo' => 'Verificación en dos pasos'])
@section('content')
<h1>Verificación en dos pasos</h1>
<span style="font-size:13.5px;color:#3C3C3C;line-height:1.45" data-2fa-txt>Escribe el código de 6 cifras de tu aplicación de autenticación.</span>
<form method="POST" action="{{ route('two-factor.login') }}" style="display:flex;flex-direction:column;gap:16px">
  @csrf
  <label data-2fa-code>Código<input class="inp mono" type="text" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" autofocus></label>
  <label data-2fa-rec class="hidden">Código de recuperación<input class="inp mono" type="text" name="recovery_code" autocomplete="off"></label>
  @if($errors->any())<span class="hint err">El código no es válido. Inténtalo de nuevo.</span>@endif
  <button class="btn lime lg" type="submit">Verificar</button>
</form>
<div class="auth-foot"><button class="lnk" type="button" data-2fa-switch style="align-self:flex-start;font-weight:600">He perdido el móvil: usar un código de recuperación</button></div>
@endsection
