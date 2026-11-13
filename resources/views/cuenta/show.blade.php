@extends('layouts.app')
@section('content')
<div class="page">
  <div class="page-h"><div class="t"><span class="crumb">Mi cuenta</span><h1 class="ttl">Seguridad</h1></div></div>
  @if(session('status') === 'password-updated')<div class="msg">Contraseña cambiada.</div>@endif
  @if(session('status') === 'two-factor-authentication-confirmed')<div class="msg">Verificación en dos pasos activada. Guarda los códigos de recuperación.</div>@endif
  @if(session('status') === 'two-factor-authentication-disabled')<div class="msg warn">Verificación en dos pasos desactivada.</div>@endif

  <div class="g2">
    <div class="col">
      <div class="card">
        <span class="ct">Datos de acceso</span>
        <div>
          <div class="kv"><span>Nombre</span><span>{{ $u->name }}</span></div>
          <div class="kv"><span>Correo</span><span class="mono">{{ $u->email }}</span></div>
          <div class="kv"><span>Rol</span><span>{{ $u->rolTexto() }}</span></div>
          @if($u->cliente)<div class="kv"><span>Empresa</span><span>{{ $u->cliente->nombre }}</span></div>@endif
          <div class="kv"><span>Último acceso</span><span class="mono">{{ \App\Support\Formato::fechaHora($u->ultimo_acceso_at) ?: '—' }}</span></div>
        </div>
      </div>
      <div class="card">
        <span class="ct">Cambiar contraseña</span>
        <form method="POST" action="{{ route('user-password.update') }}" class="fgrid">
          @csrf @method('PUT')
          <label class="fld full"><span>Contraseña actual</span><input class="inp" type="password" name="current_password" required autocomplete="current-password"></label>
          <label class="fld"><span>Contraseña nueva</span><input class="inp" type="password" name="password" required autocomplete="new-password"></label>
          <label class="fld"><span>Repite la contraseña</span><input class="inp" type="password" name="password_confirmation" required autocomplete="new-password"></label>
          @if($errors->updatePassword->any())<span class="hint err fld full">{{ $errors->updatePassword->first() }}</span>@endif
          <div class="fld full" style="flex-direction:row;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px"><span class="hint muted">Al menos 8 caracteres, con letras y números.</span><button class="btn" type="submit">Guardar contraseña</button></div>
        </form>
      </div>
    </div>
    <div class="col">
      <div class="card {{ $u->tiene2fa() ? '' : '' }}">
        <div class="card-h"><span class="ct">Verificación en dos pasos</span>
          <span class="pill" style="{{ $u->tiene2fa() ? 'background:#E6F6A8;color:#2E3A00' : 'background:#FCEBCB;color:#6E4300' }}">{{ $u->tiene2fa() ? 'Activada' : 'Desactivada' }}</span></div>
        <span style="font-size:13.5px;color:#3C3C3C;line-height:1.5">Además de la contraseña, al entrar se pide un código de 6 cifras de una app como Google Authenticator, Microsoft Authenticator o 1Password. Muy recomendable para el equipo del despacho.</span>

        @if(! $u->two_factor_secret)
          <form method="POST" action="{{ route('two-factor.enable') }}">@csrf<button class="btn lime" type="submit">Activar verificación en dos pasos</button></form>
        @elseif(! $u->tiene2fa())
          <div class="boxsoft">
            <span style="font-size:13.5px;font-weight:600">1. Escanea este código con tu app de autenticación</span>
            <div class="qr">{!! $u->twoFactorQrCodeSvg() !!}</div>
            <span class="hint muted">¿No puedes escanearlo? Introduce esta clave a mano: <b class="mono">{{ decrypt($u->two_factor_secret) }}</b></span>
            <span style="font-size:13.5px;font-weight:600">2. Escribe el código que te muestra la app</span>
            <form method="POST" action="{{ route('two-factor.confirm') }}" class="row">
              @csrf
              <input class="inp mono" name="code" inputmode="numeric" maxlength="6" placeholder="000000" style="max-width:160px" autocomplete="one-time-code" required>
              <button class="btn" type="submit">Confirmar</button>
            </form>
            @if($errors->confirmTwoFactorAuthentication->any())<span class="hint err">El código no es correcto. Comprueba la hora del móvil y vuelve a intentarlo.</span>@endif
          </div>
          <form method="POST" action="{{ route('two-factor.disable') }}">@csrf @method('DELETE')<button class="lnk" type="submit">Cancelar</button></form>
        @else
          @if(session('status') === 'two-factor-authentication-confirmed' || session('status') === 'recovery-codes-generated')
            <span style="font-size:13.5px;font-weight:600">Códigos de recuperación</span>
            <span class="hint muted">Cada código sirve una sola vez si pierdes el móvil. Guárdalos en un lugar seguro: no se volverán a mostrar.</span>
            <div class="codes">@foreach($u->recoveryCodes() as $code)<span>{{ $code }}</span>@endforeach</div>
          @endif
          <div class="row">
            <form method="POST" action="{{ route('two-factor.regenerate-recovery-codes') }}">@csrf<button class="btn soft sm" type="submit">Generar códigos de recuperación nuevos</button></form>
            <form method="POST" action="{{ route('two-factor.disable') }}">@csrf @method('DELETE')<button class="btn danger sm" type="submit">Desactivar</button></form>
          </div>
        @endif
      </div>
    </div>
  </div>
</div>
@endsection
