<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Asignacion de medios de pago a un evento, con los valores particulares.
 *
 * `enabled` dice si ese medio se puede usar para cobrar ese evento. `config`
 * guarda los overrides del evento (por ejemplo, el access_token de MP obtenido
 * por OAuth, o la webhook_key de Multipago). Si un campo no esta aca, se usa el
 * valor general del medio en tickets_payment_methods.
 *
 * La UI de "Medios de pago" es la que administra esta tabla (selector de
 * eventos por metodo). La edicion del evento solo muestra un resumen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tickets_event_payment_method', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('tickets_events')->cascadeOnDelete();
            $table->foreignId('payment_method_id')->constrained('tickets_payment_methods')->cascadeOnDelete();
            $table->boolean('enabled')->default(true);
            $table->json('config')->nullable();
            $table->timestamps();
            $table->unique(['event_id', 'payment_method_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tickets_event_payment_method');
    }
};
