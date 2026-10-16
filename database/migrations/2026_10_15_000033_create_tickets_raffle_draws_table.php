<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Auditoria de sorteos.
 *
 * Una fila por accion sobre un premio: 'draw' cuando se elige un ganador y
 * 'reset' cuando se vuelve a sortear. Guarda quien lo hizo y cuando, para que
 * un re-sorteo quede registrado. No reemplaza el ganador actual (eso vive en
 * tickets_raffle_prizes): es la bitacora de lo que paso.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tickets_raffle_draws', function (Blueprint $table) {
            $table->id();
            $table->foreignId('raffle_id')->constrained('tickets_raffles')->cascadeOnDelete();
            $table->foreignId('prize_id')->constrained('tickets_raffle_prizes')->cascadeOnDelete();
            $table->foreignId('ticket_id')->nullable()->constrained('tickets_tickets')->nullOnDelete();
            $table->enum('action', ['draw', 'reset']);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tickets_raffle_draws');
    }
};
