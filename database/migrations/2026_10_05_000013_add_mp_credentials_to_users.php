<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Token de MercadoPago por usuario (modelo OAuth multi-tenant).
 *
 * Cada usuario (admin / organizador) conecta su propia cuenta MP via OAuth.
 * El sistema intercambia el code por un access_token y lo guarda aca. Los
 * pagos de los eventos que el usuario organiza caen en SU cuenta MP, no en
 * una cuenta global de la plataforma.
 *
 * access_token: server-side credential. Nunca se devuelve en claro al cliente.
 * refresh_token: para renovar el token sin pedirle al usuario que reautorice.
 * authorized_at: timestamp del OAuth, util para saber si el token es fresco.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('mp_access_token')->nullable()->after('consent_accepted_at');
            $table->string('mp_refresh_token')->nullable()->after('mp_access_token');
            $table->timestamp('mp_authorized_at')->nullable()->after('mp_refresh_token');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['mp_access_token', 'mp_refresh_token', 'mp_authorized_at']);
        });
    }
};