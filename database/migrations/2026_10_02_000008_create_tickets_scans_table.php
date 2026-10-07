<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tickets_scans', function (Blueprint $table) {
            $table->id();
            // Nullable a proposito: un QR con uuid desconocido no corresponde a
            // ningun boleto, y ese escaneo se registra igual para poder auditar
            // los intentos. Antes se guardaba ticket_id = 0, que viola la FK.
            $table->foreignId('ticket_id')->nullable()->constrained('tickets_tickets')->cascadeOnDelete();
            $table->foreignId('event_id')->constrained('tickets_events')->cascadeOnDelete();
            $table->foreignId('scanner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('scanned_at');
            $table->enum('result', ['valid', 'used', 'invalid', 'expired', 'wrong_event', 're_entry'])
                ->default('valid')
                ->index();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['event_id', 'scanned_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tickets_scans');
    }
};