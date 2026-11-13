<?php

namespace App\Models\Concerns;

use App\Models\Cliente;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

/**
 * Aislamiento multi-tenant.
 *
 * Todo modelo con este trait lleva `cliente_id`. Si el usuario conectado es
 * del portal (cliente_admin / cliente_empleado), TODAS las consultas quedan
 * filtradas a su cliente en el backend, aunque un controlador olvide filtrar.
 * El personal del despacho (admin_gestoria / asesor) ve todos los clientes.
 */
trait PerteneceACliente
{
    public static function bootPerteneceACliente(): void
    {
        static::addGlobalScope('cliente', function (Builder $q) {
            $u = Auth::user();
            if ($u && $u->esPortal()) {
                $q->where($q->getModel()->getTable().'.cliente_id', $u->cliente_id ?? 0);
            }
        });

        // Un usuario del portal nunca puede crear filas de otro cliente
        static::creating(function ($m) {
            $u = Auth::user();
            if ($u && $u->esPortal()) {
                $m->cliente_id = $u->cliente_id;
            }
        });
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }
}
