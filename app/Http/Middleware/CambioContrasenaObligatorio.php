<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Con contraseña provisional, obliga a cambiarla antes de usar la aplicación. */
class CambioContrasenaObligatorio
{
    public function handle(Request $request, Closure $next): Response
    {
        $u = $request->user();
        if ($u && $u->must_change_password && ! $request->routeIs('cuenta.nueva-contrasena', 'cuenta.nueva-contrasena.guardar', 'logout')) {
            return redirect()->route('cuenta.nueva-contrasena');
        }

        return $next($request);
    }
}
