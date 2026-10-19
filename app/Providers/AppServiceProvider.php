<?php

namespace App\Providers;

use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
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
        // /broadcasting/auth. Va con middleware 'web' a proposito: el canal
        // privado tickets.admin se autoriza contra la sesion, asi que el
        // token queEcho manda tiene que resolver a un admin autenticado.
        Broadcast::routes(['middleware' => ['web']]);

        // La pantalla de carga y los shells comparten la identidad del portal.
        // Se resuelve en el HTML inicial para que el logo configurado aparezca
        // desde el primer byte y no haya un flash con el logo por defecto.
        View::composer('app', function ($view) {
            $branding = [
                'portalLogo' => '/img/PuntoEntrada-logo.svg',
                'businessName' => 'Entradas',
            ];

            try {
                $config = DB::table('tickets_configs')
                    ->whereIn('name', ['portal_logo', 'business_name'])
                    ->pluck('value', 'name');

                $branding['portalLogo'] = trim((string) ($config['portal_logo'] ?? ''))
                    ?: $branding['portalLogo'];

                $branding['businessName'] = trim((string) ($config['business_name'] ?? ''))
                    ?: $branding['businessName'];
            } catch (Throwable) {
                // Sin base (o sin la tabla todavia) queda el branding por
                // defecto: la pantalla de carga no puede depender de la DB.
            }

            $view->with($branding);
        });
    }
}