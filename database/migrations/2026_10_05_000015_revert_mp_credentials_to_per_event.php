<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Refactor a modelo per-evento (no per-user, no global).
 *
 * El token de MP se guarda en tickets_events.mp_access_token (OAuth-obtenido
 * por el organizador cuando crea/configura el evento). Las credenciales de la
 * app MP (Client ID/Secret) van en .env, NO en la base.
 *
 * - users.mp_*: se eliminan (OAuth no guarda nada aca).
 * - tickets_events.organizer_id: se elimina (la organizacion es implicita:
 *   el operador del evento es quien autoriza via OAuth al editarlo).
 *
 * tokens_users.mp_access_token que quedaron seteados por la migracion anterior
 * se pierden: no se pueden migrar a tickets_events porque no sabemos a que
 * evento correspondian. El operador debe re-vincular via OAuth o pegar el
 * token manualmente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['mp_access_token', 'mp_refresh_token', 'mp_authorized_at']);
        });

        Schema::table('tickets_events', function (Blueprint $table) {
            $table->dropForeign(['organizer_id']);
            $table->dropColumn('organizer_id');
        });
    }

    public function down(): void
    {
        Schema::table('tickets_events', function (Blueprint $table) {
            $table->foreignId('organizer_id')
                ->nullable()
                ->after('cover_image')
                ->constrained('users')
                ->nullOnDelete();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('mp_access_token')->nullable()->after('consent_accepted_at');
            $table->string('mp_refresh_token')->nullable()->after('mp_access_token');
            $table->timestamp('mp_authorized_at')->nullable()->after('mp_refresh_token');
        });
    }
};