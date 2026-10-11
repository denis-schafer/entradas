<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Medios de pago que un operador (vendedor o administrativo) puede cobrar
 * desde el POS.
 *
 * Lista vacia = solo efectivo. El efectivo no se guarda aca: siempre esta
 * disponible. El administrador ignora esta tabla y usa todos los medios
 * habilitados del evento. Un cajero no vende, asi que no tiene filas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tickets_user_payment_method', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('payment_method_id')->constrained('tickets_payment_methods')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'payment_method_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tickets_user_payment_method');
    }
};
