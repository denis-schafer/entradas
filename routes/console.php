<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Consola
|--------------------------------------------------------------------------
*/

Artisan::command('tickets:expire-orders', function () {
    $expired = DB::table('tickets_orders')
        ->where('status', 'pending')
        ->where('created_at', '<', now()->subHours((int) env('MP_ORDER_TTL_HOURS', 24)))
        ->get(['id', 'event_id', 'public_token']);

    if ($expired->isEmpty()) {
        $this->info('No hay ordenes pendientes vencidas.');

        return self::SUCCESS;
    }

    foreach ($expired as $order) {
        $perType = DB::table('tickets_tickets')
            ->where('order_id', $order->id)
            ->where('status', 'valid')
            ->select('ticket_type_id', DB::raw('COUNT(*) as qty'))
            ->groupBy('ticket_type_id')
            ->get();

        DB::transaction(function () use ($order, $perType) {
            DB::table('tickets_tickets')
                ->where('order_id', $order->id)
                ->where('status', 'valid')
                ->update(['status' => 'expired', 'updated_at' => now()]);

            foreach ($perType as $row) {
                DB::table('tickets_event_ticket_types')
                    ->where('id', $row->ticket_type_id)
                    ->update([
                        'sold_count' => DB::raw('GREATEST(sold_count - '.(int) $row->qty.', 0)'),
                        'updated_at' => now(),
                    ]);
            }

            DB::table('tickets_orders')->where('id', $order->id)->update([
                'status' => 'expired',
                'updated_at' => now(),
            ]);
        });

        \App\Services\Realtime::orderStatus($order->id, 'expired');
        \App\Services\Realtime::orderStatusForBuyer($order->public_token, $order->id, 'expired');

        $rows = DB::table('tickets_event_ticket_types')
            ->whereIn('id', $perType->pluck('ticket_type_id'))
            ->get(['id', 'event_id', 'stock', 'sold_count', 'enable']);

        if ($rows->isNotEmpty()) {
            \App\Services\Realtime::stockForTypes(
                (int) $rows->first()->event_id,
                $rows->map(fn ($r) => (array) $r)->all()
            );
        }
    }

    $this->info("Ordenes vencadas: {$expired->count()}");
})->purpose('Libera el stock de las ordenes pendientes que nunca se pagaron');

/*
| Las ordenes pendientes se vencen solas: si un comprador abandona el checkout,
| sus entradas quedan reservadas para siempre y el stock no vuelve a estar
| disponible. Cada 15 minutos alcanza para que el impacto sea imperceptible.
*/
Schedule::command('tickets:expire-orders')->everyFifteenMinutes();