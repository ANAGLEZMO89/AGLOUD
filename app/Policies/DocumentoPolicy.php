<?php

namespace App\Policies;

use App\Models\Documento;
use App\Models\User;

/**
 * Segunda capa de defensa (la primera es el filtro global por cliente_id).
 * Despacho: todo. Portal: solo su cliente, y solo lo compartido o subido
 * desde el portal; el empleado, solo lo que subió él.
 */
class DocumentoPolicy
{
    public function view(User $u, Documento $d): bool
    {
        if ($u->esDespacho()) {
            return true;
        }
        if (! $u->esPortal() || (int) $d->cliente_id !== (int) $u->cliente_id || $d->ambito !== 'gestor') {
            return false;
        }
        if ($u->esEmpleado()) {
            return (int) $d->subido_por === (int) $u->id;
        }

        return $d->compartido || $d->origen === 'Portal del cliente';
    }

    /** Renombrar, mover, enviar a la papelera, restaurar */
    public function update(User $u, Documento $d): bool
    {
        if ($u->esDespacho()) {
            return true;
        }

        return $this->view($u, $d) && $d->origen === 'Portal del cliente'
            && (! $u->esEmpleado() || (int) $d->subido_por === (int) $u->id);
    }

    /** Compartir en el portal, marcar revisado, borrar definitivamente */
    public function gestionar(User $u, Documento $d): bool
    {
        return $u->esDespacho();
    }
}
