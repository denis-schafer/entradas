<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Nombre del vendedor congelado al momento de la venta (snapshot).
 *
 * seller_user_id apunta a users y es nullOnDelete: al dar de baja a un vendedor
 * la orden se conserva pero pierde el id. Con esta columna el listado sigue
 * mostrando quien vendio aunque el usuario ya no exista.
 *
 * Las ventas del portal (sin operador) quedan como 'Online', que es como se
 * identifica todo lo que entra por el sistema y no por una caja.
 */
return new class extends Migration
{
    private const ONLINE = 'Online';

    public function up(): void
    {
        Schema::table('tickets_orders', function (Blueprint $table) {
            $table->string('seller_name')->nullable()->after('seller_user_id');
        });

        // Las ordenes viejas sin vendedor son del portal.
        DB::table('tickets_orders')->update(['seller_name' => self::ONLINE]);

        // Las que si tienen vendedor recuperan su nombre actual.
        DB::table('tickets_orders as o')
            ->join('users as u', 'u.id', '=', 'o.seller_user_id')
            ->update(['o.seller_name' => DB::raw('u.name')]);
    }

    public function down(): void
    {
        Schema::table('tickets_orders', function (Blueprint $table) {
            $table->dropColumn('seller_name');
        });
    }
};
