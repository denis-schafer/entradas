<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Catalogo de medios de pago y su configuracion general (credenciales de la
 * plataforma). Cada fila representa un proveedor (MercadoPago, Multipago, ...).
 *
 * `config` guarda credenciales propias de cada proveedor como JSON, porque
 * cada uno tiene campos distintos y no vale la pena una columna por cada uno.
 * Nunca se devuelve en claro al navegador: los valores con clave "secreta" se
 * enmascaran en el controlador.
 *
 * El "enabled" de aca es el interruptor GLOBAL del medio. El habilitado por
 * evento vive en tickets_event_payment_method.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tickets_payment_methods', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 60);
            $table->boolean('enabled')->default(true);
            $table->json('config')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        $now = now();

        DB::table('tickets_payment_methods')->insert([
            [
                'code' => 'mercadopago',
                'name' => 'MercadoPago',
                'enabled' => true,
                'config' => json_encode([]),
                'sort_order' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'multipago',
                'name' => 'Multipago',
                'enabled' => false,
                'config' => json_encode([]),
                'sort_order' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('tickets_payment_methods');
    }
};
