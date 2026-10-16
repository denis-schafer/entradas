<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Premio de un sorteo.
 *
 * Cada premio es una fila con su posicion (el orden en que se sortea), un
 * nombre y una descripcion opcional. El ganador se guarda aca mismo
 * (winner_ticket_id + drawn_at + drawn_by): el estado del sorteo es el conjunto
 * de premios ya sorteados. El historial de cambios vive en tickets_raffle_draws.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tickets_raffle_prizes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('raffle_id')->constrained('tickets_raffles')->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->string('name', 150)->nullable();
            $table->text('description')->nullable();

            $table->foreignId('winner_ticket_id')->nullable()->constrained('tickets_tickets')->nullOnDelete();
            $table->dateTime('drawn_at')->nullable();
            $table->foreignId('drawn_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['raffle_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tickets_raffle_prizes');
    }
};
