<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modalidad elegida dentro de un medio de pago (por ahora Multipago: 'qr' o
 * 'button'). Permite reenviar el link y servir el QR sin recalcular la
 * variante: el null queda para los medios que no exponen variantes (MP) y para
 * las ordenes viejas, que se tratan como Multipago QR.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets_orders', function (Blueprint $table) {
            $table->string('payment_variant')->nullable()->after('payment_method');
        });
    }

    public function down(): void
    {
        Schema::table('tickets_orders', function (Blueprint $table) {
            $table->dropColumn('payment_variant');
        });
    }
};
