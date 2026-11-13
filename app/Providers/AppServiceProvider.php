<?php

namespace App\Providers;

use App\Http\Controllers\NotificacionController;
use App\Models\Documento;
use App\Models\Notificacion;
use App\Policies\DocumentoPolicy;
use App\Support\Plazos;
use Carbon\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // HTTPS obligatorio en producción (enlaces, formularios y redirecciones)
        if ($this->app->isProduction()) {
            URL::forceScheme('https');
        }
        Carbon::setLocale('es');
        Gate::policy(Documento::class, DocumentoPolicy::class);

        // Insignia del menú y avisos del navegador: notificaciones sin abrir
        View::composer('layouts.app', function ($view) {
            $u = auth()->user();
            if (! $u || ! $u->esDespacho()) {
                return;
            }
            try {
                $ctx = request()->attributes->get('ctx');
                $base = Notificacion::where('estado', 'Pendiente')->where('archivada', false)->when($ctx && ! $ctx->es_despacho, fn ($q) => $q->where('cliente_id', $ctx->id));
                $dias = NotificacionController::avisos()['dias'];
                $view->with('nNotif', (clone $base)->count());
                $view->with('nCaducan', (clone $base)->whereDate('puesta', '<=', Plazos::hoy()->subDays(Plazos::CADUCIDAD_DIAS - $dias)->toDateString())->count());
            } catch (Throwable) {
                // Antes de migrar la fase 2 la tabla aún no existe
            }
        });
    }
}
