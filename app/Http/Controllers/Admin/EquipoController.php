<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\EscritorioController;
use App\Models\User;
use App\Services\AccesosPortal;
use App\Services\Auditoria;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Equipo del despacho (solo admin_gestoria). */
class EquipoController extends Controller
{
    public function index()
    {
        return view('admin.equipo', [
            'titulo' => 'Equipo y accesos', 'activo' => 'equipo', 'nRevisar' => EscritorioController::nRevisar(),
            'equipo' => User::whereIn('role', User::ROLES_DESPACHO)->orderByDesc('activo')->orderBy('name')->get(),
            'nPortal' => User::whereIn('role', User::ROLES_PORTAL)->where('activo', true)->count(),
        ]);
    }

    public function store(Request $request, AccesosPortal $accesos)
    {
        $v = $request->validate([
            'name' => ['required', 'string', 'max:190'],
            'email' => ['required', 'email:rfc', 'max:190'],
            'role' => ['required', Rule::in(User::ROLES_DESPACHO)],
            'cargo' => ['nullable', 'string', 'max:80'],
        ]);
        $msg = $accesos->invitar(null, $v['email'], $v['name'], $v['role'], null, $v['cargo'] ?? null);

        return redirect()->route('admin.equipo')->with(str_starts_with($msg, 'Ojo') ? 'aviso' : 'ok', $msg);
    }

    public function activo(Request $request, User $usuario)
    {
        abort_unless($usuario->esDespacho(), 404);
        if ($usuario->id === $request->user()->id) {
            return back()->with('aviso', 'No puedes desactivar tu propio usuario.');
        }
        if ($usuario->esAdmin() && $usuario->activo && User::where('role', User::ADMIN)->where('activo', true)->count() <= 1) {
            return back()->with('aviso', 'Debe quedar al menos un administrador activo.');
        }
        $usuario->forceFill(['activo' => ! $usuario->activo])->save();
        if (! $usuario->activo) {
            DB::table('sessions')->where('user_id', $usuario->id)->delete();
        }
        Auditoria::registrar('equipo.'.($usuario->activo ? 'activado' : 'desactivado'), 'Usuario '.$usuario->email.($usuario->activo ? ' reactivado' : ' desactivado'), $usuario);

        return back()->with('ok', $usuario->activo ? 'Usuario reactivado.' : 'Usuario desactivado. Sus sesiones se han cerrado.');
    }

    public function rol(Request $request, User $usuario)
    {
        abort_unless($usuario->esDespacho(), 404);
        $v = $request->validate(['role' => ['required', Rule::in(User::ROLES_DESPACHO)]]);
        if ($usuario->esAdmin() && $v['role'] !== User::ADMIN && User::where('role', User::ADMIN)->where('activo', true)->count() <= 1) {
            return back()->with('aviso', 'Debe quedar al menos un administrador activo.');
        }
        $usuario->forceFill(['role' => $v['role']])->save();
        Auditoria::registrar('equipo.rol', 'Rol de '.$usuario->email.' cambiado a '.$usuario->rolTexto(), $usuario);

        return back()->with('ok', 'Rol actualizado.');
    }

    public function reenviar(User $usuario, AccesosPortal $accesos)
    {
        abort_unless($usuario->esDespacho(), 404);

        return back()->with('ok', $accesos->reenviar($usuario));
    }
}
