<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Numeracion configurable por evento.
 *
 * El numero correlativo de entrada (tickets_tickets.number) se guarda como
 * entero, pero cada evento define desde que numero arranca y con cuantos digitos
 * se muestra con ceros a la izquierda. Se setean al crear el evento; despues no
 * se tocan para no descuadrar los numeros ya emitidos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets_events', function (Blueprint $table) {
            $table->unsignedInteger('ticket_number_start')->default(1)->after('capacity');
            $table->unsignedSmallInteger('ticket_number_pad')->default(1)->after('ticket_number_start');
        });
    }

    public function down(): void
    {
        Schema::table('tickets_events', function (Blueprint $table) {
            $table->dropColumn(['ticket_number_start', 'ticket_number_pad']);
        });
    }
};
