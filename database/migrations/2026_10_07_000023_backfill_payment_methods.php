<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Migra las credenciales existentes al nuevo modelo de medios de pago y crea
 * la config de la URL base de webhooks.
 *
 * - El access_token de plataforma (tickets_configs.mp_access_token) pasa a ser
 *   el token por defecto del medio mercadopago.
 * - El access_token por evento (tickets_events.mp_access_token) pasa al pivote
 *   tickets_event_payment_method, habilitando MercadoPago para ese evento.
 * - Se agrega tickets_configs.payment_webhook_base, la base publica que se le
 *   da a los proveedores para notificar (Multipago), seteable por si cambia el
 *   dominio.
 *
 * La columna vieja tickets_events.mp_access_token NO se borra: se deja por
 * compatibilidad hasta confirmar que nada la lee.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('tickets_configs')->updateOrInsert(
            ['name' => 'payment_webhook_base'],
            [
                'value' => 'https://entradas.erden.com.ar',
                'type' => 'text',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        $mercadopago = DB::table('tickets_payment_methods')->where('code', 'mercadopago')->first();

        if (! $mercadopago) {
            return;
        }

        $platformToken = trim((string) DB::table('tickets_configs')
            ->where('name', 'mp_access_token')
            ->value('value'));

        if ($platformToken !== '') {
            $config = json_decode($mercadopago->config ?? '[]', true) ?: [];
            $config['platform_access_token'] = $platformToken;

            DB::table('tickets_payment_methods')->where('id', $mercadopago->id)->update([
                'config' => json_encode($config),
                'updated_at' => now(),
            ]);
        }

        $events = DB::table('tickets_events')
            ->whereNotNull('mp_access_token')
            ->where('mp_access_token', '!=', '')
            ->get(['id', 'mp_access_token']);

        foreach ($events as $event) {
            DB::table('tickets_event_payment_method')->updateOrInsert(
                ['event_id' => $event->id, 'payment_method_id' => $mercadopago->id],
                [
                    'enabled' => true,
                    'config' => json_encode(['access_token' => $event->mp_access_token]),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }

    public function down(): void
    {
        DB::table('tickets_configs')->where('name', 'payment_webhook_base')->delete();
    }
};
