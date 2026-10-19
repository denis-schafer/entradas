<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Consola
|--------------------------------------------------------------------------
*/

Artisan::command('tickets:expire-orders', function () {
    $count = app(\App\Services\OrderExpiry::class)->expireAllPastDue();

    if ($count === 0) {
        $this->info('No hay ordenes pendientes vencidas.');

        return self::SUCCESS;
    }

    $this->info("Ordenes vencidas: {$count}");

    return self::SUCCESS;
})->purpose('Vence las ordenes pendientes que no se pagaron en su dia o cuya venta termino');

/*
| Las ordenes pendientes se vencen solas: si un comprador abandona el checkout,
| sus entradas quedan reservadas hasta el final del dia. A las 00:00 la primera
| corrida toma todas las del dia anterior. El vencimiento tambien se resuelve
| perezosamente cuando el comprador consulta el estado o intenta pagar, asi que
| no depende de que el cron llegue justo a horario.
*/
Schedule::command('tickets:expire-orders')->everyFifteenMinutes();
