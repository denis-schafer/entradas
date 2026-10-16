<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Orden manual de los eventos.
 *
 * El panel acomoda los eventos arrastrando y soltando, y ese mismo orden es el
 * que ve el portal en "Proximos eventos". El backfill conserva el orden que ya
 * mostraba el panel (mas nuevo arriba) para que nada se mueva hasta que alguien
 * lo reordene a mano.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets_events', function (Blueprint $table) {
            $table->unsignedInteger('sort_order')->default(0)->after('status')->index();
        });

        // La tabla derivada evita el "can't specify target table for update".
        DB::statement(
            'UPDATE tickets_events e
             JOIN (
                 SELECT id, ROW_NUMBER() OVER (ORDER BY id DESC) - 1 AS position
                 FROM tickets_events
             ) ranked ON ranked.id = e.id
             SET e.sort_order = ranked.position'
        );
    }

    public function down(): void
    {
        Schema::table('tickets_events', function (Blueprint $table) {
            $table->dropColumn('sort_order');
        });
    }
};
