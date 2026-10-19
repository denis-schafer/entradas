<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Las entradas de una orden que no se pago en su dia quedan en 'expired'
 * (distinto de 'cancelled', que es una anulacion manual). El enum original no
 * tenia ese valor y MySQL en modo estricto rechazaba el UPDATE del vencimiento.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets_tickets', function (Blueprint $table) {
            $table->enum('status', ['valid', 'used', 'cancelled', 'expired'])
                ->default('valid')
                ->change();
        });
    }

    public function down(): void
    {
        DB::table('tickets_tickets')->where('status', 'expired')->update(['status' => 'cancelled']);

        Schema::table('tickets_tickets', function (Blueprint $table) {
            $table->enum('status', ['valid', 'used', 'cancelled'])
                ->default('valid')
                ->change();
        });
    }
};
