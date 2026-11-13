<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

class RestablecerContrasena extends ResetPassword
{
    public function toMail($notifiable): MailMessage
    {
        $url = url(route('password.reset', ['token' => $this->token, 'email' => $notifiable->getEmailForPasswordReset()], false));

        return (new MailMessage)
            ->subject('Restablecer tu contraseña · AG Cloud')
            ->greeting('Hola, '.$notifiable->name)
            ->line('Hemos recibido una solicitud para cambiar la contraseña de tu acceso a AG Cloud.')
            ->action('Crear contraseña nueva', $url)
            ->line('El enlace caduca en '.config('auth.passwords.users.expire').' minutos.')
            ->line('Si no lo has pedido tú, ignora este correo: tu contraseña no cambia.')
            ->salutation('Un saludo, GA AGOPYME');
    }
}
