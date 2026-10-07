<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Fila de tickets_configs.
 *
 * mp_access_token, mp_webhook_secret y qr_secret se siembran vacios para que
 * existan en la tabla desde el primer momento: asi el panel las puede listar
 * y editar. qr_secret igual se regenera solo en el primer uso, asi que
 * sembrarlo vacio no rompe nada.
 */
class TicketsConfigSeeder extends Seeder
{
    private const DEFAULTS = [
        ['business_name', 'Entradas', 'text'],
        ['portal_logo', '', 'image'],
        ['portal_primary_color', '#7C5CFF', 'text'],
        ['portal_secondary_color', '#FF3D71', 'text'],
        ['welcome_message', '', 'text'],
        ['success_message', '', 'text'],
        ['rejection_message', '', 'text'],
        ['terms_url', '', 'text'],
        ['privacy_url', '', 'text'],
        ['redirect_uri', '', 'text'],
        ['mp_access_token', '', 'text'],
        ['mp_webhook_secret', '', 'text'],
        ['qr_secret', '', 'text'],
    ];

    public function run(): void
    {
        // Solo si falta: reejecutar el seeder no puede pisar la configuracion
        // que alguien haya cambiado desde el panel.
        $existing = DB::table('tickets_configs')->pluck('name')->all();

        $missing = array_filter(
            self::DEFAULTS,
            fn ($row) => ! in_array($row[0], $existing, true)
        );

        foreach ($missing as [$name, $value, $type]) {
            DB::table('tickets_configs')->insert([
                'name' => $name,
                'value' => $value,
                'type' => $type,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if ($missing) {
            $this->command?->info('Configuracion inicial creada: '.count($missing).' filas.');
        }
    }
}