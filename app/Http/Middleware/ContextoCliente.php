<?php

namespace App\Http\Middleware;

use App\Models\Cliente;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cliente/despacho seleccionado («¿Con quién vas a trabajar?»).
 * Se guarda en la sesión y se mantiene en todos los módulos.
 * Usuario del portal: siempre su propio cliente.
 */
class ContextoCliente
{
    public function handle(Request $request, Closure $next): Response
    {
        $u = $request->user();
        $ctx = null;
        if ($u?->esPortal()) {
            $ctx = Cliente::find($u->cliente_id);
        } elseif ($u?->esDespacho()) {
            $id = $request->session()->get('cliente_activo');
            $ctx = $id ? Cliente::find($id) : null;
            if (! $ctx) {
                $request->session()->forget('cliente_activo');
            }
        }
        $request->attributes->set('ctx', $ctx);
        View::share('ctx', $ctx);

        return $next($request);
    }
}
