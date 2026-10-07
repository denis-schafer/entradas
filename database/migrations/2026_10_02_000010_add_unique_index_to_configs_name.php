<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * tickets_configs.name es la clave de la configuracion: todo el backend lo
 * busca con where('name', ...)->value(), o sea que asume que hay una sola fila
 * por clave. Con un indice normal MySQL permits duplicados y el valor que
 * devuelve la consulta depende del orden de lectura.
 *
 * Eso importa de verdad con qr_secret: si dos filas existieran, cada QR
 * podria quedar firmado con un secreto distinto y el escaner rechazaria la
 * mitad de los boletos validos.
 *
 * Antes de agregar el indice unico se dejan solo las filas de id mas bajo de
 * cada clave. Se conserva la mas antigua y no la mas nueva a proposito: la
 * mas antigua es la que venia devolviendo value() hasta ahora, asi que ningun
 * valor que se estaba usando cambia de forma silenciosa.
 */
return new class extends Migration
{
    public function up(): void
    {
        $duplicates = DB::table('tickets_configs')
            ->select('name', DB::raw('MIN(id) as keep_id'), DB::raw('COUNT(*) as total'))
            ->groupBy('name')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicates as $duplicate) {
            DB::table('tickets_configs')
                ->where('name', $duplicate->name)
                ->where('id', '>', $duplicate->keep_id)
                ->delete();
        }

        Schema::table('tickets_configs', function (Blueprint $table) {
            // El indice normal que creo la migracion original queda redundante
            // con el unico: los dos cumplen la misma busqueda por clave.
            $table->dropIndex(['name']);
            $table->unique('name');
        });
    }

    public function down(): void
    {
        Schema::table('tickets_configs', function (Blueprint $table) {
            $table->dropUnique(['name']);
            $table->index('name');
        });
    }
};