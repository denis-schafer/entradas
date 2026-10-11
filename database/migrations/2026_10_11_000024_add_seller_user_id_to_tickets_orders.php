<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vendedor que cerro la venta desde el POS.
 *
 * El portal no lo usa (queda NULL); sirve para que el historial del POS pueda
 * mostrar quien vendio y para atribuir la caja. nullOnDelete porque dar de baja
 * a un vendedor no debe borrar la venta: la orden se conserva.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets_orders', function (Blueprint $table) {
            $table->foreignId('seller_user_id')->nullable()->after('buyer_user_id')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tickets_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('seller_user_id');
        });
    }
};
