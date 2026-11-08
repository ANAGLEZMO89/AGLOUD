<?php

use Illuminate\Support\Facades\Schedule;

/*
| Tareas programadas. En Hostinger basta UNA tarea cron cada minuto:
|   php /home/USUARIO/agcloud/artisan schedule:run
| (ver docs/DESPLIEGUE-HOSTINGER.md)
*/

// Copia de seguridad diaria a las 03:15 (hora de Madrid)
Schedule::command('agcloud:backup')->dailyAt('03:15')->timezone('Europe/Madrid')->withoutOverlapping(120);

// Subidas abandonadas y sesiones caducadas
Schedule::command('agcloud:limpiar')->dailyAt('04:10')->timezone('Europe/Madrid');
Schedule::command('auth:clear-resets')->dailyAt('04:20')->timezone('Europe/Madrid');

// Correos en cola (si QUEUE_CONNECTION=database), sin procesos permanentes
Schedule::command('queue:work --stop-when-empty --max-time=50')->everyMinute()->withoutOverlapping();
