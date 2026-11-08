<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Throwable;

/** Registro de accesos y acciones (RGPD · art. 32). */
class Auditoria
{
    public static function registrar(string $accion, string $descripcion, ?Model $objeto = null, ?int $clienteId = null, ?User $usuario = null): void
    {
        try {
            $u = $usuario ?? Auth::user();
            $req = request();
            if ($clienteId === null && $objeto && isset($objeto->cliente_id)) {
                $clienteId = $objeto->cliente_id;
            }
            AuditLog::create([
                'user_id' => $u?->id,
                'user_email' => $u?->email,
                'cliente_id' => $clienteId,
                'accion' => $accion,
                'descripcion' => mb_substr($descripcion, 0, 500),
                'objeto_tipo' => $objeto ? class_basename($objeto) : null,
                'objeto_id' => $objeto?->getKey(),
                'ip' => $req?->ip(),
                'user_agent' => mb_substr((string) $req?->userAgent(), 0, 300),
            ]);
        } catch (Throwable $e) {
            // La auditoría nunca debe tumbar la petición, pero sí dejar rastro
            report($e);
        }
    }
}
