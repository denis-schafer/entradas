<?php

namespace App\Support;

use Database\Seeders\TicketsConfigSeeder;
use Database\Seeders\TicketsPaymentMethodSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

/**
 * Estado global del sistema de tickets y mantenimiento de la base.
 *
 * Modo Test
 * ---------
 * Permite cargar datos de prueba sin ensuciar los reales. Al activarlo se
 * guarda una "marca" por tabla operativa: el id maximo existente en ese
 * momento. Todo lo que se cree a partir de ahi tiene un id mayor a la marca y
 * es, por lo tanto, dato de prueba. Al desactivar el modo se borra exactamente
 * eso. No hace falta tocar cada INSERT del codigo: la marca actua como flag de
 * lote, que es robusto y no se olvida en ningun controlador.
 *
 * Blanqueo
 * --------
 * Deja la base como recien instalada: borra todos los datos de negocio, pone
 * los autoincrementos en 1, vuelve a sembrar la configuracion y los medios de
 * pago por defecto, y crea el unico usuario: el administrador.
 */
class AppState
{
    private const TABLE = 'tickets_app_state';

    private const KEY_TEST_MODE = 'test_mode';
    private const KEY_TEST_MARKS = 'test_marks';

    /**
     * Tablas operativas: todo lo que el operador "carga" desde el panel o el
     * portal. Se listan de hijos a padres para poder borrar en orden sin
     * depender de foreign keys.
     */
    private const OPERATIONAL_TABLES = [
        'tickets_scans',
        'tickets_tickets',
        'tickets_orders',
        'tickets_ads',
        'tickets_event_payment_method',
        'tickets_user_payment_method',
        'tickets_event_user',
        'tickets_event_ticket_types',
        'tickets_events',
        'users',
    ];

    public static function isTestMode(): bool
    {
        return self::get(self::KEY_TEST_MODE) === '1';
    }

    /**
     * Activa o desactiva el modo test.
     *
     * Al activar se congelan las marcas actuales (una sola vez: reactivar
     * sobre un modo ya activo no recaptura, para no "adoptar" datos de prueba
     * viejos). Al desactivar se purga todo lo creado por encima de la marca.
     */
    public static function setTestMode(bool $enabled): void
    {
        if ($enabled) {
            if (! self::isTestMode()) {
                self::put(self::KEY_TEST_MARKS, json_encode(self::captureMarks()));
            }

            self::put(self::KEY_TEST_MODE, '1');

            return;
        }

        self::purgeTestData();
        self::put(self::KEY_TEST_MODE, '0');
        self::forget(self::KEY_TEST_MARKS);
    }

    /**
     * Blanqueo total. Deja solo al administrador y la configuracion por
     * defecto, con los autoincrementos en 1.
     */
    public static function resetDatabase(): void
    {
        $tables = array_merge(self::OPERATIONAL_TABLES, [
            'tickets_configs',
            'tickets_payment_methods',
            'sessions',
        ]);

        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        try {
            foreach ($tables as $table) {
                if (Schema::hasTable($table)) {
                    DB::table($table)->truncate();
                }
            }

            foreach ($tables as $table) {
                if (Schema::hasTable($table)) {
                    DB::statement('ALTER TABLE `'.$table.'` AUTO_INCREMENT = 1');
                }
            }
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }

        self::forget(self::KEY_TEST_MODE);
        self::forget(self::KEY_TEST_MARKS);

        // Configuracion y medios de pago al estado por defecto. Los seeders
        // usan updateOrInsert, asi que sobre tablas vacias crean las filas.
        (new TicketsConfigSeeder())->run();
        (new TicketsPaymentMethodSeeder())->run();

        self::createAdmin();
    }

    /**
     * Borra lo creado durante el modo test: filas cuyo id supera la marca.
     *
     * Con foreign key checks apagado y las tablas en orden hijos->padres, el
     * borrado no depende del orden. Además se anulan las referencias de filas
     * reales hacia usuarios de prueba (scanner/buyer), que con checks apagados
     * no se limpiarian solas.
     */
    private static function purgeTestData(): void
    {
        $marks = json_decode((string) self::get(self::KEY_TEST_MARKS), true);

        if (! is_array($marks)) {
            return;
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        try {
            foreach (self::OPERATIONAL_TABLES as $table) {
                if (! Schema::hasTable($table)) {
                    continue;
                }

                $mark = (int) ($marks[$table] ?? 0);
                DB::table($table)->where('id', '>', $mark)->delete();
            }

            // Referencias de filas reales a usuarios de prueba que se van a
            // borrar: quedarian colgando porque las FK no actuan con checks off.
            if (Schema::hasTable('tickets_tickets')) {
                $userMark = (int) ($marks['users'] ?? 0);
                DB::table('tickets_tickets')->where('used_by_scanner_id', '>', $userMark)
                    ->update(['used_by_scanner_id' => null]);
            }

            if (Schema::hasTable('tickets_scans')) {
                $userMark = (int) ($marks['users'] ?? 0);
                DB::table('tickets_scans')->where('scanner_user_id', '>', $userMark)
                    ->update(['scanner_user_id' => null]);
            }

            if (Schema::hasTable('tickets_orders')) {
                $userMark = (int) ($marks['users'] ?? 0);
                DB::table('tickets_orders')->where('buyer_user_id', '>', $userMark)
                    ->update(['buyer_user_id' => null]);
            }
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }
    }

    /**
     * Id maximo actual por tabla operativa. 0 si la tabla esta vacia.
     *
     * @return array<string, int>
     */
    private static function captureMarks(): array
    {
        $marks = [];

        foreach (self::OPERATIONAL_TABLES as $table) {
            if (! Schema::hasTable($table)) {
                $marks[$table] = 0;

                continue;
            }

            $marks[$table] = (int) (DB::table($table)->max('id') ?? 0);
        }

        return $marks;
    }

    /**
     * Administrador unico que sobrevive al blanqueo.
     *
     * El DNI se carga sin puntos para que el login por DNI (que normaliza a
     * solo digitos) lo encuentre igual.
     */
    private static function createAdmin(): void
    {
        DB::table('users')->insert([
            'name' => 'Denis Schafer',
            'email' => env('MP_ADMIN_EMAIL', 'admin@entradas.test'),
            'dni' => '29923360',
            'password' => Hash::make('$0deJulio'),
            'is_admin' => true,
            'role' => 'admin',
            'enable' => true,
            'must_change_password' => false,
            'email_verified_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private static function get(string $key): ?string
    {
        if (! Schema::hasTable(self::TABLE)) {
            return null;
        }

        return DB::table(self::TABLE)->where('name', $key)->value('value');
    }

    private static function put(string $key, ?string $value): void
    {
        DB::table(self::TABLE)->updateOrInsert(
            ['name' => $key],
            ['value' => $value, 'updated_at' => now(), 'created_at' => now()]
        );
    }

    private static function forget(string $key): void
    {
        if (Schema::hasTable(self::TABLE)) {
            DB::table(self::TABLE)->where('name', $key)->delete();
        }
    }
}
