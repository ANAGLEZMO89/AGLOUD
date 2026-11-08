<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\EscritorioController;
use App\Models\AuditLog;
use App\Services\Auditoria;
use App\Services\CopiaSeguridad;
use App\Services\GoogleDrive;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Throwable;

/** Estado de las copias de seguridad y conexión con Google Drive (solo admin). */
class CopiasController extends Controller
{
    public function index(CopiaSeguridad $copia, GoogleDrive $drive)
    {
        return view('admin.copias', [
            'titulo' => 'Copias de seguridad', 'activo' => 'copias', 'nRevisar' => EscritorioController::nRevisar(),
            'locales' => $copia->listar(), 'drive' => $drive,
            'historial' => AuditLog::where('accion', 'like', 'backup.%')->latest('id')->limit(15)->get(),
            'passOk' => strlen((string) config('agcloud.backup.password')) >= 12,
        ]);
    }

    public function ahora()
    {
        @set_time_limit(600);
        $code = Artisan::call('agcloud:backup');

        return back()->with($code === 0 ? 'ok' : 'aviso', trim(Artisan::output()) ?: ($code === 0 ? 'Copia creada.' : 'La copia ha fallado.'));
    }

    public function descargar(string $nombre, CopiaSeguridad $copia)
    {
        $ruta = $copia->dir().'/'.basename($nombre);
        abort_unless(is_file($ruta), 404);
        Auditoria::registrar('backup.descarga', 'Descarga de la copia '.$nombre);

        return response()->download($ruta, $nombre, ['Content-Type' => 'application/zip']);
    }

    public function conectar(Request $request, GoogleDrive $drive)
    {
        abort_unless($drive->configurado(), 400, 'Faltan GOOGLE_DRIVE_CLIENT_ID y GOOGLE_DRIVE_CLIENT_SECRET en el .env.');
        $state = Str::random(40);
        $request->session()->put('drive_state', $state);

        return redirect()->away($drive->urlAutorizacion($state));
    }

    public function callback(Request $request, GoogleDrive $drive)
    {
        $state = $request->session()->pull('drive_state');
        if (! $state || ! hash_equals($state, (string) $request->query('state')) || ! $request->query('code')) {
            return redirect()->route('admin.copias')->with('aviso', 'No se completó la conexión con Google Drive.');
        }
        try {
            $drive->canjearCodigo((string) $request->query('code'));
            Auditoria::registrar('backup.drive', 'Google Drive conectado para las copias de seguridad');

            return redirect()->route('admin.copias')->with('ok', 'Google Drive conectado. La próxima copia se subirá allí.');
        } catch (Throwable $e) {
            return redirect()->route('admin.copias')->with('aviso', $e->getMessage());
        }
    }

    public function desconectar(GoogleDrive $drive)
    {
        $drive->desconectar();
        Auditoria::registrar('backup.drive', 'Google Drive desconectado');

        return back()->with('aviso', 'Google Drive desconectado. Las copias solo quedarán en el servidor.');
    }
}
