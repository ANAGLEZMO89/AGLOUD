@extends('layouts.auth', ['titulo' => 'Cambia tu contraseña', 'sub' => auth()->user()->esPortal() ? 'Portal del cliente · GA AGOPYME' : 'GA AGOPYME'])
@section('content')
<h1>Elige tu contraseña</h1>
<span style="font-size:13.5px;color:#3C3C3C;line-height:1.45">Estás usando una contraseña provisional. Cámbiala para empezar a usar {{ auth()->user()->esPortal() ? 'tu portal' : 'AG Cloud' }}.</span>
<form method="POST" action="{{ route('cuenta.nueva-contrasena.guardar') }}" style="display:flex;flex-direction:column;gap:16px">
  @csrf
  <label>Contraseña nueva<input class="inp" type="password" name="password" required autofocus autocomplete="new-password"></label>
  <label>Repite la contraseña<input class="inp" type="password" name="password_confirmation" required autocomplete="new-password"></label>
  <span class="hint muted">Usa al menos 8 caracteres, con letras y números.</span>
  @if($errors->any())<span class="hint err">{{ $errors->first() }}</span>@endif
  <button class="btn lime lg" type="submit">Guardar contraseña</button>
</form>
<div class="auth-foot"><form method="POST" action="{{ route('logout') }}">@csrf<button class="lnk" type="submit">Cerrar sesión</button></form></div>
@endsection
