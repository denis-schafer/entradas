<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El logo del portal pasa a ser parte de la identidad del sistema: la pantalla
 * de carga, el panel y las vistas publicas lo usan. Las instalaciones que ya
 * existian tenian la fila vacia, asi que se completa con el logo del proyecto
 * sin pisar un logo subido por el administrador.
 */
return new class extends Migration
{
    private const DEFAULT_LOGO = '/img/PuntoEntrada-logo.svg';

    public function up(): void
    {
        if (! Schema::hasTable('tickets_configs')) {
            return;
        }

        $row = DB::table('tickets_configs')->where('name', 'portal_logo')->first();

        if (! $row) {
            DB::table('tickets_configs')->insert([
                'name' => 'portal_logo',
                'value' => self::DEFAULT_LOGO,
                'type' => 'image',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return;
        }

        if (trim((string) $row->value) === '') {
            DB::table('tickets_configs')
                ->where('id', $row->id)
                ->update(['value' => self::DEFAULT_LOGO, 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('tickets_configs')) {
            return;
        }

        DB::table('tickets_configs')
            ->where('name', 'portal_logo')
            ->where('value', self::DEFAULT_LOGO)
            ->update(['value' => '', 'updated_at' => now()]);
    }
};
