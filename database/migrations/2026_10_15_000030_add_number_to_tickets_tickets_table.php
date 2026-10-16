<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Numero correlativo de entrada, por evento.
 *
 * Hasta ahora una entrada solo tenia uuid: no habia forma de decir "la entrada
 * 137 del evento". El sorteo necesita un numero visible para armar el rango
 * "desde/hasta" y para anunciar al ganador. Se asigna al emitir la entrada
 * (ver TicketOrderService) y arranca en 1 por cada evento.
 *
 * Las entradas que ya existen se numeran por orden de compra (id) con
 * ROW_NUMBER() de MySQL 8. El indice unique(event_id, number) impide repetir
 * numero dentro de un evento; en MySQL varios NULL conviven sin problema, asi
 * que las filas que quedaran sin numero (no hay) no rompen el indice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets_tickets', function (Blueprint $table) {
            $table->unsignedInteger('number')->nullable()->after('uuid');
            $table->unique(['event_id', 'number'], 'tickets_tickets_event_number_unique');
        });

        // Solo toca las filas que todavia no tienen numero (idempotente).
        DB::statement('
            UPDATE tickets_tickets t
            JOIN (
                SELECT id, ROW_NUMBER() OVER (PARTITION BY event_id ORDER BY id) AS rn
                FROM tickets_tickets
                WHERE number IS NULL
            ) x ON x.id = t.id
            SET t.number = x.rn
        ');
    }

    public function down(): void
    {
        Schema::table('tickets_tickets', function (Blueprint $table) {
            $table->dropUnique('tickets_tickets_event_number_unique');
            $table->dropColumn('number');
        });
    }
};
