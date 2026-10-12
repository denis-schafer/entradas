<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Snapshot de los datos del evento al momento de la venta.
 *
 * El nombre (y la fecha/lugar) pueden cambiar despues de emitida la entrada, y
 * un evento con ventas se puede cancelar y dejar de estar visible. Con estas
 * columnas la orden muestra el evento tal como era cuando se vendio, sin
 * depender del join con tickets_events.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets_orders', function (Blueprint $table) {
            $table->string('event_name', 200)->nullable()->after('event_id');
            $table->dateTime('event_starts_at')->nullable()->after('event_name');
            $table->string('event_location', 255)->nullable()->after('event_starts_at');
        });

        // Las ordenes viejas toman los datos actuales del evento.
        DB::table('tickets_orders as o')
            ->join('tickets_events as e', 'e.id', '=', 'o.event_id')
            ->update([
                'o.event_name' => DB::raw('e.name'),
                'o.event_starts_at' => DB::raw('e.starts_at'),
                'o.event_location' => DB::raw('e.location'),
            ]);
    }

    public function down(): void
    {
        Schema::table('tickets_orders', function (Blueprint $table) {
            $table->dropColumn(['event_name', 'event_starts_at', 'event_location']);
        });
    }
};
