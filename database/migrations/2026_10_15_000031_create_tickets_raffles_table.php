<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sorteo (rifa) de un evento.
 *
 * Un sorteo no es mas que un filtro guardado sobre las entradas de un evento:
 * que medios de pago cuentan, que rango de numeros de entrada, que rango de
 * fechas y si se admiten entradas usadas/canceladas. Sobre ese conjunto (el
 * "pool") se sortean los premios definidos en tickets_raffle_prizes.
 *
 * public_token es la credencial del proyector: la ventana publica se abre con
 * /sorteo/{public_token} y no pide sesion. Igual que el link de una venta, el
 * token es aleatorio para que nadie adivine el sorteo de otro evento.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tickets_raffles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('tickets_events')->cascadeOnDelete();
            $table->uuid('public_token')->unique();
            $table->string('name', 150);
            $table->enum('status', ['draft', 'open', 'finished'])->default('draft')->index();

            // Rango de numeros de entrada (NULL = sin limite de ese lado).
            $table->unsignedInteger('number_from')->nullable();
            $table->unsignedInteger('number_to')->nullable();

            // Rango de fechas de compra (NULL = sin limite de ese lado).
            $table->dateTime('date_from')->nullable();
            $table->dateTime('date_to')->nullable();

            /*
            | Medios de pago que cuentan, como lista JSON de los mismos codigos
            | del filtro del panel ('efectivo', 'mercadopago', 'multipago:qr',
            | 'multipago:button'). NULL o lista vacia = no filtra por medio.
            */
            $table->json('payment_methods')->nullable();

            // Elegibilidad. Por defecto: solo pagas, incluye usadas, excluye canceladas.
            $table->boolean('only_paid')->default(true);
            $table->boolean('include_used')->default(true);
            $table->boolean('include_cancelled')->default(false);
            $table->boolean('allow_repeat_winners')->default(false);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tickets_raffles');
    }
};
