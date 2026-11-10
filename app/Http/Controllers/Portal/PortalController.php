<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Documento;
use App\Models\Expediente;
use Illuminate\Http\Request;

class PortalController extends Controller
{
    public function inicio(Request $request)
    {
        $c = $request->attributes->get('ctx');
        abort_unless($c, 403);
        // El filtro global ya limita a lo que este usuario puede ver
        $recientes = Documento::gestor()->vivos()->orderByDesc('created_at')->limit(6)->get();

        // Trámites marcados como visibles (solo el administrador de la empresa)
        $tramites = $request->user()->esEmpleado() ? collect() : Expediente::where('estado', '!=', 'Cerrado')->orderByDesc('id')->limit(10)->get();

        return view('portal.inicio', ['titulo' => 'Inicio', 'activo' => 'inicio', 'c' => $c, 'recientes' => $recientes, 'tramites' => $tramites]);
    }

    public function preparacion(string $modulo)
    {
        $nombre = collect(config('agcloud.menu_portal'))->flatMap(fn ($g) => $g['items'])->firstWhere(0, $modulo)[1] ?? abort(404);

        return view('preparacion', ['titulo' => $nombre, 'nombre' => $nombre, 'activo' => $modulo, 'portal' => true]);
    }
}
