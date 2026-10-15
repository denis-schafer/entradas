<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Estado global de la aplicacion (clave/valor).
 *
 * Guarda el flag de "Modo Test" y las marcas de id por tabla que se capturan
 * al activarlo. Vive en su propia tabla y no en tickets_configs para que el
 * estado interno no aparezca en la pantalla de configuracion ni se pueda
 * editar como si fuera una preferencia del negocio.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tickets_app_state', function (Blueprint $table) {
            $table->string('name', 100)->primary();
            $table->text('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tickets_app_state');
    }
};
