<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Medios de pago disponibles. No pueden inventarse desde el panel: el proveedor
 * tiene que existir como clase, asi que aca se declaran los que la app entiende.
 *
 * Solo inserta si falta el code (updateOrInsert): correrlo de nuevo no pisa lo
 * que ya se haya configurado. `enabled` inicial: MercadoPago activo, Multipago
 * apagado hasta que se carguen credenciales.
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

        foreach ($defaults as $row) {
            DB::table('tickets_payment_methods')->updateOrInsert(
                ['code' => $row['code']],
                [
                    'name' => $row['name'],
                    'enabled' => $row['enabled'],
                    'sort_order' => $row['sort_order'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }
}