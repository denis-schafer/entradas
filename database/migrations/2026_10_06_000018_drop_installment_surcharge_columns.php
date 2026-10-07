<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Reversa de las migraciones 2026_10_06_000016 y 2026_10_06_000017.
|
| Esas migraciones agregaron columnas para soportar "cuotas sin interes"
| absorbidas por el vendedor (precio inflado en cuotas, CFT/TEA embebido).
| Esa estrategia quedo descartada: cuando el operador configura cuotas en el
| checkout de MP, MP cobra su propio interes encima de cualquier precio que
| mandemos, asi que inflar el precio en nuestro sistema era doble cobro para
| el cliente.
|
| La estrategia final es la opuesta: NO tocamos el precio, dejamos que MP
| muestre las opciones reales al cliente en su checkout. Estas columnas
| quedaron siempre en 0 sin uso y se eliminan.
*/
return new class extends Migration
{
    public function up(): void
    {
        $orders = array_filter([
            'cash_price',
            'installment_surcharge',
            'installment_surcharge_pct',
            'installment_unit_price',
        ], fn (string $c) => Schema::hasColumn('tickets_orders', $c));

        if ($orders !== []) {
            Schema::table('tickets_orders', function (Blueprint $table) use ($orders) {
                $table->dropColumn($orders);
            });
        }

        if (Schema::hasColumn('tickets_event_ticket_types', 'installment_surcharge_pct')) {
            Schema::table('tickets_event_ticket_types', function (Blueprint $table) {
                $table->dropColumn('installment_surcharge_pct');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('tickets_event_ticket_types', 'installment_surcharge_pct')) {
            Schema::table('tickets_event_ticket_types', function (Blueprint $table) {
                $table->decimal('installment_surcharge_pct', 5, 2)->default(0)->after('max_installments');
            });
        }

        if (! Schema::hasColumn('tickets_orders', 'cash_price')) {
            Schema::table('tickets_orders', function (Blueprint $table) {
                $table->decimal('cash_price', 10, 2)->nullable()->after('total');
                $table->decimal('installment_surcharge', 10, 2)->default(0)->after('cash_price');
                $table->decimal('installment_surcharge_pct', 5, 2)->default(0)->after('installment_surcharge');
                $table->decimal('installment_unit_price', 10, 2)->nullable()->after('installment_surcharge_pct');
            });
        }
    }
};