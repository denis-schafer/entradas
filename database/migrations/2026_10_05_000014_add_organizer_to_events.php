<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cada evento pertenece a un organizador (un user con is_admin). Los pagos
 * de ese evento caen en la cuenta MP de ese organizador.
 *
 * Backfill: eventos existentes sin organizer_id quedan asignados al primer
 * admin del sistema. Es arbitrario (cualquier admin podrıa cobrar), pero
 * asegura que los eventos viejos no queden sin cuenta asociada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets_events', function (Blueprint $table) {
            $table->foreignId('organizer_id')
                ->nullable()
                ->after('cover_image')
                ->constrained('users')
                ->nullOnDelete();
        });

        $firstAdmin = DB::table('users')
            ->where('is_admin', true)
            ->orderBy('id')
            ->value('id');

        if ($firstAdmin) {
            DB::table('tickets_events')
                ->whereNull('organizer_id')
                ->update(['organizer_id' => $firstAdmin]);
        }
    }

    public function down(): void
    {
        Schema::table('tickets_events', function (Blueprint $table) {
            $table->dropForeign(['organizer_id']);
            $table->dropColumn('organizer_id');
        });
    }
};