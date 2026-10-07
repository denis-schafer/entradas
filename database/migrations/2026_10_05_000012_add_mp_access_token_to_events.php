<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El access_token de MercadoPago se mueve de una config global a un campo
 * por evento: cada organizador conecta la suya y los pagos de su evento
 * caen en su MP, no en una cuenta comun.
 *
 * mp_webhook_secret queda fuera del MVP por evento: si no se setea, el
 * webhook acepta sin chequear firma (mismo comportamiento que antes).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets_events', function (Blueprint $table) {
            $table->string('mp_access_token')->nullable()->after('cover_image');
        });
    }

    public function down(): void
    {
        Schema::table('tickets_events', function (Blueprint $table) {
            $table->dropColumn('mp_access_token');
        });
    }
};