<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Auditoria;
use App\Services\CopiaSeguridad;
use App\Services\GoogleDrive;
use App\Support\Formato;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

/** Copia diaria: base de datos + documentos cifrados → ZIP AES-256 → servidor y Google Drive. */
class Copia extends Command
{
    protected $signature = 'agcloud:backup {--sin-drive : No subir a Google Drive}';

    protected $description = 'Crea la copia de seguridad cifrada y la sube a Google Drive';

    public function handle(CopiaSeguridad $copia, GoogleDrive $drive): int
    {
        try {
            $r = $copia->crear();
            $msg = 'Copia creada: '.basename($r['ruta']).' ('.Formato::num($r['tamano'] / 1048576, 1).' MB, '.$r['tablas'].' tablas, '.$r['documentos'].' documentos)';
            $this->info($msg);

            if (! $this->option('sin-drive') && $drive->conectado()) {
                $drive->subir($r['ruta']);
                $borradas = $drive->limpiar((int) config('agcloud.backup.dias_drive'));
                $msg .= ' · subida a Google Drive'.($borradas ? ' ('.$borradas.' copias antiguas borradas allí)' : '');
                $this->info('Subida a Google Drive correcta.');
            } elseif (! $this->option('sin-drive')) {
                $msg .= ' · Google Drive NO conectado: la copia solo está en el servidor';
                $this->warn('Google Drive no está conectado: la copia solo está en el servidor.');
            }
            $n = $copia->limpiar((int) config('agcloud.backup.dias_local'));
            Auditoria::registrar('backup.ok', $msg);
            if ($n) {
                $this->line($n.' copias locales antiguas eliminadas.');
            }

            return self::SUCCESS;
        } catch (Throwable $e) {
            report($e);
            Auditoria::registrar('backup.error', 'Fallo en la copia de seguridad: '.$e->getMessage());
            $this->error('La copia ha fallado: '.$e->getMessage());
            $this->avisar($e->getMessage());

            return self::FAILURE;
        }
    }

    private function avisar(string $error): void
    {
        try {
            $to = User::where('role', User::ADMIN)->where('activo', true)->pluck('email')->all();
            if ($to) {
                Mail::raw("La copia de seguridad diaria de AG Cloud ha fallado.\n\nMotivo: $error\n\nRevísalo en AG Cloud › Copias de seguridad.", function ($m) use ($to) {
                    $m->to($to)->subject('⚠️ AG Cloud: la copia de seguridad ha fallado');
                });
            }
        } catch (Throwable $e) {
            report($e);
        }
    }
}
