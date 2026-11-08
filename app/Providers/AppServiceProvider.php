<?php

namespace App\Providers;

use App\Models\Documento;
use App\Policies\DocumentoPolicy;
use Carbon\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

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
    }
}
