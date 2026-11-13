<?php

use App\Http\Middleware\CabecerasSeguridad;
use App\Http\Middleware\CambioContrasenaObligatorio;
use App\Http\Middleware\ContextoCliente;
use App\Http\Middleware\Rol;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Solo si hay un proxy/CDN delante (p. ej. CDN de Hostinger): TRUSTED_PROXIES=*
        $proxies = env('TRUSTED_PROXIES');
        if ($proxies) {
            $middleware->trustProxies(at: $proxies === '*' ? '*' : explode(',', $proxies));
        }
        $middleware->prepend(CabecerasSeguridad::class);
        $middleware->web(append: [CambioContrasenaObligatorio::class, ContextoCliente::class]);
        $middleware->alias(['rol' => Rol::class]);
        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo('/');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
