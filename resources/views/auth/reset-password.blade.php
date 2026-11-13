@extends('layouts.auth', ['titulo' => 'Nueva contraseña'])
@section('content')
<h1>Crea tu contraseña</h1>
<form method="POST" action="{{ route('password.update') }}" style="display:flex;flex-direction:column;gap:16px">
  @csrf
  <input type="hidden" name="token" value="{{ $request->route('token') }}">
  <label>Correo<input class="inp" type="email" name="email" value="{{ old('email', $request->email) }}" required autocomplete="username"></label>
  <label>Contraseña nueva<input class="inp" type="password" name="password" required autocomplete="new-password"></label>
  <label>Repite la contraseña<input class="inp" type="password" name="password_confirmation" required autocomplete="new-password"></label>
  <span class="hint muted">Usa al menos 8 caracteres, con letras y números.</span>
  @if($errors->any())<span class="hint err">{{ $errors->first() }}</span>@endif
  <button class="btn lime lg" type="submit">Guardar contraseña</button>
</form>
@endsection
