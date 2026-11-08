<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Uso: ->middleware('rol:despacho') | 'rol:admin' | 'rol:portal'
 * Se comprueba en el servidor en cada petición.
 */
class Rol
{
    public function handle(Request $request, Closure $next, string $grupo): Response
    {
        $u = $request->user();
        $ok = $u && $u->activo && match ($grupo) {
            'despacho' => $u->esDespacho(),
            'admin' => $u->esAdmin(),
            'portal' => $u->esPortal() && $u->cliente_id,
            default => false,
        };
        abort_unless($ok, 403);

        return $next($request);
    }
}
