@extends('layouts.auth', ['titulo' => 'Confirma tu contraseña'])
@section('content')
<h1>Confirma tu contraseña</h1>
<span style="font-size:13.5px;color:#3C3C3C">Por seguridad, vuelve a escribir tu contraseña para continuar.</span>
<form method="POST" action="{{ route('password.confirm') }}" style="display:flex;flex-direction:column;gap:16px">
  @csrf
  <label>Contraseña<input class="inp" type="password" name="password" required autofocus autocomplete="current-password"></label>
  @error('password')<span class="hint err">{{ $message }}</span>@enderror
  <button class="btn lime lg" type="submit">Continuar</button>
</form>
@endsection
