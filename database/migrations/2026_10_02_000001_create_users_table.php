<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();

            // El portal de compra se identifica por DNI, el panel por email.
            // Ambos pueden ser nulos, pero nunca duplicarse: los dos tienen
            // indice unico y el backend normaliza antes de comparar.
            $table->string('name', 150);
            $table->string('email', 150)->nullable()->unique();
            $table->string('dni', 30)->nullable()->unique();

            $table->string('phone', 30)->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');

            // Un solo flag decide que shell monta la SPA: true -> panel de
            // administracion, false -> portal de compra.
            $table->boolean('is_admin')->default(false)->index();

            $table->boolean('enable')->default(true);
            $table->boolean('must_change_password')->default(false);
            $table->timestamp('last_login_at')->nullable();
            $table->timestamp('consent_accepted_at')->nullable();
            $table->string('remember_token', 100)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};