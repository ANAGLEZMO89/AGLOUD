<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Correo de bienvenida con usuario y contraseña provisional (cambio obligatorio al entrar). */
class BienvenidaAcceso extends Notification
{
    public function __construct(private string $pass) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $portal = $notifiable->esPortal();

        return (new MailMessage)
            ->subject($portal ? 'Tu portal de cliente de GA AGOPYME' : 'Tu acceso a AG Cloud · GA AGOPYME')
            ->greeting('Hola, '.$notifiable->name)
            ->line($portal
                ? 'Ya tienes acceso a tu portal de cliente de GA AGOPYME. Desde ahí podrás consultar y enviarnos tu documentación.'
                : 'Ya tienes acceso a AG Cloud, la plataforma de gestión de GA AGOPYME.')
            ->line('Usuario: '.$notifiable->email)
            ->line('Contraseña provisional: '.$this->pass)
            ->action('Entrar', url('/login'))
            ->line('Por seguridad, tendrás que cambiar la contraseña la primera vez que entres. Si no esperabas este correo, ignóralo o avísanos.')
            ->salutation('Un saludo, GA AGOPYME');
    }
}
