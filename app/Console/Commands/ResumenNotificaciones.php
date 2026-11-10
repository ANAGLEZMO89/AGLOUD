<?php

namespace App\Console\Commands;

use App\Http\Controllers\NotificacionController;
use App\Models\Notificacion;
use App\Models\User;
use App\Notifications\ResumenNotificaciones as Correo;
use App\Support\Plazos;
use Illuminate\Console\Command;
use Throwable;

/**
 * Cada mañana: marca como caducadas las que pasaron 10 días sin abrir y envía
 * a cada persona del despacho el resumen de SUS notificaciones (responsable o,
 * si no hay, asesor del cliente; las huérfanas, a los administradores).
 */
class ResumenNotificaciones extends Command
{
    protected $signature = 'agcloud:resumen-notificaciones';

    protected $description = 'Aplica la caducidad de 10 días y envía el resumen diario de notificaciones por correo';

    public function handle(): int
    {
        $n = Notificacion::aplicarCaducidad();
        if ($n) {
            $this->info("Caducadas hoy: $n");
        }
        if (! NotificacionController::avisos()['email']) {
            return self::SUCCESS;
        }
        $hoy = Plazos::hoy();
        $abiertas = Notificacion::with('cliente')->where('archivada', false)
            ->where(fn ($q) => $q->where('estado', 'Pendiente')->orWhere(fn ($w) => $w->whereNotNull('vence')->whereBetween('vence', [$hoy->toDateString(), $hoy->addDays(7)->toDateString()])))
            ->orderBy('puesta')->get();
        if ($abiertas->isEmpty()) {
            return self::SUCCESS;
        }
        $destino = function (Notificacion $x): ?int {
            return $x->responsable_id ?? $x->cliente?->asesor_id;
        };
        $equipo = User::whereIn('role', User::ROLES_DESPACHO)->where('activo', true)->get();
        foreach ($equipo as $u) {
            $mias = $abiertas->filter(fn ($x) => $destino($x) === $u->id || ($destino($x) === null && $u->esAdmin()));
            $pend = $mias->filter->pendiente()->values();
            $venc = $mias->reject->pendiente()->sortBy('vence')->values();
            if ($pend->isEmpty() && $venc->isEmpty()) {
                continue;
            }
            try {
                $u->notify(new Correo($pend, $venc));
                $this->line('Resumen enviado a '.$u->email);
            } catch (Throwable $e) {
                report($e);
                $this->error('No se pudo enviar a '.$u->email.': '.$e->getMessage());
            }
        }

        return self::SUCCESS;
    }
}
