<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega el rol del operador.
 *
 * Antes de esta migracion el panel era de dos clases: is_admin entra, is_admin
 * no entra. Faltaba el caso real de la puerta del evento: una persona que
 * escanea QR y entrega pulseras, y que no deberia poder tocar eventos,
 * estadisticas, configuracion ni los datos de los compradores.
 *
 * Se agrega role en vez de reemplazar is_admin a proposito:
 *
 *   - is_admin queda siendo "entra al panel", que es lo que leen el middleware
 *     de sesion, el listado de compradores y varios testes. Tocar esa columna
 *     habriaObligado a reescribir todo eso sin necesidad.
 *   - role resuelve el detalle fino: admin ve todo, cajero solo lo de la puerta.
 *
 * NULL significa admin. Es el estado de todos los operadores que ya existen en
 * produccion, asi que un operador viejo no pierde nada y no hay que backfillear
 * fila por fila. Los compradores tambien quedan en NULL, pero nunca llegan a
 * este middleware porque no son is_admin.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 20)->nullable()->after('is_admin')->index();
        });

        // Solo los que pueden escribir. Los compradores quedan en NULL porque
        // para ellos la columna no significa nada.
        DB::table('users')->where('is_admin', true)->update(['role' => 'admin']);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['role']);
            $table->dropColumn('role');
        });
    }
};