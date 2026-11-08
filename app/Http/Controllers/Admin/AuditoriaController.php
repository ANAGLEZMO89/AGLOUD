<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\EscritorioController;
use App\Models\AuditLog;
use App\Services\Auditoria;
use App\Support\Formato;
use Illuminate\Http\Request;

/** Registro de accesos (solo admin_gestoria). */
class AuditoriaController extends Controller
{
    private function consulta(Request $r)
    {
        $q = trim((string) $r->query('q', ''));
        $tipo = (string) $r->query('tipo', '');

        return AuditLog::with(['user', 'cliente'])
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('descripcion', 'like', "%$q%")->orWhere('user_email', 'like', "%$q%")->orWhere('ip', 'like', "%$q%")))
            ->when($tipo !== '', fn ($w) => $w->where('accion', 'like', $tipo.'%'))
            ->when($r->query('desde'), fn ($w, $d) => $w->where('created_at', '>=', $d.' 00:00:00'))
            ->when($r->query('hasta'), fn ($w, $d) => $w->where('created_at', '<=', $d.' 23:59:59'))
            ->orderByDesc('id');
    }

    public function index(Request $request)
    {
        return view('admin.auditoria', [
            'titulo' => 'Registro de accesos', 'activo' => 'auditoria', 'nRevisar' => EscritorioController::nRevisar(),
            'logs' => $this->consulta($request)->paginate(50)->withQueryString(),
            'tipos' => ['' => 'Todas las acciones', 'login' => 'Accesos', 'documento' => 'Documentos', 'cliente' => 'Clientes', 'acceso' => 'Usuarios del portal', 'equipo' => 'Equipo', 'password' => 'Contraseñas', '2fa' => 'Verificación en dos pasos', 'backup' => 'Copias de seguridad'],
        ]);
    }

    public function exportar(Request $request)
    {
        Auditoria::registrar('auditoria.exportar', 'Exporta el registro de accesos');
        $rows = $this->consulta($request)->limit(50000)->get();

        return response()->streamDownload(function () use ($rows) {
            $o = fopen('php://output', 'w');
            fwrite($o, "\xEF\xBB\xBF");
            fputcsv($o, ['Fecha y hora', 'Usuario', 'Cliente', 'Acción', 'Descripción', 'IP', 'Navegador'], ';');
            foreach ($rows as $l) {
                fputcsv($o, [Formato::fechaHora($l->created_at), $l->user_email, $l->cliente?->nombre, $l->accion, $l->descripcion, $l->ip, $l->user_agent], ';');
            }
            fclose($o);
        }, 'registro-accesos-'.now()->format('Ymd').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
