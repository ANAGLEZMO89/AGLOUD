<?php

namespace App\Services;

use App\Models\Cliente;
use App\Models\User;
use App\Notifications\BienvenidaAcceso;
use App\Support\Validacion;
use Throwable;

/** Alta de usuarios con contraseña provisional y correo de bienvenida. */
class AccesosPortal
{
    /** Crea el usuario (portal o despacho) y envía el correo. Devuelve el mensaje para la pantalla. */
    public function invitar(?Cliente $cliente, string $email, string $nombre, string $rol, ?string $pass = null, ?string $cargo = null): string
    {
        $email = mb_strtolower(trim($email));
        if (User::where('email', $email)->exists()) {
            return 'Ojo: ya existe un usuario con el correo '.$email.'; no se ha creado otro acceso.';
        }
        $pass = $pass ?: Validacion::generaContrasena();
        $u = User::create([
            'name' => mb_substr($nombre, 0, 190), 'email' => $email, 'password' => $pass, 'role' => $rol,
            'cliente_id' => in_array($rol, User::ROLES_PORTAL, true) ? $cliente?->id : null,
            'cargo' => $cargo, 'activo' => true, 'must_change_password' => true,
        ]);
        Auditoria::registrar('acceso.alta', 'Acceso creado para '.$email.' ('.$u->rolTexto().')', $u, $cliente?->id);

        return $this->enviar($u, $pass);
    }

    /** Genera una contraseña provisional nueva y la reenvía */
    public function reenviar(User $u): string
    {
        $pass = Validacion::generaContrasena();
        $u->forceFill(['password' => $pass, 'must_change_password' => true])->save();
        Auditoria::registrar('acceso.reenvio', 'Contraseña provisional regenerada para '.$u->email, $u, $u->cliente_id);

        return $this->enviar($u, $pass);
    }

    private function enviar(User $u, string $pass): string
    {
        try {
            $u->notify(new BienvenidaAcceso($pass));

            return 'Correo de bienvenida enviado a '.$u->email.' con su usuario y contraseña provisional.';
        } catch (Throwable $e) {
            report($e);

            return 'No se pudo enviar el correo a '.$u->email.' (revisa la configuración SMTP). Contraseña provisional: '.$pass.' — comunícasela por un canal seguro.';
        }
    }
}
