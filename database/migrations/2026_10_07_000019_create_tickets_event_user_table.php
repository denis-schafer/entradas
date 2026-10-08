<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
        | Cajeros asignados a eventos. Es lo que limita al operador de puerta:
        | un cajero solo ve y escanea los eventos de este listado, y si no tiene
        | ninguno no ve nada. El rol Administrador ignora la asignacion y pasa
        | por todos los eventos igual.
        */
        Schema::create('tickets_event_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('tickets_events')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['event_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tickets_event_user');
    }
};
