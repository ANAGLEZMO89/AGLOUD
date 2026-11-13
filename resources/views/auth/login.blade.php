@extends('layouts.auth', ['titulo' => 'Acceso'])
@section('content')
<h1>Accede a AG Cloud</h1>
@if(session('status'))<div class="msg">{{ session('status') }}</div>@endif
<form method="POST" action="{{ route('login') }}" style="display:flex;flex-direction:column;gap:16px">
  @csrf
  <label>Correo<input class="inp" type="email" name="email" value="{{ old('email') }}" placeholder="tu@correo.es" autocomplete="username" required autofocus></label>
  <label>Contraseña<input class="inp" type="password" name="password" placeholder="••••••••" autocomplete="current-password" required></label>
  <label class="chk" style="flex-direction:row;font-weight:500"><input type="checkbox" name="remember" value="1"> Recordarme en este equipo</label>
  @if($errors->any())<span class="hint err" style="font-size:13px">{{ $errors->first() }}</span>@endif
  <button class="btn lime lg" type="submit" style="font-size:15px">Entrar</button>
</form>
<div class="auth-foot">
  <a href="{{ route('password.request') }}">He olvidado mi contraseña</a>
  <small>Tus datos de acceso te llegaron por correo al darte de alta en GA AGOPYME.</small>
</div>
@endsection
