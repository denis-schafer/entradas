<?php

namespace App\Providers;

use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\ServiceProvider;

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
    }
}