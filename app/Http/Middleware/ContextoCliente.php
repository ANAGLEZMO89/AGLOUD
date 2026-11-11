<?php

namespace App\Http\Middleware;

use App\Models\Cliente;
use Closure;
use Illuminate\Database\Eloquent\Model;
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
    /** Módulos que dependen de la empresa elegida */
    private const MODULOS = ['clientes', 'ventas', 'proveedores', 'acreedores', 'productos', 'cobros', 'calendario', 'tareas', 'contabilidad', 'fiscal', 'laboral',
        'facturacion', 'crm', 'horario', 'notificaciones', 'documentos', 'expedientes', 'parametros'];

    private function requiereEmpresa(Request $request): bool
    {
        $n = (string) $request->route()?->getName();
        $raiz = explode('.', $n)[0];

        return in_array($raiz, self::MODULOS, true) && ! str_ends_with($n, '.descargar') && ! str_ends_with($n, '.ver');
    }

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

        // Personal del despacho: toda la plataforma trabaja sobre la empresa elegida en el Escritorio
        // (el despacho = el despacho y todos sus clientes; un cliente = solo ese cliente).
        if (! $ctx && $u?->esDespacho() && $request->isMethod('GET') && $this->requiereEmpresa($request)) {
            return redirect()->route('escritorio', ['cambiar' => 1, 'volver' => '/'.ltrim($request->path(), '/')])
                ->with('aviso', 'Elige primero con qué empresa vas a trabajar: el despacho o uno de tus clientes.');
        }

        // Con un cliente elegido, la plataforma es solo de ese cliente: nada de otras empresas ni de la administración del despacho
        if ($ctx && ! $ctx->es_despacho && $u?->esDespacho()) {
            $raiz = explode('.', (string) $request->route()?->getName())[0];
            if ($raiz === 'admin') {
                return redirect()->route('escritorio', ['cambiar' => 1, 'tab' => 'desp'])
                    ->with('aviso', 'Estás trabajando con '.$ctx->nombre.'. Para la administración del despacho, elige el despacho.');
            }
            if (in_array($raiz, self::MODULOS, true) && ! $this->esDeLaEmpresa($request, $ctx)) {
                abort(404);
            }
        }

        return $next($request);
    }

    /** Todo lo que se abre por enlace (cliente, factura, expediente, documento…) tiene que ser de la empresa elegida */
    private function esDeLaEmpresa(Request $request, Cliente $ctx): bool
    {
        foreach ($request->route()?->parameters() ?? [] as $p) {
            if ($p instanceof Cliente) {
                if ($p->id !== $ctx->id) {
                    return false;
                }
            } elseif ($p instanceof Model && array_key_exists('cliente_id', $p->getAttributes()) && (int) $p->getAttribute('cliente_id') !== $ctx->id) {
                return false;
            }
        }

        return true;
    }
}
