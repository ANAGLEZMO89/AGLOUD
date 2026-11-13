<?php

namespace App\Notifications;

use App\Support\Formato;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

/** Resumen de cada mañana: notificaciones sin abrir y plazos que vencen pronto. */
class ResumenNotificaciones extends Notification
{
    public function __construct(private Collection $pendientes, private Collection $vencen) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $m = (new MailMessage)
            ->subject('AG Cloud · '.$this->pendientes->count().' notificaciones sin abrir'.($this->vencen->count() ? ' · '.$this->vencen->count().' plazos vencen pronto' : ''))
            ->greeting('Buenos días, '.explode(' ', $notifiable->name)[0]);
        if ($this->pendientes->count()) {
            $m->line('**Sin abrir** (se dan por notificadas a los 10 días naturales):');
            foreach ($this->pendientes as $n) {
                $d = $n->diasCaducidad();
                $m->line('· '.$n->cliente?->nombre.' — '.$n->organismo.' · '.$n->tipo.' — caduca el '.Formato::fecha($n->caducidad()).($d <= 3 ? ' (en '.max(0, $d).' d)' : ''));
            }
        }
        if ($this->vencen->count()) {
            $m->line('**Plazos que vencen en los próximos 7 días:**');
            foreach ($this->vencen as $n) {
                $m->line('· '.$n->cliente?->nombre.' — '.$n->tipo.($n->accion ? ' ('.$n->accion.')' : '').' — vence el '.Formato::fecha($n->vence));
            }
        }

        return $m->action('Abrir Notificaciones', route('notificaciones.index'))
            ->line('Recuerda: AG Cloud no consulta las sedes por sí solo. Revisa DEHú, AEAT y TGSS y registra lo que llegue.')
            ->salutation('AG Cloud · GA AGOPYME');
    }
}
