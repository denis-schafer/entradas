<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tickets_event_ticket_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('tickets_events')->cascadeOnDelete();
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->string('image_path')->nullable();
            $table->decimal('price', 10, 2)->default(0);
            $table->unsignedInteger('stock')->nullable();
            $table->unsignedInteger('sold_count')->default(0);
            $table->unsignedInteger('max_per_order')->default(10);
            $table->dateTime('sale_start_at')->nullable();
            $table->dateTime('sale_end_at')->nullable();
            $table->enum('payment_mode', ['single', 'installments', 'both'])->default('both');
            $table->unsignedTinyInteger('max_installments')->default(1);
            $table->string('wristband_color', 30)->nullable();
            $table->string('wristband_label', 50)->nullable();
            $table->boolean('enable')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tickets_event_ticket_types');
    }
};