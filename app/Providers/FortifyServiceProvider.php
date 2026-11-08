<?php

namespace App\Providers;

use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Models\User;
use App\Services\Auditoria;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Actions\RedirectIfTwoFactorAuthenticatable;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Misma respuesta exista o no el correo: no revela qué cuentas hay
        $this->app->singleton(FailedPasswordResetLinkRequestResponse::class, fn () => new class implements FailedPasswordResetLinkRequestResponse
        {
            public function toResponse($request)
            {
                return back()->with('status', trans('passwords.sent'));
            }
        });
    }

    public function boot(): void
    {
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::redirectUserForTwoFactorAuthenticationUsing(RedirectIfTwoFactorAuthenticatable::class);

        // Vistas propias con el diseño del prototipo
        Fortify::loginView(fn () => view('auth.login'));
        Fortify::requestPasswordResetLinkView(fn () => view('auth.forgot-password'));
        Fortify::resetPasswordView(fn (Request $r) => view('auth.reset-password', ['request' => $r]));
        Fortify::twoFactorChallengeView(fn () => view('auth.two-factor-challenge'));
        Fortify::confirmPasswordView(fn () => view('auth.confirm-password'));

        // Login: usuario activo y, si es del portal, cliente no dado de baja
        Fortify::authenticateUsing(function (Request $request) {
            $user = User::where('email', Str::lower((string) $request->input('email')))->first();
            if (! $user || ! Hash::check((string) $request->input('password'), $user->password)) {
                return null;
            }
            if (! $user->activo) {
                Auditoria::registrar('login.bloqueado', 'Intento de acceso con usuario desactivado', $user, $user->cliente_id, $user);
                throw ValidationException::withMessages(['email' => 'Tu usuario está desactivado. Contacta con el despacho.']);
            }
            if ($user->esPortal()) {
                $cli = $user->cliente()->withoutGlobalScopes()->first();
                if (! $cli || $cli->trashed() || $cli->estado === 'Baja') {
                    Auditoria::registrar('login.bloqueado', 'Intento de acceso al portal de un cliente de baja', $user, $user->cliente_id, $user);
                    throw ValidationException::withMessages(['email' => 'El acceso al portal no está activo. Contacta con tu asesor.']);
                }
            }

            return $user;
        });

        RateLimiter::for('login', function (Request $request) {
            $key = Str::transliterate(Str::lower((string) $request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($key);
        });
        RateLimiter::for('two-factor', fn (Request $r) => Limit::perMinute(5)->by($r->session()->get('login.id')));

        // Auditoría de accesos
        Event::listen(Login::class, function (Login $e) {
            $e->user->forceFill(['ultimo_acceso_at' => now()])->saveQuietly();
            Auditoria::registrar('login', 'Inicio de sesión', $e->user, $e->user->cliente_id, $e->user);
        });
        Event::listen(Logout::class, function (Logout $e) {
            if ($e->user) {
                Auditoria::registrar('logout', 'Cierre de sesión', $e->user, $e->user->cliente_id, $e->user);
            }
        });
        Event::listen(Failed::class, function (Failed $e) {
            $email = (string) ($e->credentials['email'] ?? '');
            Auditoria::registrar('login.fallido', 'Contraseña o usuario incorrectos para '.mb_substr($email, 0, 120), $e->user, $e->user?->cliente_id, $e->user);
        });
        Event::listen(Lockout::class, function (Lockout $e) {
            Auditoria::registrar('login.bloqueo', 'Demasiados intentos de acceso para '.mb_substr((string) $e->request->input('email'), 0, 120));
        });
        Event::listen(TwoFactorAuthenticationConfirmed::class, fn ($e) => Auditoria::registrar('2fa.activada', 'Verificación en dos pasos activada', $e->user, $e->user->cliente_id));
        Event::listen(TwoFactorAuthenticationDisabled::class, fn ($e) => Auditoria::registrar('2fa.desactivada', 'Verificación en dos pasos desactivada', $e->user, $e->user->cliente_id));
    }
}
