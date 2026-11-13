@extends('layouts.auth', ['titulo' => 'Recuperar contraseña'])
@section('content')
<h1>Recupera tu contraseña</h1>
<span style="font-size:13.5px;color:#3C3C3C;line-height:1.45">Escribe el correo con el que accedes. Si existe una cuenta, te enviaremos un enlace para crear una contraseña nueva. Caduca en 60 minutos.</span>
@if(session('status'))<div class="msg">{{ session('status') }}</div>@endif
<form method="POST" action="{{ route('password.email') }}" style="display:flex;flex-direction:column;gap:16px">
  @csrf
  <label>Correo<input class="inp" type="email" name="email" value="{{ old('email') }}" placeholder="tu@correo.es" required autofocus></label>
  @error('email')<span class="hint err">{{ $message }}</span>@enderror
  <button class="btn lime lg" type="submit">Enviar enlace</button>
</form>
<div class="auth-foot"><a href="{{ route('login') }}">Volver al acceso</a></div>
@endsection
