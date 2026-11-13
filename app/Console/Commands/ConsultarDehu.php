<?php

namespace App\Console\Commands;

use App\Models\Cliente;
use App\Services\Dehu\NotificacionesDehu;
use Illuminate\Console\Command;
use Throwable;

/**
 * Cada mañana: consulta en la DEHú (LEMA · localiza) lo pendiente de cada cliente con el
 * certificado de su ficha y lo registra como «Sin abrir». No comparece: abrir es siempre manual.
 */
class ConsultarDehu extends Command
{
    protected $signature = 'agcloud:dehu {--cliente= : Solo este cliente (id)}';

    protected $description = 'Lista en la DEHú las notificaciones pendientes de cada cliente (sin abrirlas)';

    public function handle(NotificacionesDehu $dehu): int
    {
        $q = Cliente::where('es_despacho', false)->where('estado', '!=', 'Baja')->orderBy('id');
        if ($this->option('cliente')) {
            $q->whereKey((int) $this->option('cliente'));
        }
        $ok = $err = $nuevas = 0;
        foreach ($q->get() as $c) {
            if (NotificacionesDehu::certificado($c)[1] !== null) {
                continue;
            }
            try {
                $nuevas += $dehu->sincronizar($c)['nuevas'];
                $ok++;
            } catch (Throwable $e) {
                $err++;
                $this->warn($c->id.' '.$c->nombre.': '.NotificacionesDehu::mensaje($e));
            }
        }
        $this->info("DEHú: $ok clientes consultados, $err con error, $nuevas notificaciones nuevas.");

        return self::SUCCESS;
    }
}
