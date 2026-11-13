<?php

namespace App\Http\Controllers;

use App\Services\Auditoria;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class CuentaController extends Controller
{
    public function show(Request $request)
    {
        $u = $request->user();

        return view('cuenta.show', [
            'titulo' => 'Mi cuenta', 'activo' => 'cuenta', 'u' => $u,
            'nRevisar' => $u->esDespacho() ? EscritorioController::nRevisar() : 0,
        ]);
    }

    public function nueva(Request $request)
    {
        if (! $request->user()->must_change_password) {
            return redirect('/');
        }

        return view('auth.nueva-contrasena');
    }

    public function guardarNueva(Request $request)
    {
        $u = $request->user();
        $request->validate(['password' => ['required', 'string', 'confirmed', 'max:100', Password::min(8)->letters()->numbers()]]);
        if (Hash::check($request->input('password'), $u->password)) {
            return back()->withErrors(['password' => 'La contraseña nueva debe ser distinta de la provisional.']);
        }
        $u->forceFill(['password' => $request->input('password'), 'must_change_password' => false])->save();
        Auditoria::registrar('password.cambio', 'Contraseña provisional sustituida', $u, $u->cliente_id);

        return redirect('/')->with('ok', 'Contraseña guardada. Ya puedes usar '.($u->esPortal() ? 'tu portal.' : 'AG Cloud.'));
    }
}
