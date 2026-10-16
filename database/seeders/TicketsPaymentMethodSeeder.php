<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Medios de pago disponibles. No pueden inventarse desde el panel: el proveedor
 * tiene que existir como clase, asi que aca se declaran los que la app entiende.
 *
 * Solo inserta si falta el code: correrlo de nuevo no pisa lo que ya se haya
 * configurado desde el panel (el `enabled` de un medio prendido a mano, su
 * nombre u orden). `enabled` inicial: MercadoPago activo, Multipago apagado
 * hasta que se carguen credenciales.
 */
class TicketsPaymentMethodSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            [
                'code' => 'mercadopago',
                'name' => 'MercadoPago',
                'enabled' => true,
                'config' => json_encode(['platform_access_token' => '']),
                'sort_order' => 0,
            ],
            [
                'code' => 'multipago',
                'name' => 'Multipago',
                'enabled' => false,
                'config' => json_encode([
                    'bersacode' => '',
                    'username' => '',
                    'password' => '',
                    'large' => 10,
                    'testing' => false,
                ]),
                'sort_order' => 1,
            ],
        ];

        // Insert-only: el updateOrInsert anterior ponia `enabled` al default en
        // cada corrida, asi que el deploy volvia a apagar Multipago aunque el
        // operador lo hubiera habilitado desde el panel.
        $existing = DB::table('tickets_payment_methods')->pluck('code')->all();

        $missing = array_filter(
            $defaults,
            fn ($row) => ! in_array($row['code'], $existing, true)
        );

        foreach ($missing as $row) {
            DB::table('tickets_payment_methods')->insert([
                'code' => $row['code'],
                'name' => $row['name'],
                'enabled' => $row['enabled'],
                'config' => $row['config'],
                'sort_order' => $row['sort_order'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if ($missing) {
            $this->command?->info('Medios de pago iniciales creados: '.count($missing).' filas.');
        }
    }
}