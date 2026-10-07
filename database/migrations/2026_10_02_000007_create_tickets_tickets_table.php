<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tickets_tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('tickets_orders')->cascadeOnDelete();
            $table->foreignId('event_id')->constrained('tickets_events')->cascadeOnDelete();
            $table->foreignId('ticket_type_id')->constrained('tickets_event_ticket_types')->restrictOnDelete();
            $table->string('uuid', 36)->unique();
            $table->text('qr_payload');
            $table->enum('status', ['valid', 'used', 'cancelled'])->default('valid')->index();
            $table->dateTime('used_at')->nullable();
            $table->foreignId('used_by_scanner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('wristband_given')->default(false);
            $table->string('wristband_color', 30)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['event_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tickets_tickets');
    }
};