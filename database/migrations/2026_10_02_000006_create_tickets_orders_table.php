<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tickets_orders', function (Blueprint $table) {
            $table->id();

            // Token opaco y no adivinable de la orden. El canal websocket del
            // portal es 'tickets.order.{public_token}': usar el id aqui
            // filtraria el estado de pago de cualquier orden, porque el id es
            // autoincremental.
            $table->uuid('public_token')->unique();

            $table->foreignId('event_id')->constrained('tickets_events')->cascadeOnDelete();
            $table->foreignId('buyer_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('buyer_name', 200);
            $table->string('buyer_email', 200);
            $table->string('buyer_dni', 30)->nullable();
            $table->string('buyer_phone', 30)->nullable();
            $table->decimal('subtotal', 10, 2)->nullable();
            $table->decimal('total', 10, 2)->default(0);
            $table->enum('status', ['pending', 'paid', 'cancelled', 'expired'])
                ->default('pending')
                ->index();
            $table->enum('payment_mode', ['single', 'installments'])->default('single');
            $table->unsignedTinyInteger('installment_count')->default(1);
            $table->string('mp_preference_id')->nullable();
            $table->string('mp_payment_id')->nullable();
            $table->decimal('mp_transaction_amount', 10, 2)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['event_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tickets_orders');
    }
};