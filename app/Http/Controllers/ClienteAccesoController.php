<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Models\User;
use App\Services\AccesosPortal;
use App\Services\Auditoria;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Usuarios del portal de un cliente (cliente_admin / cliente_empleado). */
class ClienteAccesoController extends Controller
{
    public function store(Request $request, Cliente $cliente, AccesosPortal $accesos)
    {
        abort_if($cliente->es_despacho, 403);
        $v = $request->validate([
            'name' => ['required', 'string', 'max:190'],
            'email' => ['required', 'email:rfc', 'max:190'],
            'role' => ['required', Rule::in(User::ROLES_PORTAL)],
            'cargo' => ['nullable', 'string', 'max:80'],
        ]);
        $msg = $accesos->invitar($cliente, $v['email'], $v['name'], $v['role'], null, $v['cargo'] ?? null);

        return redirect()->route('clientes.show', [$cliente, 'tab' => 'accesos'])->with(str_starts_with($msg, 'Ojo') ? 'aviso' : 'ok', $msg);
    }

    public function reenviar(Cliente $cliente, User $usuario, AccesosPortal $accesos)
    {
        abort_unless((int) $usuario->cliente_id === (int) $cliente->id, 404);

        return redirect()->route('clientes.show', [$cliente, 'tab' => 'accesos'])->with('ok', $accesos->reenviar($usuario));
    }

    public function activo(Cliente $cliente, User $usuario)
    {
        abort_unless((int) $usuario->cliente_id === (int) $cliente->id, 404);
        $usuario->forceFill(['activo' => ! $usuario->activo])->save();
        if (! $usuario->activo) {
            // Cierra sus sesiones abiertas
            DB::table('sessions')->where('user_id', $usuario->id)->delete();
        }
        Auditoria::registrar('acceso.'.($usuario->activo ? 'activado' : 'desactivado'), 'Acceso de '.$usuario->email.($usuario->activo ? ' reactivado' : ' desactivado'), $usuario, $cliente->id);

        return redirect()->route('clientes.show', [$cliente, 'tab' => 'accesos'])->with('ok', $usuario->activo ? 'Acceso reactivado.' : 'Acceso desactivado. Ya no puede entrar al portal.');
    }
}
