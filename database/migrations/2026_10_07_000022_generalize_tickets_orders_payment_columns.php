<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Generaliza las columnas de pago de la orden para soportar varios medios.
 *
 * Antes todo era "mp_*" porque el unico medio era MercadoPago. Ahora el pago
 * puede ser cualquiera de los medios configurados, asi que:
 *   mp_preference_id      -> payment_reference    (pref id MP / codigo QR Multipago)
 *   mp_payment_id         -> payment_external_id  (id del pago en el proveedor)
 *   mp_transaction_amount -> payment_amount       (importe acreditado)
 * y se agrega payment_method (el code del medio usado).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets_orders', function (Blueprint $table) {
            $table->renameColumn('mp_preference_id', 'payment_reference');
            $table->renameColumn('mp_payment_id', 'payment_external_id');
            $table->renameColumn('mp_transaction_amount', 'payment_amount');
        });

        Schema::table('tickets_orders', function (Blueprint $table) {
            $table->string('payment_method', 30)->nullable()->after('installment_count');
        });
    }

    public function down(): void
    {
        Schema::table('tickets_orders', function (Blueprint $table) {
            $table->dropColumn('payment_method');
        });

        Schema::table('tickets_orders', function (Blueprint $table) {
            $table->renameColumn('payment_reference', 'mp_preference_id');
            $table->renameColumn('payment_external_id', 'mp_payment_id');
            $table->renameColumn('payment_amount', 'mp_transaction_amount');
        });
    }
};
